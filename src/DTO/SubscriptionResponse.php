<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

use Abitech\Payments\Concerns\ValidatesAmounts;

/**
 * `redirectUrl` es la página donde la persona autoriza la suscripción
 * mientras sigue pendiente. `amount` es el monto que cobra cada periodo.
 */
class SubscriptionResponse
{
    use ValidatesAmounts;

    public function __construct(
        public readonly bool $success,
        public readonly ?string $subscriptionId = null,
        public readonly ?string $status = null,
        public readonly ?string $planId = null,
        public readonly ?string $nextBillingDate = null,
        public readonly ?string $canceledAt = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
        public readonly ?string $redirectUrl = null,
        public readonly ?string $externalReference = null,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
    ) {
        self::assertDecimalAmount($amount);
    }
}
