<?php

declare(strict_types=1);

namespace Abitech\Payments\Contracts;

use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\DTO\SubscriptionResponse;

interface SubscriptionInterface
{
    /**
     * Crear una suscripcion recurrente.
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse;

    /**
     * Cancelar una suscripcion activa.
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function cancelSubscription(string $subscriptionId, ?string $reason = null): SubscriptionResponse;

    /**
     * Actualizar plan o datos de una suscripcion existente.
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function updateSubscription(string $subscriptionId, SubscriptionRequest $request): SubscriptionResponse;

    /**
     * Obtener estado actual de una suscripcion.
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function getSubscription(string $subscriptionId): SubscriptionResponse;
}
