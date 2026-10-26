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
    metadata: [
        'payment_method_types' => ['card', 'ideal', 'bancontact'],
    ],
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
        'payment_method_types' => ['card'],
    ],
));
```

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
