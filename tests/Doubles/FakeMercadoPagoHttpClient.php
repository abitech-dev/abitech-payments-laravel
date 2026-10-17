<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Doubles;

use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;

/**
 * Transporte HTTP del SDK de Mercado Pago que responde en orden sin red.
 */
class FakeMercadoPagoHttpClient implements MPHttpClient
{
    /** @var list<MPRequest> */
    public array $requests = [];

    /**
     * @param  list<array{0: int, 1: array<string, mixed>}>  $responses
     */
    private function __construct(private array $responses)
    {
    }

    /**
     * Instala el doble en el SDK; cada respuesta es `[código, cuerpo]`.
     *
     * @param  list<array{0: int, 1: array<string, mixed>}>  $responses
     */
    public static function install(array $responses): self
    {
        $client = new self($responses);
        MercadoPagoConfig::setHttpClient($client);

        return $client;
    }

    public static function uninstall(): void
    {
        MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient());
    }

    public function send(MPRequest $request): MPResponse
    {
        $this->requests[] = $request;

        [$status, $body] = array_shift($this->responses) ?? [500, ['message' => 'Sin respuesta preparada.']];
        $response = new MPResponse($status, $body);

        // Mismo contrato que MPDefaultHttpClient, que lanza ante todo no 2xx.
        if ($status < 200 || $status >= 300) {
            throw new MPApiException('Api error. Check response for details', $response);
        }

        return $response;
    }
}
