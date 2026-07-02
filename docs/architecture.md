# Arquitectura del paquete

## Principio

El paquete sigue un patron **Strategy + Factory** desacoplado de la aplicacion host:

```
Host App (Controlador → Servicio)
    │
    ▼
PaymentManager (Factory)
    │  resolveGatewayConfig()
    │  forTenant($id)
    ▼
AbstractPaymentDriver
    │  RateLimitsApiCalls (throttle)
    │  RetriesApiCalls (retry con backoff)
    │  validateCurrency()
    ▼
Driver Concreto (StripeCheckoutDriver, MercadoPagoApiDriver, ...)
    │  purchase()  → PaymentResponse
    │  refund()    → bool
    │  payout()    → PayoutResponse
    │  handleWebhook() → WebhookResult
    │  health()    → bool
    ▼
API HTTP de la pasarela (Stripe API, MercadoPago API)
```

El paquete **nunca** persiste datos. Los DTOs son inmutables (`readonly`). Los drivers solo traducen DTOs a llamadas HTTP y retornan DTOs normalizados. La app host es responsable de guardar en BD, disparar eventos y ejecutar logica de negocio.

---

## Como agregar una nueva pasarela

### Paso 1: Crear el driver

```
src/Drivers/NuevaPasarela/
└── NuevaPasarelaCheckoutDriver.php
```

```php
<?php

declare(strict_types=1);

namespace Abitech\Payments\Drivers\NuevaPasarela;

use Abitech\Payments\Drivers\AbstractPaymentDriver;
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\DTO\PaymentResponse;
use Abitech\Payments\DTO\PayoutRequest;
use Abitech\Payments\DTO\PayoutResponse;
use Abitech\Payments\DTO\WebhookResult;
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Illuminate\Http\Request;
use Exception;

class NuevaPasarelaCheckoutDriver extends AbstractPaymentDriver
{
    // Si la pasarela soporta suscripciones, implementar tambien SubscriptionInterface
    // implements SubscriptionInterface

    // Si el webhook tiene logica compartida con otro driver de la misma pasarela,
    // usar un trait como HandlesMercadoPagoWebhook

    protected array $supportedCurrencies = ['USD', 'EUR', 'PEN'];

    public function getGatewayName(): string
    {
        return 'nuevapasarela_checkout';
    }

    protected function authenticate(): void
    {
        $apiKey = $this->config['api_key'] ?? null;

        if (empty($apiKey)) {
            throw new PaymentGatewayException(
                "Falta la API key de NuevaPasarela en la configuracion."
            );
        }

        // Inicializar SDK o HTTP client
    }

    public function health(): bool
    {
        $this->authenticate();

        return $this->retry(function () {
            // Llamada ligera a la API para verificar conectividad
            return true;
        });
    }

    public function purchase(PaymentRequest $request): PaymentResponse
    {
        $this->validateCurrency($request->currency);
        $this->authenticate();
        $this->throttle('nuevapasarela_checkout:purchase');

        return $this->retry(function () use ($request) {
            // Llamada a la API de la pasarela

            return new PaymentResponse(
                success: true,
                transactionId: 'txn_xxx',
                status: 'pending',
                redirectUrl: 'https://...',
                raw: []
            );
        });
    }

    public function refund(string $transactionId, ?float $amount = null): bool
    {
        $this->authenticate();
        $this->throttle('nuevapasarela_checkout:refund');

        return $this->retry(function () use ($transactionId, $amount) {
            // Llamada a la API de reembolso
            return true;
        });
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $this->authenticate();
        $this->throttle('nuevapasarela_checkout:payout');

        return $this->retry(function () use ($request) {
            // Si no soporta payouts, lanzar excepcion descriptiva

            return new PayoutResponse(
                success: true,
                payoutId: 'payout_xxx',
                status: 'completed',
                raw: []
            );
        });
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        // Verificar firma del webhook (usar VerifiesWebhookSignature u otro mecanismo)
        // Procesar y normalizar el evento

        return new WebhookResult(
            gateway: 'nuevapasarela',
            eventType: 'payment.completed',
            transactionId: 'txn_xxx',
            status: 'completed',
            amount: 100.00,
            currency: 'USD',
            raw: $request->all()
        );
    }
}
```

### Paso 2: Agregar credenciales en `config/abitech_payments.php`

```php
'gateways' => [
    // ... existentes ...
    'nuevapasarela' => [
        'api_key' => env('NUEVAPASARELA_API_KEY'),
        'secret' => env('NUEVAPASARELA_SECRET'),
        'webhook_secret' => env('NUEVAPASARELA_WEBHOOK_SECRET'),
    ],
],
```

### Paso 3: Registrar en `PaymentManager`

```php
use Abitech\Payments\Drivers\NuevaPasarela\NuevaPasarelaCheckoutDriver;

class PaymentManager extends Manager
{
    // ... drivers existentes ...

    public function createNuevapasarelaCheckoutDriver(): NuevaPasarelaCheckoutDriver
    {
        return new NuevaPasarelaCheckoutDriver(
            $this->resolveGatewayConfig('nuevapasarela')
        );
    }
}
```

### Paso 4: Agregar SDK a `composer.json` (si aplica)

```json
"suggest": {
    "nuevapasarela/sdk-php": "^1.0 Requerido para usar los drivers de NuevaPasarela"
}
```

### Paso 5: Documentar en `docs/nuevapasarela.md`

Crear guia especifica con ejemplos de uso, divisas soportadas y configuracion de webhooks.

---

## Traits disponibles

El paquete provee varios traits reutilizables para drivers:

| Trait | Funcion |
|-------|---------|
| `RateLimitsApiCalls` | Limita frecuencia de llamadas con `$this->throttle('key')` |
| `RetriesApiCalls` | Reintenta llamadas con backoff: `$this->retry(fn() => ...)` |
| `VerifiesWebhookSignature` | Verifica firmas HMAC de Stripe y MercadoPago |
| `HandlesMercadoPagoWebhook` | Logica compartida de webhooks MP (solo para drivers MP) |

---

## Estructura de directorios

```
src/
├── Concerns/         # Traits reutilizables
├── Contracts/        # Interfaces (PaymentGatewayInterface, SubscriptionInterface, ConfigResolverInterface)
├── Drivers/
│   ├── AbstractPaymentDriver.php
│   ├── MercadoPago/
│   ├── Stripe/
│   └── Testing/
│       └── FakePaymentDriver.php
├── DTO/              # Data Transfer Objects inmutables
├── Events/           # Clases de eventos (los dispara la Host App)
├── Exceptions/
├── Facades/
├── Http/Middleware/
├── Resolvers/        # Resolvedores de configuracion dinamica
├── PaymentManager.php
└── Providers/
```

---

## Flujo de una compra

```
1. POST /api/v1/billing/checkout
2. BillingController@checkout (Host App)
3. BillingService@processPayment (Host App)
       │
       ├── Valida stock, calcula impuestos (Host App)
       ├── $manager->driver('stripe_checkout')->purchase($dto)
       │       │
       │       ├── validateCurrency('USD')
       │       ├── authenticate() → StripeClient
       │       ├── throttle('stripe_checkout:purchase')
       │       ├── retry() → Stripe API
       │       └── return PaymentResponse
       │
       ├── Guarda orden en BD con estado 'pending' (Host App)
       └── Retorna redirectUrl al frontend
            │
            ▼
4. Cliente paga en Stripe/MercadoPago
            │
            ▼
5. POST /api/v1/webhooks/stripe (Host App)
6. WebhookController (Host App)
       │
       ├── IncomingWebhookLog::create(payload crudo)  ← auditoria (#8)
       ├── $manager->driver('stripe_checkout')->handleWebhook($request)
       │       └── WebhookResult (normalizado)
       ├── Actualiza Invoice/Ledger en BD (Host App)
       └── event(new PaymentSucceeded(...)) (Host App)
```
