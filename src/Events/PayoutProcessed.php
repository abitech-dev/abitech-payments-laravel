<?php

declare(strict_types=1);

namespace Abitech\Payments\Events;

use Abitech\Payments\DTO\PayoutResponse;

class PayoutProcessed
{
    public function __construct(
        public readonly PayoutResponse $response,
        public readonly string $gateway,
        public readonly array $context = []
    ) {}
}
