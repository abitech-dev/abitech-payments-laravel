<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\MercadoPago;

use Abitech\Payments\Drivers\MercadoPago\MercadoPagoCheckoutDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeMercadoPagoHttpClient;
use Abitech\Payments\Tests\TestCase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class MercadoPagoCheckoutDriverTest extends TestCase
{
    private const WEBHOOK_SECRET = 'secreto-mp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'abitech_payments.gateways.mercadopago' => ['access_token' => 'TEST-token', 'webhook_secret' => self::WEBHOOK_SECRET],
            'abitech_payments.retry.base_delay_ms' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        FakeMercadoPagoHttpClient::uninstall();

        parent::tearDown();
    }

    public function test_purchase_dispatches_no_events(): void
    {
        $this->fakePackageEvents();
        FakeMercadoPagoHttpClient::install([[201, ['id' => 'pref_1', 'init_point' => 'https://www.mercadopago.com.pe/checkout/pref_1']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '50.00',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
        ));

        Event::assertNothingDispatched();
    }

    public function test_health_retries_a_503(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([
            [503, ['message' => 'Servicio no disponible']],
            [404, ['message' => 'Payment not found']],
        ]);

        $this->assertTrue($this->driver()->health());
        $this->assertCount(2, $mercadoPago->requests);
    }

    public function test_a_missing_sdk_asks_for_the_exact_composer_require(): void
    {
        $transport = FakeMercadoPagoHttpClient::install([]);
        $driver = new class(['access_token' => 'TEST-token']) extends MercadoPagoCheckoutDriver {
            protected const SDK_CLASS = 'MercadoPago\\ClaseAusente';
        };

        try {
            $driver->health();
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame([], $transport->requests);
            $this->assertStringContainsString('composer require mercadopago/dx-php', $exception->getMessage());
        }
    }

    public function test_purchase_creates_a_preference_and_returns_its_checkout_url(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preference()]]);

        $response = $this->driver()->purchase(self::checkout());

        $this->assertTrue($response->success);
        $this->assertSame('pending', $response->status);
        $this->assertSame('pref_1', $response->transactionId);
        $this->assertSame('https://www.mercadopago.com.pe/checkout/pref_1', $response->redirectUrl);
        $this->assertStringContainsString('"external_reference":"k-1"', $mercadoPago->requests[0]->getPayload());
        $this->assertStringContainsString('"unit_price":50.5', $mercadoPago->requests[0]->getPayload());
    }

    public function test_a_full_refund_sends_an_empty_body(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, ['id' => 1, 'status' => 'approved']]]);

        $this->assertTrue($this->driver()->refund('123'));
        $this->assertSame('[]', $mercadoPago->requests[0]->getPayload());
    }

    public function test_a_pending_payout_is_not_successful(): void
    {
        FakeMercadoPagoHttpClient::install([[201, ['id' => 125, 'status' => 'in_process']]]);

        $response = $this->driver()->payout(self::payout());

        $this->assertFalse($response->success);
        $this->assertSame('pending', $response->status);
    }

    public function test_create_subscription_sends_the_preapproval(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preapproval()]]);

        $response = $this->driver()->createSubscription(self::subscription());

        $this->assertSame('pre_1', $response->subscriptionId);
        $this->assertSame('pending', $response->status);
        $this->assertSame('2026-10-15T00:00:00.000-05:00', $response->nextBillingDate);
        $payload = json_decode($mercadoPago->requests[0]->getPayload(), true);
        $this->assertSame('months', $payload['auto_recurring']['frequency_type']);
        $this->assertSame(49.9, $payload['auto_recurring']['transaction_amount']);
    }

    public function test_cancel_subscription_marks_the_preapproval_cancelled(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[200, ['id' => 'pre_1', 'status' => 'cancelled', 'reason' => 'plan-mensual']]]);

        $response = $this->driver()->cancelSubscription('pre_1');

        $this->assertSame('cancelled', $response->status);
        $this->assertSame('plan-mensual', $response->planId);
        $this->assertNotNull($response->canceledAt);
        $this->assertSame('{"status":"cancelled"}', $mercadoPago->requests[0]->getPayload());
    }

    public function test_update_subscription_without_amount_only_reads_the_preapproval(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[200, self::preapproval()]]);

        $this->driver()->updateSubscription('pre_1', new SubscriptionRequest(planId: 'plan-mensual', email: 'cliente@example.com'));

        $this->assertSame('GET', $mercadoPago->requests[0]->getMethod());
    }

    public function test_get_subscription_reads_the_plan_from_the_reason(): void
    {
        FakeMercadoPagoHttpClient::install([[200, self::preapproval()]]);

        $response = $this->driver()->getSubscription('pre_1');

        $this->assertSame('plan-mensual', $response->planId);
    }

    /**
     * @return array<string, array{0: Closure(MercadoPagoCheckoutDriver): mixed, 1: array<string, mixed>}>
     */
    public static function operations(): array
    {
        return [
            'purchase' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->purchase(self::checkout()), self::preference()],
            'refund' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->refund('123'), ['id' => 1, 'status' => 'approved']],
            'payout' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->payout(self::payout()), ['id' => 125, 'status' => 'approved']],
            'createSubscription' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->createSubscription(self::subscription()), self::preapproval()],
            'cancelSubscription' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->cancelSubscription('pre_1'), self::preapproval()],
            'updateSubscription' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->updateSubscription('pre_1', self::subscription()), self::preapproval()],
            'getSubscription' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->getSubscription('pre_1'), self::preapproval()],
        ];
    }

    #[DataProvider('operations')]
    public function test_a_client_error_is_surfaced_without_retrying(Closure $operation): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[400, ['message' => 'Solicitud invalida']]]);

        try {
            $operation($this->driver());
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(400, $exception->getCode());
        }

        $this->assertCount(1, $mercadoPago->requests);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('operations')]
    public function test_a_503_is_retried(Closure $operation, array $body): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[503, ['message' => 'Servicio no disponible']], [200, $body]]);

        $operation($this->driver());

        $this->assertCount(2, $mercadoPago->requests);
    }

    public function test_a_signed_payment_webhook_is_normalized(): void
    {
        FakeMercadoPagoHttpClient::install([[200, ['id' => 123, 'status' => 'refunded', 'transaction_amount' => 50.5, 'currency_id' => 'PEN']]]);
        $hash = hash_hmac('sha256', 'id:123;request-id:req-1;ts:1700000000;', self::WEBHOOK_SECRET);
        $request = Request::create('/webhook', 'POST', ['type' => 'payment', 'data' => ['id' => '123']]);
        $request->headers->add(['x-signature' => "ts=1700000000,v1={$hash}", 'x-request-id' => 'req-1']);

        $result = $this->driver()->handleWebhook($request);

        $this->assertSame('refunded', $result->status);
        $this->assertSame('50.50', $result->amount);
        $this->assertSame('mercadopago_checkout', $result->gateway);
    }

    private static function checkout(): PaymentRequest
    {
        return new PaymentRequest(
            amount: '50.50',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            idempotencyKey: 'k-1',
            successUrl: 'https://app.example.com/ok',
        );
    }

    private static function payout(): PayoutRequest
    {
        return new PayoutRequest(amount: '20.50', currency: 'PEN', recipient: 'vendedor@example.com', description: 'Liquidacion');
    }

    private static function subscription(): SubscriptionRequest
    {
        return new SubscriptionRequest(planId: 'plan-mensual', email: 'cliente@example.com', amount: '49.90', currency: 'PEN');
    }

    /**
     * @return array<string, mixed>
     */
    private static function preference(): array
    {
        return ['id' => 'pref_1', 'init_point' => 'https://www.mercadopago.com.pe/checkout/pref_1'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function preapproval(): array
    {
        return [
            'id' => 'pre_1',
            'status' => 'pending',
            'reason' => 'plan-mensual',
            'auto_recurring' => ['start_date' => '2026-10-15T00:00:00.000-05:00'],
        ];
    }

    private function driver(): MercadoPagoCheckoutDriver
    {
        return $this->app->make(PaymentManager::class)->driver('mercadopago_checkout');
    }
}
