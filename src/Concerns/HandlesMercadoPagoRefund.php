<?php

declare(strict_types=1);

namespace Abitech\Payments\Concerns;

use Abitech\Payments\Exceptions\PaymentGatewayException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\HttpMethod;
use MercadoPago\Net\MPRequest;

trait HandlesMercadoPagoRefund
{
    protected function sendRefundRequest(string $transactionId, ?float $amount = null): true
    {
        $httpClient = MercadoPagoConfig::getHttpClient();
        $mpRequest = new MPRequest(
            "/v1/payments/{$transactionId}/refunds",
            HttpMethod::POST,
            json_encode(array_filter(['amount' => $amount])),
            [
                'Accept: application/json',
                'Content-Type: application/json; charset=UTF-8',
                'Authorization: Bearer ' . MercadoPagoConfig::getAccessToken(),
            ],
            MercadoPagoConfig::getConnectionTimeout()
        );

        $mpResponse = $httpClient->send($mpRequest);
        $statusCode = $mpResponse->getStatusCode();

        if ($statusCode >= 400) {
            $body = $mpResponse->getContent();
            throw new PaymentGatewayException(
                "Error al reembolsar pago en Mercado Pago: " . ($body['message'] ?? 'Error de conexion'),
                $statusCode ?: 500,
            );
        }

        return true;
    }
}
