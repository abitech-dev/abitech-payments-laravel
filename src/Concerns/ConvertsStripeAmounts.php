<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Support\Str;

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

    protected function toStripeAmount(string $amount, string $currency): int
    {
        $factor = $this->stripeFactor($currency);
        $scale = Str::contains($amount, '.') ? Str::length(Str::after($amount, '.')) : 0;
        $minorUnits = bcmul($amount, $factor, 0);

        if (bccomp(bcmul($amount, $factor, $scale), $minorUnits, $scale) !== 0) {
            throw new PaymentGatewayException(
                "El monto {$amount} tiene más decimales de los que admite {$currency}.",
                422
            );
        }

        return (int) $minorUnits;
    }

    protected function fromStripeAmount(?int $amount, ?string $currency): ?string
    {
        if ($amount === null) {
            return null;
        }

        $factor = $this->stripeFactor((string) $currency);

        return bcdiv((string) $amount, $factor, Str::length($factor) - 1);
    }

    private function stripeFactor(string $currency): string
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? '1' : '100';
    }
}
