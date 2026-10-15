<?php

declare(strict_types=1);

namespace Abitech\Payments\Facades;

use Abitech\Payments\PaymentManager;
use Illuminate\Support\Facades\Facade;

/**
 * Acceso estático a PaymentManager, como `Payment::driver('fake')->health()`.
 *
 * @method static \Abitech\Payments\Contracts\PaymentGatewayInterface driver(string $driver = null)
 * @method static \Abitech\Payments\DTO\PaymentResponse purchase(\Abitech\Payments\DTO\PaymentRequest $request)
 * @method static bool refund(string $transactionId, ?string $amount = null)
 * @method static \Abitech\Payments\DTO\PayoutResponse payout(\Abitech\Payments\DTO\PayoutRequest $request)
 * @method static \Abitech\Payments\DTO\WebhookResult handleWebhook(\Illuminate\Http\Request $request)
 * @method static bool health()
 * @method static \Abitech\Payments\DTO\SubscriptionResponse createSubscription(\Abitech\Payments\DTO\SubscriptionRequest $request)
 * @method static \Abitech\Payments\DTO\SubscriptionResponse cancelSubscription(string $subscriptionId, ?string $reason = null)
 * @method static \Abitech\Payments\DTO\SubscriptionResponse updateSubscription(string $subscriptionId, \Abitech\Payments\DTO\SubscriptionRequest $request)
 * @method static \Abitech\Payments\DTO\SubscriptionResponse getSubscription(string $subscriptionId)
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
