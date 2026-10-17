<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\DTOs;

use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Tests\TestCase;

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
        $this->assertNull($dto->amount);
        $this->assertSame('month', $dto->interval);
    }

    public function test_an_amount_that_is_not_a_decimal_string_is_rejected(): void
    {
        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(422);

        new SubscriptionRequest(planId: 'plan-001', email: 'sub@test.com', amount: '49.9.0');
    }
}
