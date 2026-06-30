<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

class WebhookResult
{
    public function __construct(
        public readonly string $gateway,
        public readonly string $eventType,
        public readonly ?string $transactionId = null,
        public readonly ?string $status = null,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly array $raw = []
    ) {}
}
