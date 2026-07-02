<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\MercadoPago;

use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\Concerns\HandlesMercadoPagoWebhook;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use Exception;

class MercadoPagoApiDriver extends AbstractPaymentDriver
{
    use HandlesMercadoPagoWebhook;

    protected array $supportedCurrencies = ['PEN', 'USD', 'BRL', 'ARS', 'MXN', 'CLP', 'COP'];

    public function getGatewayName(): string
    {
        return 'mercadopago_api';
    }

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

    public function health(): bool
    {
        $this->authenticate();

        try {
            $client = new PaymentClient();
            $client->get(1);
            return true;
        } catch (MPApiException $e) {
            if ($e->getStatusCode() === 404) {
                return true;
            }

            throw new PaymentGatewayException(
                "Mercado Pago no responde: " . $e->getMessage(),
                $e->getStatusCode() ?: 500,
                $e
            );
        } catch (Exception $e) {
            throw new PaymentGatewayException(
                "Fallo de conectividad con Mercado Pago: " . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);

        if (empty($request->cardToken)) {
            throw new PaymentGatewayException(
                "Se requiere un token de tarjeta valido para procesar pagos por API transparente."
            );
        }

        $this->authenticate();
        $this->throttle('mercadopago_api:purchase');

        return $this->retry(function () use ($request) {
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

            $options = null;

            if ($request->idempotencyKey) {
                $className = 'MercadoPago\Resources\RequestOptions';

                if (class_exists($className)) {
                    $options = new $className();
                    $options->setCustomHeaders([
                        'X-Idempotency-Key: ' . $request->idempotencyKey
                    ]);
                }
            }

            $payment = $client->create($payload, $options);

            $mappedStatus = $this->mapMercadoPagoStatus($payment->status);
            $success = in_array($mappedStatus, ['completed', 'pending'], true);

            return new PaymentResponse(
                success: $success,
                transactionId: (string) $payment->id,
                status: $mappedStatus,
                redirectUrl: null,
                errorMessage: $payment->status_detail ?? null,
                raw: json_decode(json_encode($payment), true)
            );
        });
    }

    public function refund(string $transactionId, ?float $amount = null): bool
    {
        $this->authenticate();
        $this->throttle('mercadopago_api:refund');

        return $this->retry(function () use ($transactionId, $amount) {
            return $this->sendRefundRequest($transactionId, $amount);
        });
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $this->validateCurrency($request->currency);
        $this->authenticate();
        $this->throttle('mercadopago_api:payout');

        return $this->retry(function () use ($request) {
            $client = new PaymentClient();
            $payment = $client->create([
                'transaction_amount' => $request->amount,
                'description' => $request->description,
                'payment_method_id' => 'account_money',
                'payer' => ['email' => $request->recipient],
            ]);

            return new PayoutResponse(
                success: $payment->status === 'approved',
                payoutId: (string) $payment->id,
                status: $payment->status === 'approved' ? 'completed' : 'pending',
                raw: json_decode(json_encode($payment), true)
            );
        });
    }

    protected function sendRefundRequest(string $transactionId, ?float $amount = null): true
    {
        $accessToken = $this->config['access_token'];
        $url = "https://api.mercadopago.com/v1/payments/{$transactionId}/refunds";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$accessToken}",
            ],
            CURLOPT_POSTFIELDS => json_encode(array_filter([
                'amount' => $amount,
            ])),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $body = $response ? json_decode($response, true) : [];
            throw new PaymentGatewayException(
                "Error al reembolsar pago en Mercado Pago: " . ($body['message'] ?? 'Error de conexion'),
                $httpCode ?: 500,
            );
        }

        return true;
    }
}
