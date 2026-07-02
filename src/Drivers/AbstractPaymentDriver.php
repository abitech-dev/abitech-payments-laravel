<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers;

use Abitech\Payments\Concerns\RateLimitsApiCalls;
use Abitech\Payments\Concerns\RetriesApiCalls;
use Abitech\Payments\Contracts\PaymentGatewayInterface;
use Abitech\Payments\Exceptions\UnsupportedCurrencyException;

/**
 * Clase base para todos los drivers de pasarelas de pago.
 *
 * Provee rate limiting, reintentos con backoff, validacion de divisas
 * y acceso a la configuracion de la pasarela.
 */
abstract class AbstractPaymentDriver implements PaymentGatewayInterface
{
    use RateLimitsApiCalls;
    use RetriesApiCalls;

    /** Configuracion de la pasarela (credenciales, secrets). */
    protected array $config = [];

    /** Codigos ISO de divisas soportadas por esta pasarela. */
    protected array $supportedCurrencies = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Nombre unico del driver usado como slug en el manager.
     * Ej: 'mercadopago_checkout', 'stripe_paymentintents'.
     */
    abstract public function getGatewayName(): string;

    /**
     * Lanza UnsupportedCurrencyException si la divisa no esta en supportedCurrencies.
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
