<?php

declare(strict_types=1);

namespace Abitech\Payments\Events;

class PaymentFailed
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $errorMessage,
        public readonly int $statusCode,
        public readonly array $context = []
    ) {}
}
