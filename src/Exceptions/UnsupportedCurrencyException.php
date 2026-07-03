<?php

declare(strict_types=1);

namespace Abitech\Payments\Exceptions;

class UnsupportedCurrencyException extends PaymentGatewayException
{
    protected $code = 422;
}
