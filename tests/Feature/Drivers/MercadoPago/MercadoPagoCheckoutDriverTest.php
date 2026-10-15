<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\MercadoPago;

use Abitech\Payments\Drivers\MercadoPago\MercadoPagoCheckoutDriver;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeMercadoPagoHttpClient;
use Abitech\Payments\Tests\TestCase;

class MercadoPagoCheckoutDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'abitech_payments.gateways.mercadopago' => ['access_token' => 'TEST-token'],
            'abitech_payments.retry.base_delay_ms' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        FakeMercadoPagoHttpClient::uninstall();

        parent::tearDown();
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

    private function driver(): MercadoPagoCheckoutDriver
    {
        return $this->app->make(PaymentManager::class)->driver('mercadopago_checkout');
    }
}
