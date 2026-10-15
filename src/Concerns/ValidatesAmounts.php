<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Support\Str;

/**
 * Un monto viaja como cadena decimal en unidad mayor (`'19.99'`), nunca como
 * float, para que la app host lo opere con bcmath sin perder centavos.
 */
trait ValidatesAmounts
{
    protected static function assertDecimalAmount(?string $amount): void
    {
        if ($amount !== null && ! Str::isMatch('/^\d+(\.\d+)?$/', $amount)) {
            throw new PaymentGatewayException(
                "El monto «{$amount}» no es una cadena decimal en unidad mayor, como 19.99.",
                422
            );
        }
    }
}
