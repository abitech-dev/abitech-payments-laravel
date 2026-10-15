<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

/**
 * Stripe recibe los montos en la unidad menor de la moneda, salvo las monedas
 * sin decimales, que van tal cual.
 * https://docs.stripe.com/currencies#zero-decimal
 */
trait ConvertsStripeAmounts
{
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    protected function toStripeAmount(float $amount, string $currency): int
    {
        return (int) round($amount * $this->stripeFactor($currency));
    }

    protected function fromStripeAmount(int|float|null $amount, ?string $currency): ?float
    {
        return $amount === null ? null : (float) $amount / $this->stripeFactor((string) $currency);
    }

    private function stripeFactor(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;
    }
}
