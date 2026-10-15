<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\MercadoPago;

use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\Drivers\MercadoPago\MercadoPagoApiDriver;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeMercadoPagoHttpClient;
use Abitech\Payments\Tests\TestCase;
use Illuminate\Http\Request;

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
            amount: 50.0,
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            cardToken: 'tok_1',
            idempotencyKey: 'k-1',
        ));

        $this->assertContains('X-Idempotency-Key: k-1', $mercadoPago->requests[0]->getHeaders());
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
