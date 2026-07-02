<?php

declare(strict_types=1);

namespace Abitech\Payments;

use Illuminate\Support\Manager;
use Abitech\Payments\Contracts\ConfigResolverInterface;
use Abitech\Payments\Drivers\MercadoPago\MercadoPagoCheckoutDriver;
use Abitech\Payments\Drivers\MercadoPago\MercadoPagoApiDriver;
use Abitech\Payments\Drivers\Stripe\StripeCheckoutDriver;
use Abitech\Payments\Drivers\Stripe\StripePaymentIntentsDriver;
use Abitech\Payments\Drivers\Testing\FakePaymentDriver;

/**
 * Factory de drivers de pasarelas de pago.
 *
 * Resuelve la instancia del driver correcto segun configuracion,
 * soportando resolucion de credenciales desde archivo .env o desde
 * base de datos via ConfigResolverInterface.
 *
 * Uso:
 *   $manager->driver('stripe_checkout')->purchase($dto);
 *   $manager->forTenant('store_42')->driver('mercadopago_api');
 */
class PaymentManager extends Manager
{
    /** Resolutor de configuracion dinamico (BD + cache). Null = usar config file. */
    protected ?ConfigResolverInterface $configResolver = null;

    /** ID del tenant actual para multi-tenant. Null = global. */
    protected ?string $tenantId = null;

    /**
     * Establece un resolutor de configuracion personalizado.
     * Util cuando las credenciales se gestionan por panel admin en BD.
     */
    public function setConfigResolver(ConfigResolverInterface $resolver): static
    {
        $this->configResolver = $resolver;
        return $this;
    }

    /**
     * Retorna una nueva instancia del manager con alcance de tenant.
     * Cada tenant puede tener credenciales distintas para la misma pasarela.
     */
    public function forTenant(string $tenantId): static
    {
        $instance = new static($this->container);
        $instance->configResolver = $this->configResolver;
        $instance->tenantId = $tenantId;
        return $instance;
    }

    public function getDefaultDriver(): string
    {
        return $this->config->get('abitech_payments.default', 'mercadopago_checkout');
    }

    public function createMercadopagoCheckoutDriver(): MercadoPagoCheckoutDriver
    {
        return new MercadoPagoCheckoutDriver(
            $this->resolveGatewayConfig('mercadopago')
        );
    }

    public function createMercadopagoApiDriver(): MercadoPagoApiDriver
    {
        return new MercadoPagoApiDriver(
            $this->resolveGatewayConfig('mercadopago')
        );
    }

    public function createStripeCheckoutDriver(): StripeCheckoutDriver
    {
        return new StripeCheckoutDriver(
            $this->resolveGatewayConfig('stripe')
        );
    }

    public function createStripePaymentIntentsDriver(): StripePaymentIntentsDriver
    {
        return new StripePaymentIntentsDriver(
            $this->resolveGatewayConfig('stripe')
        );
    }

    /**
     * Crea un driver falso para testing. No requiere SDKs ni credenciales.
     */
    public function createFakeDriver(): FakePaymentDriver
    {
        return new FakePaymentDriver([]);
    }

    /**
     * Resuelve credenciales: primero prueba el resolver dinamico (BD),
     * luego cae en el archivo de configuracion estatico.
     */
    protected function resolveGatewayConfig(string $gateway): array
    {
        if ($this->configResolver !== null) {
            return $this->configResolver->resolve($gateway, $this->tenantId);
        }

        return $this->config->get("abitech_payments.gateways.{$gateway}", []);
    }
}
