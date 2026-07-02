<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

use Abitech\Payments\Exceptions\PaymentGatewayException;

/**
 * Reintenta llamadas a APIs de pasarelas con backoff exponencial.
 *
 * Solo reintenta codigos HTTP transitorios (429, 5xx).
 * Configurable por driver: maxRetries, retryBaseDelayMs, retryMultiplier.
 */
trait RetriesApiCalls
{
    /** Maximo de reintentos por llamada (incluye el intento inicial). */
    protected int $maxRetries = 3;

    /** Delay base entre reintentos en milisegundos. */
    protected int $retryBaseDelayMs = 300;

    /** Multiplicador exponencial del delay (delay = base * multiplicador^intento). */
    protected float $retryMultiplier = 2.0;

    /** Codigos HTTP que ameritan reintento. */
    protected array $retryableHttpCodes = [429, 500, 502, 503, 504];

    /**
     * Ejecuta un callback con reintentos y backoff exponencial.
     *
     * @template T
     * @param callable(): T $callback  Operacion a reintentar
     * @param int|null $maxRetries     Sobreescribe el maximo de reintentos (null = usar $this->maxRetries)
     * @return T
     */
    protected function retry(callable $callback, ?int $maxRetries = null): mixed
    {
        $attempts = $maxRetries ?? $this->maxRetries;
        $lastException = null;

        for ($i = 0; $i <= $attempts; $i++) {
            try {
                return $callback();
            } catch (PaymentGatewayException $e) {
                if ($i === $attempts || !$this->isRetryable($e)) {
                    throw $e;
                }

                $lastException = $e;
                $delayMs = (int) ($this->retryBaseDelayMs * pow($this->retryMultiplier, $i));
                usleep($delayMs * 1000);
            }
        }

        throw $lastException ?? new PaymentGatewayException("Se agotaron los reintentos sin exito.");
    }

    /**
     * Determina si un error HTTP es reintentable basado en su codigo de estado.
     */
    protected function isRetryable(PaymentGatewayException $e): bool
    {
        return in_array($e->getCode(), $this->retryableHttpCodes, true);
    }
}
