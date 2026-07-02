<?php

declare(strict_types=1);

namespace Abitech\Payments\Events;

use Abitech\Payments\DTO\PaymentResponse;

class PaymentSucceeded
{
    public function __construct(
        public readonly PaymentResponse $response,
        public readonly string $gateway,
        public readonly array $context = []
    ) {}
}
