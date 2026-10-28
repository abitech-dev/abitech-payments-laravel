<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

/**
 * Lee el medio de pago de un cargo de Stripe expandido como arreglo.
 */
trait ReadsStripeCards
{
    /**
     * Sin cargo expandido no hay datos; un cargo que no es de tarjeta da solo
     * su tipo (`link`, `ideal`).
     *
     * @param  array<string, mixed>|string|null  $charge
     * @return array{payment_method?: string, brand?: ?string, last_four?: ?string}
     */
    protected function paymentMethodFromStripeCharge(array|string|null $charge): array
    {
        $details = is_array($charge) ? ($charge['payment_method_details'] ?? null) : null;

        if (! is_array($details)) {
            return [];
        }

        $card = $details['card'] ?? null;

        if (! $card) {
            return isset($details['type']) ? ['payment_method' => $details['type']] : [];
        }

        return [
            'payment_method' => $card['brand'] ?? 'card',
            'brand' => $card['brand'] ?? null,
            'last_four' => $card['last4'] ?? null,
        ];
    }
}
