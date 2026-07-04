<?php

declare(strict_types=1);

namespace Abitech\Payments\Exceptions;

use Exception;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentGatewayException extends Exception implements Responsable
{
    /**
     * Renderiza la excepción como respuesta JSON.
     *
     * Laravel invoca este método automáticamente cuando la excepción
     * alcanza el manejador global, respetando el código HTTP y el
     * mensaje original sin que la app host lo sobrescriba.
     */
    public function toResponse($request): JsonResponse
    {
        $status = $this->isValidHttpStatus($this->code) ? $this->code : 500;

        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => null,
        ], $status);
    }

    /**
     * Determina si el código de excepción es un código HTTP válido.
     */
    protected function isValidHttpStatus(int $code): bool
    {
        return $code >= 100 && $code < 600;
    }
}
