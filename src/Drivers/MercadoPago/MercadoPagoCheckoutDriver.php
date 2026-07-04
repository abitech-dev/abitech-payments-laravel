<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\MercadoPago;

use Abitech\Payments\Contracts\SubscriptionInterface;
use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\Concerns\HandlesMercadoPagoWebhook;
use Abitech\Payments\Concerns\HandlesMercadoPagoRefund;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\DTO\SubscriptionResponse;
use Abitech\Payments\Events\PaymentInitiated;
use Abitech\Payments\Events\PaymentSucceeded;
use Abitech\Payments\Events\PayoutProcessed;
use Abitech\Payments\Events\RefundProcessed;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\Exceptions\MPApiException;
use DateTime;
use Exception;

class MercadoPagoCheckoutDriver extends AbstractPaymentDriver implements SubscriptionInterface
{
    use HandlesMercadoPagoWebhook, HandlesMercadoPagoRefund;

    protected array $supportedCurrencies = ['PEN', 'USD', 'BRL', 'ARS', 'MXN', 'CLP', 'COP'];

    public function getGatewayName(): string
    {
        return 'mercadopago_checkout';
    }

    protected function authenticate(): void
    {
        $accessToken = $this->config['access_token'] ?? null;

        if (empty($accessToken)) {
            throw new PaymentGatewayException(
                "Falta el token de acceso de Mercado Pago en la configuracion."
            );
        }

        MercadoPagoConfig::setAccessToken($accessToken);
    }

    public function health(): bool
    {
        $this->authenticate();

        try {
            $client = new PaymentClient();
            $client->get(1);
            return true;
        } catch (MPApiException $e) {
            if ($e->getStatusCode() === 404) {
                return true;
            }

            throw new PaymentGatewayException(
                "Mercado Pago no responde: " . $e->getMessage(),
                $e->getStatusCode() ?: 500,
                $e
            );
        } catch (Exception $e) {
            throw new PaymentGatewayException(
                "Fallo de conectividad con Mercado Pago: " . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);
        $this->authenticate();
        $this->throttle('mercadopago_checkout:purchase');

        return $this->retry(function () use ($request) {
            $client = new PreferenceClient();

            $items = [
                [
                    'id' => $request->idempotencyKey ?? uniqid('', true),
                    'title' => $request->description,
                    'quantity' => 1,
                    'unit_price' => $request->amount,
                    'currency_id' => strtoupper($request->currency),
                ]
            ];

            $backUrls = $request->metadata['back_urls'] ?? [];
            $successUrl = $request->successUrl ?? $backUrls['success'] ?? $request->metadata['success_url'] ?? null;
            $cancelUrl = $request->cancelUrl ?? $backUrls['failure'] ?? $request->metadata['cancel_url'] ?? null;
            $pendingUrl = $backUrls['pending'] ?? $request->metadata['pending_url'] ?? null;

            $backUrlsPayload = array_filter([
                'success' => $successUrl,
                'failure' => $cancelUrl,
                'pending' => $pendingUrl,
            ], fn ($v) => $v !== null);

            $payload = [
                'items' => $items,
                'payer' => ['email' => $request->email],
                'back_urls' => $backUrlsPayload,
            ];

            if ($request->idempotencyKey) {
                $payload['external_reference'] = $request->idempotencyKey;
            }

            if ($successUrl && str_starts_with($successUrl, 'https://')) {
                $payload['auto_return'] = $request->metadata['auto_return'] ?? 'approved';
            }

            $notificationUrl = $request->metadata['notification_url'] ?? null;
            if ($notificationUrl) {
                $payload['notification_url'] = $notificationUrl;
            }

            $paymentMethods = $request->metadata['payment_methods'] ?? null;
            if ($paymentMethods) {
                $payload['payment_methods'] = $this->normalizePaymentMethods($paymentMethods);
            }

            $preference = $client->create($payload);

            $response = new PaymentResponse(
                success: true,
                transactionId: $preference->id,
                status: 'pending',
                redirectUrl: $preference->init_point,
                raw: json_decode(json_encode($preference), true)
            );

            event(new PaymentInitiated($response, 'mercadopago_checkout'));

            return $response;
        });
    }

    public function refund(string $transactionId, ?float $amount = null): bool
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:refund');

        $result = $this->retry(function () use ($transactionId, $amount) {
            return $this->sendRefundRequest($transactionId, $amount);
        });

        event(new RefundProcessed('mercadopago_checkout', $transactionId, $amount));

        return $result;
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $this->validateCurrency($request->currency);
        $this->authenticate();
        $this->throttle('mercadopago_checkout:payout');

        return $this->retry(function () use ($request) {
            $client = new PaymentClient();
            $payment = $client->create([
                'transaction_amount' => $request->amount,
                'description' => $request->description,
                'payment_method_id' => $request->metadata['payment_method_id'] ?? 'account_money',
                'payer' => ['email' => $request->recipient],
            ]);

            $response = new PayoutResponse(
                success: $payment->status === 'approved',
                payoutId: (string) $payment->id,
                status: $payment->status === 'approved' ? 'completed' : 'pending',
                raw: json_decode(json_encode($payment), true)
            );

            event(new PayoutProcessed($response, 'mercadopago_checkout'));

            return $response;
        });
    }

    // ─── Subscriptions (Preapproval) ────────────────────────────────────────

    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:create_subscription');

        return $this->retry(function () use ($request) {
            $client = new PreApprovalClient();

            $startDate = new DateTime();
            $endDate = isset($request->metadata['end_date'])
                ? new DateTime($request->metadata['end_date'])
                : (new DateTime())->modify('+2 years');

            $preapproval = $client->create([
                'reason' => $request->planId,
                'auto_recurring' => [
                    'frequency' => $request->intervalCount ?? 1,
                    'frequency_type' => $this->mapInterval($request->interval),
                    'transaction_amount' => $request->amount,
                    'currency_id' => $request->currency,
                    'start_date' => $startDate->format('Y-m-d\TH:i:sP'),
                    'end_date' => $endDate->format('Y-m-d\TH:i:sP'),
                ],
                'payer_email' => $request->email,
                'back_url' => $request->metadata['back_url'] ?? null,
                'status' => 'pending',
            ]);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $preapproval->id,
                status: $preapproval->status,
                planId: $request->planId,
                nextBillingDate: $preapproval->auto_recurring?->start_date ?? null,
                raw: json_decode(json_encode($preapproval), true)
            );
        });
    }

    public function cancelSubscription(string $subscriptionId, ?string $reason = null): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:cancel_subscription');

        return $this->retry(function () use ($subscriptionId, $reason) {
            $client = new PreApprovalClient();
            $preapproval = $client->update($subscriptionId, [
                'status' => 'cancelled',
            ]);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $preapproval->id,
                status: 'cancelled',
                planId: $preapproval->reason ?? null,
                canceledAt: (new DateTime())->format('Y-m-d\TH:i:sP'),
                raw: json_decode(json_encode($preapproval), true)
            );
        });
    }

    public function updateSubscription(string $subscriptionId, SubscriptionRequest $request): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:update_subscription');

        return $this->retry(function () use ($subscriptionId, $request) {
            $client = new PreApprovalClient();

            $payload = [];

            if ($request->amount !== null) {
                $payload['auto_recurring']['transaction_amount'] = $request->amount;
            }

            if (!empty($payload)) {
                $preapproval = $client->update($subscriptionId, $payload);
            } else {
                $preapproval = $client->get($subscriptionId);
            }

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $preapproval->id,
                status: $preapproval->status,
                planId: $request->planId,
                raw: json_decode(json_encode($preapproval), true)
            );
        });
    }

    public function getSubscription(string $subscriptionId): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:get_subscription');

        return $this->retry(function () use ($subscriptionId) {
            $client = new PreApprovalClient();
            $preapproval = $client->get($subscriptionId);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $preapproval->id,
                status: $preapproval->status,
                planId: $preapproval->reason ?? null,
                nextBillingDate: $preapproval->auto_recurring?->start_date ?? null,
                raw: json_decode(json_encode($preapproval), true)
            );
        });
    }

    protected function mapInterval(string $interval): string
    {
        return match ($interval) {
            'day' => 'days',
            'week' => 'weeks',
            'month' => 'months',
            'year' => 'years',
            default => 'months',
        };
    }

    protected function normalizePaymentMethods(array $paymentMethods): array
    {
        foreach (['excluded_payment_methods', 'excluded_payment_types'] as $key) {
            if (isset($paymentMethods[$key]) && is_array($paymentMethods[$key])) {
                $paymentMethods[$key] = array_map(function ($item) {
                    return is_string($item) ? ['id' => $item] : $item;
                }, $paymentMethods[$key]);
            }
        }

        return $paymentMethods;
    }
}
