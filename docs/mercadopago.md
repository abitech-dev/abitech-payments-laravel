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
- `findPayment` — Consulta el pago de la preferencia por su referencia externa
  ([Consulta de un pago](#consulta-de-un-pago))

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
- `findPayment` — Consulta el pago por su id
  ([Consulta de un pago](#consulta-de-un-pago))

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

## Consulta de un pago

`findPayment(?string $transactionId = null, ?string $externalReference = null)`
lee el estado de un pago sin esperar el aviso, para conciliar un checkout cuyo
webhook no llegó. Devuelve el mismo `WebhookResult` que `handleWebhook()` para
ese pago, con `eventType` `payment`, o `null` si el pago no existe.

```php
// Checkout Pro: la preferencia lleva la referencia externa del checkout.
$result = $manager->driver('mercadopago_checkout')->findPayment(externalReference: $checkoutId);

// API (Bricks): el id que devolvió purchase().
$result = $manager->driver('mercadopago_api')->findPayment('1234567890');
```

| Dato | Consulta |
|------|----------|
| `transactionId` | `GET /v1/payments/{id}`; el id es numérico |
| Solo `externalReference` | `GET /v1/payments/search` por `external_reference`, de lo más reciente a lo más antiguo |

- Con id y referencia manda el id. Sin ninguno de los dos, o con un id que no es
  numérico (el de una preferencia), lanza `PaymentGatewayException` 422 antes
  de llamar a Mercado Pago.
- Por referencia elige el pago aprobado y, si no hay, el más reciente por
  `date_created`, porque un checkout puede tener varios intentos rechazados.
  La referencia es la que `purchase()` envía cuando hay `idempotencyKey`
  (`metadata.session_id` o la propia clave).
- Monto en cadena decimal (`'50.50'`), moneda ISO 4217 en mayúsculas y estado
  normalizado (`completed`, `pending`, `failed` o `refunded`), como el webhook.
- Un 404 de Mercado Pago o una búsqueda vacía devuelven `null`. Cualquier otro
  error lanza `PaymentGatewayException` con el código HTTP de la API, tras
  reintentar 429 y 5xx.

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

`updateSubscription()` con `amount` hace PUT de `auto_recurring.transaction_amount`
y devuelve el preapproval con su `amount` y su `nextBillingDate`.

### Cobros del preapproval

El driver también implementa `SubscriptionChargesInterface` sobre
`/authorized_payments`:

| Método | Llamada | Devuelve |
| --- | --- | --- |
| `subscriptionCharges($preapprovalId)` | `GET /authorized_payments/search?preapproval_id=` | Los cobros del más reciente al más antiguo, sin el detalle del pago |
| `findSubscriptionCharge($id)` | `GET /authorized_payments/{id}` y `GET /v1/payments/{payment.id}` | El cobro con su pago en `payment`, o `null` si no existe |

`status` sale del pago (`completed`, `pending`, `failed` o `refunded`); sin
pago es `scheduled`, o `canceled` si el cobro se canceló. `debitDate` va en
UTC. El aviso `subscription_authorized_payment` trae el id del cobro en
`WebhookResult::$transactionId`.

## SDK

Documentacion oficial del SDK: https://github.com/mercadopago/dx-php
