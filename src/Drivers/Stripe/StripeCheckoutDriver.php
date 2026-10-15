<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\Stripe;

use Illuminate\Http\Request;
use Abitech\Payments\Concerns\ConvertsStripeAmounts;
use Abitech\Payments\Contracts\SubscriptionInterface;
use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\Concerns\VerifiesWebhookSignature;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\DTO\SubscriptionResponse;
use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Exception;
use Illuminate\Support\Carbon;
use Stripe\StripeClient;

class StripeCheckoutDriver extends AbstractPaymentDriver implements SubscriptionInterface
{
    use ConvertsStripeAmounts, VerifiesWebhookSignature;

    /** @var string[] Divisas soportadas por Stripe. */
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
                ['id' => 'sofort', 'label' => 'SOFORT'],
                ['id' => 'sepa_debit', 'label' => 'SEPA Direct Debit'],
            ]],
        ];
    }

    public function getGatewayName(): string
    {
        return 'stripe_checkout';
    }

    /**
     * Inicializa StripeClient con la secret key del config.
     */
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

    /**
     * Verifica conectividad consultando el balance de Stripe.
     */
    public function health(): bool
    {
        $this->authenticate();
        $this->throttle('stripe_checkout:health');

        return $this->retry(function () {
            $this->client->balance->retrieve();
            return true;
        });
    }

    /**
     * Crea una sesion de Stripe Checkout y retorna la URL de redireccion.
     *
     * metadata esperado: success_url, cancel_url, quantity (opcional).
     */
    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);

        $successUrl = $request->successUrl ?? $request->metadata['success_url'] ?? null;
        $cancelUrl = $request->cancelUrl ?? $request->metadata['cancel_url'] ?? null;

        if (empty($successUrl) || empty($cancelUrl)) {
            throw new PaymentGatewayException(
                "Se requiere success_url y cancel_url para Stripe Checkout."
            );
        }

        $unitAmount = $this->toStripeAmount($request->amount, $request->currency);
        $this->authenticate();
        $this->throttle('stripe_checkout:purchase');

        return $this->retry(function () use ($request, $successUrl, $cancelUrl, $unitAmount) {
            $paymentMethodTypes = $request->metadata['payment_method_types'] ?? ['card'];

            $session = $this->client->checkout->sessions->create([
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($request->currency),
                        'product_data' => ['name' => $request->description],
                        'unit_amount' => $unitAmount,
                    ],
                    'quantity' => $request->metadata['quantity'] ?? 1,
                ]],
                'mode' => 'payment',
                'payment_method_types' => $paymentMethodTypes,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $request->email,
                'client_reference_id' => $request->metadata['session_id'] ?? $request->idempotencyKey,
                'expand' => ['payment_intent.latest_charge'],
                'metadata' => $request->idempotencyKey
                    ? ['idempotency_key' => $request->idempotencyKey]
                    : [],
            ]);

            return new PaymentResponse(
                success: true,
                transactionId: $session->id,
                status: 'pending',
                redirectUrl: $session->url,
                raw: $session->toArray()
            );
        });
    }

    /**
     * Reembolsa un PaymentIntent; el monto parcial se convierte con la moneda
     * del PaymentIntent.
     */
    public function refund(string $transactionId, ?string $amount = null): bool
    {
        self::assertDecimalAmount($amount);
        $this->authenticate();
        $this->throttle('stripe_checkout:refund');

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
        $this->throttle('stripe_checkout:payout');

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

    /**
     * Procesa un webhook de Stripe: verifica firma, extrae tipo de evento
     * y normaliza los datos en un WebhookResult.
     */
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

        $transactionId = null;
        $status = null;
        $amount = null;
        $currency = null;

        if (! $this->isRelevantEvent($type)) {
            $status = 'ignored';
        } elseif (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            $transactionId = $object['id'] ?? null;
            $status = 'completed';
            $amount = $this->fromStripeAmount($object['amount_total'] ?? null, $object['currency'] ?? null);
            $currency = $object['currency'] ?? null;
        } elseif ($type === 'payment_intent.succeeded') {
            $transactionId = $object['payment_details']['order_reference'] ?? $object['id'] ?? null;
            $status = 'completed';
            $amount = $this->fromStripeAmount($object['amount'] ?? null, $object['currency'] ?? null);
            $currency = $object['currency'] ?? null;
        } elseif (in_array($type, ['checkout.session.async_payment_failed', 'payment_intent.payment_failed', 'payment_intent.canceled'], true)) {
            $transactionId = $object['payment_details']['order_reference'] ?? $object['id'] ?? null;
            $status = 'failed';
            $amount = $this->fromStripeAmount($object['amount'] ?? $object['amount_total'] ?? null, $object['currency'] ?? null);
            $currency = $object['currency'] ?? null;
        } elseif ($type === 'charge.refunded') {
            $transactionId = $object['payment_intent'] ?? null;
            $status = 'refunded';
            $amount = $this->fromStripeAmount($object['amount_refunded'] ?? null, $object['currency'] ?? null);
            $currency = $object['currency'] ?? null;
        }

        $card = $this->extractCardDetails($type, $object);

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

    protected function isRelevantEvent(string $type): bool
    {
        return in_array($type, [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'payment_intent.canceled',
            'charge.refunded',
        ], true);
    }

    protected function extractCardDetails(string $type, array $object): array
    {
        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'payment_intent.succeeded'], true)) {
            return [];
        }

        try {
            if (str_starts_with($type, 'checkout.session')) {
                return $this->readCardFromExpandedSession($object);
            }

            return $this->readCardFromPaymentIntent($object['id'] ?? null);
        } catch (Exception) {
            return ['payment_method' => ($object['payment_method_types'][0] ?? null)];
        }
    }

    protected function readCardFromExpandedSession(array $session): array
    {
        $card = $session['payment_intent']['latest_charge']['payment_method_details']['card'] ?? null;

        if ($card) {
            return [
                'payment_method' => $card['brand'] ?? 'card',
                'brand' => $card['brand'] ?? null,
                'last_four' => $card['last4'] ?? null,
            ];
        }

        return ['payment_method' => ($session['payment_method_types'][0] ?? null)];
    }

    protected function readCardFromPaymentIntent(?string $piId): array
    {
        if (! $piId) {
            return [];
        }

        $this->throttle('stripe_checkout:webhook');
        $pi = $this->retry(fn () => $this->client->paymentIntents->retrieve($piId));
        $card = $pi->latest_charge?->payment_method_details?->card;

        if ($card) {
            return [
                'payment_method' => $card->brand ?? 'card',
                'brand' => $card->brand ?? null,
                'last_four' => $card->last4 ?? null,
            ];
        }

        return [];
    }

    protected function buildStripeCustomerDetails(PaymentRequest $request): array
    {
        $details = ['email' => $request->email];

        $name = trim(($request->payerName ?? '') . ' ' . ($request->payerSurname ?? ''));
        if ($name !== '') {
            $details['name'] = $name;
        }

        if ($request->payerPhone) {
            $details['phone'] = $request->payerPhone;
        }

        return $details;
    }

    /**
     * Crea una suscripcion en Stripe: crea Customer, Price recurrente y Subscription.
     * El planId se usa como nombre del producto.
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $unitAmount = $this->toStripeAmount($request->amount ?? '0', $request->currency ?? 'usd');
        $this->authenticate();
        $this->throttle('stripe_checkout:create_subscription');

        return $this->retry(function () use ($request, $unitAmount) {
            $customer = $this->client->customers->create([
                'email' => $request->email,
            ]);

            $price = $this->client->prices->create([
                'currency' => strtolower($request->currency ?? 'usd'),
                'unit_amount' => $unitAmount,
                'recurring' => [
                    'interval' => $request->interval ?? 'month',
                    'interval_count' => $request->intervalCount ?? 1,
                ],
                'product_data' => ['name' => $request->planId],
            ]);

            $subscription = $this->client->subscriptions->create([
                'customer' => $customer->id,
                'items' => [['price' => $price->id]],
                'trial_period_days' => $request->trialDays,
                'metadata' => $request->metadata,
            ]);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $subscription->id,
                status: $subscription->status,
                planId: $request->planId,
                nextBillingDate: Carbon::createFromTimestamp($subscription->current_period_end)->toIso8601String(),
                raw: $subscription->toArray()
            );
        });
    }

    public function cancelSubscription(string $subscriptionId, ?string $reason = null): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('stripe_checkout:cancel_subscription');

        return $this->retry(function () use ($subscriptionId, $reason) {
            $params = [];

            if ($reason) {
                $params['cancellation_reason'] = $reason;
            }

            $subscription = $this->client->subscriptions->cancel($subscriptionId, $params);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $subscription->id,
                status: $subscription->status,
                canceledAt: Carbon::now()->toIso8601String(),
                raw: $subscription->toArray()
            );
        });
    }

    public function updateSubscription(string $subscriptionId, SubscriptionRequest $request): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('stripe_checkout:update_subscription');

        return $this->retry(function () use ($subscriptionId, $request) {
            $payload = ['metadata' => $request->metadata];

            $subscription = $this->client->subscriptions->update($subscriptionId, $payload);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $subscription->id,
                status: $subscription->status,
                planId: $request->planId,
                nextBillingDate: Carbon::createFromTimestamp($subscription->current_period_end)->toIso8601String(),
                raw: $subscription->toArray()
            );
        });
    }

    public function getSubscription(string $subscriptionId): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('stripe_checkout:get_subscription');

        return $this->retry(function () use ($subscriptionId) {
            $subscription = $this->client->subscriptions->retrieve($subscriptionId);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $subscription->id,
                status: $subscription->status,
                nextBillingDate: Carbon::createFromTimestamp($subscription->current_period_end)->toIso8601String(),
                raw: $subscription->toArray()
            );
        });
    }

    /**
     * Mapea el estado de PaymentIntent de Stripe a normalizado.
     * succeeded->completed, processing/*->pending, canceled->failed.
     */
    protected function mapPaymentIntentStatus(string $status): string
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
