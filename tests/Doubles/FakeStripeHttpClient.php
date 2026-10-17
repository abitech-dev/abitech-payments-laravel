<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Doubles;

use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Transporte HTTP del SDK de Stripe que responde en orden sin salir a la red.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: list<string>, params: array<string, mixed>}> */
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
        ApiRequestor::setHttpClient($client);

        return $client;
    }

    public static function uninstall(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

        [$status, $body] = array_shift($this->responses) ?? [500, ['error' => ['message' => 'Sin respuesta preparada.']]];

        return [json_encode($body), $status, []];
    }

    /**
     * @return list<string>
     */
    public function headersOf(int $index): array
    {
        return $this->requests[$index]['headers'];
    }
}
