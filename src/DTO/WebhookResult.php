<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

use Abitech\Payments\Concerns\ValidatesAmounts;

class WebhookResult
{
    use ValidatesAmounts;

    /** Código ISO 4217 en mayúsculas, aunque la pasarela lo dé en minúsculas. */
    public readonly ?string $currency;

    public function __construct(
        public readonly string $gateway,
        public readonly string $eventType,
        public readonly ?string $transactionId = null,
        public readonly ?string $status = null,
        public readonly ?string $amount = null,
        ?string $currency = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $cardBrand = null,
        public readonly ?string $cardLastFour = null,
        public readonly ?int $installments = null,
        public readonly array $raw = [],
    ) {
        self::assertDecimalAmount($amount);
        $this->currency = $currency === null ? null : strtoupper($currency);
    }
}
