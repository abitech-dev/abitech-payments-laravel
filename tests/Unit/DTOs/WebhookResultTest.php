<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\DTOs;

use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Tests\TestCase;

class WebhookResultTest extends TestCase
{
    public function test_an_event_without_amount_is_valid(): void
    {
        $result = new WebhookResult(gateway: 'stripe', eventType: 'customer.created', status: 'ignored');

        $this->assertNull($result->amount);
    }

    public function test_the_currency_is_an_uppercase_iso_4217_code(): void
    {
        $result = new WebhookResult(gateway: 'stripe', eventType: 'charge.refunded', amount: '5.00', currency: 'pen');

        $this->assertSame('PEN', $result->currency);
    }

    public function test_an_event_without_currency_keeps_it_null(): void
    {
        $result = new WebhookResult(gateway: 'stripe', eventType: 'customer.created', status: 'ignored');

        $this->assertNull($result->currency);
    }

    public function test_an_amount_that_is_not_a_decimal_string_is_rejected(): void
    {
        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(422);

        new WebhookResult(gateway: 'stripe', eventType: 'charge.refunded', amount: '-5.00');
    }
}
