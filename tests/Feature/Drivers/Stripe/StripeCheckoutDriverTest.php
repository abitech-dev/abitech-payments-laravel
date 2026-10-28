<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\Stripe;

use Abitech\Payments\Drivers\Stripe\StripeCheckoutDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeStripeHttpClient;
use Abitech\Payments\Tests\Doubles\StripeWebhookRequest;
use Abitech\Payments\Tests\TestCase;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

class StripeCheckoutDriverTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'abitech_payments.gateways.stripe' => ['secret' => 'sk_test_x', 'webhook_secret' => self::WEBHOOK_SECRET],
            'abitech_payments.retry.base_delay_ms' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    public function test_purchase_dispatches_no_events(): void
    {
        $this->fakePackageEvents();
        FakeStripeHttpClient::install([[200, ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/cs_1']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '10.00',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            successUrl: 'https://app.example.com/ok',
            cancelUrl: 'https://app.example.com/cancelado',
        ));

        Event::assertNothingDispatched();
    }

    public function test_a_signed_payment_intent_succeeded_webhook_reads_the_card_without_prior_authentication(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, $this->paymentIntentWithCard()]]);

        $result = $this->driver()->handleWebhook($this->succeededWebhook());

        $this->assertSame('completed', $result->status);
        $this->assertSame('visa', $result->cardBrand);
        $this->assertSame('4242', $result->cardLastFour);
        $this->assertSame('10.00', $result->amount);
        $this->assertCount(1, $stripe->requests);
        $this->assertSame(['latest_charge'], $stripe->requests[0]['params']['expand']);
    }

    public function test_purchase_lets_stripe_choose_the_payment_methods(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, self::checkoutSession()]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '10.00',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            successUrl: 'https://app.example.com/ok',
            cancelUrl: 'https://app.example.com/cancelado',
            metadata: ['payment_method_types' => ['card']],
        ));

        $this->assertArrayNotHasKey('payment_method_types', $stripe->requests[0]['params']);
        $this->assertArrayNotHasKey('allowed_payment_method_types', $stripe->requests[0]['params']);
    }

    public function test_the_admin_panel_gets_no_stripe_checkout_settings(): void
    {
        $this->assertSame([], PaymentManager::getPaymentMethodSchemas()['stripe_checkout']['fields']);
    }

    public function test_purchase_sends_the_amount_in_cents(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/cs_1']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '19.99',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            successUrl: 'https://app.example.com/ok',
            cancelUrl: 'https://app.example.com/cancelado',
        ));

        $this->assertSame(1999, $stripe->requests[0]['params']['line_items'][0]['price_data']['unit_amount']);
    }

    public function test_purchase_charges_the_amount_once_whatever_the_metadata_quantity(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, self::checkoutSession()]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '10.00',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            successUrl: 'https://app.example.com/ok',
            cancelUrl: 'https://app.example.com/cancelado',
            metadata: ['quantity' => 3],
        ));

        $lineItems = $stripe->requests[0]['params']['line_items'];
        $this->assertCount(1, $lineItems);
        $this->assertSame(1, $lineItems[0]['quantity']);
        $this->assertSame(1000, $lineItems[0]['price_data']['unit_amount']);
    }

    public function test_a_partial_refund_converts_the_amount_with_the_payment_intent_currency(): void
    {
        $stripe = FakeStripeHttpClient::install([
            [200, ['id' => 'pi_1', 'object' => 'payment_intent', 'currency' => 'jpy']],
            [200, ['id' => 're_1', 'object' => 'refund']],
        ]);

        $this->assertTrue($this->driver()->refund('pi_1', '1500'));
        $this->assertSame(1500, $stripe->requests[1]['params']['amount']);
    }

    public function test_the_webhook_card_lookup_retries_a_503(): void
    {
        $stripe = FakeStripeHttpClient::install([
            [503, ['error' => ['message' => 'Servicio no disponible', 'type' => 'api_error']]],
            [200, $this->paymentIntentWithCard()],
        ]);

        $result = $this->driver()->handleWebhook($this->succeededWebhook());

        $this->assertCount(2, $stripe->requests);
        $this->assertSame('visa', $result->cardBrand);
    }

    public function test_a_missing_sdk_asks_for_the_exact_composer_require(): void
    {
        $transport = FakeStripeHttpClient::install([]);
        $driver = new class(['secret' => 'sk_test_x']) extends StripeCheckoutDriver {
            protected const SDK_CLASS = 'Stripe\\ClaseAusente';
        };

        try {
            $driver->health();
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame([], $transport->requests);
            $this->assertStringContainsString('composer require stripe/stripe-php', $exception->getMessage());
        }
    }

    public function test_purchase_without_urls_fails_before_authenticating_or_throttling(): void
    {
        config(['abitech_payments.gateways.stripe' => []]);

        try {
            $this->driver()->purchase(new PaymentRequest(
                amount: '10.00',
                currency: 'USD',
                email: 'cliente@example.com',
                description: 'Plan mensual',
            ));
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertStringContainsString('success_url', $exception->getMessage());
        }

        $this->assertSame(0, $this->app->make(RateLimiter::class)->attempts('abitech_payments:stripe_checkout:purchase'));
    }

    public function test_a_failed_card_lookup_is_logged_without_credentials(): void
    {
        Log::spy();
        FakeStripeHttpClient::install([[400, ['error' => ['message' => 'No such payment_intent', 'type' => 'invalid_request_error']]]]);

        $result = $this->driver()->handleWebhook($this->succeededWebhook());

        $this->assertSame('completed', $result->status);
        $this->assertNull($result->cardLastFour);
        $this->assertNull($result->paymentMethod);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['payment_intent'] === 'pi_1'
                && $context['gateway'] === 'stripe_checkout'
                && str_contains($context['exception'], 'No such payment_intent')
                && ! str_contains(json_encode($context), 'sk_test_x')
        );
    }

    public function test_purchase_returns_the_checkout_session_url(): void
    {
        FakeStripeHttpClient::install([[200, self::checkoutSession()]]);

        $response = $this->driver()->purchase(self::checkout());

        $this->assertSame('cs_1', $response->transactionId);
        $this->assertSame('pending', $response->status);
        $this->assertSame('https://checkout.stripe.com/c/cs_1', $response->redirectUrl);
    }

    public function test_a_full_refund_only_names_the_payment_intent(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, ['id' => 're_1', 'object' => 'refund']]]);

        $this->assertTrue($this->driver()->refund('pi_1'));
        $this->assertSame(['payment_intent' => 'pi_1'], $stripe->requests[0]['params']);
    }

    public function test_a_payout_transfers_the_amount_in_cents(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, ['id' => 'tr_1', 'object' => 'transfer']]]);

        $response = $this->driver()->payout(self::payout());

        $this->assertSame('tr_1', $response->payoutId);
        $this->assertSame(2050, $stripe->requests[0]['params']['amount']);
    }

    public function test_create_subscription_creates_customer_price_and_subscription(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, self::customer()], [200, self::price()], [200, self::subscriptionObject()]]);

        $response = $this->driver()->createSubscription(self::subscription());

        $this->assertSame('sub_1', $response->subscriptionId);
        $this->assertSame('active', $response->status);
        $this->assertSame(1767225600, Carbon::parse($response->nextBillingDate)->getTimestamp());
        $this->assertSame(4990, $stripe->requests[1]['params']['unit_amount']);
    }

    public function test_cancel_subscription_returns_the_cancellation_time(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'canceled']]]);

        $response = $this->driver()->cancelSubscription('sub_1');

        $this->assertSame('canceled', $response->status);
        $this->assertNotNull($response->canceledAt);
        $this->assertSame('delete', $stripe->requests[0]['method']);
        $this->assertSame([], $stripe->requests[0]['params']);
    }

    public function test_cancel_subscription_sends_the_reason_as_a_cancellation_comment(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'canceled']]]);

        $this->driver()->cancelSubscription('sub_1', 'Cambio de plan');

        $this->assertSame(['cancellation_details' => ['comment' => 'Cambio de plan']], $stripe->requests[0]['params']);
    }

    public function test_get_subscription_reads_the_next_billing_date(): void
    {
        FakeStripeHttpClient::install([[200, self::subscriptionObject()]]);

        $response = $this->driver()->getSubscription('sub_1');

        $this->assertSame(1767225600, Carbon::parse($response->nextBillingDate)->getTimestamp());
    }

    public function test_a_completed_checkout_session_webhook_reads_the_card_of_its_payment_intent(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, $this->paymentIntentWithCard()]]);
        $request = StripeWebhookRequest::signed('checkout.session.completed', [
            'id' => 'cs_1',
            'object' => 'checkout.session',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1999,
            'currency' => 'usd',
            'payment_intent' => 'pi_1',
            'payment_method_types' => ['card', 'link'],
        ], self::WEBHOOK_SECRET);

        $result = $this->driver()->handleWebhook($request);

        $this->assertSame('completed', $result->status);
        $this->assertSame('19.99', $result->amount);
        $this->assertSame('visa', $result->paymentMethod);
        $this->assertSame('4242', $result->cardLastFour);
        $this->assertStringEndsWith('/v1/payment_intents/pi_1', $stripe->requests[0]['url']);
        $this->assertSame(['latest_charge'], $stripe->requests[0]['params']['expand']);
    }

    public function test_a_payment_without_card_reports_the_payment_method_type_of_its_charge(): void
    {
        $session = self::sessionWith('complete', 'paid', 'succeeded');
        $session['payment_intent']['latest_charge']['payment_method_details'] = ['type' => 'link', 'link' => ['country' => 'PE']];
        FakeStripeHttpClient::install([[200, $session]]);

        $result = $this->driver()->findPayment('cs_1');

        $this->assertSame('link', $result->paymentMethod);
        $this->assertNull($result->cardBrand);
        $this->assertNull($result->cardLastFour);
    }

    public function test_an_unrelated_event_is_ignored(): void
    {
        FakeStripeHttpClient::install([]);

        $result = $this->driver()->handleWebhook(StripeWebhookRequest::signed('customer.created', ['id' => 'cus_1'], self::WEBHOOK_SECRET));

        $this->assertSame('ignored', $result->status);
    }

    public function test_the_webhook_currency_is_an_uppercase_iso_4217_code(): void
    {
        FakeStripeHttpClient::install([]);
        $request = StripeWebhookRequest::signed('checkout.session.completed', [
            'id' => 'cs_1',
            'object' => 'checkout.session',
            'amount_total' => 1999,
            'currency' => 'pen',
        ], self::WEBHOOK_SECRET);

        $this->assertSame('PEN', $this->driver()->handleWebhook($request)->currency);
    }

    /**
     * @return array<string, array{0: Closure(StripeCheckoutDriver): mixed, 1: list<array<string, mixed>>}>
     */
    public static function operations(): array
    {
        return [
            'purchase' => [static fn (StripeCheckoutDriver $driver) => $driver->purchase(self::checkout()), [self::checkoutSession()]],
            'refund' => [static fn (StripeCheckoutDriver $driver) => $driver->refund('pi_1'), [['id' => 're_1', 'object' => 'refund']]],
            'payout' => [static fn (StripeCheckoutDriver $driver) => $driver->payout(self::payout()), [['id' => 'tr_1', 'object' => 'transfer']]],
            'health' => [static fn (StripeCheckoutDriver $driver) => $driver->health(), [['object' => 'balance']]],
            'createSubscription' => [static fn (StripeCheckoutDriver $driver) => $driver->createSubscription(self::subscription()), [self::customer(), self::price(), self::subscriptionObject()]],
            'cancelSubscription' => [static fn (StripeCheckoutDriver $driver) => $driver->cancelSubscription('sub_1'), [self::subscriptionObject()]],
            'updateSubscription' => [static fn (StripeCheckoutDriver $driver) => $driver->updateSubscription('sub_1', self::subscription()), [self::subscriptionObject()]],
            'getSubscription' => [static fn (StripeCheckoutDriver $driver) => $driver->getSubscription('sub_1'), [self::subscriptionObject()]],
            'findPayment' => [static fn (StripeCheckoutDriver $driver) => $driver->findPayment('cs_1'), [self::sessionWith('complete', 'paid', 'succeeded')]],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function sessionStatuses(): array
    {
        return [
            'paid' => [self::sessionWith('complete', 'paid', 'succeeded'), 'completed'],
            'free' => [self::sessionWith('complete', 'no_payment_required', null), 'completed'],
            'open' => [self::sessionWith('open', 'unpaid', 'requires_payment_method'), 'pending'],
            'async payment processing' => [self::sessionWith('complete', 'unpaid', 'processing'), 'pending'],
            'async payment failed' => [self::sessionWith('complete', 'unpaid', 'requires_payment_method'), 'failed'],
            'expired' => [self::sessionWith('expired', 'unpaid', 'canceled'), 'failed'],
            'refunded' => [self::sessionWith('complete', 'paid', 'succeeded', refunded: true), 'refunded'],
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    #[DataProvider('sessionStatuses')]
    public function test_find_payment_reads_the_checkout_session(array $session, string $status): void
    {
        $stripe = FakeStripeHttpClient::install([[200, $session]]);

        $result = $this->driver()->findPayment('cs_1');

        $this->assertSame('get', $stripe->requests[0]['method']);
        $this->assertStringEndsWith('/v1/checkout/sessions/cs_1', $stripe->requests[0]['url']);
        $this->assertSame(['payment_intent.latest_charge'], $stripe->requests[0]['params']['expand']);
        $this->assertSame($status, $result->status);
        $this->assertSame('cs_1', $result->transactionId);
        $this->assertSame('checkout.session', $result->eventType);
        $this->assertSame('stripe', $result->gateway);
        $this->assertSame('19.99', $result->amount);
        $this->assertSame('PEN', $result->currency);
        $this->assertSame('ref-1', $result->raw['client_reference_id']);
    }

    public function test_find_payment_reads_the_card_of_the_expanded_charge(): void
    {
        FakeStripeHttpClient::install([[200, self::sessionWith('complete', 'paid', 'succeeded')]]);

        $result = $this->driver()->findPayment('cs_1');

        $this->assertSame('visa', $result->cardBrand);
        $this->assertSame('4242', $result->cardLastFour);
    }

    public function test_find_payment_returns_null_for_an_unknown_session(): void
    {
        FakeStripeHttpClient::install([[404, ['error' => [
            'code' => 'resource_missing',
            'message' => "No such checkout.session: 'cs_x'",
            'type' => 'invalid_request_error',
        ]]]]);

        $this->assertNull($this->driver()->findPayment('cs_x'));
    }

    public function test_find_payment_without_session_id_fails_before_authenticating_or_throttling(): void
    {
        config(['abitech_payments.gateways.stripe' => []]);
        $stripe = FakeStripeHttpClient::install([]);

        try {
            $this->driver()->findPayment(externalReference: 'ref-1');
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(422, $exception->getCode());
        }

        $this->assertSame([], $stripe->requests);
        $this->assertSame(0, $this->app->make(RateLimiter::class)->attempts('abitech_payments:stripe_checkout:find_payment'));
    }

    /**
     * @return array<string, array{0: Closure(StripeCheckoutDriver): mixed}>
     */
    public static function operationClosures(): array
    {
        return array_map(static fn (array $case) => [$case[0]], self::operations());
    }

    #[DataProvider('operationClosures')]
    public function test_a_client_error_is_surfaced_without_retrying(Closure $operation): void
    {
        $stripe = FakeStripeHttpClient::install([[400, ['error' => ['message' => 'Solicitud invalida', 'type' => 'invalid_request_error']]]]);

        try {
            $operation($this->driver());
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(400, $exception->getCode());
        }

        $this->assertCount(1, $stripe->requests);
    }

    /**
     * @param  list<array<string, mixed>>  $bodies
     */
    #[DataProvider('operations')]
    public function test_a_503_is_retried(Closure $operation, array $bodies): void
    {
        $stripe = FakeStripeHttpClient::install([
            [503, ['error' => ['message' => 'Servicio no disponible', 'type' => 'api_error']]],
            ...collect($bodies)->map(fn (array $body) => [200, $body])->all(),
        ]);

        $operation($this->driver());

        $this->assertCount(1 + count($bodies), $stripe->requests);
    }

    public function test_a_forged_webhook_signature_is_rejected_as_forbidden(): void
    {
        $request = StripeWebhookRequest::signed('payment_intent.succeeded', ['id' => 'pi_1'], 'whsec_ajeno');

        $this->assertWebhookRejected($request, 403);
    }

    public function test_a_webhook_without_signature_header_is_rejected_as_forbidden(): void
    {
        $this->assertWebhookRejected(Request::create('/webhook', 'POST', content: '{}'), 403);
    }

    public function test_a_webhook_without_configured_secret_is_rejected_before_reading_it(): void
    {
        config(['abitech_payments.gateways.stripe' => ['secret' => 'sk_test_x']]);

        $this->assertWebhookRejected($this->succeededWebhook(), 0);
    }

    private function assertWebhookRejected(Request $request, int $status): void
    {
        $stripe = FakeStripeHttpClient::install([]);

        try {
            $this->driver()->handleWebhook($request);
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame($status, $exception->getCode());
        }

        $this->assertSame([], $stripe->requests);
    }

    private static function checkout(): PaymentRequest
    {
        return new PaymentRequest(
            amount: '10.00',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            successUrl: 'https://app.example.com/ok',
            cancelUrl: 'https://app.example.com/cancelado',
        );
    }

    private static function payout(): PayoutRequest
    {
        return new PayoutRequest(amount: '20.50', currency: 'USD', recipient: 'acct_1', description: 'Liquidacion');
    }

    private static function subscription(): SubscriptionRequest
    {
        return new SubscriptionRequest(planId: 'plan-mensual', email: 'cliente@example.com', amount: '49.90', currency: 'USD');
    }

    /**
     * @return array<string, mixed>
     */
    private static function checkoutSession(): array
    {
        return ['id' => 'cs_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/cs_1'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sessionWith(string $status, string $paymentStatus, ?string $intentStatus, bool $refunded = false): array
    {
        return [
            'id' => 'cs_1',
            'object' => 'checkout.session',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'client_reference_id' => 'ref-1',
            'amount_total' => 1999,
            'currency' => 'pen',
            'payment_method_types' => ['card', 'link'],
            'payment_intent' => $intentStatus === null ? null : [
                'id' => 'pi_1',
                'object' => 'payment_intent',
                'status' => $intentStatus,
                'latest_charge' => [
                    'id' => 'ch_1',
                    'object' => 'charge',
                    'refunded' => $refunded,
                    'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customer(): array
    {
        return ['id' => 'cus_1', 'object' => 'customer'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function price(): array
    {
        return ['id' => 'price_1', 'object' => 'price'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function subscriptionObject(): array
    {
        return [
            'id' => 'sub_1',
            'object' => 'subscription',
            'status' => 'active',
            'items' => [
                'object' => 'list',
                'data' => [['id' => 'si_1', 'object' => 'subscription_item', 'current_period_end' => 1767225600]],
            ],
        ];
    }

    private function driver(): StripeCheckoutDriver
    {
        return $this->app->make(PaymentManager::class)->driver('stripe_checkout');
    }

    private function succeededWebhook(): Request
    {
        return StripeWebhookRequest::signed('payment_intent.succeeded', [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 1000,
            'currency' => 'usd',
            'latest_charge' => 'ch_1',
            'payment_method_types' => ['card', 'link'],
        ], self::WEBHOOK_SECRET);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentIntentWithCard(): array
    {
        return [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'latest_charge' => [
                'id' => 'ch_1',
                'object' => 'charge',
                'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']],
            ],
        ];
    }
}
