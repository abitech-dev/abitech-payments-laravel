<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

class PayoutResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $payoutId = null,
        public readonly ?string $status = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = []
    ) {}
}
