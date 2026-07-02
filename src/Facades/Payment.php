<?php

declare(strict_types=1);

namespace Abitech\Payments\Facades;

use Abitech\Payments\PaymentManager;
use Illuminate\Support\Facades\Facade;

/**
 * Acceso directo a PaymentManager sin inyeccion de dependencias.
 *
 *   Payment::purchase($dto);
 *   Payment::refund('txn_123');
 *   Payment::driver('stripe_checkout')->health();
 *   Payment::forTenant('store_42')->purchase($dto);
 *
 * @method static \Abitech\Payments\Contracts\PaymentGatewayInterface driver(string $driver = null)
 * @method static \Abitech\Payments\DTO\PaymentResponse purchase(\Abitech\Payments\DTO\PaymentRequest $request)
 * @method static bool refund(string $transactionId, ?float $amount = null)
 * @method static \Abitech\Payments\DTO\PayoutResponse payout(\Abitech\Payments\DTO\PayoutRequest $request)
 * @method static \Abitech\Payments\DTO\WebhookResult handleWebhook(\Illuminate\Http\Request $request)
 * @method static bool health()
 * @method static static forTenant(string $tenantId)
 *
 * @see \Abitech\Payments\PaymentManager
 */
class Payment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
