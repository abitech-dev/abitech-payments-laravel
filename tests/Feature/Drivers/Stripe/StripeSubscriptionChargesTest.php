<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\Stripe;

use Abitech\Payments\Contracts\SubscriptionChargesInterface;
use Abitech\Payments\Drivers\Stripe\StripeCheckoutDriver;
use Abitech\Payments\DTO\SubscriptionCharge;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeStripeHttpClient;
use Abitech\Payments\Tests\Doubles\StripeWebhookRequest;
use Abitech\Payments\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StripeSubscriptionChargesTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_prueba';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'abitech_payments.gateways.stripe' => ['secret' => 'sk_test_x', 'webhook_secret' => self::WEBHOOK_SECRET],
            'abitech_payments.retry.base_delay_ms' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        FakeStripeHttpClient::uninstall();

        parent::tearDown();
    }

    public function test_the_checkout_driver_reads_subscription_charges(): void
    {
        $this->assertInstanceOf(SubscriptionChargesInterface::class, $this->driver());
    }

    public function test_subscription_charges_list_the_invoices_of_the_subscription(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, [
            'object' => 'list',
            'data' => [
                self::invoice('in_2', 'open', 1795964400, attempted: false),
                self::invoice('in_1', 'paid', 1793286000, paymentIntent: 'pi_1'),
            ],
        ]]]);

        $charges = $this->driver()->subscriptionCharges('sub_1');

        $this->assertSame('get', $stripe->requests[0]['method']);
        $this->assertStringEndsWith('/v1/invoices', $stripe->requests[0]['url']);
        $this->assertSame('sub_1', $stripe->requests[0]['params']['subscription']);
        $this->assertSame(['data.payments'], $stripe->requests[0]['params']['expand']);
        $this->assertSame(['in_2', 'in_1'], array_map(fn (SubscriptionCharge $charge): string => $charge->chargeId, $charges));
        $this->assertSame('scheduled', $charges[0]->status);
        $this->assertNull($charges[0]->paymentId);
        $this->assertSame('completed', $charges[1]->status);
        $this->assertSame('pi_1', $charges[1]->paymentId);
        $this->assertSame('sub_1', $charges[1]->subscriptionId);
        $this->assertSame('29.90', $charges[1]->amount);
        $this->assertSame('PEN', $charges[1]->currency);
        $this->assertSame('2026-10-29T15:00:00+00:00', $charges[1]->debitDate);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function invoiceStatuses(): array
    {
        return [
            'pagada' => ['paid', true, 'completed'],
            'abierta sin intento' => ['open', false, 'scheduled'],
            'abierta tras un rechazo' => ['open', true, 'failed'],
            'borrador' => ['draft', false, 'scheduled'],
            'anulada' => ['void', false, 'canceled'],
            'incobrable' => ['uncollectible', true, 'failed'],
        ];
    }

    #[DataProvider('invoiceStatuses')]
    public function test_each_invoice_status_is_normalized(string $status, bool $attempted, string $normalized): void
    {
        FakeStripeHttpClient::install([[200, ['object' => 'list', 'data' => [self::invoice('in_1', $status, 1793286000, attempted: $attempted)]]]]);

        $this->assertSame($normalized, $this->driver()->subscriptionCharges('sub_1')[0]->status);
    }

    public function test_find_subscription_charge_reads_the_invoice_and_its_payment_intent(): void
    {
        $stripe = FakeStripeHttpClient::install([
            [200, self::invoice('in_1', 'paid', 1793286000, paymentIntent: 'pi_1')],
            [200, [
                'id' => 'pi_1',
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 2990,
                'currency' => 'pen',
                'latest_charge' => [
                    'id' => 'ch_1',
                    'object' => 'charge',
                    'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']],
                ],
            ]],
        ]);

        $charge = $this->driver()->findSubscriptionCharge('in_1');

        $this->assertStringEndsWith('/v1/invoices/in_1', $stripe->requests[0]['url']);
        $this->assertSame(['payments'], $stripe->requests[0]['params']['expand']);
        $this->assertStringEndsWith('/v1/payment_intents/pi_1', $stripe->requests[1]['url']);
        $this->assertSame(['latest_charge'], $stripe->requests[1]['params']['expand']);
        $this->assertSame('completed', $charge->status);
        $this->assertSame('pi_1', $charge->payment->transactionId);
        $this->assertSame('completed', $charge->payment->status);
        $this->assertSame('29.90', $charge->payment->amount);
        $this->assertSame('PEN', $charge->payment->currency);
        $this->assertSame('4242', $charge->payment->cardLastFour);
        $this->assertSame('payment_intent', $charge->payment->raw['object']);
    }

    public function test_find_subscription_charge_returns_null_for_an_unknown_invoice(): void
    {
        FakeStripeHttpClient::install([[404, ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such invoice']]]]);

        $this->assertNull($this->driver()->findSubscriptionCharge('in_9'));
    }

    public function test_get_subscription_returns_the_recurring_amount(): void
    {
        FakeStripeHttpClient::install([[200, self::subscription(2990)]]);

        $response = $this->driver()->getSubscription('sub_1');

        $this->assertSame('29.90', $response->amount);
        $this->assertSame('PEN', $response->currency);
    }

    public function test_update_subscription_with_amount_replaces_the_item_price_without_proration(): void
    {
        $stripe = FakeStripeHttpClient::install([[200, self::subscription(2990)], [200, self::subscription(3550)]]);

        $response = $this->driver()->updateSubscription('sub_1', new SubscriptionRequest(planId: 'plan-mensual', email: 'cliente@example.com', amount: '35.50'));

        $this->assertSame('get', $stripe->requests[0]['method']);
        $this->assertSame('post', $stripe->requests[1]['method']);
        $this->assertSame('none', $stripe->requests[1]['params']['proration_behavior']);
        $this->assertSame([[
            'id' => 'si_1',
            'price_data' => [
                'currency' => 'pen',
                'product' => 'prod_1',
                'unit_amount' => 3550,
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ],
        ]], $stripe->requests[1]['params']['items']);
        $this->assertSame('35.50', $response->amount);
    }

    public function test_an_invoice_webhook_names_the_invoice_to_read(): void
    {
        FakeStripeHttpClient::install([]);

        $result = $this->driver()->handleWebhook(StripeWebhookRequest::signed('invoice.paid', ['id' => 'in_1', 'object' => 'invoice'], self::WEBHOOK_SECRET));

        $this->assertSame('invoice.paid', $result->eventType);
        $this->assertSame('in_1', $result->transactionId);
        $this->assertSame('unknown', $result->status);
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoice(string $id, string $status, int $periodEnd, bool $attempted = true, ?string $paymentIntent = null): array
    {
        return [
            'id' => $id,
            'object' => 'invoice',
            'status' => $status,
            'attempted' => $attempted,
            'billing_reason' => 'subscription_cycle',
            'currency' => 'pen',
            'amount_due' => 2990,
            'amount_paid' => $status === 'paid' ? 2990 : 0,
            'attempt_count' => $attempted ? 1 : 0,
            'period_start' => $periodEnd - 2592000,
            'period_end' => $periodEnd,
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
            'payments' => [
                'object' => 'list',
                'data' => $paymentIntent === null ? [] : [[
                    'id' => 'inpay_1',
                    'object' => 'invoice_payment',
                    'status' => 'paid',
                    'is_default' => true,
                    'payment' => ['type' => 'payment_intent', 'payment_intent' => $paymentIntent],
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function subscription(int $unitAmount): array
    {
        return [
            'id' => 'sub_1',
            'object' => 'subscription',
            'status' => 'active',
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_1',
                    'object' => 'subscription_item',
                    'quantity' => 1,
                    'current_period_end' => 1795964400,
                    'price' => [
                        'id' => 'price_1',
                        'object' => 'price',
                        'currency' => 'pen',
                        'product' => 'prod_1',
                        'unit_amount' => $unitAmount,
                        'recurring' => ['interval' => 'month', 'interval_count' => 1],
                    ],
                ]],
            ],
        ];
    }

    private function driver(): StripeCheckoutDriver
    {
        return $this->app->make(PaymentManager::class)->driver('stripe_checkout');
    }
}
