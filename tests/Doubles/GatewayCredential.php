<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Doubles;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de la app host con las columnas que lee `DatabaseConfigResolver`.
 */
class GatewayCredential extends Model
{
    public $timestamps = false;

    protected $table = 'gateway_credentials';

    protected $guarded = [];

    public function scopeForTenant(Builder $query, string $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }
}
