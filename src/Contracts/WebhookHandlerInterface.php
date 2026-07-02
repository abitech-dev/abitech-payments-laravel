<?php

declare(strict_types=1);

namespace Abitech\Payments\Contracts;

use Abitech\Payments\DTO\WebhookResult;
use Illuminate\Http\Request;

/**
 * Contrato para procesamiento y normalizacion de webhooks de pasarelas.
 *
 * Separado de PaymentGatewayInterface para permitir que un mismo driver
 * tenga logica de webhook reutilizable o que middleware/controladores
 * tipen contra esta interfaz especifica sin depender de purchase/refund.
 */
interface WebhookHandlerInterface
{
    /**
     * Recibe un request HTTP de la pasarela, verifica la firma,
     * consulta el recurso (pago, suscripcion, etc.) y retorna
     * un WebhookResult normalizado.
     */
    public function handleWebhook(Request $request): WebhookResult;
}
