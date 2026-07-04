<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\MercadoPago;

use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\Concerns\HandlesMercadoPagoWebhook;
use Abitech\Payments\Concerns\HandlesMercadoPagoRefund;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\Events\PaymentInitiated;
use Abitech\Payments\Events\PaymentSucceeded;
use Abitech\Payments\Events\PayoutProcessed;
use Abitech\Payments\Events\RefundProcessed;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;
use Exception;

class MercadoPagoApiDriver extends AbstractPaymentDriver
{
    use HandlesMercadoPagoWebhook, HandlesMercadoPagoRefund;

    protected array $supportedCurrencies = ['PEN', 'USD', 'BRL', 'ARS', 'MXN', 'CLP', 'COP'];

    public static function paymentMethodSchema(): array
    {
        return [
            ['key' => 'installments', 'type' => 'number', 'label' => 'Cuotas máximas (1 = sin cuotas)', 'min' => 1, 'max' => 12, 'default' => 1],
            ['key' => 'excluded_payment_methods', 'type' => 'checkboxes', 'label' => 'Excluir métodos', 'default' => [], 'options' => [
                ['id' => 'visa', 'label' => 'Visa'],
                ['id' => 'master', 'label' => 'Mastercard'],
                ['id' => 'amex', 'label' => 'American Express'],
                ['id' => 'diners', 'label' => 'Diners Club'],
                ['id' => 'debvisa', 'label' => 'Visa Débito'],
                ['id' => 'debmaster', 'label' => 'Mastercard Débito'],
                ['id' => 'yape', 'label' => 'Yape'],
                ['id' => 'pagoefectivo_atm', 'label' => 'PagoEfectivo'],
            ]],
        ];
    }

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

            $response = new PaymentResponse(
                success: $success,
                transactionId: (string) $payment->id,
                status: $mappedStatus,
                redirectUrl: null,
                errorMessage: $payment->status_detail ?? null,
                raw: json_decode(json_encode($payment), true)
            );

            if ($mappedStatus === 'completed') {
                event(new PaymentSucceeded($response, 'mercadopago_api'));
            } else {
                event(new PaymentInitiated($response, 'mercadopago_api'));
            }

            return $response;
        });
    }

    public function refund(string $transactionId, ?float $amount = null): bool
    {
        $this->authenticate();
        $this->throttle('mercadopago_api:refund');

        $result = $this->retry(function () use ($transactionId, $amount) {
            return $this->sendRefundRequest($transactionId, $amount);
        });

        event(new RefundProcessed('mercadopago_api', $transactionId, $amount));

        return $result;
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
                'payment_method_id' => $request->metadata['payment_method_id'] ?? 'account_money',
                'payer' => ['email' => $request->recipient],
            ]);

            $response = new PayoutResponse(
                success: $payment->status === 'approved',
                payoutId: (string) $payment->id,
                status: $payment->status === 'approved' ? 'completed' : 'pending',
                raw: json_decode(json_encode($payment), true)
            );

            event(new PayoutProcessed($response, 'mercadopago_api'));

            return $response;
        });
    }
}
