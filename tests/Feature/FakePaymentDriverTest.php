<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature;

use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\Drivers\Testing\FakePaymentDriver;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Tests\TestCase;

class FakePaymentDriverTest extends TestCase
{
    protected FakePaymentDriver $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakePaymentDriver([]);
    }

    public function test_gateway_name(): void
    {
        $this->assertSame('fake', $this->fake->getGatewayName());
    }

    public function test_purchase_returns_payment_response(): void
    {
        $response = $this->fake->purchase(new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));

        $this->assertTrue($response->success);
        $this->assertSame('completed', $response->status);
    }

    public function test_should_return_custom_response(): void
    {
        $custom = new PaymentResponse(
            success: false,
            transactionId: 'err-1',
            status: 'failed',
            errorMessage: 'Fondos insuficientes'
        );

        $this->fake->shouldReturn('purchase', $custom);

        $response = $this->fake->purchase(new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));

        $this->assertFalse($response->success);
        $this->assertSame('Fondos insuficientes', $response->errorMessage);
    }

    public function test_should_throw_exception(): void
    {
        $this->fake->shouldThrowException('Gateway timeout', 504);

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('Gateway timeout');

        $this->fake->purchase(new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));
    }

    public function test_should_throw_resets_after_one_call(): void
    {
        $this->fake->shouldThrowException('Error simulado');

        try {
            $this->fake->purchase(new PaymentRequest(
                amount: 100.00,
                currency: 'USD',
                email: 'test@test.com',
                description: 'Test'
            ));
        } catch (PaymentGatewayException) {
            // expected
        }

        $response = $this->fake->purchase(new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));

        $this->assertTrue($response->success);
    }

    public function test_assert_called(): void
    {
        $this->fake->purchase(new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));

        $this->fake->assertCalled('purchase');
        $this->addToAssertionCount(1);
    }

    public function test_assert_called_fails_when_not_called(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->fake->assertCalled('purchase');
    }

    public function test_assert_called_count(): void
    {
        $dto = new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        );

        $this->fake->purchase($dto);
        $this->fake->purchase($dto);

        $this->fake->assertCalledCount('purchase', 2);
        $this->addToAssertionCount(1);
    }

    public function test_assert_called_count_fails_on_mismatch(): void
    {
        $dto = new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        );

        $this->fake->purchase($dto);

        $this->expectException(\RuntimeException::class);
        $this->fake->assertCalledCount('purchase', 3);
    }

    public function test_assert_not_called(): void
    {
        $this->fake->assertNotCalled('purchase');
        $this->addToAssertionCount(1);
    }

    public function test_assert_not_called_fails_when_called(): void
    {
        $this->fake->purchase(new PaymentRequest(
            amount: 100.00,
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));

        $this->expectException(\RuntimeException::class);
        $this->fake->assertNotCalled('purchase');
    }

    public function test_health_returns_true(): void
    {
        $this->assertTrue($this->fake->health());
    }

    public function test_refund_returns_true(): void
    {
        $this->assertTrue($this->fake->refund('txn-1'));
    }

    public function test_payout_returns_response(): void
    {
        $response = $this->fake->payout(new \Abitech\Payments\DTO\PayoutRequest(
            amount: 100.00,
            currency: 'USD',
            recipient: 'user@test.com',
            description: 'Payout'
        ));

        $this->assertTrue($response->success);
    }

    public function test_handle_webhook_returns_result(): void
    {
        $request = \Illuminate\Http\Request::create('/webhook', 'POST', [
            'type' => 'payment',
            'data' => ['id' => '123'],
        ]);

        $result = $this->fake->handleWebhook($request);
        $this->assertSame('completed', $result->status);
    }
}
