<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Doubles;

use Illuminate\Http\Request;

/**
 * Aviso de Stripe firmado como lo firma Stripe (`t=…,v1=HMAC`).
 */
class StripeWebhookRequest
{
    /**
     * @param  array<string, mixed>  $object
     */
    public static function signed(string $type, array $object, string $secret): Request
    {
        $payload = json_encode([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return Request::create('/webhook', 'POST', server: [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: $payload);
    }
}
