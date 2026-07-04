# Changelog

Todas las versiones notables de `abitech/payments-laravel` estan documentadas aqui.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) y adhiere a [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/abitech-dev/abitech-payments-laravel/releases/tag/v1.0.0
