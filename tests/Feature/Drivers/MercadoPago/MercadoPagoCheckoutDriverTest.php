<?php

declare(strict_types=1);

namespace Abitech\Payments\Tests\Feature\Drivers\MercadoPago;

use Abitech\Payments\Drivers\MercadoPago\MercadoPagoCheckoutDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\PaymentManager;
use Abitech\Payments\Tests\Doubles\FakeMercadoPagoHttpClient;
use Abitech\Payments\Tests\TestCase;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class MercadoPagoCheckoutDriverTest extends TestCase
{
    private const WEBHOOK_SECRET = 'secreto-mp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'abitech_payments.gateways.mercadopago' => ['access_token' => 'TEST-token', 'webhook_secret' => self::WEBHOOK_SECRET],
            'abitech_payments.retry.base_delay_ms' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        FakeMercadoPagoHttpClient::uninstall();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_purchase_dispatches_no_events(): void
    {
        $this->fakePackageEvents();
        FakeMercadoPagoHttpClient::install([[201, ['id' => 'pref_1', 'init_point' => 'https://www.mercadopago.com.pe/checkout/pref_1']]]);

        $this->driver()->purchase(new PaymentRequest(
            amount: '50.00',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
        ));

        Event::assertNothingDispatched();
    }

    public function test_health_retries_a_503(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([
            [503, ['message' => 'Servicio no disponible']],
            [404, ['message' => 'Payment not found']],
        ]);

        $this->assertTrue($this->driver()->health());
        $this->assertCount(2, $mercadoPago->requests);
    }

    public function test_a_missing_sdk_asks_for_the_exact_composer_require(): void
    {
        $transport = FakeMercadoPagoHttpClient::install([]);
        $driver = new class(['access_token' => 'TEST-token']) extends MercadoPagoCheckoutDriver {
            protected const SDK_CLASS = 'MercadoPago\\ClaseAusente';
        };

        try {
            $driver->health();
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame([], $transport->requests);
            $this->assertStringContainsString('composer require mercadopago/dx-php', $exception->getMessage());
        }
    }

    public function test_purchase_creates_a_preference_and_returns_its_checkout_url(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preference()]]);

        $response = $this->driver()->purchase(self::checkout());

        $this->assertTrue($response->success);
        $this->assertSame('pending', $response->status);
        $this->assertSame('pref_1', $response->transactionId);
        $this->assertSame('https://www.mercadopago.com.pe/checkout/pref_1', $response->redirectUrl);
        $this->assertStringContainsString('"external_reference":"k-1"', $mercadoPago->requests[0]->getPayload());
        $this->assertStringContainsString('"unit_price":50.5', $mercadoPago->requests[0]->getPayload());
    }

    public function test_each_item_reaches_the_preference_with_its_quantity(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preference()]]);

        $this->driver()->purchase(self::checkoutWithItems([
            ['name' => 'Licencia', 'price' => '20.00', 'quantity' => 2],
            ['name' => 'Soporte', 'price' => '10.50'],
        ]));

        $items = collect(json_decode($mercadoPago->requests[0]->getPayload(), true)['items']);
        $this->assertSame(['k-1-1', 'k-1-2'], $items->pluck('id')->all());
        $this->assertSame([2, 1], $items->pluck('quantity')->all());
        $this->assertSame([20, 10.5], $items->pluck('unit_price')->all());
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>}>
     */
    public static function invalidItems(): array
    {
        return [
            'items adding up to another amount' => [[
                ['name' => 'Licencia', 'price' => '20.00', 'quantity' => 2],
                ['name' => 'Soporte', 'price' => '10.00'],
            ]],
            'zero quantity' => [[['name' => 'Licencia', 'price' => '50.50', 'quantity' => 0]]],
            'float quantity' => [[['name' => 'Licencia', 'price' => '25.25', 'quantity' => 2.0]]],
            'quantity as text' => [[['name' => 'Licencia', 'price' => '25.25', 'quantity' => '2']]],
            'float price' => [[['name' => 'Licencia', 'price' => 50.5]]],
            'missing price' => [[['name' => 'Licencia']]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    #[DataProvider('invalidItems')]
    public function test_invalid_items_are_rejected_before_authenticating_or_throttling(array $items): void
    {
        config(['abitech_payments.gateways.mercadopago' => []]);
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preference()]]);

        try {
            $this->driver()->purchase(self::checkoutWithItems($items));
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(422, $exception->getCode());
        }

        $this->assertSame([], $mercadoPago->requests);
        $this->assertSame(0, $this->app->make(RateLimiter::class)->attempts('abitech_payments:mercadopago_checkout:purchase'));
    }

    public function test_items_without_idempotency_key_share_a_generated_id_prefix(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preference()]]);

        $this->driver()->purchase(self::checkoutWithItems([
            ['name' => 'Licencia', 'price' => '40.00'],
            ['name' => 'Soporte', 'price' => '10.50'],
        ], idempotencyKey: null));

        [$first, $second] = collect(json_decode($mercadoPago->requests[0]->getPayload(), true)['items'])->pluck('id')->all();
        $this->assertMatchesRegularExpression('/^[0-9a-f.]+-1$/', $first);
        $this->assertSame(Str::beforeLast($first, '-') . '-2', $second);
    }

    public function test_a_full_refund_sends_an_empty_body(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, ['id' => 1, 'status' => 'approved']]]);

        $this->assertTrue($this->driver()->refund('123'));
        $this->assertSame('[]', $mercadoPago->requests[0]->getPayload());
    }

    public function test_a_pending_payout_is_not_successful(): void
    {
        FakeMercadoPagoHttpClient::install([[201, ['id' => 125, 'status' => 'in_process']]]);

        $response = $this->driver()->payout(self::payout());

        $this->assertFalse($response->success);
        $this->assertSame('pending', $response->status);
    }

    public function test_create_subscription_sends_the_preapproval(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preapproval()]]);

        $response = $this->driver()->createSubscription(self::subscription());

        $this->assertSame('pre_1', $response->subscriptionId);
        $this->assertSame('pending', $response->status);
        $this->assertSame('2026-10-15T00:00:00.000-05:00', $response->nextBillingDate);
        $payload = json_decode($mercadoPago->requests[0]->getPayload(), true);
        $this->assertSame('months', $payload['auto_recurring']['frequency_type']);
        $this->assertSame(49.9, $payload['auto_recurring']['transaction_amount']);
    }

    public function test_create_subscription_sends_the_reference_and_returns_the_init_point(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preapproval()]]);

        $response = $this->driver()->createSubscription(new SubscriptionRequest(
            planId: 'plan-mensual',
            email: 'cliente@example.com',
            amount: '49.90',
            currency: 'PEN',
            externalReference: 'sub-uuid',
            successUrl: 'https://cuenta.example.com/checkout/vuelta',
        ));

        $payload = json_decode($mercadoPago->requests[0]->getPayload(), true);
        $this->assertSame('sub-uuid', $payload['external_reference']);
        $this->assertSame('https://cuenta.example.com/checkout/vuelta', $payload['back_url']);
        $this->assertSame('pending', $payload['status']);
        $this->assertArrayNotHasKey('card_token_id', $payload);
        $this->assertSame('https://www.mercadopago.com.pe/subscriptions/checkout?preapproval_id=pre_1', $response->redirectUrl);
        $this->assertSame('sub-uuid', $response->externalReference);
    }

    public function test_a_card_token_creates_an_authorized_preapproval(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, [...self::preapproval(), 'status' => 'authorized']]]);

        $response = $this->driver()->createSubscription(new SubscriptionRequest(
            planId: 'plan-mensual',
            email: 'cliente@example.com',
            cardToken: 'tok_1',
            amount: '49.90',
            currency: 'PEN',
        ));

        $payload = json_decode($mercadoPago->requests[0]->getPayload(), true);
        $this->assertSame('tok_1', $payload['card_token_id']);
        $this->assertSame('authorized', $payload['status']);
        $this->assertSame('authorized', $response->status);
    }

    public function test_a_trial_moves_the_first_debit_to_its_end(): void
    {
        Carbon::setTestNow('2026-10-29T15:00:00Z');
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preapproval()]]);

        $this->driver()->createSubscription(new SubscriptionRequest(
            planId: 'plan-mensual',
            email: 'cliente@example.com',
            amount: '49.90',
            currency: 'PEN',
            trialDays: 14,
        ));

        $payload = json_decode($mercadoPago->requests[0]->getPayload(), true);
        $this->assertSame('2026-11-12T15:00:00.000Z', $payload['auto_recurring']['start_date']);
        $this->assertSame('2028-10-29T15:00:00.000Z', $payload['auto_recurring']['end_date']);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: string}>
     */
    public static function frequencies(): array
    {
        return [
            'día' => ['day', 3, 3, 'days'],
            'semana en días' => ['week', 2, 14, 'days'],
            'mes' => ['month', 3, 3, 'months'],
            'año en meses' => ['year', 1, 12, 'months'],
        ];
    }

    #[DataProvider('frequencies')]
    public function test_the_interval_is_sent_in_the_units_mercado_pago_accepts(string $interval, int $count, int $frequency, string $type): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[201, self::preapproval()]]);

        $this->driver()->createSubscription(new SubscriptionRequest(
            planId: 'plan',
            email: 'cliente@example.com',
            interval: $interval,
            intervalCount: $count,
            amount: '10.00',
            currency: 'PEN',
        ));

        $payload = json_decode($mercadoPago->requests[0]->getPayload(), true);
        $this->assertSame($frequency, $payload['auto_recurring']['frequency']);
        $this->assertSame($type, $payload['auto_recurring']['frequency_type']);
    }

    public function test_get_subscription_returns_the_reference_and_the_init_point(): void
    {
        FakeMercadoPagoHttpClient::install([[200, [...self::preapproval(), 'status' => 'authorized']]]);

        $response = $this->driver()->getSubscription('pre_1');

        $this->assertSame('authorized', $response->status);
        $this->assertSame('sub-uuid', $response->externalReference);
        $this->assertSame('https://www.mercadopago.com.pe/subscriptions/checkout?preapproval_id=pre_1', $response->redirectUrl);
    }

    public function test_cancel_subscription_marks_the_preapproval_cancelled(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[200, ['id' => 'pre_1', 'status' => 'cancelled', 'reason' => 'plan-mensual']]]);

        $response = $this->driver()->cancelSubscription('pre_1');

        $this->assertSame('cancelled', $response->status);
        $this->assertSame('plan-mensual', $response->planId);
        $this->assertNotNull($response->canceledAt);
        $this->assertSame('{"status":"cancelled"}', $mercadoPago->requests[0]->getPayload());
    }

    public function test_update_subscription_without_amount_only_reads_the_preapproval(): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[200, self::preapproval()]]);

        $this->driver()->updateSubscription('pre_1', new SubscriptionRequest(planId: 'plan-mensual', email: 'cliente@example.com'));

        $this->assertSame('GET', $mercadoPago->requests[0]->getMethod());
    }

    public function test_get_subscription_reads_the_plan_from_the_reason(): void
    {
        FakeMercadoPagoHttpClient::install([[200, self::preapproval()]]);

        $response = $this->driver()->getSubscription('pre_1');

        $this->assertSame('plan-mensual', $response->planId);
    }

    /**
     * @return array<string, array{0: Closure(MercadoPagoCheckoutDriver): mixed, 1: array<string, mixed>}>
     */
    public static function operations(): array
    {
        return [
            'purchase' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->purchase(self::checkout()), self::preference()],
            'refund' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->refund('123'), ['id' => 1, 'status' => 'approved']],
            'payout' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->payout(self::payout()), ['id' => 125, 'status' => 'approved']],
            'createSubscription' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->createSubscription(self::subscription()), self::preapproval()],
            'cancelSubscription' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->cancelSubscription('pre_1'), self::preapproval()],
            'updateSubscription' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->updateSubscription('pre_1', self::subscription()), self::preapproval()],
            'getSubscription' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->getSubscription('pre_1'), self::preapproval()],
            'findPayment' => [static fn (MercadoPagoCheckoutDriver $driver) => $driver->findPayment(externalReference: 'ref-1'), self::searchResults([self::payment(1, 'approved')])],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paymentStatuses(): array
    {
        return [
            'approved' => ['approved', 'completed'],
            'pending' => ['pending', 'pending'],
            'rejected' => ['rejected', 'failed'],
        ];
    }

    #[DataProvider('paymentStatuses')]
    public function test_find_payment_searches_the_checkout_reference(string $gatewayStatus, string $status): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[200, self::searchResults([self::payment(7, $gatewayStatus)])]]);

        $result = $this->driver()->findPayment(externalReference: 'ref-1');

        $this->assertSame(
            '/v1/payments/search?external_reference=ref-1&sort=date_created&criteria=desc',
            $mercadoPago->requests[0]->getUri()
        );
        $this->assertSame($status, $result->status);
        $this->assertSame('7', $result->transactionId);
        $this->assertSame('50.50', $result->amount);
        $this->assertSame('PEN', $result->currency);
        $this->assertSame('mercadopago_checkout', $result->gateway);
        $this->assertSame('ref-1', $result->raw['external_reference']);
    }

    public function test_find_payment_prefers_the_approved_payment_of_the_reference(): void
    {
        FakeMercadoPagoHttpClient::install([[200, self::searchResults([
            self::payment(3, 'rejected', '2026-10-26T12:00:00.000-05:00'),
            self::payment(2, 'approved', '2026-10-26T11:00:00.000-05:00'),
            self::payment(1, 'rejected', '2026-10-26T10:00:00.000-05:00'),
        ])]]);

        $result = $this->driver()->findPayment(externalReference: 'ref-1');

        $this->assertSame('2', $result->transactionId);
        $this->assertSame('completed', $result->status);
    }

    public function test_find_payment_without_an_approved_payment_returns_the_latest(): void
    {
        FakeMercadoPagoHttpClient::install([[200, self::searchResults([
            self::payment(1, 'rejected', '2026-10-26T10:00:00.000-05:00'),
            self::payment(3, 'in_process', '2026-10-26T12:00:00.000-05:00'),
            self::payment(2, 'rejected', '2026-10-26T11:00:00.000-05:00'),
        ])]]);

        $result = $this->driver()->findPayment(externalReference: 'ref-1');

        $this->assertSame('3', $result->transactionId);
        $this->assertSame('pending', $result->status);
    }

    public function test_find_payment_returns_null_when_the_reference_has_no_payments(): void
    {
        FakeMercadoPagoHttpClient::install([[200, self::searchResults([])]]);

        $this->assertNull($this->driver()->findPayment(externalReference: 'ref-1'));
    }

    public function test_find_payment_returns_null_for_an_unknown_payment_id(): void
    {
        FakeMercadoPagoHttpClient::install([[404, ['message' => 'Payment not found', 'status' => 404]]]);

        $this->assertNull($this->driver()->findPayment('999'));
    }

    /**
     * @return array<string, array{0: Closure(MercadoPagoCheckoutDriver): mixed}>
     */
    public static function operationClosures(): array
    {
        return array_map(static fn (array $case) => [$case[0]], self::operations());
    }

    #[DataProvider('operationClosures')]
    public function test_a_client_error_is_surfaced_without_retrying(Closure $operation): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[400, ['message' => 'Solicitud invalida']]]);

        try {
            $operation($this->driver());
            $this->fail('Se esperaba PaymentGatewayException.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame(400, $exception->getCode());
        }

        $this->assertCount(1, $mercadoPago->requests);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('operations')]
    public function test_a_503_is_retried(Closure $operation, array $body): void
    {
        $mercadoPago = FakeMercadoPagoHttpClient::install([[503, ['message' => 'Servicio no disponible']], [200, $body]]);

        $operation($this->driver());

        $this->assertCount(2, $mercadoPago->requests);
    }

    public function test_a_signed_payment_webhook_is_normalized(): void
    {
        FakeMercadoPagoHttpClient::install([[200, ['id' => 123, 'status' => 'refunded', 'transaction_amount' => 50.5, 'currency_id' => 'PEN']]]);
        $hash = hash_hmac('sha256', 'id:123;request-id:req-1;ts:1700000000;', self::WEBHOOK_SECRET);
        $request = Request::create('/webhook', 'POST', ['type' => 'payment', 'data' => ['id' => '123']]);
        $request->headers->add(['x-signature' => "ts=1700000000,v1={$hash}", 'x-request-id' => 'req-1']);

        $result = $this->driver()->handleWebhook($request);

        $this->assertSame('refunded', $result->status);
        $this->assertSame('50.50', $result->amount);
        $this->assertSame('mercadopago_checkout', $result->gateway);
    }

    private static function checkout(): PaymentRequest
    {
        return new PaymentRequest(
            amount: '50.50',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            idempotencyKey: 'k-1',
            successUrl: 'https://app.example.com/ok',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private static function checkoutWithItems(array $items, ?string $idempotencyKey = 'k-1'): PaymentRequest
    {
        return new PaymentRequest(
            amount: '50.50',
            currency: 'PEN',
            email: 'cliente@example.com',
            description: 'Plan mensual',
            idempotencyKey: $idempotencyKey,
            successUrl: 'https://app.example.com/ok',
            metadata: ['items' => $items],
        );
    }

    private static function payout(): PayoutRequest
    {
        return new PayoutRequest(amount: '20.50', currency: 'PEN', recipient: 'vendedor@example.com', description: 'Liquidacion');
    }

    private static function subscription(): SubscriptionRequest
    {
        return new SubscriptionRequest(planId: 'plan-mensual', email: 'cliente@example.com', amount: '49.90', currency: 'PEN');
    }

    /**
     * @return array<string, mixed>
     */
    private static function preference(): array
    {
        return ['id' => 'pref_1', 'init_point' => 'https://www.mercadopago.com.pe/checkout/pref_1'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function preapproval(): array
    {
        return [
            'id' => 'pre_1',
            'status' => 'pending',
            'reason' => 'plan-mensual',
            'external_reference' => 'sub-uuid',
            'init_point' => 'https://www.mercadopago.com.pe/subscriptions/checkout?preapproval_id=pre_1',
            'auto_recurring' => ['start_date' => '2026-10-15T00:00:00.000-05:00'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payment(int $id, string $status, string $dateCreated = '2026-10-26T10:00:00.000-05:00'): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'external_reference' => 'ref-1',
            'date_created' => $dateCreated,
            'transaction_amount' => 50.5,
            'currency_id' => 'PEN',
            'payment_method_id' => 'visa',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    private static function searchResults(array $payments): array
    {
        return [
            'paging' => ['total' => count($payments), 'limit' => 30, 'offset' => 0],
            'results' => $payments,
        ];
    }

    private function driver(): MercadoPagoCheckoutDriver
    {
        return $this->app->make(PaymentManager::class)->driver('mercadopago_checkout');
    }
}
