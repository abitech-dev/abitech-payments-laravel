<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\Stripe;

use Abitech\Payments\Drivers\Stripe\StripePaymentIntentsDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeStripeHttpClient;
use Abitech\Payments\Tests\Doubles\StripeWebhookRequest;
use Abitech\Payments\Tests\TestCase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

class StripePaymentIntentsDriverTest extends TestCase
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

    public function test_purchase_sends_the_idempotency_key_to_stripe(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '10.00',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'pm_1',
            idempotencyKey: 'k-1',
        ));

        $this->assertContains('Idempotency-Key: k-1', $stripe->headersOf(0));
    }

    public function test_purchase_dispatches_no_events(): void
    {
        $this->fakePackageEvents();
        FakeStripeHttpClient::install([[200, ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '10.00',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'pm_1',
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
    }

    public function test_purchase_sends_the_amount_in_cents(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '19.99',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'pm_1',
        ));

        $this->assertSame(1999, $stripe->requests[0]['params']['amount']);
    }

    public function test_an_amount_with_more_decimals_than_the_currency_fails_before_calling_stripe(): void
    {
        $stripe = FakeStripeHttpClient::install([]);

        try {
            $this->driver()->purchase(new PaymentRequest(
                amount: '19.999',
                currency: 'USD',
                email: 'cliente@example.com',
                description: 'Plan mensual',
                cardToken: 'pm_1',
            ));
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(422, $exception->getCode());
        }

        $this->assertSame([], $stripe->requests);
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
        $driver = new class(['secret' => 'sk_test_x']) extends StripePaymentIntentsDriver {
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

    public function test_a_failed_card_lookup_is_logged_without_credentials(): void
    {
        Log::spy();
        FakeStripeHttpClient::install([[400, ['error' => ['message' => 'No such payment_intent', 'type' => 'invalid_request_error']]]]);

        $result = $this->driver()->handleWebhook($this->succeededWebhook());

        $this->assertSame('completed', $result->status);
        $this->assertNull($result->cardLastFour);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['payment_intent'] === 'pi_1'
                && $context['gateway'] === 'stripe_paymentintents'
                && str_contains($context['exception'], 'No such payment_intent')
                && ! str_contains(json_encode($context), 'sk_test_x')
        );
    }

    public function test_a_declined_card_is_surfaced_with_its_http_status(): void
    {
        FakeStripeHttpClient::install([[402, ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.']]]]);

        try {
            $this->driver()->purchase(self::cardPayment());
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(402, $exception->getCode());
            $this->assertSame('Your card was declined.', $exception->getMessage());
        }
    }

    public function test_an_intent_that_requires_action_is_pending_with_its_redirect(): void
    {
        FakeStripeHttpClient::install([[200, [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'next_action' => ['redirect_to_url' => ['url' => 'https://hooks.stripe.com/3ds']],
        ]]]);

        $response = $this->driver()->purchase(self::cardPayment());

        $this->assertTrue($response->success);
        $this->assertSame('pending', $response->status);
        $this->assertSame('https://hooks.stripe.com/3ds', $response->redirectUrl);
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

    public function test_a_refunded_charge_webhook_is_normalized(): void
    {
        FakeStripeHttpClient::install([]);
        $request = StripeWebhookRequest::signed('charge.refunded', [
            'id' => 'ch_1',
            'object' => 'charge',
            'payment_intent' => 'pi_1',
            'amount_refunded' => 500,
            'currency' => 'usd',
        ], self::WEBHOOK_SECRET);

        $result = $this->driver()->handleWebhook($request);

        $this->assertSame('refunded', $result->status);
        $this->assertSame('pi_1', $result->transactionId);
        $this->assertSame('5.00', $result->amount);
    }

    public function test_the_webhook_currency_is_an_uppercase_iso_4217_code(): void
    {
        FakeStripeHttpClient::install([]);
        $request = StripeWebhookRequest::signed('charge.refunded', [
            'id' => 'ch_1',
            'object' => 'charge',
            'payment_intent' => 'pi_1',
            'amount_refunded' => 500,
            'currency' => 'pen',
        ], self::WEBHOOK_SECRET);

        $this->assertSame('PEN', $this->driver()->handleWebhook($request)->currency);
    }

    /**
     * @return array<string, array{0: Closure(StripePaymentIntentsDriver): mixed, 1: list<array<string, mixed>>}>
     */
    public static function operations(): array
    {
        return [
            'purchase' => [static fn (StripePaymentIntentsDriver $driver) => $driver->purchase(self::cardPayment()), [['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded']]],
            'refund' => [static fn (StripePaymentIntentsDriver $driver) => $driver->refund('pi_1'), [['id' => 're_1', 'object' => 'refund']]],
            'payout' => [static fn (StripePaymentIntentsDriver $driver) => $driver->payout(self::payout()), [['id' => 'tr_1', 'object' => 'transfer']]],
            'health' => [static fn (StripePaymentIntentsDriver $driver) => $driver->health(), [['object' => 'balance']]],
            'findPayment' => [static fn (StripePaymentIntentsDriver $driver) => $driver->findPayment('pi_1'), [self::intentWith('succeeded')]],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function intentStatuses(): array
    {
        return [
            'succeeded' => [self::intentWith('succeeded'), 'completed'],
            'requires action' => [self::intentWith('requires_action'), 'pending'],
            'processing' => [self::intentWith('processing'), 'pending'],
            'canceled' => [self::intentWith('canceled'), 'failed'],
            'refunded' => [self::intentWith('succeeded', refunded: true), 'refunded'],
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    #[DataProvider('intentStatuses')]
    public function test_find_payment_reads_the_payment_intent(array $intent, string $status): void
    {
        $stripe = FakeStripeHttpClient::install([[200, $intent]]);

        $result = $this->driver()->findPayment('pi_1');

        $this->assertSame('get', $stripe->requests[0]['method']);
        $this->assertStringEndsWith('/v1/payment_intents/pi_1', $stripe->requests[0]['url']);
        $this->assertSame(['latest_charge'], $stripe->requests[0]['params']['expand']);
        $this->assertSame($status, $result->status);
        $this->assertSame('pi_1', $result->transactionId);
        $this->assertSame('payment_intent', $result->eventType);
        $this->assertSame('stripe', $result->gateway);
        $this->assertSame('10.00', $result->amount);
        $this->assertSame('PEN', $result->currency);
    }

    public function test_find_payment_reads_the_card_of_the_expanded_charge(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, self::intentWith('succeeded')]]);

        $result = $this->driver()->findPayment('pi_1');

        $this->assertSame('visa', $result->cardBrand);
        $this->assertSame('4242', $result->cardLastFour);
        $this->assertCount(1, $stripe->requests);
    }

    public function test_find_payment_returns_null_for_an_unknown_payment_intent(): void
    {
        FakeStripeHttpClient::install([[404, ['error' => [
            'code' => 'resource_missing',
            'message' => "No such payment_intent: 'pi_x'",
            'type' => 'invalid_request_error',
        ]]]]);

        $this->assertNull($this->driver()->findPayment('pi_x'));
    }

    public function test_find_payment_without_payment_intent_id_fails_before_authenticating(): void
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
    }

    /**
     * @return array<string, array{0: Closure(StripePaymentIntentsDriver): mixed}>
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

    private static function cardPayment(): PaymentRequest
    {
        return new PaymentRequest(
            amount: '10.00',
            currency: 'USD',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'pm_1',
        );
    }

    private static function payout(): PayoutRequest
    {
        return new PayoutRequest(amount: '20.50', currency: 'USD', recipient: 'acct_1', description: 'Liquidacion');
    }

    /**
     * @return array<string, mixed>
     */
    private static function intentWith(string $status, bool $refunded = false): array
    {
        return [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => 1000,
            'currency' => 'pen',
            'latest_charge' => [
                'id' => 'ch_1',
                'object' => 'charge',
                'refunded' => $refunded,
                'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
            ],
        ];
    }

    private function driver(): StripePaymentIntentsDriver
    {
        return $this->app->make(PaymentManager::class)->driver('stripe_paymentintents');
    }

    private function succeededWebhook(): Request
    {
        return StripeWebhookRequest::signed('payment_intent.succeeded', [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount' => 1000,
            'currency' => 'usd',
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
                'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
            ],
        ];
    }
}
