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

**Uso:**
```php
$response = $manager->driver('stripe_checkout')->purchase(new PaymentRequest(
    amount: 100.00,
    currency: 'USD',
    email: 'cliente@email.com',
    description: 'Suscripcion mensual',
    idempotencyKey: 'uuid-unico',
    metadata: [
        'success_url' => 'https://miapp.com/gracias?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => 'https://miapp.com/carrito',
        'quantity'    => 1,
    ],
));

redirect($response->redirectUrl);
```

### PaymentIntents (`stripe_paymentintents`)

Flujo de pago directo con Stripe Elements (formulario propio). Requiere un PaymentMethod ID (`cardToken`) generado desde el frontend con Stripe.js.

**Capacidades:**
- `purchase` — Crea y confirma un PaymentIntent directamente
- `refund` — Reembolso total o parcial
- `payout` — Transferencia a cuenta conectada (Stripe Connect)
- `handleWebhook` — Procesa webhooks con verificacion de firma

**Uso:**
```php
$response = $manager->driver('stripe_paymentintents')->purchase(new PaymentRequest(
    amount: 100.00,
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

## Divisas soportadas

USD, EUR, GBP, PEN, MXN, BRL, ARS, CLP, COP, CAD, AUD, NZD, CHF, HKD, SGD, JPY, SEK, NOK, DKK, PLN, CZK

## Webhooks

La verificacion de firma usa el encabezado `Stripe-Signature` y el secreto `STRIPE_WEBHOOK_SECRET`.

Eventos procesados: `checkout.session.completed`, `payment_intent.*`, `charge.refunded`.

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
