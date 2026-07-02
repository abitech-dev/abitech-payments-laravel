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
    amount: 100.00,
    currency: 'PEN',
    email: 'cliente@email.com',
    description: 'Producto X',
    idempotencyKey: 'uuid-unico',
    metadata: [
        'back_urls' => [
            'success' => 'https://miapp.com/gracias',
            'failure' => 'https://miapp.com/error',
            'pending' => 'https://miapp.com/pendiente',
        ],
        'notification_url' => 'https://miapp.com/api/webhooks/mercadopago',
    ],
));

redirect($response->redirectUrl);
```

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
    amount: 100.00,
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

## SDK

Documentacion oficial del SDK: https://github.com/mercadopago/dx-php
