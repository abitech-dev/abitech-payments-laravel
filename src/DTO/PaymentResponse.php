<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

class PaymentResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionId = null,
        public readonly ?string $status = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = []
    ) {}
}
