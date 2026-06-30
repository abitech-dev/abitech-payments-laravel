<?php

declare(strict_types=1);

namespace Abitech\Payments\Exceptions;

class UnsupportedCurrencyException extends PaymentGatewayException
{
    // Excepción para cuando una divisa no es soportada por un driver específico
}
