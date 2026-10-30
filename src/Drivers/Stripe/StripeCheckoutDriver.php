<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\Stripe;

use Illuminate\Http\Request;
use Abitech\Payments\Concerns\ConvertsStripeAmounts;
use Abitech\Payments\Concerns\ReadsStripeCards;
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
use Illuminate\Support\Carbon;
use Stripe\Checkout\Session;
use Stripe\StripeClient;
use Stripe\Subscription;

class StripeCheckoutDriver extends AbstractPaymentDriver implements SubscriptionInterface
{
    use ConvertsStripeAmounts, ReadsStripeCards, VerifiesWebhookSignature;

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

    /**
     * Los métodos de pago se activan en el panel de Stripe, no en el host.
     */
    public static function paymentMethodSchema(): array
    {
        return [];
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
     * Crea una sesión de Stripe Checkout con una sola línea por el monto total
     * y retorna la URL de redirección.
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
            $session = $this->client->checkout->sessions->create([
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($request->currency),
                        'product_data' => ['name' => $request->description],
                        'unit_amount' => $unitAmount,
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
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

        // El aviso trae el PaymentIntent de la sesión como id, sin expandir.
        $paymentIntentId = str_starts_with($type, 'checkout.session')
            ? ($object['payment_intent'] ?? null)
            : ($object['id'] ?? null);

        try {
            return $this->readCardFromPaymentIntent(is_string($paymentIntentId) ? $paymentIntentId : null);
        } catch (PaymentGatewayException $exception) {
            $this->logRecoveredFailure('No se pudo leer la tarjeta del PaymentIntent de Stripe.', [
                'payment_intent' => $paymentIntentId,
            ], $exception);

            return [];
        }
    }

    protected function readCardFromExpandedSession(array $session): array
    {
        return $this->paymentMethodFromStripeCharge($session['payment_intent']['latest_charge'] ?? null);
    }

    /**
     * Consulta la sesión de Checkout (`cs_…`) con el cargo de su PaymentIntent.
     */
    public function findPayment(?string $transactionId = null, ?string $externalReference = null): ?WebhookResult
    {
        if (blank($transactionId)) {
            throw new PaymentGatewayException(
                "Stripe Checkout consulta el pago por el id de la sesión (cs_…); la referencia externa no basta.",
                422
            );
        }

        $this->authenticate();
        $this->throttle('stripe_checkout:find_payment');

        $session = $this->findWithRetry(fn () => $this->client->checkout->sessions->retrieve(
            $transactionId,
            ['expand' => ['payment_intent.latest_charge']]
        ));

        if ($session === null) {
            return null;
        }

        $session = $session->toArray();
        $card = $this->readCardFromExpandedSession($session);

        return new WebhookResult(
            gateway: 'stripe',
            eventType: 'checkout.session',
            transactionId: $session['id'],
            status: $this->mapCheckoutSessionStatus($session),
            amount: $this->fromStripeAmount($session['amount_total'] ?? null, $session['currency'] ?? null),
            currency: $session['currency'] ?? null,
            paymentMethod: $card['payment_method'] ?? null,
            cardBrand: $card['brand'] ?? null,
            cardLastFour: $card['last_four'] ?? null,
            raw: $session
        );
    }

    /**
     * Una sesión completa sin pagar espera un cobro asíncrono mientras su
     * PaymentIntent está en `processing`; en otro estado ese cobro falló.
     */
    protected function mapCheckoutSessionStatus(array $session): string
    {
        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : [];

        return match (true) {
            ($intent['latest_charge']['refunded'] ?? false) === true => 'refunded',
            in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true) => 'completed',
            ($session['status'] ?? null) === 'expired' => 'failed',
            ($session['status'] ?? null) === 'complete' && ($intent['status'] ?? null) !== 'processing' => 'failed',
            default => 'pending',
        };
    }

    protected function readCardFromPaymentIntent(?string $piId): array
    {
        if (! $piId) {
            return [];
        }

        $this->throttle('stripe_checkout:webhook');
        $intent = $this->retry(fn () => $this->client->paymentIntents->retrieve(
            $piId,
            ['expand' => ['latest_charge']]
        ));

        return $this->paymentMethodFromStripeCharge($intent->toArray()['latest_charge'] ?? null);
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
     * Con `successUrl` y `cancelUrl` abre Stripe Checkout en modo suscripción
     * y devuelve la sesión `cs_`; sin ellas crea Customer, Price y
     * Subscription. El planId se usa como nombre del producto.
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        if ($request->successUrl !== null && $request->cancelUrl !== null) {
            return $this->createSubscriptionCheckout($request);
        }

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
                nextBillingDate: $this->nextBillingDateOf($subscription),
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
                $params['cancellation_details'] = ['comment' => $reason];
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
                nextBillingDate: $this->nextBillingDateOf($subscription),
                raw: $subscription->toArray()
            );
        });
    }

    /**
     * Una sesión `cs_` informa la suscripción que creó al completarse; antes
     * queda `incomplete`, y vencida, `incomplete_expired`.
     */
    public function getSubscription(string $subscriptionId): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('stripe_checkout:get_subscription');

        if (str_starts_with($subscriptionId, 'cs_')) {
            return $this->retry(fn () => $this->subscriptionOfCheckout(
                $this->client->checkout->sessions->retrieve($subscriptionId, ['expand' => ['subscription']]),
            ));
        }

        return $this->retry(function () use ($subscriptionId) {
            $subscription = $this->client->subscriptions->retrieve($subscriptionId);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $subscription->id,
                status: $subscription->status,
                nextBillingDate: $this->nextBillingDateOf($subscription),
                raw: $subscription->toArray()
            );
        });
    }

    protected function createSubscriptionCheckout(SubscriptionRequest $request): SubscriptionResponse
    {
        $currency = $request->currency ?? 'usd';
        $this->validateCurrency(strtoupper($currency));
        $unitAmount = $this->toStripeAmount($request->amount ?? '0', $currency);
        $this->authenticate();
        $this->throttle('stripe_checkout:create_subscription');

        return $this->retry(function () use ($request, $currency, $unitAmount) {
            $session = $this->client->checkout->sessions->create(array_filter([
                'mode' => 'subscription',
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => ['name' => $request->planId],
                        'unit_amount' => $unitAmount,
                        'recurring' => [
                            'interval' => $request->interval ?? 'month',
                            'interval_count' => $request->intervalCount ?? 1,
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $request->successUrl,
                'cancel_url' => $request->cancelUrl,
                'customer_email' => $request->email,
                'client_reference_id' => $request->externalReference,
                'metadata' => $request->metadata ?: null,
                'subscription_data' => array_filter([
                    'trial_period_days' => $request->trialDays ?: null,
                    'metadata' => $request->metadata ?: null,
                ]) ?: null,
            ], fn (mixed $value): bool => $value !== null));

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $session->id,
                status: 'incomplete',
                planId: $request->planId,
                raw: $session->toArray(),
                redirectUrl: $session->url,
                externalReference: $request->externalReference,
            );
        });
    }

    protected function subscriptionOfCheckout(Session $session): SubscriptionResponse
    {
        $subscription = $session->subscription instanceof Subscription ? $session->subscription : null;

        return new SubscriptionResponse(
            success: true,
            subscriptionId: $subscription->id ?? $session->id,
            status: $subscription->status ?? ($session->status === 'expired' ? 'incomplete_expired' : 'incomplete'),
            nextBillingDate: $subscription === null ? null : $this->nextBillingDateOf($subscription),
            raw: $session->toArray(),
            redirectUrl: $session->url ?? null,
            externalReference: $session->client_reference_id ?? null,
        );
    }

    /**
     * Desde la API `2025-03-31.basil` el fin del período vive en cada ítem.
     */
    protected function nextBillingDateOf(Subscription $subscription): ?string
    {
        $periodEnd = $subscription->items->data[0]->current_period_end ?? null;

        return $periodEnd === null ? null : Carbon::createFromTimestamp($periodEnd)->toIso8601String();
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
