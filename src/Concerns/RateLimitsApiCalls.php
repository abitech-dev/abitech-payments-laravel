<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Cache\RateLimiter;

/**
 * Limita la frecuencia de llamadas a las APIs de pasarelas
 * usando el RateLimiter de Laravel. Evita exceder limites de rate
 * impuestos por Stripe, MercadoPago y otros proveedores.
 *
 * Requiere illuminate/cache.
 */
trait RateLimitsApiCalls
{
    /** Instancia de RateLimiter de Laravel (lazy init). */
    protected ?RateLimiter $limiter = null;

    /** Maximo de intentos permitidos por minuto. */
    protected int $maxRequestsPerMinute = 60;

    /** Prefijo para las claves de cache de rate limit. */
    protected string $limiterPrefix = 'abitech_payments';

    /** Resuelve el RateLimiter desde el contenedor si esta disponible. */
    protected function initRateLimiter(): void
    {
        if ($this->limiter === null && function_exists('app')) {
            $this->limiter = app(RateLimiter::class);
        }
    }

    /**
     * Verifica y registra un intento de llamada.
     * Lanza PaymentGatewayException con codigo 429 si se excede el limite.
     *
     * @param string $key         Identificador unico de la operacion (ej: 'stripe_checkout:purchase')
     * @param int    $maxAttempts Maximo de intentos (0 = usar maxRequestsPerMinute)
     * @param int|null $decaySeconds Ventana de tiempo en segundos (null = 60)
     */
    protected function throttle(string $key, int $maxAttempts = 0, ?int $decaySeconds = null): void
    {
        $this->initRateLimiter();

        if ($this->limiter === null) {
            return;
        }

        $limitKey = "{$this->limiterPrefix}:{$key}";
        $max = $maxAttempts > 0 ? $maxAttempts : $this->maxRequestsPerMinute;
        $decay = $decaySeconds ?? 60;

        if ($this->limiter->tooManyAttempts($limitKey, $max)) {
            $seconds = $this->limiter->availableIn($limitKey);
            throw new PaymentGatewayException(
                "Limite de llamadas excedido para {$key}. Reintente en {$seconds} segundos.",
                429
            );
        }

        $this->limiter->hit($limitKey, $decay);
    }
}
