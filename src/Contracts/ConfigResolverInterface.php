<?php

declare(strict_types=1);

namespace Abitech\Payments\Contracts;

interface ConfigResolverInterface
{
    /**
     * Resolver las credenciales de una pasarela.
     *
     * @param string $gateway  Nombre de la pasarela (mercadopago, stripe, etc.)
     * @param string|null $tenantId  Identificador del tenant (null = default global)
     * @return array<string, mixed>  Array asociativo con las credenciales
     */
    public function resolve(string $gateway, ?string $tenantId = null): array;
}
