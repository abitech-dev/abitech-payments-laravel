<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\Stripe;

use Abitech\Payments\Drivers\Stripe\StripeCheckoutDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeStripeHttpClient;
use Abitech\Payments\Tests\Doubles\StripeWebhookRequest;
use Abitech\Payments\Tests\TestCase;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

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
