<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

/**
 * Lee la tarjeta de un cargo de Stripe expandido como arreglo.
 */
trait ReadsStripeCards
{
    /**
     * Sin cargo expandido, o con un cargo que no es de tarjeta, no hay datos.
     *
     * @param  array<string, mixed>|string|null  $charge
     * @return array{payment_method?: string, brand?: ?string, last_four?: ?string}
     */
    protected function cardFromStripeCharge(array|string|null $charge): array
    {
        $card = is_array($charge) ? ($charge['payment_method_details']['card'] ?? null) : null;

        if (! $card) {
            return [];
        }

        return [
            'payment_method' => $card['brand'] ?? 'card',
            'brand' => $card['brand'] ?? null,
            'last_four' => $card['last4'] ?? null,
        ];
    }
}
