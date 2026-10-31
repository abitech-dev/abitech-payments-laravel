<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature;

use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\SubscriptionCharge;
use Abitech\Payments\Drivers\Testing\FakePaymentDriver;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Tests\TestCase;
use Illuminate\Http\Request;
use RuntimeException;

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
            amount: '100.00',
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
            amount: '100.00',
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
            amount: '100.00',
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
                amount: '100.00',
                currency: 'USD',
                email: 'test@test.com',
                description: 'Test'
            ));
        } catch (PaymentGatewayException) {
            // expected
        }

        $response = $this->fake->purchase(new PaymentRequest(
            amount: '100.00',
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));

        $this->assertTrue($response->success);
    }

    public function test_assert_called(): void
    {
        $this->fake->purchase(new PaymentRequest(
            amount: '100.00',
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));

        $this->fake->assertCalled('purchase');
        $this->addToAssertionCount(1);
    }

    public function test_assert_called_fails_when_not_called(): void
    {
        $this->expectException(RuntimeException::class);
        $this->fake->assertCalled('purchase');
    }

    public function test_assert_called_count(): void
    {
        $dto = new PaymentRequest(
            amount: '100.00',
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
            amount: '100.00',
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        );

        $this->fake->purchase($dto);

        $this->expectException(RuntimeException::class);
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
            amount: '100.00',
            currency: 'USD',
            email: 'test@test.com',
            description: 'Test'
        ));

        $this->expectException(RuntimeException::class);
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

    public function test_a_partial_refund_records_the_decimal_amount(): void
    {
        $this->fake->refund('txn-1', '10.50');

        $this->fake->assertCalled('refund', fn (string $id, ?string $amount) => $amount === '10.50');
        $this->addToAssertionCount(1);
    }

    public function test_a_refund_amount_that_is_not_a_decimal_string_is_rejected(): void
    {
        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionCode(422);

        $this->fake->refund('txn-1', '10,50');
    }

    public function test_payout_returns_response(): void
    {
        $response = $this->fake->payout(new PayoutRequest(
            amount: '100.00',
            currency: 'USD',
            recipient: 'user@test.com',
            description: 'Payout'
        ));

        $this->assertTrue($response->success);
    }

    public function test_handle_webhook_returns_result(): void
    {
        $request = Request::create('/webhook', 'POST', [
            'type' => 'payment',
            'data' => ['id' => '123'],
        ]);

        $result = $this->fake->handleWebhook($request);
        $this->assertSame('completed', $result->status);
        $this->assertSame('100.00', $result->amount);
    }

    public function test_find_payment_returns_a_completed_payment_by_default(): void
    {
        $result = $this->fake->findPayment('txn-1');

        $this->assertSame('completed', $result->status);
        $this->assertSame('txn-1', $result->transactionId);
        $this->assertSame('100.00', $result->amount);
    }

    public function test_find_payment_records_the_id_and_the_reference(): void
    {
        $this->fake->findPayment(externalReference: 'ref-1');

        $this->fake->assertCalled('findPayment', fn (?string $id, ?string $reference) => $id === null && $reference === 'ref-1');
        $this->addToAssertionCount(1);
    }

    public function test_find_payment_can_report_a_missing_payment(): void
    {
        $this->fake->shouldReturn('findPayment', null);

        $this->assertNull($this->fake->findPayment('txn-1'));
    }

    public function test_find_payment_without_id_or_reference_is_rejected(): void
    {
        try {
            $this->fake->findPayment();
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(422, $exception->getCode());
        }

        $this->fake->assertNotCalled('findPayment');
        $this->addToAssertionCount(1);
    }

    public function test_find_payment_can_simulate_a_gateway_error(): void
    {
        $this->fake->shouldThrowException('Servicio no disponible', 503);

        try {
            $this->fake->findPayment('txn-1');
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(503, $exception->getCode());
        }
    }

    public function test_subscription_charges_are_empty_by_default_and_can_be_configured(): void
    {
        $this->assertSame([], $this->fake->subscriptionCharges('sub-1'));

        $charge = new SubscriptionCharge(chargeId: 'charge-1', subscriptionId: 'sub-1', status: 'completed', paymentId: 'txn-1');
        $this->fake->shouldReturn('subscriptionCharges', [$charge]);
        $this->fake->shouldReturn('findSubscriptionCharge', $charge);

        $this->assertSame([$charge], $this->fake->subscriptionCharges('sub-1'));
        $this->assertSame($charge, $this->fake->findSubscriptionCharge('charge-1'));
        $this->fake->assertCalled('findSubscriptionCharge', fn (string $chargeId): bool => $chargeId === 'charge-1');
    }

    public function test_an_unknown_subscription_charge_is_null_by_default(): void
    {
        $this->assertNull($this->fake->findSubscriptionCharge('charge-9'));
    }
}
