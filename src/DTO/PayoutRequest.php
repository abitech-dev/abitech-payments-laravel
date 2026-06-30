<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

class PayoutRequest
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $recipient,
        public readonly string $description,
        public readonly array $metadata = []
    ) {}
}
