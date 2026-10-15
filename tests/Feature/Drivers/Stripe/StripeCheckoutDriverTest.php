<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\Stripe;

use Abitech\Payments\Drivers\Stripe\StripeCheckoutDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeStripeHttpClient;
use Abitech\Payments\Tests\Doubles\StripeWebhookRequest;
use Abitech\Payments\Tests\TestCase;
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
            amount: 10.0,
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
        $this->assertCount(1, $stripe->requests);
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
