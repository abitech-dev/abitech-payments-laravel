<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\Concerns;

use Abitech\Payments\Concerns\ConvertsStripeAmounts;
use Abitech\Payments\Tests\TestCase;

class ConvertsStripeAmountsTest extends TestCase
{
    use ConvertsStripeAmounts;

    public function test_a_two_decimal_currency_is_sent_in_cents(): void
    {
        $this->assertSame(9990, $this->toStripeAmount(99.9, 'USD'));
        $this->assertSame(1, $this->toStripeAmount(0.01, 'pen'));
    }

    public function test_a_zero_decimal_currency_is_sent_as_is(): void
    {
        $this->assertSame(1500, $this->toStripeAmount(1500, 'JPY'));
        $this->assertSame(25000, $this->toStripeAmount(25000, 'clp'));
    }

    public function test_the_webhook_amount_comes_back_in_major_units(): void
    {
        $this->assertSame(99.9, $this->fromStripeAmount(9990, 'usd'));
        $this->assertSame(1500.0, $this->fromStripeAmount(1500, 'jpy'));
        $this->assertNull($this->fromStripeAmount(null, 'usd'));
    }
}
