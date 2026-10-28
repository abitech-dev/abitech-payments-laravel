# Stripe

## Credenciales

| Variable | Descripcion |
|----------|-------------|
| `STRIPE_KEY` | Clave publica (publishable key) |
| `STRIPE_SECRET` | Clave secreta (secret key) |
| `STRIPE_WEBHOOK_SECRET` | Secreto de firma de webhooks |

Obtener credenciales: https://dashboard.stripe.com/apikeys

## Drivers

### Stripe Checkout (`stripe_checkout`)

Flujo de redireccion al checkout hospedado de Stripe.

**Capacidades:**
- `purchase` — Crea sesion de Checkout y retorna URL de redireccion
- `refund` — Reembolso total o parcial
- `payout` — Transferencia a cuenta conectada (Stripe Connect)
- `handleWebhook` — Procesa webhooks con verificacion de firma
- `findPayment` — Consulta la sesión de Checkout por su id
  ([Consulta de un pago](#consulta-de-un-pago))

**Uso:**
```php
$response = $manager->driver('stripe_checkout')->purchase(new PaymentRequest(
    amount: '100.00',
    currency: 'USD',
    email: 'cliente@email.com',
    description: 'Suscripcion mensual',
    idempotencyKey: 'uuid-unico',
    successUrl: 'https://miapp.com/gracias?session_id={CHECKOUT_SESSION_ID}',
    cancelUrl: 'https://miapp.com/carrito',
));

redirect($response->redirectUrl);
```

La sesión cobra `amount` en una sola línea de cantidad 1, con `description` como
nombre del producto. Stripe no recibe `metadata.items` ni otra cantidad, así que
el total cobrado siempre es `amount`.

### PaymentIntents (`stripe_paymentintents`)

Flujo de pago directo con Stripe Elements (formulario propio). Requiere un PaymentMethod ID (`cardToken`) generado desde el frontend con Stripe.js.

**Capacidades:**
- `purchase` — Crea y confirma un PaymentIntent directamente
- `refund` — Reembolso total o parcial
- `payout` — Transferencia a cuenta conectada (Stripe Connect)
- `handleWebhook` — Procesa webhooks con verificacion de firma
- `findPayment` — Consulta el PaymentIntent por su id
  ([Consulta de un pago](#consulta-de-un-pago))

**Uso:**
```php
$response = $manager->driver('stripe_paymentintents')->purchase(new PaymentRequest(
    amount: '100.00',
    currency: 'USD',
    email: 'cliente@email.com',
    description: 'Producto X',
    cardToken: 'pm_xxx', // PaymentMethod ID generado en frontend
    idempotencyKey: request()->header('X-Idempotency-Key'),
    metadata: [
        'order_id' => '12345',
    ],
));
```

## Métodos de pago

Los drivers no eligen los métodos de pago: se activan en el panel de Stripe
(**Settings → Payment methods**), como pide Stripe con los
[métodos de pago dinámicos](https://docs.stripe.com/payments/payment-methods/dynamic-payment-methods).
Desde la API `2026-09-30.endive`, que fija stripe-php 22, Stripe rechaza
`payment_method_types` con 400 al crear Checkout Sessions, PaymentIntents y
SetupIntents
([changelog](https://docs.stripe.com/changelog/endive/2026-09-30/remove-payment-method-types-checkout-sessions)).

- `stripe_checkout` muestra en la página de Stripe los métodos activos que
  admiten la moneda y el monto.
- `stripe_paymentintents` confirma el `cardToken` con
  `automatic_payment_methods` y `allow_redirects: never`, así que Stripe no
  exige `return_url`. La autenticación 3DS de la tarjeta llega en
  `next_action` y el estado queda `pending`.
- `metadata['payment_method_types']` no tiene efecto y `paymentMethodSchema()`
  de los dos drivers devuelve `[]`.
- `WebhookResult::$paymentMethod` lleva la marca si el cargo es de tarjeta
  (`visa`) o el tipo del cargo si no lo es (`link`). El driver lo lee del
  `latest_charge` expandido del PaymentIntent, también en el aviso
  `checkout.session.completed`, que trae el PaymentIntent sin expandir.

## Divisas soportadas

USD, EUR, GBP, PEN, MXN, BRL, ARS, CLP, COP, CAD, AUD, NZD, CHF, HKD, SGD, JPY, SEK, NOK, DKK, PLN, CZK

## Webhooks

La verificacion de firma usa el encabezado `Stripe-Signature` y el secreto `STRIPE_WEBHOOK_SECRET`.

Eventos procesados: `checkout.session.completed`, `payment_intent.*`, `charge.refunded`.

`WebhookResult::$currency` llega en ISO 4217 en mayúsculas (`'PEN'`), aunque
Stripe la envíe en minúsculas.

## Consulta de un pago

`findPayment(?string $transactionId = null, ?string $externalReference = null)`
lee el estado de un pago sin esperar el webhook, para conciliar un pago cuyo
aviso no llegó. Stripe lo consulta por el id que devolvió `purchase()`; la
referencia externa no basta y, sin id, lanza `PaymentGatewayException` 422
antes de llamar a Stripe.

```php
$result = $manager->driver('stripe_checkout')->findPayment('cs_test_a1b2');
$result = $manager->driver('stripe_paymentintents')->findPayment('pi_3Nx');
```

| Driver | Consulta | `eventType` |
|--------|----------|-------------|
| `stripe_checkout` | `GET /v1/checkout/sessions/{id}` con `payment_intent.latest_charge` expandido | `checkout.session` |
| `stripe_paymentintents` | `GET /v1/payment_intents/{id}` con `latest_charge` expandido | `payment_intent` |

Estado de la sesión de Checkout:

| Sesión | Estado |
|--------|--------|
| Cargo reembolsado por completo | `refunded` |
| `payment_status` `paid` o `no_payment_required` | `completed` |
| `status` `expired` | `failed` |
| `status` `complete` sin pagar, con el PaymentIntent en `processing` | `pending` (cobro asíncrono en curso) |
| `status` `complete` sin pagar, en otro estado | `failed` (cobro asíncrono fallido) |
| `status` `open` | `pending` |

El PaymentIntent sigue el mapeo del webhook (`succeeded` → `completed`,
`canceled` → `failed`, el resto `pending`) y da `refunded` si su último cargo
está reembolsado por completo.

- Devuelve el mismo `WebhookResult` que el webhook, con `gateway` `stripe`,
  monto en cadena decimal (`'19.99'`), moneda en mayúsculas, la tarjeta del
  cargo expandido y el objeto de Stripe en `raw`.
- Un 404 (`resource_missing`) devuelve `null`. Cualquier otro error lanza
  `PaymentGatewayException` con el código HTTP de Stripe, tras reintentar 429
  y 5xx.

## Subscriptions

El driver `stripe_checkout` soporta `SubscriptionInterface`:

```php
$response = $manager->driver('stripe_checkout')->createSubscription(new SubscriptionRequest(
    planId: 'price_xxx',
    email: 'cliente@email.com',
    interval: 'month',
    intervalCount: 1,
));
```

`nextBillingDate` sale del `current_period_end` del primer ítem de la
suscripción, donde lo deja la API `2025-03-31.basil` desde stripe-php 17.

### Configurar endpoint en Stripe Dashboard

1. Ir a https://dashboard.stripe.com/webhooks
2. Agregar endpoint: `https://miapp.com/api/webhooks/stripe`
3. Copiar el `Signing secret` a `STRIPE_WEBHOOK_SECRET`

### Pruebas locales con Stripe CLI

```bash
stripe listen --forward-to localhost:8000/api/webhooks/stripe
```

## SDK

Documentacion oficial del SDK: https://github.com/stripe/stripe-php
