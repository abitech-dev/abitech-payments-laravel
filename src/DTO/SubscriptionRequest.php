<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

use Abitech\Payments\Concerns\ValidatesAmounts;

class SubscriptionRequest
{
    use ValidatesAmounts;

    public function __construct(
        public readonly string $planId,
        public readonly string $email,
        public readonly ?string $cardToken = null,
        public readonly ?string $interval = 'month',
        public readonly ?int $intervalCount = 1,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?int $trialDays = null,
        public readonly ?string $idempotencyKey = null,
        public readonly array $metadata = []
    ) {
        self::assertDecimalAmount($amount);
    }
}
