# Changelog

Todas las versiones notables de `abitech/payments-laravel` estan documentadas aqui.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) y adhiere a [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Sin publicar]

### Agregado

- `findPayment(?string $transactionId = null, ?string $externalReference = null): ?WebhookResult`
  consulta el estado de un pago sin esperar el webhook, para conciliar un
  checkout cuyo aviso no llegó. Devuelve el mismo `WebhookResult` que
  `handleWebhook()`, o `null` si el pago no existe.
  - Mercado Pago lee el pago por su id o, sin id, busca por
    `external_reference` y elige el pago aprobado o el más reciente.
  - Stripe Checkout lee la sesión `cs_…` con `payment_intent.latest_charge`
    expandido y Stripe PaymentIntents lee el PaymentIntent; los dos exigen el
    id y responden 422 sin él.

### Corregido

- `WebhookResult::$currency` lleva el código ISO 4217 en mayúsculas en todos
  los drivers; los webhooks de Stripe lo devolvían en minúsculas (`pen`). Una
  app que lo pasaba a mayúsculas por su cuenta ya no necesita hacerlo.

## [2.0.2] - 2026-10-17

### Corregido

- Mercado Pago Checkout envía la `quantity` de cada ítem de `metadata.items`
  con su precio unitario; antes mandaba siempre 1, así que un ítem con cantidad
  2 se cobraba una sola vez. Si la suma de `price × quantity`, hecha con bcmath,
  no es `amount`, o si un ítem no trae `price` como cadena decimal y `quantity`
  como entero de 1 en adelante, `purchase()` lanza `PaymentGatewayException` 422
  antes de autenticar, en vez de cobrar otro importe.
- Mercado Pago Checkout sin `idempotencyKey` arma el `id` de cada ítem con un
  prefijo generado; antes quedaba `-1`, `-2`.
- Stripe Checkout cobra `amount` en una sola línea de cantidad 1 e ignora
  `metadata['quantity']`, que multiplicaba el total. Una app que la enviaba
  debe pasar el total en `amount`.

## [2.0.0] - 2026-10-17

### Cambiado

- **Incompatible.** Deja de admitir Laravel 11, porque ya no tiene versión sin
  avisos de seguridad. Los `illuminate/*` piden `^12.69|^13.30`, las primeras
  versiones sin avisos, y PHP `^8.2`.
  El CI prueba PHP 8.2 con Laravel 12 en `--prefer-lowest`, 8.3 con Laravel 12
  y 13 y 8.5 con Laravel 13, con el bloqueo de avisos de Composer activo.
- La versión sale del tag `vX.Y.Z` y no de `composer.json`. El paquete ya no
  distribuye `.agents/`, `.claude/` ni `CLAUDE.md`.
- `abitech.idempotency` responde «Se requiere el encabezado X-Idempotency-Key.»
  sin cabecera, sin aludir a pagos, porque la app host también lo aplica en
  mutaciones que no cobran. Una prueba que comparaba el texto anterior debe
  actualizarse.
- **Incompatible.** Los montos son cadenas decimales en unidad mayor
  (`'19.99'`) en vez de `float`: `$amount` de `PaymentRequest`,
  `PayoutRequest`, `SubscriptionRequest`, `WebhookResult` y `RefundProcessed`,
  y `refund(string $transactionId, ?string $amount = null)`. Un monto con otro
  formato lanza `PaymentGatewayException` 422, y en Stripe también uno con más
  decimales de los que admite la moneda. La conversión a la unidad menor de
  Stripe usa bcmath, así que el paquete requiere `ext-bcmath`. La app host debe
  pasar cadenas y leer `WebhookResult::$amount` como cadena.
- `illuminate/collections` pasa a `require`, porque los drivers usan
  `collect()`; las fechas de suscripción salen de `Illuminate\Support\Carbon`
  en ISO 8601.
- **Incompatible.** Los drivers ya no disparan `PaymentInitiated`,
  `PaymentSucceeded`, `RefundProcessed` ni `PayoutProcessed`; devuelven el DTO
  y la app host dispara el evento de `src/Events/` que le corresponda. Iban
  dentro de `retry()`, así que un listener que fallaba con 429 o 5xx repetía la
  llamada a la pasarela. Una app que los escuchaba debe dispararlos tras la
  llamada al driver (account no escucha ninguno).

### Corregido

- Los webhooks de Stripe que no logran leer la tarjeta registran un
  `Log::warning()` con la pasarela, el PaymentIntent y el error; antes el fallo
  se descartaba. `illuminate/log` pasa a `require`.
- `abitech_payments.retry.retryable_http_codes`, o la misma clave en las
  credenciales de la pasarela, decide qué códigos HTTP reintenta `retry()`;
  antes se ignoraba y regían siempre 429, 500, 502, 503 y 504.
- Stripe Checkout valida `success_url` y `cancel_url` antes de autenticar y de
  contar la llamada en el limitador.
- Sin el SDK de la pasarela instalado, `authenticate()` lanza
  `PaymentGatewayException` con el `composer require` exacto
  (`stripe/stripe-php` o `mercadopago/dx-php`) en vez de un `Error` de clase
  inexistente.
- `PaymentRequest::$idempotencyKey` llega a la pasarela al cobrar. Stripe
  PaymentIntents la recibe como opción `idempotency_key` y Mercado Pago API
  como cabecera `X-Idempotency-Key`, que antes no se enviaba porque el driver
  buscaba `RequestOptions` en un namespace inexistente. Un reintento ya no
  puede duplicar el cobro.
- Stripe: `handleWebhook()` autentica el cliente tras verificar la firma, así
  que un `payment_intent.succeeded` trae marca y últimos dígitos de la tarjeta;
  antes la consulta al SDK fallaba con el cliente nulo y el dato se perdía.
- Toda llamada al SDK de `handleWebhook()` y `health()` pasa por `throttle()` y
  `retry()`, y un 429 o 5xx transitorio se reintenta. En Mercado Pago el error
  del webhook o de `health()` sale como `PaymentGatewayException` con el código
  HTTP de la API.
- Webhooks: la ausencia de encabezados de firma (`x-signature`/`x-request-id`
  de Mercado Pago, `Stripe-Signature`) lanza `PaymentGatewayException` 403;
  antes salía con código 0 y la app host respondía 500, invitando a reintentar.
- Stripe: las monedas sin decimales (JPY, CLP, KRW…) se envían y se leen sin
  multiplicar por 100 (`ConvertsStripeAmounts`); un reembolso parcial toma la
  moneda del PaymentIntent.
- `PaymentManager` se registra `scoped`: bajo Octane cada petición resuelve sus
  drivers con las credenciales vigentes.

## [1.0.0] - 2026-07-03

### Agregado

- `PaymentGatewayInterface` con `purchase`, `refund`, `payout`, `handleWebhook`, `health`
- `SubscriptionInterface` con `createSubscription`, `cancelSubscription`, `updateSubscription`, `getSubscription`
- `ConfigResolverInterface` (con `resolve` y `forget`) + `DatabaseConfigResolver` para resolucion de credenciales desde BD con cache
- `WebhookHandlerInterface` para tipado especifico de procesamiento de webhooks
- Drivers de MercadoPago: `MercadoPagoCheckoutDriver` (Checkout Pro), `MercadoPagoApiDriver` (Bricks)
- Drivers de Stripe: `StripeCheckoutDriver` (Checkout), `StripePaymentIntentsDriver` (PaymentIntents)
- `FakePaymentDriver` para testing con aserciones `assertCalled`, `assertNotCalled`, `assertCalledCount` (sin dependencia de PHPUnit)
- `PaymentManager` (Factory) con soporte multi-tenant via `forTenant()`
- `Payment` Facade para acceso estatico
- Traits: `RateLimitsApiCalls`, `RetriesApiCalls`, `VerifiesWebhookSignature`, `HandlesMercadoPagoWebhook`, `HandlesMercadoPagoRefund`
- Middleware `EnforceIdempotencyKey` para validar `X-Idempotency-Key`
- Migraciones publicables: `currencies`, `payment_gateways`, `payment_gateway_methods`, `payment_method_currency`, `incoming_webhook_logs`
- Eventos: `PaymentInitiated`, `PaymentSucceeded`, `PaymentFailed`, `RefundProcessed`, `PayoutProcessed`, `WebhookReceived`
- DTOs inmutables PHP 8.2+: `PaymentRequest`, `PaymentResponse`, `PayoutRequest`, `PayoutResponse`, `WebhookResult`, `SubscriptionRequest`, `SubscriptionResponse`
- `PaymentRequest` con `successUrl` y `cancelUrl` tipados como propiedad de primera clase
- Columna `options` (JSON) en `payment_gateway_methods` para configuracion por metodo de pago
- Columna `credentials` tipo `text` (no `json`) para compatibilidad con `encrypted:json`
- `payment_methods` en metadata para configurar metodos de pago en MercadoPago
- `payment_method_types` en metadata para Stripe Checkout y PaymentIntents
- Configuracion global de reintentos y rate limiting en `abitech_payments.php`
- `idempotencyKey` en `PayoutRequest` y `SubscriptionRequest`
- Facade alias auto-registrado via `composer.json` extra.laravel.aliases

### Corregido

- `RetriesApiCalls` ahora detecta `getStatusCode()` de `MPApiException` (MercadoPago SDK)
- `auto_return` solo se activa con URLs HTTPS (MercadoPago requiere HTTPS)
- Refunds de MercadoPago usan el SDK (no raw cURL)
- `FakePaymentDriver::shouldThrowException()` se auto-resetea tras una llamada (no persiste)
- `FakePaymentDriver` usa `\RuntimeException` en vez de `\PHPUnit\Framework\AssertionFailedError`
- Stripe Checkout valida que `success_url` y `cancel_url` no esten vacios antes de llamar al API
- `payout()` en ambos drivers de Stripe ahora valida la divisa
- `VerifiesWebhookSignature` usa `instanceof` en vez de string comparison
- Codigo duplicado de refunds extraido a trait `HandlesMercadoPagoRefund`
- `config()` helper usado en `AbstractPaymentDriver` para retry/rate limit defaults
- Eliminado `minimum-stability: dev` de composer.json
- Corregidas restricciones de version de PHP/illuminate

[2.0.2]: https://github.com/abitech-dev/abitech-payments-laravel/releases/tag/v2.0.2
[2.0.0]: https://github.com/abitech-dev/abitech-payments-laravel/releases/tag/v2.0.0
[1.0.0]: https://github.com/abitech-dev/abitech-payments-laravel/releases/tag/v1.0.0
