<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\MercadoPago;

use Abitech\Payments\Drivers\MercadoPago\MercadoPagoCheckoutDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeMercadoPagoHttpClient;
use Abitech\Payments\Tests\TestCase;
use Illuminate\Support\Facades\Event;

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

    private function driver(): MercadoPagoCheckoutDriver
    {
        return $this->app->make(PaymentManager::class)->driver('mercadopago_checkout');
    }
}
