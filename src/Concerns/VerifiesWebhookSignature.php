<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

use Illuminate\Http\Request;
use Abitech\Payments\Exceptions\PaymentGatewayException;

/**
 * Verifica firmas HMAC de webhooks entrantes de Stripe y MercadoPago.
 */
trait VerifiesWebhookSignature
{
    /**
     * Verifica la firma de un webhook de Stripe usando el secreto compartido.
     *
     * @param string $secret     Webhook signing secret de Stripe
     * @param int    $tolerance  Tolerancia de tiempo en segundos (default 300)
     * @return array             Evento de Stripe decodificado como array
     */
    protected function verifyStripeSignature(Request $request, string $secret, int $tolerance = 300): array
    {
        if (!class_exists('Stripe\Webhook')) {
            throw new PaymentGatewayException(
                "El SDK de Stripe no esta instalado. Ejecute: composer require stripe/stripe-php"
            );
        }

        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        if (empty($signature)) {
            throw new PaymentGatewayException(
                "Falta el encabezado Stripe-Signature en la notificacion."
            );
        }

        try {
            $webhookClass = 'Stripe\Webhook';
            $event = $webhookClass::constructEvent(
                $payload,
                $signature,
                $secret,
                $tolerance
            );

            return $event->toArray();
        } catch (\Throwable $e) {
            $class = get_class($e);

            if ($class === 'Stripe\Exception\SignatureVerificationException' || $class === 'Stripe\Exception\UnexpectedValueException') {
                throw new PaymentGatewayException(
                    "Firma de webhook de Stripe invalida: " . $e->getMessage(),
                    403,
                    $e
                );
            }

            throw new PaymentGatewayException(
                "Error inesperado al verificar firma de Stripe: " . $e->getMessage(),
                500,
                $e
            );
        }
    }

    /**
     * Verifica la firma HMAC-SHA256 de un webhook de MercadoPago.
     * Usa los encabezados x-signature y x-request-id.
     */
    protected function verifyMercadoPagoSignature(Request $request, string $secret): void
    {
        $signature = $request->header('x-signature');
        $requestId = $request->header('x-request-id');

        if (empty($signature) || empty($requestId)) {
            throw new PaymentGatewayException(
                "Faltan los encabezados de verificacion de Mercado Pago (x-signature, x-request-id)."
            );
        }

        $dataId = $request->input('data.id') ?? '';

        [$ts, $hash] = explode(',', $signature);
        $ts = str_replace('ts=', '', $ts);
        $hash = str_replace('v1=', '', $hash);

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $expectedHash = hash_hmac('sha256', $manifest, $secret);

        if (!hash_equals($expectedHash, $hash)) {
            throw new PaymentGatewayException(
                "Firma de webhook de Mercado Pago invalida.",
                403
            );
        }
    }
}
