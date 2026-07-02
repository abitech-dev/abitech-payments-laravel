# Integracion en la aplicacion host

Guia paso a paso para integrar `abitech/payments-laravel` en cualquier proyecto Laravel.

## 1. Instalacion

Agregar el repositorio VCS en `composer.json` de la app host:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/abitech-dev/abitech-payments-laravel.git"
        }
    ]
}
```

Luego:

```bash
composer require abitech/payments-laravel:dev-main

# Publicar archivos
php artisan vendor:publish --tag=abitech-payments-config
php artisan vendor:publish --tag=abitech-payments-migrations
php artisan migrate
```

## 2. Variables de entorno

```env
ABITECH_PAYMENTS_KEY_TYPE=uuid
ABITECH_PAYMENTS_DEFAULT=mercadopago_checkout

# MercadoPago
MERCADOPAGO_ACCESS_TOKEN=APP_USR-...

# Stripe
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

## 3. Configuracion desde Base de Datos (opcional)

Si las credenciales se gestionan por panel admin en vez de `.env`:

```php
// app/Providers/AppServiceProvider.php

use Abitech\Payments\Contracts\ConfigResolverInterface;
use Abitech\Payments\Resolvers\DatabaseConfigResolver;
use App\Models\PaymentGateway;

public function register(): void
{
    $this->app->bind(ConfigResolverInterface::class, function ($app) {
        return new DatabaseConfigResolver(
            PaymentGateway::class,
            $app->make('cache.store'),
            1800 // TTL 30 minutos
        );
    });
}
```

### Invalidar cache al actualizar credenciales

```php
// En el controlador del panel admin, despues de guardar:
$resolver = app(ConfigResolverInterface::class);
$resolver->forget('stripe');
$resolver->forget('mercadopago', $tenantId);
```

## 4. Controlador de Checkout

```php
// app/Http/Controllers/Api/BillingController.php

use Abitech\Payments\PaymentManager;
use Abitech\Payments\DTO\PaymentRequest;

class BillingController extends Controller
{
    public function checkout(Request $request, PaymentManager $manager)
    {
        $dto = new PaymentRequest(
            amount: $request->input('amount'),
            currency: $request->input('currency', 'PEN'),
            email: $request->user()->email,
            description: $request->input('description'),
            idempotencyKey: $request->header('X-Idempotency-Key'),
            metadata: [
                'success_url' => route('billing.success'),
                'cancel_url'  => route('billing.cart'),
            ],
        );

        $driver = $request->input('gateway', 'mercadopago_checkout');
        $response = $manager->driver($driver)->purchase($dto);

        // Guardar orden pendiente en BD (Host App)
        // Order::create([...]);

        return response()->json([
            'redirect_url' => $response->redirectUrl,
            'transaction_id' => $response->transactionId,
        ]);
    }
}
```

## 5. Middleware de idempotencia

Aplica el middleware en rutas de pago, reembolso y transferencia:

```php
// routes/api.php
Route::middleware('abitech.idempotency')->group(function () {
    Route::post('/billing/checkout', [BillingController::class, 'checkout']);
    Route::post('/billing/refund', [BillingController::class, 'refund']);
    Route::post('/billing/payout', [BillingController::class, 'payout']);
});
```

El frontend debe enviar `X-Idempotency-Key: <uuid>` en cada request.

## 6. Controlador de Webhooks

```php
// app/Http/Controllers/Api/WebhookController.php

use Abitech\Payments\PaymentManager;
use Abitech\Payments\Events;

class WebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, PaymentManager $manager)
    {
        // Registrar payload crudo ANTES de procesar (Standard #8)
        IncomingWebhookLog::create([
            'gateway' => $gateway,
            'payload' => $request->all(),
            'headers'  => $request->headers->all(),
            'status'   => 'received',
        ]);

        try {
            $driver = $manager->driver($gateway);
            $result = $driver->handleWebhook($request);

            // Actualizar estado en BD (Host App)
            // $invoice = Invoice::where('transaction_id', $result->transactionId)->first();
            // $invoice->update(['status' => $result->status]);

            // Disparar evento de negocio (Host App)
            if ($result->status === 'completed') {
                event(new Events\PaymentSucceeded($result, $gateway));
            }
        } catch (PaymentGatewayException $e) {
            // Actualizar log con error
            log::error("Webhook {$gateway} invalido", ['error' => $e->getMessage()]);
        }

        return response()->json(['status' => 'ok']);
    }
}
```

Ruta del webhook (sin middleware de idempotencia):

```php
// routes/api.php
Route::post('/webhooks/{gateway}', [WebhookController::class, '__invoke']);
```

## 7. Multi-tenant

```php
$manager = app(PaymentManager::class);

// Tenant especifico con credenciales propias
$driver = $manager->forTenant('store_42')->driver('stripe_checkout');
$driver->purchase($dto);
```

## 8. Uso con Facade

```php
use Abitech\Payments\Facades\Payment;

// Pago
$response = Payment::purchase($dto);

// Reembolso
Payment::refund('txn_123');

// Health check
Payment::driver('stripe_checkout')->health();

// Multi-tenant con Facade
Payment::forTenant('store_42')->purchase($dto);
```

## 9. Testing

```php
use Abitech\Payments\Facades\Payment;

public function test_checkout_creates_order(): void
{
    $fake = Payment::driver('fake');
    $fake->shouldReturn('purchase', new PaymentResponse(
        success: true,
        transactionId: 'txn_test_123',
        status: 'pending',
        redirectUrl: 'https://checkout.test/pay',
    ));

    $response = $this->postJson('/api/billing/checkout', [
        'amount' => 100,
        'currency' => 'PEN',
        'description' => 'Test product',
    ], ['X-Idempotency-Key' => 'test-key-1234567890']);

    $fake->assertCalled('purchase');
    $response->assertOk();
}

public function test_checkout_rechaza_sin_idempotencia(): void
{
    // La ruta tiene middleware abitech.idempotency
    $response = $this->postJson('/api/billing/checkout', [
        'amount' => 100,
    ]);

    $response->assertStatus(500);
}
```
