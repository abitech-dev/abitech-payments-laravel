<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\Stripe;

use Illuminate\Http\Request;
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

class StripeCheckoutDriver extends AbstractPaymentDriver implements SubscriptionInterface
{
    use VerifiesWebhookSignature;

    /** @var string[] Divisas soportadas por Stripe. */
    protected array $supportedCurrencies = [
        'USD', 'EUR', 'GBP', 'PEN', 'MXN', 'BRL', 'ARS', 'CLP', 'COP',
        'CAD', 'AUD', 'NZD', 'CHF', 'HKD', 'SGD', 'JPY', 'SEK', 'NOK',
        'DKK', 'PLN', 'CZK',
    ];

    /** Cliente de Stripe inicializado en authenticate(). */
    protected $client;

    public function getGatewayName(): string
    {
        return 'stripe_checkout';
    }

    /**
     * Inicializa StripeClient con la secret key del config.
     */
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

    /**
     * Verifica conectividad consultando el balance de Stripe.
     */
    public function health(): bool
    {
        $this->authenticate();

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
        $this->authenticate();
        $this->throttle('stripe_checkout:purchase');

        return $this->retry(function () use ($request) {
            $session = $this->client->checkout->sessions->create([
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($request->currency),
                        'product_data' => ['name' => $request->description],
                        'unit_amount' => (int) round($request->amount * 100),
                    ],
                    'quantity' => $request->metadata['quantity'] ?? 1,
                ]],
                'mode' => 'payment',
                'success_url' => $request->metadata['success_url'] ?? '',
                'cancel_url' => $request->metadata['cancel_url'] ?? '',
                'customer_email' => $request->email,
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
     * Reembolsa un PaymentIntent total o parcialmente.
     *
     * @param string $transactionId  ID del PaymentIntent a reembolsar
     * @param float|null $amount     Monto parcial (null = reembolso total)
     */
    public function refund(string $transactionId, ?float $amount = null): bool
    {
        $this->authenticate();
        $this->throttle('stripe_checkout:refund');

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
        $this->throttle('stripe_checkout:payout');

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

        $type = $event['type'] ?? 'unknown';
        $object = $event['data']['object'] ?? [];

        $transactionId = null;
        $status = null;
        $amount = null;
        $currency = null;

        if ($type === 'checkout.session.completed') {
            $transactionId = $object['id'] ?? null;
            $status = 'completed';
            $amount = isset($object['amount_total']) ? (float) $object['amount_total'] / 100 : null;
            $currency = $object['currency'] ?? null;
        } elseif ($type === 'charge.refunded') {
            $transactionId = $object['payment_intent'] ?? null;
            $status = 'refunded';
            $amount = isset($object['amount_refunded']) ? (float) $object['amount_refunded'] / 100 : null;
            $currency = $object['currency'] ?? null;
        } elseif (str_starts_with($type, 'payment_intent.')) {
            $transactionId = $object['id'] ?? null;
            $status = $this->mapPaymentIntentStatus($object['status'] ?? 'pending');
            $amount = isset($object['amount']) ? (float) $object['amount'] / 100 : null;
            $currency = $object['currency'] ?? null;
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

    /**
     * Crea una suscripcion en Stripe: crea Customer, Price recurrente y Subscription.
     * El planId se usa como nombre del producto.
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('stripe_checkout:create_subscription');

        return $this->retry(function () use ($request) {
            $customer = $this->client->customers->create([
                'email' => $request->email,
            ]);

            $price = $this->client->prices->create([
                'currency' => strtolower($request->currency ?? 'usd'),
                'unit_amount' => (int) round(($request->amount ?? 0) * 100),
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
                nextBillingDate: date('c', $subscription->current_period_end),
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
                canceledAt: date('c'),
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
                nextBillingDate: date('c', $subscription->current_period_end),
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
                nextBillingDate: date('c', $subscription->current_period_end),
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
