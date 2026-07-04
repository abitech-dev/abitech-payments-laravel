<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Unit\Concerns;

use Abitech\Payments\Concerns\RetriesApiCalls;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Tests\TestCase;

class RetriesApiCallsTest extends TestCase
{
    use RetriesApiCalls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->maxRetries = 2;
        $this->retryBaseDelayMs = 1;
    }

    public function test_it_returns_value_on_success(): void
    {
        $result = $this->retry(fn () => 'success');
        $this->assertSame('success', $result);
    }

    public function test_it_retries_on_retryable_code(): void
    {
        $attempts = 0;

        $this->expectException(PaymentGatewayException::class);

        $this->retry(function () use (&$attempts) {
            $attempts++;
            throw new PaymentGatewayException('Error', 500);
        });

        $this->assertSame(3, $attempts); // 1 initial + 2 retries
    }

    public function test_it_does_not_retry_on_non_retryable_code(): void
    {
        $attempts = 0;

        $this->expectException(PaymentGatewayException::class);

        $this->retry(function () use (&$attempts) {
            $attempts++;
            throw new PaymentGatewayException('Bad request', 400);
        });

        $this->assertSame(1, $attempts);
    }

    public function test_it_rethrows_payment_gateway_exception(): void
    {
        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('Fallo');

        $this->retry(function () {
            throw new PaymentGatewayException('Fallo', 500);
        });
    }

    public function test_it_wraps_generic_exception_with_http_code(): void
    {
        $exception = new class('API Error', 429) extends \Exception {};

        $this->expectException(PaymentGatewayException::class);

        $this->retry(function () use ($exception) {
            throw $exception;
        });
    }

    public function test_it_wraps_exception_with_get_status_code_method(): void
    {
        $exception = new class('Forbidden', 0) extends \Exception {
            public function getStatusCode(): int
            {
                return 403;
            }
        };

        $this->expectException(PaymentGatewayException::class);

        $this->retry(function () use ($exception) {
            throw $exception;
        });
    }
}
