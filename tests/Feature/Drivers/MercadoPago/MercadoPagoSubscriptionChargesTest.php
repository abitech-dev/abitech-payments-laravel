<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\MercadoPago;

use Abitech\Payments\Contracts\SubscriptionChargesInterface;
use Abitech\Payments\Drivers\MercadoPago\MercadoPagoCheckoutDriver;
use Abitech\Payments\DTO\SubscriptionCharge;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeMercadoPagoHttpClient;
use Abitech\Payments\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MercadoPagoSubscriptionChargesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'abitech_payments.gateways.mercadopago' => ['access_token' => 'TEST-token'],
            'abitech_payments.retry.base_delay_ms' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        FakeMercadoPagoHttpClient::uninstall();

        parent::tearDown();
    }

    public function test_the_checkout_driver_reads_subscription_charges(): void
    {
        $this->assertInstanceOf(SubscriptionChargesInterface::class, $this->driver());
    }

    public function test_subscription_charges_search_the_authorized_payments_of_the_preapproval(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[200, [
            'paging' => ['total' => 2, 'limit' => 50, 'offset' => 0],
            'results' => [
                self::authorizedPayment(7001, '2026-10-29T15:00:00.000-04:00', 'processed', ['id' => 9001, 'status' => 'approved', 'status_detail' => 'accredited']),
                self::authorizedPayment(7002, '2026-11-29T15:00:00.000-04:00', 'scheduled', null),
            ],
        ]]]);

        $charges = $this->driver()->subscriptionCharges('pre_1');

        $this->assertSame('GET', $mercadoPago->requests[0]->getMethod());
        $this->assertStringContainsString('/authorized_payments/search', $mercadoPago->requests[0]->getUri());
        $this->assertStringContainsString('preapproval_id=pre_1', $mercadoPago->requests[0]->getUri());
        $this->assertStringContainsString('limit=12', $mercadoPago->requests[0]->getUri());
        $this->assertCount(1, $mercadoPago->requests);
        $this->assertSame(['7002', '7001'], array_map(fn (SubscriptionCharge $charge): string => $charge->chargeId, $charges));
        $this->assertSame('scheduled', $charges[0]->status);
        $this->assertNull($charges[0]->paymentId);
        $this->assertSame('completed', $charges[1]->status);
        $this->assertSame('9001', $charges[1]->paymentId);
        $this->assertSame('pre_1', $charges[1]->subscriptionId);
        $this->assertSame('29.90', $charges[1]->amount);
        $this->assertSame('PEN', $charges[1]->currency);
        $this->assertSame('2026-10-29T19:00:00+00:00', $charges[1]->debitDate);
        $this->assertNull($charges[1]->payment);
    }

    public function test_subscription_charges_read_every_page_of_twelve(): void
    {
        $first = array_map(fn (int $month): array => self::authorizedPayment(7000 + $month, sprintf('2025-%02d-29T15:00:00.000-04:00', $month), 'processed', ['id' => 9000 + $month, 'status' => 'approved']), range(1, 12));
        $mercadoPago = FakeMercadoPagoHttpClient::install([
            [200, ['paging' => ['total' => 13, 'limit' => 12, 'offset' => 0], 'results' => $first]],
            [200, ['paging' => ['total' => 13, 'limit' => 12, 'offset' => 12], 'results' => [self::authorizedPayment(7013, '2026-01-29T15:00:00.000-04:00', 'scheduled', null)]]],
        ]);

        $charges = $this->driver()->subscriptionCharges('pre_1');

        $this->assertCount(13, $charges);
        $this->assertSame('7013', $charges[0]->chargeId);
        $this->assertStringContainsString('offset=12', $mercadoPago->requests[1]->getUri());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>|null, 2: string}>
     */
    public static function chargeStatuses(): array
    {
        return [
            'aprobado' => ['processed', ['id' => 9001, 'status' => 'approved'], 'completed'],
            'rechazado en reciclaje' => ['recycling', ['id' => 9001, 'status' => 'rejected'], 'failed'],
            'en proceso' => ['processed', ['id' => 9001, 'status' => 'in_process'], 'pending'],
            'programado' => ['scheduled', null, 'scheduled'],
            'cancelado' => ['cancelled', null, 'canceled'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payment
     */
    #[DataProvider('chargeStatuses')]
    public function test_each_charge_status_is_normalized(string $status, ?array $payment, string $normalized): void
    {
        FakeMercadoPagoHttpClient::install([[200, ['results' => [self::authorizedPayment(7001, '2026-10-29T15:00:00.000-04:00', $status, $payment)]]]]);

        $this->assertSame($normalized, $this->driver()->subscriptionCharges('pre_1')[0]->status);
    }

    public function test_find_subscription_charge_reads_the_authorized_payment_and_then_its_payment(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([
            [200, self::authorizedPayment(7001, '2026-10-29T15:00:00.000-04:00', 'processed', ['id' => 9001, 'status' => 'approved'])],
            [200, ['id' => 9001, 'status' => 'approved', 'status_detail' => 'accredited', 'transaction_amount' => 29.9, 'currency_id' => 'PEN', 'payment_method_id' => 'visa']],
        ]);

        $charge = $this->driver()->findSubscriptionCharge('7001');

        $this->assertStringEndsWith('/authorized_payments/7001', $mercadoPago->requests[0]->getUri());
        $this->assertStringEndsWith('/v1/payments/9001', $mercadoPago->requests[1]->getUri());
        $this->assertSame('7001', $charge->chargeId);
        $this->assertSame('completed', $charge->status);
        $this->assertSame('9001', $charge->payment->transactionId);
        $this->assertSame('completed', $charge->payment->status);
        $this->assertSame('29.90', $charge->payment->amount);
        $this->assertSame('accredited', $charge->payment->raw['status_detail']);
    }

    public function test_a_charge_without_payment_is_read_without_a_second_request(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[200, self::authorizedPayment(7002, '2026-11-29T15:00:00.000-04:00', 'scheduled', null)]]);

        $charge = $this->driver()->findSubscriptionCharge('7002');

        $this->assertCount(1, $mercadoPago->requests);
        $this->assertSame('scheduled', $charge->status);
        $this->assertNull($charge->payment);
    }

    public function test_find_subscription_charge_returns_null_for_an_unknown_charge(): void
    {
        FakeMercadoPagoHttpClient::install([[404, ['message' => 'not found']]]);

        $this->assertNull($this->driver()->findSubscriptionCharge('7009'));
    }

    public function test_a_non_numeric_charge_id_is_rejected_before_authenticating(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([]);

        try {
            $this->driver()->findSubscriptionCharge('pre_1');
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $e) {
            $this->assertSame(422, $e->getCode());
        }

        $this->assertSame([], $mercadoPago->requests);
    }

    public function test_get_subscription_returns_the_recurring_amount(): void
    {
        FakeMercadoPagoHttpClient::install([[200, self::preapproval()]]);

        $response = $this->driver()->getSubscription('pre_1');

        $this->assertSame('29.90', $response->amount);
        $this->assertSame('PEN', $response->currency);
    }

    public function test_update_subscription_puts_the_amount_and_returns_the_preapproval(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[200, [...self::preapproval(), 'auto_recurring' => ['transaction_amount' => 35.5, 'currency_id' => 'PEN']]]]);

        $response = $this->driver()->updateSubscription('pre_1', new SubscriptionRequest(planId: 'plan-mensual', email: 'cliente@example.com', amount: '35.50'));

        $this->assertSame('PUT', $mercadoPago->requests[0]->getMethod());
        $this->assertSame(['auto_recurring' => ['transaction_amount' => 35.5]], json_decode($mercadoPago->requests[0]->getPayload(), true));
        $this->assertSame('35.50', $response->amount);
        $this->assertSame('2026-11-29T15:00:00.000-04:00', $response->nextBillingDate);
    }

    /**
     * @param  array<string, mixed>|null  $payment
     * @return array<string, mixed>
     */
    private static function authorizedPayment(int $id, string $debitDate, string $status, ?array $payment): array
    {
        return [
            'id' => $id,
            'type' => 'scheduled',
            'preapproval_id' => 'pre_1',
            'external_reference' => 'sub-uuid',
            'currency_id' => 'PEN',
            'transaction_amount' => 29.9,
            'debit_date' => $debitDate,
            'retry_attempt' => 0,
            'status' => $status,
            'payment' => $payment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function preapproval(): array
    {
        return [
            'id' => 'pre_1',
            'status' => 'authorized',
            'reason' => 'plan-mensual',
            'external_reference' => 'sub-uuid',
            'next_payment_date' => '2026-11-29T15:00:00.000-04:00',
            'auto_recurring' => ['transaction_amount' => 29.9, 'currency_id' => 'PEN'],
        ];
    }

    private function driver(): MercadoPagoCheckoutDriver
    {
        return $this->app->make(PaymentManager::class)->driver('mercadopago_checkout');
    }
}
