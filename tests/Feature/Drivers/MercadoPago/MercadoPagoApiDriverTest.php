<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\MercadoPago;

use Abitech\Payments\Drivers\MercadoPago\MercadoPagoApiDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeMercadoPagoHttpClient;
use Abitech\Payments\Tests\TestCase;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class MercadoPagoApiDriverTest extends TestCase
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

    public function test_purchase_sends_the_idempotency_key_header(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, ['id' => 123, 'status' => 'approved']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '50.50',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'tok_1',
            idempotencyKey: 'k-1',
        ));

        $this->assertContains('X-Idempotency-Key: k-1', $mercadoPago->requests[0]->getHeaders());
    }

    public function test_purchase_sends_the_amount_as_a_json_number(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, ['id' => 123, 'status' => 'approved']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '19.99',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'tok_1',
        ));

        $this->assertStringContainsString('"transaction_amount":19.99,', $mercadoPago->requests[0]->getPayload());
    }

    public function test_a_partial_refund_sends_the_amount_as_a_json_number(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, ['id' => 1, 'status' => 'approved']]]);

        $this->assertTrue($this->driver()->refund('123', '10.50'));
        $this->assertSame('{"amount":10.5}', $mercadoPago->requests[0]->getPayload());
    }

    public function test_purchase_dispatches_no_events(): void
    {
        $this->fakePackageEvents();
        FakeMercadoPagoHttpClient::install([[201, ['id' => 123, 'status' => 'approved']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '50.50',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'tok_1',
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

    public function test_the_retryable_codes_come_from_the_config(): void
    {
        config(['abitech_payments.retry.retryable_http_codes' => [418]]);
        $mercadoPago = FakeMercadoPagoHttpClient::install([
            [418, ['message' => 'Soy una tetera']],
            [404, ['message' => 'Payment not found']],
        ]);

        $this->assertTrue($this->driver()->health());
        $this->assertCount(2, $mercadoPago->requests);
    }

    public function test_the_webhook_payment_lookup_retries_a_503(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([
            [503, ['message' => 'Servicio no disponible']],
            [200, ['id' => 123, 'status' => 'approved', 'transaction_amount' => 50.0, 'currency_id' => 'PEN']],
        ]);

        $result = $this->driver()->handleWebhook($this->signedPaymentWebhook());

        $this->assertCount(2, $mercadoPago->requests);
        $this->assertSame('completed', $result->status);
        $this->assertSame('123', $result->transactionId);
        $this->assertSame('50.00', $result->amount);
    }

    public function test_a_missing_sdk_asks_for_the_exact_composer_require(): void
    {
        $transport = FakeMercadoPagoHttpClient::install([]);
        $driver = new class(['access_token' => 'TEST-token']) extends MercadoPagoApiDriver {
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

    public function test_an_approved_purchase_is_completed(): void
    {
        FakeMercadoPagoHttpClient::install([[201, ['id' => 123, 'status' => 'approved']]]);

        $response = $this->driver()->purchase(self::cardPayment());

        $this->assertTrue($response->success);
        $this->assertSame('completed', $response->status);
        $this->assertSame('123', $response->transactionId);
    }

    public function test_a_rejected_card_returns_a_failed_response(): void
    {
        FakeMercadoPagoHttpClient::install([[201, ['id' => 124, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount']]]);

        $response = $this->driver()->purchase(self::cardPayment());

        $this->assertFalse($response->success);
        $this->assertSame('failed', $response->status);
        $this->assertSame('cc_rejected_insufficient_amount', $response->errorMessage);
    }

    public function test_a_full_refund_sends_an_empty_body(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, ['id' => 1, 'status' => 'approved']]]);

        $this->assertTrue($this->driver()->refund('123'));
        $this->assertSame('/v1/payments/123/refunds', $mercadoPago->requests[0]->getUri());
        $this->assertSame('[]', $mercadoPago->requests[0]->getPayload());
    }

    public function test_an_approved_payout_is_completed(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, ['id' => 125, 'status' => 'approved']]]);

        $response = $this->driver()->payout(self::payout());

        $this->assertTrue($response->success);
        $this->assertSame('completed', $response->status);
        $this->assertStringContainsString('"transaction_amount":20.5', $mercadoPago->requests[0]->getPayload());
    }

    /**
     * @return array<string, array{0: Closure(MercadoPagoApiDriver): mixed, 1: array<string, mixed>}>
     */
    public static function operations(): array
    {
        return [
            'purchase' => [static fn (MercadoPagoApiDriver $driver) => $driver->purchase(self::cardPayment()), ['id' => 123, 'status' => 'approved']],
            'refund' => [static fn (MercadoPagoApiDriver $driver) => $driver->refund('123'), ['id' => 1, 'status' => 'approved']],
            'payout' => [static fn (MercadoPagoApiDriver $driver) => $driver->payout(self::payout()), ['id' => 125, 'status' => 'approved']],
        ];
    }

    /**
     * @return array<string, array{0: Closure(MercadoPagoApiDriver): mixed}>
     */
    public static function operationClosures(): array
    {
        return array_map(static fn (array $case) => [$case[0]], self::operations());
    }

    #[DataProvider('operationClosures')]
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
        $mercadoPago = FakeMercadoPagoHttpClient::install([[503, ['message' => 'Servicio no disponible']], [201, $body]]);

        $operation($this->driver());

        $this->assertCount(2, $mercadoPago->requests);
    }

    public function test_a_forged_webhook_signature_is_rejected_as_forbidden(): void
    {
        $request = Request::create('/webhook', 'POST', ['type' => 'payment', 'data' => ['id' => '123']]);
        $request->headers->add(['x-signature' => 'ts=1700000000,v1=falsa', 'x-request-id' => 'req-1']);

        $this->assertWebhookRejectedWith(403, $request);
    }

    public function test_a_webhook_without_signature_headers_is_rejected_as_forbidden(): void
    {
        $this->assertWebhookRejectedWith(403, Request::create('/webhook', 'POST', ['type' => 'payment', 'data' => ['id' => '123']]));
    }

    private function assertWebhookRejectedWith(int $status, Request $request): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([]);

        try {
            $this->driver()->handleWebhook($request);
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame($status, $exception->getCode());
        }

        $this->assertSame([], $mercadoPago->requests);
    }

    private static function cardPayment(): PaymentRequest
    {
        return new PaymentRequest(
            amount: '50.50',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'tok_1',
        );
    }

    private static function payout(): PayoutRequest
    {
        return new PayoutRequest(amount: '20.50', currency: 'PEN', recipient: 'vendedor@example.com', description: 'Liquidacion');
    }

    private function driver(): MercadoPagoApiDriver
    {
        return $this->app->make(PaymentManager::class)->driver('mercadopago_api');
    }

    private function signedPaymentWebhook(): Request
    {
        $hash = hash_hmac('sha256', 'id:123;request-id:req-1;ts:1700000000;', self::WEBHOOK_SECRET);
        $request = Request::create('/webhook', 'POST', ['type' => 'payment', 'data' => ['id' => '123']]);
        $request->headers->add(['x-signature' => "ts=1700000000,v1={$hash}", 'x-request-id' => 'req-1']);

        return $request;
    }
}
