<?php

declare(strict_types=1);

namespace Abitech\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Middleware que exige el encabezado X-Idempotency-Key en endpoints de pago.
 *
 * Previene operaciones duplicadas (doble cargo) requiriendo una llave
 * unica por cada request de creacion de pago, reembolso o transferencia.
 *
 * Ignora metodos GET, HEAD y OPTIONS.
 * La llave debe tener entre 16 y 255 caracteres.
 */
class EnforceIdempotencyKey
{
    /** Metodos HTTP adicionales a excluir de la validacion. */
    protected array $except = [];

    public function __construct(array $except = [])
    {
        $this->except = $except;
    }

    /**
     * Lista de metodos HTTP que no requieren llave de idempotencia.
     */
    public function getExceptMethods(): array
    {
        return array_merge(['GET', 'HEAD', 'OPTIONS'], $this->except);
    }

    /**
     * Valida que el request contenga X-Idempotency-Key valido.
     *
     * Devuelve una respuesta JSON 422 directamente en lugar de lanzar una
     * excepcion, evitando que errores de validacion controlados se registren
     * como errores internos en los logs de la aplicacion host.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (in_array($request->method(), $this->getExceptMethods(), true)) {
            return $next($request);
        }

        $config = config('abitech_payments.idempotency', []);
        $header = $config['header'] ?? 'X-Idempotency-Key';
        $minLength = $config['min_length'] ?? 16;
        $maxLength = $config['max_length'] ?? 255;

        $idempotencyKey = $request->header($header);

        if (empty($idempotencyKey)) {
            return $this->errorResponse(
                "Se requiere el encabezado {$header} para operaciones de pago."
            );
        }

        if (strlen($idempotencyKey) < $minLength || strlen($idempotencyKey) > $maxLength) {
            return $this->errorResponse(
                "El encabezado {$header} debe tener entre {$minLength} y {$maxLength} caracteres."
            );
        }

        return $next($request);
    }

    /**
     * Construye una respuesta JSON de error de validacion 422.
     */
    protected function errorResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => null,
        ], 422);
    }
}
