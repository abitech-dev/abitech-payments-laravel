<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\Testing;

use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\DTO\SubscriptionResponse;
use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Http\Request;

/**
 * Driver falso para testing que no requiere SDKs ni conexion a internet.
 *
 * Permite simular respuestas de cualquier pasarela con API fluida:
 *
 *   $fake = $manager->driver('fake');
 *   $fake->shouldReturn('purchase', new PaymentResponse(...));
 *   $fake->shouldThrowException('Error simulado', 500);
 *
 *   $dto = new PaymentRequest(...);
 *   $response = $fake->purchase($dto);
 *   $fake->assertCalled('purchase');
 *   $fake->assertCalledCount('purchase', 1);
 *   $fake->assertNotCalled('refund');
 */
class FakePaymentDriver extends AbstractPaymentDriver implements SubscriptionInterface
{
    protected array $supportedCurrencies = ['USD', 'EUR', 'PEN'];

    /** Respuestas pre-configuradas por metodo. */
    protected array $responses = [];

    /** Registro de llamadas [metodo => [args, ...]]. */
    protected array $calls = [];

    /** Si true, el siguiente metodo lanzara excepcion. */
    protected bool $shouldThrow = false;

    protected string $throwMessage = 'Error simulado en modo testing.';

    protected int $throwCode = 500;

    public function getGatewayName(): string
    {
        return 'fake';
    }

    /** Retorna true siempre. No hace llamada HTTP. */
    public function health(): bool
    {
        $this->recordCall('health', []);
        return $this->getResponse('health', true);
    }

    // ─── API fluida de configuracion ─────────────────────────────────────

    /**
     * Hace que la siguiente llamada a cualquier metodo lance excepcion.
     */
    public function shouldThrowException(string $message = '', int $code = 500): static
    {
        $this->shouldThrow = true;
        $this->throwMessage = $message ?: 'Error simulado en modo testing.';
        $this->throwCode = $code;
        return $this;
    }

    /**
     * Define la respuesta que retornara un metodo especifico.
     * Solo afecta la siguiente llamada.
     */
    public function shouldReturn(string $method, mixed $response): static
    {
        $this->responses[$method] = $response;
        $this->shouldThrow = false;
        return $this;
    }

    // ─── Asserciones ─────────────────────────────────────────────────────

    /**
     * Verifica que un metodo fue llamado al menos una vez.
     * Opcionalmente valida los argumentos con un callback.
     */
    public function assertCalled(string $method, ?callable $callback = null): static
    {
        $calls = $this->calls[$method] ?? [];

        if (empty($calls)) {
            throw new \PHPUnit\Framework\AssertionFailedError(
                "Se esperaba que '{$method}' fuera llamado, pero no se registro ninguna llamada."
            );
        }

        if ($callback !== null) {
            foreach ($calls as $args) {
                if ($callback(...$args)) {
                    return $this;
                }
            }

            throw new \PHPUnit\Framework\AssertionFailedError(
                "Se encontro llamada a '{$method}' pero los argumentos no coinciden."
            );
        }

        return $this;
    }

    /**
     * Verifica el conteo exacto de llamadas a un metodo.
     */
    public function assertCalledCount(string $method, int $count): static
    {
        $calls = $this->calls[$method] ?? [];
        $actual = count($calls);

        if ($actual !== $count) {
            throw new \PHPUnit\Framework\AssertionFailedError(
                "Se esperaba que '{$method}' fuera llamado {$count} veces, pero se llamo {$actual}."
            );
        }

        return $this;
    }

    /**
     * Verifica que un metodo NO fue llamado.
     */
    public function assertNotCalled(string $method): static
    {
        if (!empty($this->calls[$method])) {
            throw new \PHPUnit\Framework\AssertionFailedError(
                "No se esperaba que '{$method}' fuera llamado, pero se encontro al menos una llamada."
            );
        }

        return $this;
    }

    // ─── PaymentGatewayInterface ─────────────────────────────────────────

    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->recordCall('purchase', [$request]);
        $this->guardThrow();

        return $this->getResponse('purchase', new PaymentResponse(
            success: true,
            transactionId: 'fake_txn_' . uniqid(),
            status: 'completed',
            redirectUrl: 'https://fake-gateway.test/checkout',
            raw: ['test' => true]
        ));
    }

    public function refund(string $transactionId, ?float $amount = null): bool
    {
        $this->recordCall('refund', [$transactionId, $amount]);
        $this->guardThrow();

        return $this->getResponse('refund', true);
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $this->recordCall('payout', [$request]);
        $this->guardThrow();

        return $this->getResponse('payout', new PayoutResponse(
            success: true,
            payoutId: 'fake_payout_' . uniqid(),
            status: 'completed',
            raw: ['test' => true]
        ));
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $this->recordCall('handleWebhook', [$request]);
        $this->guardThrow();

        return $this->getResponse('handleWebhook', new WebhookResult(
            gateway: 'fake',
            eventType: 'payment.succeeded',
            transactionId: 'fake_txn_webhook',
            status: 'completed',
            amount: 100.00,
            currency: 'USD',
            raw: $request->all()
        ));
    }

    // ─── SubscriptionInterface ───────────────────────────────────────────

    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $this->recordCall('createSubscription', [$request]);
        $this->guardThrow();

        return $this->getResponse('createSubscription', new SubscriptionResponse(
            success: true,
            subscriptionId: 'fake_sub_' . uniqid(),
            status: 'active',
            planId: $request->planId,
            nextBillingDate: date('c', strtotime('+1 month')),
            raw: ['test' => true]
        ));
    }

    public function cancelSubscription(string $subscriptionId, ?string $reason = null): SubscriptionResponse
    {
        $this->recordCall('cancelSubscription', [$subscriptionId, $reason]);
        $this->guardThrow();

        return $this->getResponse('cancelSubscription', new SubscriptionResponse(
            success: true,
            subscriptionId: $subscriptionId,
            status: 'cancelled',
            canceledAt: date('c'),
            raw: ['test' => true]
        ));
    }

    public function updateSubscription(string $subscriptionId, SubscriptionRequest $request): SubscriptionResponse
    {
        $this->recordCall('updateSubscription', [$subscriptionId, $request]);
        $this->guardThrow();

        return $this->getResponse('updateSubscription', new SubscriptionResponse(
            success: true,
            subscriptionId: $subscriptionId,
            status: 'active',
            raw: ['test' => true]
        ));
    }

    public function getSubscription(string $subscriptionId): SubscriptionResponse
    {
        $this->recordCall('getSubscription', [$subscriptionId]);
        $this->guardThrow();

        return $this->getResponse('getSubscription', new SubscriptionResponse(
            success: true,
            subscriptionId: $subscriptionId,
            status: 'active',
            nextBillingDate: date('c', strtotime('+1 month')),
            raw: ['test' => true]
        ));
    }

    // ─── Internos ────────────────────────────────────────────────────────

    protected function guardThrow(): void
    {
        if ($this->shouldThrow) {
            throw new PaymentGatewayException($this->throwMessage, $this->throwCode);
        }
    }

    protected function getResponse(string $method, mixed $default): mixed
    {
        return $this->responses[$method] ?? $default;
    }

    protected function recordCall(string $method, array $args): void
    {
        $this->calls[$method][] = $args;
    }
}
