<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\MercadoPago;

use Abitech\Payments\Contracts\SubscriptionChargesInterface;
use Abitech\Payments\Contracts\SubscriptionInterface;
use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\Concerns\HandlesMercadoPagoPaymentLookup;
use Abitech\Payments\Concerns\HandlesMercadoPagoWebhook;
use Abitech\Payments\Concerns\HandlesMercadoPagoRefund;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\SubscriptionCharge;
use Abitech\Payments\DTO\SubscriptionRequest;
use Abitech\Payments\DTO\SubscriptionResponse;
use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Invoice\InvoiceClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\Net\MPSearchRequest;

class MercadoPagoCheckoutDriver extends AbstractPaymentDriver implements SubscriptionChargesInterface, SubscriptionInterface
{
    use HandlesMercadoPagoWebhook, HandlesMercadoPagoRefund, HandlesMercadoPagoPaymentLookup;

    protected array $supportedCurrencies = ['PEN', 'USD', 'BRL', 'ARS', 'MXN', 'CLP', 'COP'];

    /** Cobros por página; la búsqueda rechaza un `limit` de 20 o más. */
    private const CHARGES_PAGE = 12;

    /** Clase cuya ausencia indica que falta el SDK. */
    protected const SDK_CLASS = MercadoPagoConfig::class;

    public static function paymentMethodSchema(): array
    {
        return [
            ['key' => 'installments', 'type' => 'number', 'label' => 'Cuotas máximas (1 = sin cuotas)', 'min' => 1, 'max' => 12, 'default' => 12],
            ['key' => 'excluded_payment_types', 'type' => 'checkboxes', 'label' => 'Excluir tipos de pago', 'default' => [], 'options' => [
                ['id' => 'ticket', 'label' => 'Efectivo'],
                ['id' => 'atm', 'label' => 'Cajero automático'],
                ['id' => 'bank_transfer', 'label' => 'Transferencia bancaria'],
                ['id' => 'prepaid_card', 'label' => 'Tarjeta prepago'],
                ['id' => 'debit_card', 'label' => 'Tarjeta de débito'],
            ]],
            ['key' => 'excluded_payment_methods', 'type' => 'checkboxes', 'label' => 'Excluir marcas / billeteras', 'default' => [], 'options' => [
                ['id' => 'visa', 'label' => 'Visa'],
                ['id' => 'master', 'label' => 'Mastercard'],
                ['id' => 'amex', 'label' => 'American Express'],
                ['id' => 'diners', 'label' => 'Diners Club'],
                ['id' => 'debvisa', 'label' => 'Visa Débito'],
                ['id' => 'debmaster', 'label' => 'Mastercard Débito'],
                ['id' => 'yape', 'label' => 'Yape'],
                ['id' => 'pagoefectivo_atm', 'label' => 'PagoEfectivo'],
            ]],
        ];
    }

    public function getGatewayName(): string
    {
        return 'mercadopago_checkout';
    }

    protected function authenticate(): void
    {
        $this->ensureSdkInstalled(static::SDK_CLASS, 'mercadopago/dx-php');

        $accessToken = $this->config['access_token'] ?? null;

        if (empty($accessToken)) {
            throw new PaymentGatewayException(
                "Falta el token de acceso de Mercado Pago en la configuracion."
            );
        }

        MercadoPagoConfig::setAccessToken($accessToken);
    }

    public function health(): bool
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:health');

        try {
            return $this->retry(function () {
                (new PaymentClient())->get(1);

                return true;
            });
        } catch (PaymentGatewayException $e) {
            // El pago 1 no existe; un 404 prueba que la API responde.
            if ($e->getCode() === 404) {
                return true;
            }

            throw new PaymentGatewayException("Mercado Pago no responde: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);
        $items = $this->buildItems($request);
        $this->authenticate();
        $this->throttle('mercadopago_checkout:purchase');

        return $this->retry(function () use ($request, $items) {
            $client = new PreferenceClient();

            $backUrls = $request->metadata['back_urls'] ?? [];
            $successUrl = $request->successUrl ?? $backUrls['success'] ?? $request->metadata['success_url'] ?? null;
            $cancelUrl = $request->cancelUrl ?? $backUrls['failure'] ?? $request->metadata['cancel_url'] ?? null;
            $pendingUrl = $backUrls['pending'] ?? $request->metadata['pending_url'] ?? null;

            $backUrlsPayload = collect([
                'success' => $successUrl,
                'failure' => $cancelUrl,
                'pending' => $pendingUrl,
            ])->filter(fn ($url) => $url !== null)->all();

            $payload = [
                'items' => $items,
                'payer' => $this->buildMercadoPagoPayer($request),
                'back_urls' => $backUrlsPayload,
            ];

            if ($request->idempotencyKey) {
                $payload['external_reference'] = $request->metadata['session_id'] ?? $request->idempotencyKey;
            }

            if ($successUrl && str_starts_with($successUrl, 'https://')) {
                $payload['auto_return'] = $request->metadata['auto_return'] ?? 'approved';
            }

            $notificationUrl = $request->metadata['notification_url']
                ?? $this->config['notification_url']
                ?? null;
            if ($notificationUrl) {
                $payload['notification_url'] = $notificationUrl;
            }

            $paymentMethods = $request->metadata['payment_methods'] ?? null;
            if ($paymentMethods) {
                $payload['payment_methods'] = $this->normalizePaymentMethods($paymentMethods);
            }

            $preference = $client->create($payload);

            return new PaymentResponse(
                success: true,
                transactionId: $preference->id,
                status: 'pending',
                redirectUrl: $preference->init_point,
                raw: json_decode(json_encode($preference), true)
            );
        });
    }

    public function refund(string $transactionId, ?string $amount = null): bool
    {
        self::assertDecimalAmount($amount);
        $this->authenticate();
        $this->throttle('mercadopago_checkout:refund');

        return $this->retry(function () use ($transactionId, $amount) {
            return $this->sendRefundRequest($transactionId, $amount);
        });
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $this->validateCurrency($request->currency);
        $this->authenticate();
        $this->throttle('mercadopago_checkout:payout');

        return $this->retry(function () use ($request) {
            $client = new PaymentClient();
            $payment = $client->create([
                'transaction_amount' => $this->toMercadoPagoAmount($request->amount),
                'description' => $request->description,
                'payment_method_id' => $request->metadata['payment_method_id'] ?? 'account_money',
                'payer' => ['email' => $request->recipient],
            ]);

            return new PayoutResponse(
                success: $payment->status === 'approved',
                payoutId: (string) $payment->id,
                status: $payment->status === 'approved' ? 'completed' : 'pending',
                raw: json_decode(json_encode($payment), true)
            );
        });
    }

    // ─── Subscriptions (Preapproval) ────────────────────────────────────────

    /**
     * Sin `cardToken` la suscripción queda `pending` y la persona la autoriza
     * en `init_point`. Un trial mueve el primer débito a su fin.
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:create_subscription');

        return $this->retry(function () use ($request) {
            $client = new PreApprovalClient();

            $startDate = Carbon::now()->addDays($request->trialDays ?? 0);
            $endDate = isset($request->metadata['end_date'])
                ? Carbon::parse($request->metadata['end_date'])
                : Carbon::now()->addYears(2);
            [$frequency, $frequencyType] = $this->frequencyOf($request->interval ?? 'month', $request->intervalCount ?? 1);

            $preapproval = $client->create(array_filter([
                'reason' => $request->planId,
                'external_reference' => $request->externalReference,
                'auto_recurring' => [
                    'frequency' => $frequency,
                    'frequency_type' => $frequencyType,
                    'transaction_amount' => $request->amount === null ? null : $this->toMercadoPagoAmount($request->amount),
                    'currency_id' => $request->currency,
                    'start_date' => self::preapprovalDate($startDate),
                    'end_date' => self::preapprovalDate($endDate),
                ],
                'payer_email' => $request->email,
                'card_token_id' => $request->cardToken,
                'back_url' => $request->successUrl ?? $request->metadata['back_url'] ?? null,
                'status' => $request->cardToken === null ? 'pending' : 'authorized',
            ], fn (mixed $value): bool => $value !== null));

            return $this->toSubscriptionResponse($preapproval, $request->planId);
        });
    }

    public function cancelSubscription(string $subscriptionId, ?string $reason = null): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:cancel_subscription');

        return $this->retry(function () use ($subscriptionId, $reason) {
            $client = new PreApprovalClient();
            $preapproval = $client->update($subscriptionId, [
                'status' => 'cancelled',
            ]);

            return new SubscriptionResponse(
                success: true,
                subscriptionId: $preapproval->id,
                status: 'cancelled',
                planId: $preapproval->reason ?? null,
                canceledAt: Carbon::now()->toIso8601String(),
                raw: json_decode(json_encode($preapproval), true)
            );
        });
    }

    public function updateSubscription(string $subscriptionId, SubscriptionRequest $request): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:update_subscription');

        return $this->retry(function () use ($subscriptionId, $request) {
            $client = new PreApprovalClient();

            $payload = [];

            if ($request->amount !== null) {
                $payload['auto_recurring']['transaction_amount'] = $this->toMercadoPagoAmount($request->amount);
            }

            if (!empty($payload)) {
                $preapproval = $client->update($subscriptionId, $payload);
            } else {
                $preapproval = $client->get($subscriptionId);
            }

            return $this->toSubscriptionResponse($preapproval, $request->planId);
        });
    }

    public function getSubscription(string $subscriptionId): SubscriptionResponse
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:get_subscription');

        return $this->retry(function () use ($subscriptionId) {
            $preapproval = (new PreApprovalClient())->get($subscriptionId);

            return $this->toSubscriptionResponse($preapproval, $preapproval->reason ?? null);
        });
    }

    protected function toSubscriptionResponse(object $preapproval, ?string $planId): SubscriptionResponse
    {
        $raw = json_decode(json_encode($preapproval), true);

        return new SubscriptionResponse(
            success: true,
            subscriptionId: $preapproval->id,
            status: $preapproval->status,
            planId: $planId,
            nextBillingDate: $preapproval->next_payment_date ?? $preapproval->auto_recurring?->start_date ?? null,
            raw: $raw,
            redirectUrl: $preapproval->init_point ?? null,
            externalReference: $preapproval->external_reference ?? null,
            amount: $this->fromMercadoPagoAmount($raw['auto_recurring']['transaction_amount'] ?? null),
            currency: $raw['auto_recurring']['currency_id'] ?? null,
        );
    }

    public function subscriptionCharges(string $subscriptionId): array
    {
        $this->authenticate();
        $this->throttle('mercadopago_checkout:subscription_charges');

        $charges = [];
        $offset = 0;

        do {
            $search = $this->retry(fn () => (new InvoiceClient())->search(new MPSearchRequest(self::CHARGES_PAGE, $offset, [
                'preapproval_id' => $subscriptionId,
            ])));
            $page = json_decode(json_encode($search->results ?? []), true);
            $charges = [...$charges, ...$page];
            $offset += self::CHARGES_PAGE;
        } while ($page !== [] && $offset < (int) ($search->paging->total ?? 0));

        return collect($charges)
            ->map(fn (array $charge): SubscriptionCharge => $this->toSubscriptionCharge($charge, null))
            ->sortByDesc(fn (SubscriptionCharge $charge): string => (string) $charge->debitDate)
            ->values()
            ->all();
    }

    /**
     * El `authorized_payment` y luego el pago que lo liquida, como pide el
     * aviso `subscription_authorized_payment`.
     */
    public function findSubscriptionCharge(string $chargeId): ?SubscriptionCharge
    {
        if (! ctype_digit($chargeId)) {
            throw new PaymentGatewayException("El id de un cobro de Mercado Pago es numérico.", 422);
        }

        $this->authenticate();
        $this->throttle('mercadopago_checkout:find_subscription_charge');

        $charge = $this->findWithRetry(fn () => json_decode(json_encode((new InvoiceClient())->get((int) $chargeId)), true));

        if ($charge === null) {
            return null;
        }

        $paymentId = $charge['payment']['id'] ?? null;
        $payment = $paymentId === null ? null : $this->retry(fn () => json_decode(json_encode((new PaymentClient())->get((int) $paymentId)), true));

        return $this->toSubscriptionCharge($charge, $payment === null ? null : $this->toMercadoPagoResult('payment', $payment));
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function toSubscriptionCharge(array $charge, ?WebhookResult $payment): SubscriptionCharge
    {
        $paymentStatus = $charge['payment']['status'] ?? null;

        return new SubscriptionCharge(
            chargeId: (string) $charge['id'],
            subscriptionId: $charge['preapproval_id'] ?? null,
            status: match (true) {
                is_string($paymentStatus) => $this->mapMercadoPagoStatus($paymentStatus),
                ($charge['status'] ?? null) === 'cancelled' => 'canceled',
                default => 'scheduled',
            },
            amount: $this->fromMercadoPagoAmount($charge['transaction_amount'] ?? null),
            currency: $charge['currency_id'] ?? null,
            debitDate: isset($charge['debit_date']) ? Carbon::parse($charge['debit_date'])->utc()->toIso8601String() : null,
            paymentId: isset($charge['payment']['id']) ? (string) $charge['payment']['id'] : null,
            payment: $payment,
            retryAttempt: $charge['retry_attempt'] ?? null,
            raw: $charge,
        );
    }

    // El preapproval rechaza las fechas sin milisegundos.
    private static function preapprovalDate(Carbon $date): string
    {
        return $date->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * El preapproval solo cobra cada tantos días o meses; una semana son
     * siete días y un año, doce meses.
     *
     * @return array{0: int, 1: string}
     */
    protected function frequencyOf(string $interval, int $count): array
    {
        return match ($interval) {
            'day' => [$count, 'days'],
            'week' => [$count * 7, 'days'],
            'year' => [$count * 12, 'months'],
            default => [$count, 'months'],
        };
    }

    /**
     * Sin `items` en metadata, la preferencia lleva una sola línea por el monto.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildItems(PaymentRequest $request): array
    {
        $currency = strtoupper($request->currency);

        if (empty($request->metadata['items'])) {
            return [[
                'id' => $request->idempotencyKey ?? uniqid('', true),
                'title' => $request->description,
                'quantity' => 1,
                'unit_price' => $this->toMercadoPagoAmount($request->amount),
                'currency_id' => $currency,
            ]];
        }

        $prefix = $request->idempotencyKey ?? uniqid('', true);
        $lines = collect($request->metadata['items'])->values()->map(fn (array $item, int $index) => [
            'id' => "{$prefix}-" . ($index + 1),
            'title' => $item['name'] ?? $item['description'] ?? 'Producto',
            'quantity' => $item['quantity'] ?? 1,
            'unit_price' => $item['price'] ?? null,
            'currency_id' => $currency,
        ]);

        self::assertLinesAddUpTo($lines, $request->amount);

        return $lines->map(fn (array $line) => [
            ...$line,
            'unit_price' => $this->toMercadoPagoAmount($line['unit_price']),
        ])->all();
    }

    /**
     * Mercado Pago cobra la suma de precio unitario por cantidad, así que esa
     * suma, hecha con bcmath, debe ser exactamente el monto a cobrar.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private static function assertLinesAddUpTo(Collection $lines, string $amount): void
    {
        $lines->each(function (array $line, int $index) {
            $number = $index + 1;

            if (! is_string($line['unit_price'])) {
                throw new PaymentGatewayException(
                    "El ítem {$number} no trae su precio unitario como cadena decimal, como 19.99.",
                    422
                );
            }

            self::assertDecimalAmount($line['unit_price']);

            if (! is_int($line['quantity']) || $line['quantity'] < 1) {
                throw new PaymentGatewayException(
                    "La cantidad del ítem {$number} debe ser un entero mayor que cero.",
                    422
                );
            }
        });

        $scale = $lines->map(fn (array $line) => self::decimalPlaces($line['unit_price']))
            ->push(self::decimalPlaces($amount))
            ->max();
        $total = $lines->reduce(
            fn (string $sum, array $line) => bcadd($sum, bcmul($line['unit_price'], (string) $line['quantity'], $scale), $scale),
            '0'
        );

        if (bccomp($total, $amount, $scale) !== 0) {
            throw new PaymentGatewayException(
                "Los ítems suman {$total} y el monto a cobrar es {$amount}.",
                422
            );
        }
    }

    private static function decimalPlaces(string $amount): int
    {
        return Str::contains($amount, '.') ? Str::length(Str::after($amount, '.')) : 0;
    }

    protected function buildMercadoPagoPayer(PaymentRequest $request): array
    {
        $payer = ['email' => $request->email];

        if ($request->payerName) {
            $payer['name'] = $request->payerName;
        }

        if ($request->payerSurname) {
            $payer['surname'] = $request->payerSurname;
        }

        if ($request->payerDocumentType && $request->payerDocumentNumber) {
            $payer['identification'] = [
                'type' => $request->payerDocumentType,
                'number' => $request->payerDocumentNumber,
            ];
        }

        if ($request->payerPhone) {
            $payer['phone'] = ['number' => $request->payerPhone];
        }

        return $payer;
    }

    protected function normalizePaymentMethods(array $paymentMethods): array
    {
        foreach (['excluded_payment_methods', 'excluded_payment_types'] as $key) {
            if (isset($paymentMethods[$key]) && is_array($paymentMethods[$key])) {
                $paymentMethods[$key] = collect($paymentMethods[$key])
                    ->map(fn ($item) => is_string($item) ? ['id' => $item] : $item)
                    ->all();
            }
        }

        return $paymentMethods;
    }
}
