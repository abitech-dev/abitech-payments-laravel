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
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Support\Carbon;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;

class MercadoPagoCheckoutDriver extends AbstractPaymentDriver implements SubscriptionInterface
{
    use HandlesMercadoPagoWebhook, HandlesMercadoPagoRefund;

    protected array $supportedCurrencies = ['PEN', 'USD', 'BRL', 'ARS', 'MXN', 'CLP', 'COP'];

    /** Clase cuya ausencia indica que falta el SDK. */
    protected const SDK_CLASS = MercadoPagoConfig::class;

    public static function paymentMethodSchema(): array
    {
        return [
            ['key' => 'installments', 'type' => 'number', 'label' => 'Cuotas máximas (1 = sin cuotas)', 'min' => 1, 'max' => 12, 'default' => 12],
            ['key' => 'excluded_payment_types', 'type' => 'checkboxes', 'label' => 'Excluir tipos de pago', 'default' => [], 'options' => [
                ['id' => 'ticket', 'label' => 'Efectivo'],
                ['id' => 'atm', 'label' => 'Cajero automático'],
                ['id' => 'bank_transfer', 'label' => 'Transferencia bancaria'],
                ['id' => 'prepaid_card', 'label' => 'Tarjeta prepago'],
                ['id' => 'debit_card', 'label' => 'Tarjeta de débito'],
            ]],
            ['key' => 'excluded_payment_methods', 'type' => 'checkboxes', 'label' => 'Excluir marcas / billeteras', 'default' => [], 'options' => [
                ['id' => 'visa', 'label' => 'Visa'],
                ['id' => 'master', 'label' => 'Mastercard'],
                ['id' => 'amex', 'label' => 'American Express'],
                ['id' => 'diners', 'label' => 'Diners Club'],
                ['id' => 'debvisa', 'label' => 'Visa Débito'],
                ['id' => 'debmaster', 'label' => 'Mastercard Débito'],
                ['id' => 'yape', 'label' => 'Yape'],
                ['id' => 'pagoefectivo_atm', 'label' => 'PagoEfectivo'],
            ]],
        ];
    }

    public function getGatewayName(): string
    {
        return 'mercadopago_checkout';
    }

    protected function authenticate(): void
    {
        $this->ensureSdkInstalled(static::SDK_CLASS, 'mercadopago/dx-php');

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
        $this->throttle('mercadopago_checkout:health');

        try {
            return $this->retry(function () {
                (new PaymentClient())->get(1);

                return true;
            });
        } catch (PaymentGatewayException $e) {
            // El pago 1 no existe; un 404 prueba que la API responde.
            if ($e->getCode() === 404) {
                return true;
            }

            throw new PaymentGatewayException("Mercado Pago no responde: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);
        $this->authenticate();
        $this->throttle('mercadopago_checkout:purchase');

        return $this->retry(function () use ($request) {
            $client = new PreferenceClient();

            $items = $this->buildItems($request);

            $backUrls = $request->metadata['back_urls'] ?? [];
            $successUrl = $request->successUrl ?? $backUrls['success'] ?? $request->metadata['success_url'] ?? null;
            $cancelUrl = $request->cancelUrl ?? $backUrls['failure'] ?? $request->metadata['cancel_url'] ?? null;
            $pendingUrl = $backUrls['pending'] ?? $request->metadata['pending_url'] ?? null;

            $backUrlsPayload = collect([
                'success' => $successUrl,
                'failure' => $cancelUrl,
                'pending' => $pendingUrl,
            ])->filter(fn ($url) => $url !== null)->all();

            $payload = [
                'items' => $items,
                'payer' => $this->buildMercadoPagoPayer($request),
                'back_urls' => $backUrlsPayload,
            ];

            if ($request->idempotencyKey) {
                $payload['external_reference'] = $request->metadata['session_id'] ?? $request->idempotencyKey;
            }

            if ($successUrl && str_starts_with($successUrl, 'https://')) {
                $payload['auto_return'] = $request->metadata['auto_return'] ?? 'approved';
            }

            $notificationUrl = $request->metadata['notification_url']
                ?? $this->config['notification_url']
                ?? null;
            if ($notificationUrl) {
                $payload['notification_url'] = $notificationUrl;
            }

            $paymentMethods = $request->metadata['payment_methods'] ?? null;
            if ($paymentMethods) {
                $payload['payment_methods'] = $this->normalizePaymentMethods($paymentMethods);
            }

            $preference = $client->create($payload);

            return new PaymentResponse(
                success: true,
                transactionId: $preference->id,
                status: 'pending',
                redirectUrl: $preference->init_point,
                raw: json_decode(json_encode($preference), true)
            );
        });
    }

    public function refund(string $transactionId, ?string $amount = null): bool
    {
        self::assertDecimalAmount($amount);
        $this->authenticate();
        $this->throttle('mercadopago_checkout:refund');

        return $this->retry(function () use ($transactionId, $amount) {
            return $this->sendRefundRequest($transactionId, $amount);
        });
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $this->validateCurrency($request->currency);
        $this->authenticate();
        $this->throttle('mercadopago_checkout:payout');

        return $this->retry(function () use ($request) {
            $client = new PaymentClient();
            $payment = $client->create([
                'transaction_amount' => $this->toMercadoPagoAmount($request->amount),
                'description' => $request->description,
                'payment_method_id' => $request->metadata['payment_method_id'] ?? 'account_money',
                'payer' => ['email' => $request->recipient],
            ]);

            return new PayoutResponse(
                success: $payment->status === 'approved',
                payoutId: (string) $payment->id,
                status: $payment->status === 'approved' ? 'completed' : 'pending',
                raw: json_decode(json_encode($payment), true)
            );
        });
    }

    // ─── Subscriptions (Preapproval) ────────────────────────────────────────

    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:create_subscription');

        return $this->retry(function () use ($request) {
            $client = new PreApprovalClient();

            $startDate = Carbon::now();
            $endDate = isset($request->metadata['end_date'])
                ? Carbon::parse($request->metadata['end_date'])
                : Carbon::now()->addYears(2);

            $preapproval = $client->create([
                'reason' => $request->planId,
                'auto_recurring' => [
                    'frequency' => $request->intervalCount ?? 1,
                    'frequency_type' => $this->mapInterval($request->interval),
                    'transaction_amount' => $request->amount === null ? null : $this->toMercadoPagoAmount($request->amount),
                    'currency_id' => $request->currency,
                    'start_date' => $startDate->toIso8601String(),
                    'end_date' => $endDate->toIso8601String(),
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
                canceledAt: Carbon::now()->toIso8601String(),
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
                $payload['auto_recurring']['transaction_amount'] = $this->toMercadoPagoAmount($request->amount);
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

    protected function buildItems(PaymentRequest $request): array
    {
        if (!empty($request->metadata['items'])) {
            $prefix = $request->idempotencyKey ?? uniqid('', true);

            return collect($request->metadata['items'])->map(fn ($item, $i) => [
                'id' => "{$prefix}-" . ($i + 1),
                'title' => $item['name'] ?? $item['description'] ?? 'Producto',
                'quantity' => 1,
                'unit_price' => $this->toMercadoPagoAmount((string) ($item['price'] ?? '0')),
                'currency_id' => strtoupper($request->currency),
            ])->values()->all();
        }

        return [[
            'id' => $request->idempotencyKey ?? uniqid('', true),
            'title' => $request->description,
            'quantity' => 1,
            'unit_price' => $this->toMercadoPagoAmount($request->amount),
            'currency_id' => strtoupper($request->currency),
        ]];
    }

    protected function buildMercadoPagoPayer(PaymentRequest $request): array
    {
        $payer = ['email' => $request->email];

        if ($request->payerName) {
            $payer['name'] = $request->payerName;
        }

        if ($request->payerSurname) {
            $payer['surname'] = $request->payerSurname;
        }

        if ($request->payerDocumentType && $request->payerDocumentNumber) {
            $payer['identification'] = [
                'type' => $request->payerDocumentType,
                'number' => $request->payerDocumentNumber,
            ];
        }

        if ($request->payerPhone) {
            $payer['phone'] = ['number' => $request->payerPhone];
        }

        return $payer;
    }

    protected function normalizePaymentMethods(array $paymentMethods): array
    {
        foreach (['excluded_payment_methods', 'excluded_payment_types'] as $key) {
            if (isset($paymentMethods[$key]) && is_array($paymentMethods[$key])) {
                $paymentMethods[$key] = collect($paymentMethods[$key])
                    ->map(fn ($item) => is_string($item) ? ['id' => $item] : $item)
                    ->all();
            }
        }

        return $paymentMethods;
    }
}
