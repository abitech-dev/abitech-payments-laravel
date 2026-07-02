<?php

declare(strict_types=1);

namespace Abitech\Payments\Resolvers;

use Abitech\Payments\Contracts\ConfigResolverInterface;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Closure;

/**
 * Resuelve credenciales de pasarelas desde base de datos, con soporte de cache.
 *
 * Cuando el host app gestiona credenciales por panel admin (no .env),
 * este resolver consulta el modelo Eloquent configurado.
 *
 * Soporta multi-tenant via scopeForTenant() en el modelo.
 */
class DatabaseConfigResolver implements ConfigResolverInterface
{
    /** @var Closure(string $gateway, ?string $tenantId): array */
    protected Closure $resolver;

    /** Repositorio de cache de Laravel (opcional). */
    protected ?CacheRepository $cache = null;

    /** TTL del cache en segundos (default 1 hora). */
    protected int $ttlSeconds = 3600;

    /** Prefijo para las claves de cache. */
    protected string $cachePrefix = 'abitech_payments_config';

    /**
     * @param Closure|string $resolver  Closure o nombre de clase Eloquent.
     *                                   Si es string, se asume que el modelo tiene columnas 'name', 'is_active' y 'credentials'.
     *                                   Soporta scopeForTenant() para multi-tenant.
     * @param CacheRepository|null $cache  Repositorio de cache (null = sin cache).
     * @param int $ttlSeconds              Tiempo de vida del cache en segundos.
     */
    public function __construct(Closure|string $resolver, ?CacheRepository $cache = null, int $ttlSeconds = 3600)
    {
        if (is_string($resolver)) {
            $class = $resolver;
            $resolver = function (string $gateway, ?string $tenantId) use ($class) {
                $query = $class::query();

                if ($tenantId !== null && method_exists($class, 'scopeForTenant')) {
                    $query->forTenant($tenantId);
                }

                $model = $query->where('name', $gateway)
                    ->where('is_active', true)
                    ->first();

                if (!$model) {
                    throw new PaymentGatewayException(
                        "No se encontro configuracion activa para la pasarela '{$gateway}'."
                    );
                }

                $credentials = $model->getAttribute('credentials');

                if (is_string($credentials)) {
                    $credentials = json_decode($credentials, true);
                }

                return $credentials ?? [];
            };
        }

        $this->resolver = $resolver;
        $this->cache = $cache;
        $this->ttlSeconds = $ttlSeconds;
    }

    /**
     * Resuelve las credenciales para una pasarela, usando cache si esta disponible.
     *
     * @param string $gateway   Nombre de la pasarela (mercadopago, stripe)
     * @param string|null $tenantId  ID del tenant (null = global)
     * @return array<string, mixed>
     */
    public function resolve(string $gateway, ?string $tenantId = null): array
    {
        $cacheKey = $this->buildCacheKey($gateway, $tenantId);

        if ($this->cache) {
            return $this->cache->remember($cacheKey, $this->ttlSeconds, function () use ($gateway, $tenantId) {
                return ($this->resolver)($gateway, $tenantId);
            });
        }

        return ($this->resolver)($gateway, $tenantId);
    }

    /**
     * Invalida la entrada de cache para una pasarela/tenant especificos.
     */
    public function forget(string $gateway, ?string $tenantId = null): void
    {
        if ($this->cache) {
            $this->cache->forget($this->buildCacheKey($gateway, $tenantId));
        }
    }

    /**
     * Construye la clave de cache: abitech_payments_config:{gateway}[:{tenantId}]
     */
    protected function buildCacheKey(string $gateway, ?string $tenantId): string
    {
        $key = "{$this->cachePrefix}:{$gateway}";

        if ($tenantId !== null) {
            $key .= ":{$tenantId}";
        }

        return $key;
    }
}
