<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

/**
 * Mercado Pago recibe y devuelve el monto en unidad mayor como número JSON.
 * El float solo existe al armar el cuerpo y al leer la respuesta del SDK.
 */
trait ConvertsMercadoPagoAmounts
{
    protected function toMercadoPagoAmount(string $amount): float
    {
        return (float) $amount;
    }

    protected function fromMercadoPagoAmount(int|float|null $amount): ?string
    {
        return $amount === null ? null : number_format((float) $amount, 2, '.', '');
    }
}
