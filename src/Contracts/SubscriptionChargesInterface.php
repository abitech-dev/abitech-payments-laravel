<?php

declare(strict_types=1);

namespace Abitech\Payments\Contracts;

use Abitech\Payments\DTO\SubscriptionCharge;

/**
 * Lectura de los cobros que la pasarela hace por cada periodo de una
 * suscripción, para conciliarlos con las facturas del host.
 */
interface SubscriptionChargesInterface
{
    /**
     * Los cobros de la suscripción, del más reciente al más antiguo, sin el
     * detalle de su pago.
     *
     * @return list<SubscriptionCharge>
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function subscriptionCharges(string $subscriptionId): array;

    /**
     * El cobro con el pago que lo liquida; nulo si no existe.
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function findSubscriptionCharge(string $chargeId): ?SubscriptionCharge;
}
