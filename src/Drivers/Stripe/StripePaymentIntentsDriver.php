<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\Stripe;

use Illuminate\Http\Request;
use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\Concerns\VerifiesWebhookSignature;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;

class StripePaymentIntentsDriver extends AbstractPaymentDriver
{
    use VerifiesWebhookSignature;

    protected array $supportedCurrencies = [
        'USD', 'EUR', 'GBP', 'PEN', 'MXN', 'BRL', 'ARS', 'CLP', 'COP',
        'CAD', 'AUD', 'NZD', 'CHF', 'HKD', 'SGD', 'JPY', 'SEK', 'NOK',
        'DKK', 'PLN', 'CZK',
    ];

    protected $client;

    public function getGatewayName(): string
    {
        return 'stripe_paymentintents';
    }

    protected function authenticate(): void
    {
        $secret = $this->config['secret'] ?? null;

        if (empty($secret)) {
            throw new PaymentGatewayException(
                "Falta la clave secreta de Stripe en la configuracion."
            );
        }

        $className = 'Stripe\StripeClient';
        $this->client = new $className($secret);
    }

    public function health(): bool
    {
        $this->authenticate();

        return $this->retry(function () {
            $this->client->balance->retrieve();
            return true;
        });
    }

    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);

        if (empty($request->cardToken)) {
            throw new PaymentGatewayException(
                "Se requiere un token de metodo de pago valido (cardToken) para procesar pagos directos con Stripe."
            );
        }

        $this->authenticate();
        $this->throttle('stripe_paymentintents:purchase');

        return $this->retry(function () use ($request) {
            $intent = $this->client->paymentIntents->create([
                'amount' => (int) round($request->amount * 100),
                'currency' => strtolower($request->currency),
                'payment_method' => $request->cardToken,
                'description' => $request->description,
                'metadata' => $request->metadata,
                'confirm' => true,
                'receipt_email' => $request->email ?: null,
            ]);

            $status = $this->mapStatus($intent->status);

            return new PaymentResponse(
                success: $status === 'completed' || $status === 'pending',
                transactionId: $intent->id,
                status: $status,
                redirectUrl: $intent->next_action?->redirect_to_url?->url ?? null,
                errorMessage: $intent->last_payment_error?->message ?? null,
                raw: $intent->toArray()
            );
        });
    }

    public function refund(string $transactionId, ?float $amount = null): bool
    {
        $this->authenticate();
        $this->throttle('stripe_paymentintents:refund');

        return $this->retry(function () use ($transactionId, $amount) {
            $params = ['payment_intent' => $transactionId];

            if ($amount !== null) {
                $params['amount'] = (int) round($amount * 100);
            }

            $this->client->refunds->create($params);
            return true;
        });
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $this->authenticate();
        $this->throttle('stripe_paymentintents:payout');

        return $this->retry(function () use ($request) {
            $transfer = $this->client->transfers->create([
                'amount' => (int) round($request->amount * 100),
                'currency' => strtolower($request->currency),
                'destination' => $request->recipient,
                'description' => $request->description,
                'metadata' => $request->metadata,
            ]);

            return new PayoutResponse(
                success: true,
                payoutId: $transfer->id,
                status: 'completed',
                raw: $transfer->toArray()
            );
        });
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $webhookSecret = $this->config['webhook_secret'] ?? null;

        if (empty($webhookSecret)) {
            throw new PaymentGatewayException(
                "Falta el secreto de webhook de Stripe en la configuracion."
            );
        }

        $event = $this->verifyStripeSignature($request, $webhookSecret);

        $type = $event['type'] ?? 'unknown';
        $object = $event['data']['object'] ?? [];

        $transactionId = $object['id'] ?? null;
        $amount = isset($object['amount']) ? (float) $object['amount'] / 100 : null;
        $currency = $object['currency'] ?? null;

        if (str_starts_with($type, 'payment_intent.')) {
            $status = $this->mapStatus($object['status'] ?? 'pending');
        } elseif ($type === 'charge.refunded') {
            $transactionId = $object['payment_intent'] ?? $transactionId;
            $status = 'refunded';
            $amount = isset($object['amount_refunded']) ? (float) $object['amount_refunded'] / 100 : null;
        } else {
            $status = 'pending';
        }

        return new WebhookResult(
            gateway: 'stripe',
            eventType: $type,
            transactionId: $transactionId,
            status: $status,
            amount: $amount,
            currency: $currency,
            raw: $event
        );
    }

    protected function mapStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => 'completed',
            'processing' => 'pending',
            'requires_payment_method',
            'requires_confirmation',
            'requires_action',
            'requires_capture' => 'pending',
            'canceled' => 'failed',
            default => 'pending',
        };
    }
}
