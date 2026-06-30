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
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Resources\RequestOptions;
use MercadoPago\Exceptions\MPApiException;
use Illuminate\Http\Request;
use Exception;

class MercadoPagoApiDriver extends AbstractPaymentDriver
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
     * Procesar cobro transparente directo usando token de tarjeta.
     */
    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);

        if (empty($request->cardToken)) {
            throw new PaymentGatewayException(
                "Se requiere un token de tarjeta valido para procesar pagos por API transparente."
            );
        }

        $this->authenticate();
        $client = new PaymentClient();

        $payload = [
            'transaction_amount' => $request->amount,
            'token' => $request->cardToken,
            'description' => $request->description,
            'installments' => $request->metadata['installments'] ?? 1,
            'payment_method_id' => $request->metadata['payment_method_id'] ?? null,
            'payer' => [
                'email' => $request->email,
                'identification' => array_filter([
                    'type' => $request->metadata['payer_id_type'] ?? null,
                    'number' => $request->metadata['payer_id_number'] ?? null,
                ])
            ],
        ];

        // Configurar opciones del request (Idempotencia)
        $options = new RequestOptions();
        
        if ($request->idempotencyKey) {
            $options->setCustomHeaders([
                'X-Idempotency-Key: ' . $request->idempotencyKey
            ]);
        }

        try {
            $payment = $client->create($payload, $options);

            $statusMap = [
                'approved' => 'completed',
                'in_process' => 'pending',
                'pending' => 'pending',
                'rejected' => 'failed',
                'cancelled' => 'failed',
            ];

            $mappedStatus = $statusMap[$payment->status] ?? 'pending';
            $success = in_array($mappedStatus, ['completed', 'pending'], true);

            return new PaymentResponse(
                success: $success,
                transactionId: (string) $payment->id,
                status: $mappedStatus,
                redirectUrl: null,
                errorMessage: $payment->status_detail ?? null,
                raw: $payment->toArray()
            );
        } catch (MPApiException $e) {
            throw new PaymentGatewayException(
                "Error en API de Mercado Pago: " . $e->getMessage(),
                $e->getStatusCode() ?: 500,
                $e
            );
        } catch (Exception $e) {
            throw new PaymentGatewayException(
                "Error inesperado al procesar pago por API de Mercado Pago: " . $e->getMessage(),
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
     * Transferir fondos a un tercero.
     */
    public function payout(PayoutRequest $request): PayoutResponse
    {
        throw new PaymentGatewayException(
            "El driver de Mercado Pago API no soporta retiros automatizados directos."
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
