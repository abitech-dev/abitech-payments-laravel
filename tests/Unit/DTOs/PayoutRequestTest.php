<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\DTOs;

use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Tests\TestCase;

class PayoutRequestTest extends TestCase
{
    public function test_it_creates_with_idempotency_key(): void
    {
        $dto = new PayoutRequest(
            amount: '200.00',
            currency: 'USD',
            recipient: 'user@test.com',
            description: 'Payout test',
            idempotencyKey: 'idem-payout-001'
        );

        $this->assertSame('200.00', $dto->amount);
        $this->assertSame('idem-payout-001', $dto->idempotencyKey);
    }

    public function test_an_amount_that_is_not_a_decimal_string_is_rejected(): void
    {
        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(422);

        new PayoutRequest(amount: '200,00', currency: 'USD', recipient: 'user@test.com', description: 'Payout test');
    }
}
