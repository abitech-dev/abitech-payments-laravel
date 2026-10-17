# Mercado Pago

## Credenciales

| Variable | Descripcion |
|----------|-------------|
| `MERCADOPAGO_PUBLIC_KEY` | Clave publica del frontend |
| `MERCADOPAGO_ACCESS_TOKEN` | Token de acceso para API (produccion o sandbox) |
| `MERCADOPAGO_CLIENT_ID` | Client ID de la aplicacion |
| `MERCADOPAGO_CLIENT_SECRET` | Client Secret de la aplicacion |

Obtener credenciales: https://www.mercadopago.com.pe/developers/panel

## Drivers

### Checkout Pro (`mercadopago_checkout`)

Flujo de redireccion al checkout hospedado de Mercado Pago.

**Capacidades:**
- `purchase` — Crea preferencia de pago y retorna URL de redireccion
- `refund` — Reembolso total o parcial
- `payout` — Transferencia a email usando saldo de MP (account_money)
- `handleWebhook` — Procesa notificaciones IPN

**Uso:**
```php
$response = $manager->driver('mercadopago_checkout')->purchase(new PaymentRequest(
    amount: '100.00',
    currency: 'PEN',
    email: 'cliente@email.com',
    description: 'Producto X',
    idempotencyKey: 'uuid-unico',
    successUrl: 'https://miapp.com/gracias',
    cancelUrl: 'https://miapp.com/error',
    metadata: [
        'items' => [
            ['name' => 'Licencia anual', 'price' => '40.00', 'quantity' => 2],
            ['name' => 'Soporte', 'price' => '20.00'],
        ],
        'pending_url' => 'https://miapp.com/pendiente',
        'notification_url' => 'https://miapp.com/api/webhooks/mercadopago',
        'payment_methods' => [
            'installments' => 12,
            'excluded_payment_types' => [['id' => 'ticket']],
        ],
    ],
));

redirect($response->redirectUrl);
```

**Ítems (`metadata.items`):**

| Clave | Tipo | Uso |
|-------|------|-----|
| `name` | cadena | Título del ítem; si falta, `description` del ítem o «Producto» |
| `price` | cadena decimal (`'19.99'`) | Precio unitario; obligatorio |
| `quantity` | entero ≥ 1 | Cantidad; 1 si falta |

La suma de `price × quantity`, hecha con bcmath, debe ser igual a `amount`. Si
no lo es, o si un ítem no cumple la tabla, `purchase()` lanza
`PaymentGatewayException` 422 antes de llamar a Mercado Pago. Sin `items`, la
preferencia lleva una sola línea por `amount` con cantidad 1. Cada ítem recibe
el `id` `<idempotencyKey>-<n>`, con un prefijo generado si no hay clave.

### API Bricks (`mercadopago_api`)

Flujo de pago transparente (formulario propio). Requiere token de tarjeta generado desde el frontend con MercadoPago.js.

**Capacidades:**
- `purchase` — Cobro directo con token de tarjeta, soporta `X-Idempotency-Key`
- `refund` — Reembolso total o parcial
- `payout` — Transferencia a email usando saldo de MP (account_money)
- `handleWebhook` — Procesa notificaciones IPN

**Uso:**
```php
$response = $manager->driver('mercadopago_api')->purchase(new PaymentRequest(
    amount: '100.00',
    currency: 'PEN',
    email: 'cliente@email.com',
    description: 'Producto X',
    cardToken: 'token-generado-en-frontend',
    idempotencyKey: request()->header('X-Idempotency-Key'),
    metadata: [
        'installments' => 1,
        'payment_method_id' => 'visa',
        'payer_id_type' => 'DNI',
        'payer_id_number' => '12345678',
    ],
));
```

## Divisas soportadas

PEN, USD, BRL, ARS, MXN, CLP, COP

## Webhooks

La verificacion de firma usa los encabezados `x-signature` y `x-request-id` enviados por Mercado Pago.

El secreto de verificacion se define con `MERCADOPAGO_CLIENT_SECRET`.

## Subscriptions

El driver `mercadopago_checkout` soporta `SubscriptionInterface` (Preapproval API):

```php
$response = $manager->driver('mercadopago_checkout')->createSubscription(new SubscriptionRequest(
    planId: 'plan-mensual',
    email: 'cliente@email.com',
    amount: '49.90',
    currency: 'PEN',
    interval: 'month',
    intervalCount: 1,
));
```

## SDK

Documentacion oficial del SDK: https://github.com/mercadopago/dx-php
