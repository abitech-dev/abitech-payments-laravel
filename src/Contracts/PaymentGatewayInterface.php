<?php

declare(strict_types=1);

namespace Abitech\Payments\Contracts;

use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\WebhookResult;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    /**
     * Procesar un cobro directo o crear una preferencia de checkout.
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function purchase(PaymentRequest $request): PaymentResponse;

    /**
     * Reembolsar una transacción de forma total o parcial; `$amount` es una
     * cadena decimal en unidad mayor y `null` reembolsa el total.
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function refund(string $transactionId, ?string $amount = null): bool;

    /**
     * Enviar fondos a terceros o transferencias (Payouts).
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function payout(PayoutRequest $request): PayoutResponse;

    /**
     * Procesar y normalizar el webhook recibido de la pasarela.
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function handleWebhook(Request $request): WebhookResult;

    /**
     * Verificar conectividad con la pasarela (health check).
     *
     * @throws \Abitech\Payments\Exceptions\PaymentGatewayException
     */
    public function health(): bool;
}
