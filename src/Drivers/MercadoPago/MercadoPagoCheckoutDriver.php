<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\MercadoPago;

use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use Illuminate\Http\Request;
use Exception;

class MercadoPagoCheckoutDriver extends AbstractPaymentDriver
{
    /**
     * Divisas soportadas por Mercado Pago.
     */
    protected array $supportedCurrencies = ['PEN', 'USD', 'BRL', 'ARS', 'MXN', 'CLP', 'COP'];

    /**
     * Inicializar credenciales del SDK de Mercado Pago.
     */
    protected function authenticate(): void
    {
        $accessToken = $this->config['access_token'] ?? null;

        if (empty($accessToken)) {
            throw new PaymentGatewayException(
                "Falta el token de acceso de Mercado Pago en la configuracion."
            );
        }

        MercadoPagoConfig::setAccessToken($accessToken);
    }

    /**
     * Crear preferencia de pago y retornar URL de redirección.
     */
    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);
        $this->authenticate();

        $client = new PreferenceClient();

        $items = [
            [
                'id' => $request->idempotencyKey ?? uniqid(),
                'title' => $request->description,
                'quantity' => 1,
                'unit_price' => $request->amount,
                'currency_id' => strtoupper($request->currency),
            ]
        ];

        $payload = [
            'items' => $items,
            'payer' => [
                'email' => $request->email,
            ],
            'back_urls' => [
                'success' => $request->metadata['back_urls']['success'] ?? null,
                'failure' => $request->metadata['back_urls']['failure'] ?? null,
                'pending' => $request->metadata['back_urls']['pending'] ?? null,
            ],
            'auto_return' => 'approved',
            'external_reference' => $request->idempotencyKey,
            'notification_url' => $request->metadata['notification_url'] ?? null,
        ];

        try {
            $preference = $client->create($payload);

            return new PaymentResponse(
                success: true,
                transactionId: $preference->id,
                status: 'pending',
                redirectUrl: $preference->init_point,
                raw: $preference->toArray()
            );
        } catch (MPApiException $e) {
            throw new PaymentGatewayException(
                "Error de Mercado Pago al crear preferencia: " . $e->getMessage(),
                $e->getStatusCode() ?: 500,
                $e
            );
        } catch (Exception $e) {
            throw new PaymentGatewayException(
                "Error inesperado al crear preferencia de Mercado Pago: " . $e->getMessage(),
                500,
                $e
            );
        }
    }

    /**
     * Reembolsar un pago procesado.
     */
    public function refund(string $transactionId, ?float $amount = null): bool
    {
        $this->authenticate();
        $client = new PaymentClient();

        try {
            $client->refund($transactionId, $amount);
            return true;
        } catch (MPApiException $e) {
            throw new PaymentGatewayException(
                "Error al reembolsar pago en Mercado Pago: " . $e->getMessage(),
                $e->getStatusCode() ?: 500,
                $e
            );
        } catch (Exception $e) {
            throw new PaymentGatewayException(
                "Fallo inesperado al reembolsar pago en Mercado Pago: " . $e->getMessage(),
                500,
                $e
            );
        }
    }

    /**
     * Transferir fondos a un tercero (No soportado nativamente en checkout estándar).
     */
    public function payout(PayoutRequest $request): PayoutResponse
    {
        throw new PaymentGatewayException(
            "El driver de Mercado Pago Checkout no soporta retiros automatizados directos."
        );
    }

    /**
     * Recibir y normalizar webhook de Mercado Pago.
     */
    public function handleWebhook(Request $request): WebhookResult
    {
        $this->authenticate();

        $paymentId = $request->input('data.id') ?? $request->input('id');
        $type = $request->input('type');

        if (empty($paymentId) || ($type !== null && $type !== 'payment')) {
            throw new PaymentGatewayException(
                "Notificacion recibida no corresponde a un pago valido de Mercado Pago."
            );
        }

        $client = new PaymentClient();

        try {
            $payment = $client->get((int) $paymentId);

            $statusMap = [
                'approved' => 'completed',
                'rejected' => 'failed',
                'cancelled' => 'failed',
                'in_process' => 'pending',
                'pending' => 'pending',
                'refunded' => 'refunded',
            ];

            $mappedStatus = $statusMap[$payment->status] ?? 'pending';

            return new WebhookResult(
                gateway: 'mercadopago',
                eventType: $type ?? 'payment',
                transactionId: (string) $payment->id,
                status: $mappedStatus,
                amount: (float) $payment->transaction_amount,
                currency: $payment->currency_id,
                raw: $payment->toArray()
            );
        } catch (MPApiException $e) {
            throw new PaymentGatewayException(
                "Error de Mercado Pago al obtener el detalle del pago: " . $e->getMessage(),
                $e->getStatusCode() ?: 500,
                $e
            );
        } catch (Exception $e) {
            throw new PaymentGatewayException(
                "Error al procesar el webhook de Mercado Pago: " . $e->getMessage(),
                500,
                $e
            );
        }
    }
}
