<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\Stripe;

use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\Drivers\Stripe\StripePaymentIntentsDriver;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeStripeHttpClient;
use Abitech\Payments\Tests\Doubles\StripeWebhookRequest;
use Abitech\Payments\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

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
