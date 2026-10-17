<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

use Abitech\Payments\Concerns\ValidatesAmounts;

class PayoutRequest
{
    use ValidatesAmounts;

    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $recipient,
        public readonly string $description,
        public readonly ?string $idempotencyKey = null,
        public readonly array $metadata = []
    ) {
        self::assertDecimalAmount($amount);
    }
}
