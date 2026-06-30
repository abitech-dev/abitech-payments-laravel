<?php

declare(strict_types=1);

namespace Abitech\Payments;

use Illuminate\Support\Manager;
use Abitech\Payments\Drivers\MercadoPago\MercadoPagoCheckoutDriver;
use Abitech\Payments\Drivers\MercadoPago\MercadoPagoApiDriver;

class PaymentManager extends Manager
{
    /**
     * Obtener el nombre del driver por defecto.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('abitech_payments.default', 'mercadopago_checkout');
    }

    /**
     * Crear instancia del driver Mercado Pago Checkout Pro (Redirect).
     */
    public function createMercadopagoCheckoutDriver(): MercadoPagoCheckoutDriver
    {
        return new MercadoPagoCheckoutDriver(
            $this->config->get('abitech_payments.gateways.mercadopago', [])
        );
    }

    /**
     * Crear instancia del driver Mercado Pago API Brick (Firma transparente).
     */
    public function createMercadopagoApiDriver(): MercadoPagoApiDriver
    {
        return new MercadoPagoApiDriver(
            $this->config->get('abitech_payments.gateways.mercadopago', [])
        );
    }
}
