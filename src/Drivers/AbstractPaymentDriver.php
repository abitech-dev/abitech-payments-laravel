<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers;

use Abitech\Payments\Contracts\PaymentGatewayInterface;
use Abitech\Payments\Exceptions\UnsupportedCurrencyException;

abstract class AbstractPaymentDriver implements PaymentGatewayInterface
{
    /**
     * Configuración específica de la pasarela.
     */
    protected array $config = [];

    /**
     * Listado de divisas soportadas nativamente por este driver.
     * Debe ser sobreescrito en cada driver concreto.
     */
    protected array $supportedCurrencies = [];

    /**
     * Crear una nueva instancia de driver.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Validar si la divisa especificada es compatible con este driver.
     *
     * @throws \Abitech\Payments\Exceptions\UnsupportedCurrencyException
     */
    public function validateCurrency(string $currency): void
    {
        $upperCurrency = strtoupper($currency);

        if (!in_array($upperCurrency, $this->supportedCurrencies, true)) {
            throw new UnsupportedCurrencyException(
                "La moneda {$currency} no es soportada por este metodo de pago."
            );
        }
    }
}
