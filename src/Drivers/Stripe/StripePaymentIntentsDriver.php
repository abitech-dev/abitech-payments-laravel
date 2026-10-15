<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\Stripe;

use Illuminate\Http\Request;
use Abitech\Payments\Concerns\ConvertsStripeAmounts;
use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\Concerns\VerifiesWebhookSignature;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Exception;
use Stripe\StripeClient;

class StripePaymentIntentsDriver extends AbstractPaymentDriver
{
    use ConvertsStripeAmounts, VerifiesWebhookSignature;

    protected array $supportedCurrencies = [
        'USD', 'EUR', 'GBP', 'PEN', 'MXN', 'BRL', 'ARS', 'CLP', 'COP',
        'CAD', 'AUD', 'NZD', 'CHF', 'HKD', 'SGD', 'JPY', 'SEK', 'NOK',
        'DKK', 'PLN', 'CZK',
    ];

    /** Clase cuya ausencia indica que falta el SDK. */
    protected const SDK_CLASS = StripeClient::class;

    /** Cliente de Stripe inicializado en authenticate(). */
    protected ?StripeClient $client = null;

    public static function paymentMethodSchema(): array
    {
        return [
            ['key' => 'payment_method_types', 'type' => 'checkboxes', 'label' => 'Métodos habilitados', 'default' => ['card'], 'options' => [
                ['id' => 'card', 'label' => 'Tarjeta crédito/débito'],
                ['id' => 'ideal', 'label' => 'iDEAL'],
                ['id' => 'bancontact', 'label' => 'Bancontact'],
            ]],
        ];
    }

    public function getGatewayName(): string
    {
        return 'stripe_paymentintents';
    }

    protected function authenticate(): void
    {
        $this->ensureSdkInstalled(static::SDK_CLASS, 'stripe/stripe-php');

        $secret = $this->config['secret'] ?? null;

        if (empty($secret)) {
            throw new PaymentGatewayException(
                "Falta la clave secreta de Stripe en la configuracion."
            );
        }

        $this->client = new StripeClient($secret);
    }

    public function health(): bool
    {
        $this->authenticate();
        $this->throttle('stripe_paymentintents:health');

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

        $amount = $this->toStripeAmount($request->amount, $request->currency);
        $this->authenticate();
        $this->throttle('stripe_paymentintents:purchase');

        return $this->retry(function () use ($request, $amount) {
            $paymentMethodTypes = $request->metadata['payment_method_types'] ?? ['card'];

            $intent = $this->client->paymentIntents->create([
                'amount' => $amount,
                'currency' => strtolower($request->currency),
                'payment_method' => $request->cardToken,
                'payment_method_types' => $paymentMethodTypes,
                'description' => $request->description,
                'metadata' => $request->metadata,
                'confirm' => true,
                'receipt_email' => $request->email ?: null,
            ], $request->idempotencyKey ? ['idempotency_key' => $request->idempotencyKey] : []);

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

    public function refund(string $transactionId, ?string $amount = null): bool
    {
        self::assertDecimalAmount($amount);
        $this->authenticate();
        $this->throttle('stripe_paymentintents:refund');

        return $this->retry(function () use ($transactionId, $amount) {
            $params = ['payment_intent' => $transactionId];

            if ($amount !== null) {
                $params['amount'] = $this->toStripeAmount(
                    $amount,
                    $this->client->paymentIntents->retrieve($transactionId)->currency,
                );
            }

            $this->client->refunds->create($params);

            return true;
        });
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $this->validateCurrency($request->currency);
        $amount = $this->toStripeAmount($request->amount, $request->currency);
        $this->authenticate();
        $this->throttle('stripe_paymentintents:payout');

        return $this->retry(function () use ($request, $amount) {
            $transfer = $this->client->transfers->create([
                'amount' => $amount,
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
        $this->authenticate();

        $type = $event['type'] ?? 'unknown';
        $object = $event['data']['object'] ?? [];

        $transactionId = $object['id'] ?? null;
        $amount = $this->fromStripeAmount($object['amount'] ?? null, $object['currency'] ?? null);
        $currency = $object['currency'] ?? null;

        if (str_starts_with($type, 'payment_intent.')) {
            $status = $this->mapStatus($object['status'] ?? 'pending');
        } elseif ($type === 'charge.refunded') {
            $transactionId = $object['payment_intent'] ?? $transactionId;
            $status = 'refunded';
            $amount = $this->fromStripeAmount($object['amount_refunded'] ?? null, $object['currency'] ?? null);
        } else {
            $status = 'pending';
        }

        $card = ['payment_method' => null, 'brand' => null, 'last_four' => null];

        if ($status === 'completed' && $transactionId) {
            $card = $this->extractCardDetails($transactionId);
        }

        return new WebhookResult(
            gateway: 'stripe',
            eventType: $type,
            transactionId: $transactionId,
            status: $status,
            amount: $amount,
            currency: $currency,
            paymentMethod: $card['payment_method'] ?? null,
            cardBrand: $card['brand'] ?? null,
            cardLastFour: $card['last_four'] ?? null,
            installments: null,
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

    protected function extractCardDetails(string $paymentIntentId): array
    {
        try {
            $this->throttle('stripe_paymentintents:webhook');
            $pi = $this->retry(fn () => $this->client->paymentIntents->retrieve($paymentIntentId));

            if ($pi->latest_charge && $pi->latest_charge->payment_method_details?->card) {
                $c = $pi->latest_charge->payment_method_details->card;

                return [
                    'payment_method' => $c->brand ?? 'card',
                    'brand' => $c->brand ?? null,
                    'last_four' => $c->last4 ?? null,
                ];
            }

            $pmId = $pi->payment_method ?? null;
            if ($pmId && str_starts_with($pmId, 'pm_')) {
                $pm = $this->retry(fn () => $this->client->paymentMethods->retrieve($pmId));

                return [
                    'payment_method' => $pm->card->brand ?? $pm->type ?? 'card',
                    'brand' => $pm->card->brand ?? null,
                    'last_four' => $pm->card->last4 ?? null,
                ];
            }

            return [];
        } catch (Exception) {
            return [];
        }
    }
}
