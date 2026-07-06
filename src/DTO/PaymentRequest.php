<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

class PaymentRequest
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $email,
        public readonly string $description,
        public readonly ?string $cardToken = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $successUrl = null,
        public readonly ?string $cancelUrl = null,
        public readonly ?string $payerName = null,
        public readonly ?string $payerSurname = null,
        public readonly ?string $payerDocumentType = null,
        public readonly ?string $payerDocumentNumber = null,
        public readonly ?string $payerPhone = null,
        public readonly array $metadata = [],
    ) {}
}
