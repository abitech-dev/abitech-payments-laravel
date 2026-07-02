<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

class SubscriptionRequest
{
    public function __construct(
        public readonly string $planId,
        public readonly string $email,
        public readonly ?string $cardToken = null,
        public readonly ?string $interval = 'month',
        public readonly ?int $intervalCount = 1,
        public readonly ?float $amount = null,
        public readonly ?string $currency = null,
        public readonly ?int $trialDays = null,
        public readonly array $metadata = []
    ) {}
}
