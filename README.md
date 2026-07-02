# abitech/payments-laravel

Motor de pasarelas de pago para aplicaciones Laravel. Implementa el patron Strategy + Factory para integrar multiples pasarelas con una interfaz unificada.

## Requisitos

- PHP ^8.2 | ^8.3 | ^8.4
- Laravel ^11.0 | ^12.0 | ^13.0

## Instalacion

Agregar el repositorio en `composer.json` de la app host:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/abitech-dev/abitech-payments-laravel.git"
        }
    ],
    "require": {
        "abitech/payments-laravel": "dev-main"
    }
}
```

Luego:

```bash
composer require abitech/payments-laravel:dev-main
```

El paquete se registra automaticamente mediante Laravel auto-discovery.

## Publicacion de archivos

```bash
# Publicar archivo de configuracion
php artisan vendor:publish --tag=abitech-payments-config

# Publicar migraciones base (infraestructura)
php artisan vendor:publish --tag=abitech-payments-migrations
```

## Variables de entorno (.env)

```env
# Configuracion general del paquete
ABITECH_PAYMENTS_KEY_TYPE=uuid
ABITECH_PAYMENTS_DB_CONNECTION=null
ABITECH_PAYMENTS_DEFAULT=mercadopago_checkout

# Mercado Pago
MERCADOPAGO_PUBLIC_KEY=
MERCADOPAGO_ACCESS_TOKEN=
MERCADOPAGO_CLIENT_ID=
MERCADOPAGO_CLIENT_SECRET=

# Stripe
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

## Uso basico

```php
use Abitech\Payments\PaymentManager;
use Abitech\Payments\DTO\PaymentRequest;

class BillingController extends Controller
{
    public function checkout(PaymentManager $manager)
    {
        $request = new PaymentRequest(
            amount: 100.00,
            currency: 'PEN',
            email: 'cliente@email.com',
            description: 'Suscripcion mensual',
            idempotencyKey: request()->header('X-Idempotency-Key'),
            metadata: [
                'success_url' => route('billing.success'),
                'cancel_url'  => route('billing.cancel'),
            ],
        );

        $response = $manager->driver('mercadopago_checkout')->purchase($request);

        return redirect($response->redirectUrl);
    }
}
```

## Webhooks

Define un endpoint en tu Host App para recibir notificaciones. Registra el payload crudo en `incoming_webhook_logs` antes de procesar:

```php
use Abitech\Payments\PaymentManager;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __invoke(Request $request, PaymentManager $manager)
    {
        // Registrar log crudo (modelo Eloquent definido en la Host App)
        IncomingWebhookLog::create([
            'gateway' => $request->route('gateway'),
            'payload' => $request->all(),
            'headers'  => $request->headers->all(),
            'status'   => 'received',
        ]);

        $driver = $manager->driver($request->route('gateway'));

        try {
            $result = $driver->handleWebhook($request);

            // La Host App actualiza ledger, invoices y dispara eventos de negocio
            // event(new \Abitech\Payments\Events\WebhookReceived($result, $request->all()));
        } catch (\Abitech\Payments\Exceptions\PaymentGatewayException $e) {
            // Manejar error de validacion de firma o procesamiento
        }
    }
}
```

## Eventos disponibles

El paquete incluye clases de eventos que la Host App debe disparar segun la logica de negocio:

| Evento | Contexto |
|--------|----------|
| `Abitech\Payments\Events\PaymentSucceeded` | Pago completado exitosamente |
| `Abitech\Payments\Events\PaymentFailed` | Pago rechazado o fallido |
| `Abitech\Payments\Events\RefundProcessed` | Reembolso procesado |
| `Abitech\Payments\Events\PayoutProcessed` | Retiro/transferencia completada |
| `Abitech\Payments\Events\WebhookReceived` | Webhook recibido y validado |

## Drivers disponibles

| Driver | Slug | Tipo |
|--------|------|------|
| Mercado Pago Checkout Pro | `mercadopago_checkout` | Redirect |
| Mercado Pago API (Bricks) | `mercadopago_api` | Directo |
| Stripe Checkout | `stripe_checkout` | Redirect |
| Stripe PaymentIntents | `stripe_paymentintents` | Directo |

## Documentacion por pasarela

- [Mercado Pago](docs/mercadopago.md)
- [Stripe](docs/stripe.md)

## Licencia

MIT
