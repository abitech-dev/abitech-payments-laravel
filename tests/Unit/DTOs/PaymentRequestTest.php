<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\DTOs;

use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\Tests\TestCase;

class PaymentRequestTest extends TestCase
{
    public function test_it_creates_with_minimal_params(): void
    {
        $dto = new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test payment'
        );

        $this->assertSame(100.0, $dto->amount);
        $this->assertSame('USD', $dto->currency);
        $this->assertSame('test@test.com', $dto->email);
        $this->assertSame('Test payment', $dto->description);
        $this->assertNull($dto->cardToken);
        $this->assertNull($dto->idempotencyKey);
        $this->assertNull($dto->successUrl);
        $this->assertNull($dto->cancelUrl);
        $this->assertSame([], $dto->metadata);
    }

    public function test_it_creates_with_all_params(): void
    {
        $dto = new PaymentRequest(
            amount: 50.50,
            currency: 'PEN',
            email: 'cliente@email.com',
            description: 'Producto X',
            cardToken: 'tok_abc123',
            idempotencyKey: 'idem-001',
            successUrl: 'https://miapp.com/success',
            cancelUrl: 'https://miapp.com/cancel',
            metadata: ['order_id' => '123']
        );

        $this->assertSame(50.50, $dto->amount);
        $this->assertSame('PEN', $dto->currency);
        $this->assertSame('tok_abc123', $dto->cardToken);
        $this->assertSame('idem-001', $dto->idempotencyKey);
        $this->assertSame('https://miapp.com/success', $dto->successUrl);
        $this->assertSame('https://miapp.com/cancel', $dto->cancelUrl);
        $this->assertSame(['order_id' => '123'], $dto->metadata);
    }

    public function test_dtos_are_readonly(): void
    {
        $dto = new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        );

        $this->expectException(\Error::class);
        unset($dto->amount);
    }
}

class PayoutRequestTest extends TestCase
{
    public function test_it_creates_with_idempotency_key(): void
    {
        $dto = new PayoutRequest(
            amount: 200.00,
            currency: 'USD',
            recipient: 'user@test.com',
            description: 'Payout test',
            idempotencyKey: 'idem-payout-001'
        );

        $this->assertSame('idem-payout-001', $dto->idempotencyKey);
    }
}

class SubscriptionRequestTest extends TestCase
{
    public function test_it_creates_with_idempotency_key(): void
    {
        $dto = new SubscriptionRequest(
            planId: 'plan-001',
            email: 'sub@test.com',
            idempotencyKey: 'idem-sub-001'
        );

        $this->assertSame('idem-sub-001', $dto->idempotencyKey);
    }
}

class PaymentResponseTest extends TestCase
{
    public function test_it_creates_successful_response(): void
    {
        $response = new PaymentResponse(
            success: true,
            transactionId: 'txn-001',
            status: 'pending',
            redirectUrl: 'https://checkout.com/pay'
        );

        $this->assertTrue($response->success);
        $this->assertSame('txn-001', $response->transactionId);
        $this->assertSame('pending', $response->status);
        $this->assertSame('https://checkout.com/pay', $response->redirectUrl);
        $this->assertNull($response->errorMessage);
    }

    public function test_it_creates_failed_response(): void
    {
        $response = new PaymentResponse(
            success: false,
            transactionId: '',
            status: 'failed',
            errorMessage: 'Tarjeta rechazada'
        );

        $this->assertFalse($response->success);
        $this->assertSame('Tarjeta rechazada', $response->errorMessage);
    }
}
