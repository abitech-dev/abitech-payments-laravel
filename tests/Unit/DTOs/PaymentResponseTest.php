<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\DTOs;

use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\Tests\TestCase;

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
