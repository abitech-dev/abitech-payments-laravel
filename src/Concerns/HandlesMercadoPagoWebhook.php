<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

use Illuminate\Http\Request;
use MercadoPago\Client\Payment\PaymentClient;
use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;

/**
 * Logica compartida para procesar webhooks de Mercado Pago.
 *
 * Ambos drivers MP (Checkout y API) usan el mismo flujo:
 * verificar firma, obtener pago por ID, mapear estado y retornar WebhookResult.
 *
 * Satisface WebhookHandlerInterface.
 */
trait HandlesMercadoPagoWebhook
{
    use VerifiesWebhookSignature;

    /**
     * Mapea el estado nativo de MercadoPago a un estado normalizado.
     *
     * MP: approved|rejected|cancelled|in_process|pending|refunded
     *   -> completed|failed|pending|refunded
     */
    protected function mapMercadoPagoStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'completed',
            'rejected', 'cancelled' => 'failed',
            'in_process', 'pending' => 'pending',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }

    /**
     * Recibe un webhook de MercadoPago, verifica firma (si hay client_secret),
     * consulta el pago y retorna un WebhookResult normalizado.
     */
    public function handleWebhook(Request $request): WebhookResult
    {
        $this->authenticate();

        $secret = $this->config['webhook_secret'] ?? $this->config['client_secret'] ?? null;

        if ($secret) {
            $this->verifyMercadoPagoSignature($request, $secret);
        }

        $paymentId = $request->input('data.id') ?? $request->input('id');
        $type = $request->input('type', 'payment');

        $validTypes = ['payment', 'subscription_preapproval', 'subscription_authorized_payment', 'merchant_order'];

        if (empty($paymentId) || !in_array($type, $validTypes, true)) {
            throw new PaymentGatewayException(
                "Notificacion recibida no corresponde a un evento valido de Mercado Pago.",
                422
            );
        }

        if ($type !== 'payment') {
            return new WebhookResult(
                gateway: $this->getGatewayName(),
                eventType: $type,
                transactionId: (string) $paymentId,
                status: 'unknown',
                raw: $request->all()
            );
        }

        $this->throttle($this->getGatewayName() . ':webhook');
        $payment = $this->retry(fn () => (new PaymentClient())->get((int) $paymentId));

        return new WebhookResult(
            gateway: $this->getGatewayName(),
            eventType: $type,
            transactionId: (string) $payment->id,
            status: $this->mapMercadoPagoStatus($payment->status),
            amount: (float) $payment->transaction_amount,
            currency: $payment->currency_id,
            paymentMethod: $payment->payment_method_id ?? null,
            cardBrand: $payment->card->issuer->name ?? null,
            cardLastFour: $payment->card->last_four_digits ?? null,
            installments: $payment->installments ?? null,
            raw: json_decode(json_encode($payment), true)
        );
    }
}
