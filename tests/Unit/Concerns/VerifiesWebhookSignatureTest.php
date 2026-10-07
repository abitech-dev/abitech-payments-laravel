<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\Concerns;

use Abitech\Payments\Concerns\VerifiesWebhookSignature;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Tests\TestCase;
use Illuminate\Http\Request;

class VerifiesWebhookSignatureTest extends TestCase
{
    use VerifiesWebhookSignature;

    private const SECRET = 'secreto-mp';

    public function test_missing_mercadopago_headers_are_rejected_as_forbidden(): void
    {
        $this->assertRejectedWith(403, fn () => $this->verifyMercadoPagoSignature($this->mercadoPagoRequest([]), self::SECRET));
    }

    public function test_a_forged_mercadopago_signature_is_rejected_as_forbidden(): void
    {
        $request = $this->mercadoPagoRequest(['x-signature' => 'ts=1,v1=falsa', 'x-request-id' => 'req-1']);

        $this->assertRejectedWith(403, fn () => $this->verifyMercadoPagoSignature($request, self::SECRET));
    }

    public function test_a_valid_mercadopago_signature_passes(): void
    {
        $hash = hash_hmac('sha256', 'id:123;request-id:req-1;ts:1700000000;', self::SECRET);
        $request = $this->mercadoPagoRequest(['x-signature' => "ts=1700000000,v1={$hash}", 'x-request-id' => 'req-1']);

        $this->verifyMercadoPagoSignature($request, self::SECRET);

        $this->addToAssertionCount(1);
    }

    public function test_missing_stripe_header_is_rejected_as_forbidden(): void
    {
        if (! class_exists('Stripe\Webhook')) {
            $this->markTestSkipped('SDK de Stripe no instalado.');
        }

        $this->assertRejectedWith(403, fn () => $this->verifyStripeSignature(Request::create('/webhook', 'POST', content: '{}'), 'whsec_x'));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function mercadoPagoRequest(array $headers): Request
    {
        $request = Request::create('/webhook', 'POST', ['type' => 'payment', 'data' => ['id' => '123']]);
        $request->headers->add($headers);

        return $request;
    }

    private function assertRejectedWith(int $status, callable $verify): void
    {
        try {
            $verify();
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame($status, $exception->getCode());
        }
    }
}
