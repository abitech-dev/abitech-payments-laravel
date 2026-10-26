<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\MercadoPago;

use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\Concerns\HandlesMercadoPagoPaymentLookup;
use Abitech\Payments\Concerns\HandlesMercadoPagoWebhook;
use Abitech\Payments\Concerns\HandlesMercadoPagoRefund;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;

class MercadoPagoApiDriver extends AbstractPaymentDriver
{
    use HandlesMercadoPagoWebhook, HandlesMercadoPagoRefund, HandlesMercadoPagoPaymentLookup;

    protected array $supportedCurrencies = ['PEN', 'USD', 'BRL', 'ARS', 'MXN', 'CLP', 'COP'];

    /** Clase cuya ausencia indica que falta el SDK. */
    protected const SDK_CLASS = MercadoPagoConfig::class;

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
        $this->ensureSdkInstalled(static::SDK_CLASS, 'mercadopago/dx-php');

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
        $this->throttle('mercadopago_api:health');

        try {
            return $this->retry(function () {
                (new PaymentClient())->get(1);

                return true;
            });
        } catch (PaymentGatewayException $e) {
            // El pago 1 no existe; un 404 prueba que la API responde.
            if ($e->getCode() === 404) {
                return true;
            }

            throw new PaymentGatewayException("Mercado Pago no responde: " . $e->getMessage(), $e->getCode(), $e);
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
                'transaction_amount' => $this->toMercadoPagoAmount($request->amount),
                'token' => $request->cardToken,
                'description' => $request->description,
                'installments' => $request->metadata['installments'] ?? 1,
                'payment_method_id' => $request->metadata['payment_method_id'] ?? null,
                'payer' => [
                    'email' => $request->email,
                    'identification' => collect([
                        'type' => $request->metadata['payer_id_type'] ?? null,
                        'number' => $request->metadata['payer_id_number'] ?? null,
                    ])->filter()->all(),
                ],
            ];

            $options = $request->idempotencyKey
                ? new RequestOptions(custom_headers: ['X-Idempotency-Key: ' . $request->idempotencyKey])
                : null;

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

    public function refund(string $transactionId, ?string $amount = null): bool
    {
        self::assertDecimalAmount($amount);
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
                'transaction_amount' => $this->toMercadoPagoAmount($request->amount),
                'description' => $request->description,
                'payment_method_id' => $request->metadata['payment_method_id'] ?? 'account_money',
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
}
