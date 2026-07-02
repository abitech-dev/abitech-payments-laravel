<?php

declare(strict_types=1);

namespace Abitech\Payments\Events;

class RefundProcessed
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $transactionId,
        public readonly ?float $amount = null,
        public readonly array $context = []
    ) {}
}
