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

        $globalRetry = config('abitech_payments.retry', []);
        $globalRateLimit = config('abitech_payments.rate_limit', []);

        $this->maxRetries = (int) ($config['max_retries'] ?? $globalRetry['max_attempts'] ?? $this->maxRetries);
        $this->retryBaseDelayMs = (int) ($config['retry_base_delay_ms'] ?? $globalRetry['base_delay_ms'] ?? $this->retryBaseDelayMs);
        $this->retryMultiplier = (float) ($config['retry_multiplier'] ?? $globalRetry['multiplier'] ?? $this->retryMultiplier);
        $this->maxRequestsPerMinute = (int) ($config['max_requests_per_minute'] ?? $globalRateLimit['max_requests_per_minute'] ?? $this->maxRequestsPerMinute);

        if (!empty($globalRateLimit['cache_prefix'])) {
            $this->limiterPrefix = (string) $globalRateLimit['cache_prefix'];
        }
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
