<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\Concerns;

use Abitech\Payments\Concerns\ConvertsStripeAmounts;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Tests\TestCase;

class ConvertsStripeAmountsTest extends TestCase
{
    use ConvertsStripeAmounts;

    public function test_a_two_decimal_currency_is_sent_in_cents(): void
    {
        $this->assertSame(1999, $this->toStripeAmount('19.99', 'USD'));
        $this->assertSame(1, $this->toStripeAmount('0.01', 'pen'));
        $this->assertSame(5000, $this->toStripeAmount('50', 'usd'));
    }

    public function test_a_sum_made_with_bcmath_converts_exactly(): void
    {
        $this->assertSame(30, $this->toStripeAmount(bcadd('0.1', '0.2', 2), 'USD'));
    }

    public function test_the_float_sum_of_0_1_and_0_2_is_rejected(): void
    {
        $this->assertRejectedAsInvalid(fn () => $this->toStripeAmount('0.30000000000000004', 'USD'));
    }

    public function test_a_zero_decimal_currency_is_sent_as_is(): void
    {
        $this->assertSame(1500, $this->toStripeAmount('1500', 'JPY'));
        $this->assertSame(25000, $this->toStripeAmount('25000.00', 'clp'));
    }

    public function test_a_fraction_in_a_zero_decimal_currency_is_rejected(): void
    {
        $this->assertRejectedAsInvalid(fn () => $this->toStripeAmount('1500.5', 'JPY'));
    }

    public function test_the_webhook_amount_comes_back_as_a_decimal_string_in_major_units(): void
    {
        $this->assertSame('19.99', $this->fromStripeAmount(1999, 'usd'));
        $this->assertSame('0.30', $this->fromStripeAmount(30, 'usd'));
        $this->assertSame('1500', $this->fromStripeAmount(1500, 'jpy'));
        $this->assertNull($this->fromStripeAmount(null, 'usd'));
    }

    private function assertRejectedAsInvalid(callable $convert): void
    {
        try {
            $convert();
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(422, $exception->getCode());
        }
    }
}
