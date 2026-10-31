<?php

declare(strict_types=1);

namespace Abitech\Payments\DTO;

use Abitech\Payments\Concerns\ValidatesAmounts;

/**
 * Cobro de un periodo, el `authorized_payment` de Mercado Pago o la factura
 * `in_` de Stripe. `status` es `scheduled`, `pending`, `completed`, `failed`,
 * `refunded` o `canceled`; `payment` llega al consultar el cobro.
 */
class SubscriptionCharge
{
    use ValidatesAmounts;

    public function __construct(
        public readonly string $chargeId,
        public readonly ?string $subscriptionId,
        public readonly string $status,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $debitDate = null,
        public readonly ?string $paymentId = null,
        public readonly ?WebhookResult $payment = null,
        public readonly ?int $retryAttempt = null,
        public readonly array $raw = [],
    ) {
        self::assertDecimalAmount($amount);
    }
}
