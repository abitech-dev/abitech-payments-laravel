<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/logo-dark.svg">
    <img alt="Payments para Laravel" src=".github/assets/logo-light.svg" width="460">
  </picture>
</p>

<h3 align="center">Mercado Pago y Stripe en Laravel, con una sola interfaz</h3>

<p align="center">
  <a href="https://packagist.org/packages/abitechdev/payments-laravel"><img alt="Versión en Packagist" src="https://img.shields.io/packagist/v/abitechdev/payments-laravel?style=flat-square&color=1c6ccc&label=packagist"></a>
  <a href="https://packagist.org/packages/abitechdev/payments-laravel"><img alt="Descargas" src="https://img.shields.io/packagist/dt/abitechdev/payments-laravel?style=flat-square&color=1c6ccc&label=descargas"></a>
  <a href="https://github.com/abitech-dev/abitech-payments-laravel/actions/workflows/ci.yml"><img alt="Integración continua" src="https://img.shields.io/github/actions/workflow/status/abitech-dev/abitech-payments-laravel/ci.yml?branch=main&style=flat-square&label=CI"></a>
  <img alt="PHP 8.2 o superior" src="https://img.shields.io/badge/php-%E2%89%A58.2-777bb4?style=flat-square&logo=php&logoColor=white">
  <img alt="Laravel 12 y 13" src="https://img.shields.io/badge/laravel-12%20y%2013-ff2d20?style=flat-square&logo=laravel&logoColor=white">
  <a href="LICENSE"><img alt="Licencia MIT" src="https://img.shields.io/badge/licencia-MIT-2ea043?style=flat-square"></a>
</p>

<p align="center">
  <a href="#inicio-rápido">Inicio rápido</a> &nbsp;|&nbsp;
  <a href="#drivers-disponibles">Drivers</a> &nbsp;|&nbsp;
  <a href="#webhooks">Webhooks</a> &nbsp;|&nbsp;
  <a href="#variables-de-entorno">Variables</a> &nbsp;|&nbsp;
  <a href="#en-las-pruebas">En las pruebas</a> &nbsp;|&nbsp;
  <a href="README.en.md">English</a>
</p>

<br>

Cobrar, reembolsar, suscribir y recibir los avisos de **Mercado Pago** y
**Stripe** con el mismo código. Tu app arma un `PaymentRequest`, elige el
driver y recibe una respuesta normalizada. El paquete habla con la pasarela,
verifica la firma de sus webhooks y devuelve DTO iguales para las dos. Tu app
guarda, deduplica y decide.

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/flujo-dark.svg">
    <img alt="Para cobrar, tu app pide el pago al driver, que habla con la pasarela y devuelve la respuesta. Para los avisos, el paquete verifica la firma del webhook, lo normaliza y tu app decide" src=".github/assets/flujo-light.svg" width="860">
  </picture>
</p>

## Inicio rápido

**1. Instala el paquete.** Laravel lo registra solo.

```bash
composer require abitechdev/payments-laravel
```

**2. Agrega las credenciales** de tu pasarela al `.env`.

```env
ABITECH_PAYMENTS_DEFAULT=mercadopago_checkout
MERCADOPAGO_ACCESS_TOKEN=APP_USR-...
```

**3. Cobra** con el driver que elijas.

```php
use Abitech\Payments\DTO\PaymentRequest;
use Abitech\Payments\Facades\Payment;

$respuesta = Payment::driver('mercadopago_checkout')->purchase(new PaymentRequest(
    amount: '100.00',
    currency: 'PEN',
    email: 'cliente@email.com',
    description: 'Suscripción mensual',
    idempotencyKey: request()->header('X-Idempotency-Key'),
    metadata: [
        'success_url' => route('billing.success'),
        'cancel_url' => route('billing.cancel'),
    ],
));

return redirect($respuesta->redirectUrl);
```

## Características

<table>
  <tr>
    <td width="50%" valign="top">
      <h4>🔌 Una interfaz, dos pasarelas</h4>
      Mercado Pago y Stripe responden con los mismos DTO. Cambiar de
      pasarela es cambiar el slug del driver.
    </td>
    <td width="50%" valign="top">
      <h4>🔐 Webhooks con firma verificada</h4>
      Con el secreto de la pasarela configurado, cada aviso se valida antes
      de llegar a tu app y se normaliza en un <code>WebhookResult</code>.
    </td>
  </tr>
  <tr>
    <td valign="top">
      <h4>🔁 Suscripciones completas</h4>
      Alta, cambio de monto, cancelación y la lista de cobros de cada
      suscripción.
    </td>
    <td valign="top">
      <h4>💵 Montos exactos</h4>
      Los montos viajan como cadenas decimales y se calculan con
      <code>bcmath</code>, nunca con <code>float</code>.
    </td>
  </tr>
  <tr>
    <td valign="top">
      <h4>🛟 Reintentos e idempotencia</h4>
      Reintenta los errores pasajeros con espera creciente, limita las
      peticiones por minuto y evita cobros dobles con la clave de
      idempotencia.
    </td>
    <td valign="top">
      <h4>🧪 Driver falso para pruebas</h4>
      <code>FakePaymentDriver</code> cubre cobros, suscripciones y webhooks
      sin salir a internet. El paquete tiene 276 pruebas.
    </td>
  </tr>
</table>

## Drivers disponibles

| Driver | Slug | Tipo |
| --- | --- | --- |
| <img alt="" src="https://img.shields.io/badge/-Mercado%20Pago-00b1ea?style=flat-square&logo=mercadopago&logoColor=white"> Checkout Pro | `mercadopago_checkout` | Redirige a la pasarela |
| <img alt="" src="https://img.shields.io/badge/-Mercado%20Pago-00b1ea?style=flat-square&logo=mercadopago&logoColor=white"> API (Bricks) | `mercadopago_api` | Cobro directo |
| <img alt="" src="https://img.shields.io/badge/-Stripe-635bff?style=flat-square&logo=stripe&logoColor=white"> Checkout | `stripe_checkout` | Redirige a la pasarela |
| <img alt="" src="https://img.shields.io/badge/-Stripe-635bff?style=flat-square&logo=stripe&logoColor=white"> PaymentIntents | `stripe_paymentintents` | Cobro directo |
| 🧪 Pruebas | `fake` | Sin red |

## Requisitos

| Componente | Versión |
| --- | --- |
| PHP | 8.2 o superior, con `bcmath`. Laravel 13 pide 8.3 |
| Laravel | 12.69 o superior, o 13.30 o superior |
| Mercado Pago | `mercadopago/dx-php` 3.0.5 o superior ([SDK](docs/mercadopago.md)) |
| Stripe | `stripe/stripe-php` de la 17 a la 22 ([SDK](docs/stripe.md#sdk)) |

Instala solo el SDK de la pasarela que uses.

## Webhooks

Define un endpoint por pasarela y entrega el request al driver. Con el
`*_WEBHOOK_SECRET` configurado, el paquete verifica la firma y lanza
`PaymentGatewayException` si no es válida. Configúralo siempre en producción.

```php
use Abitech\Payments\Exceptions\PaymentGatewayException;
use Abitech\Payments\Facades\Payment;
use Illuminate\Http\Request;

Route::post('/webhooks/{gateway}', function (Request $request, string $gateway) {
    try {
        $resultado = Payment::driver($gateway)->handleWebhook($request);
    } catch (PaymentGatewayException $e) {
        return response()->noContent(400);
    }

    // Tu app guarda el aviso, deduplica y actualiza el pago.
    return response()->noContent();
});
```

Guarda el payload crudo antes de procesarlo. La
[guía de integración](docs/host-integration.md) muestra el registro de
avisos y los eventos que tu app puede disparar.

## Variables de entorno

```env
# General
ABITECH_PAYMENTS_DEFAULT=mercadopago_checkout
ABITECH_PAYMENTS_KEY_TYPE=uuid
ABITECH_PAYMENTS_DB_CONNECTION=null

# Mercado Pago
MERCADOPAGO_PUBLIC_KEY=
MERCADOPAGO_ACCESS_TOKEN=
MERCADOPAGO_CLIENT_ID=
MERCADOPAGO_CLIENT_SECRET=
MERCADOPAGO_WEBHOOK_SECRET=

# Stripe
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
```

Para cambiar los reintentos, el límite de peticiones o la idempotencia,
publica la configuración. Las migraciones crean las tablas base que usa el
host.

```bash
php artisan vendor:publish --tag=abitech-payments-config
php artisan vendor:publish --tag=abitech-payments-migrations
```

<details>
<summary><strong>Montos y monedas</strong></summary>
<br>

Los montos son cadenas decimales en la unidad mayor (`'19.99'`), nunca
`float`, en los DTO, en `refund()` y en `WebhookResult::$amount`. Un monto con
otro formato, o con más decimales de los que admite la moneda en Stripe, lanza
`PaymentGatewayException` 422. `WebhookResult::$currency` es el código ISO 4217
en mayúsculas (`'PEN'`) en todas las pasarelas.

</details>

<details>
<summary><strong>Varias cuentas de una misma pasarela</strong></summary>
<br>

En una app con varios clientes, cada uno con su propia cuenta, pide el driver
para un cliente concreto. Sus credenciales salen del resolvedor que registres.

```php
Payment::forTenant($clienteId)->driver('stripe_checkout')->purchase($pedido);
```

</details>

## En las pruebas

Usa el driver `fake` para probar cobros, suscripciones y webhooks sin
credenciales ni red.

```php
$respuesta = Payment::driver('fake')->purchase($pedido);
```

## Más información

- 📚 Guías: [integración en tu app](docs/host-integration.md),
  [arquitectura](docs/architecture.md) y [migraciones](docs/migrations.md).
- 💳 Referencias: [Mercado Pago](docs/mercadopago.md) y [Stripe](docs/stripe.md).
- 🔒 [Política de seguridad](SECURITY.md). Las vulnerabilidades se reportan en
  privado.
- 🤝 [Guía para contribuir](CONTRIBUTING.md). Las propuestas son bienvenidas.
- 📝 [Cambios de cada versión](CHANGELOG.md).

## Licencia

Publicado con la [licencia MIT](LICENSE). Puedes usarlo, modificarlo y
distribuirlo, también en proyectos comerciales.

<br>

<p align="center">
  Hecho en Huánuco, Perú, por <a href="https://abitech.dev">Abimael Fernandez</a>
</p>
