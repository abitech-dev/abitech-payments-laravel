# Changelog

Todas las versiones notables de `abitech/payments-laravel` estan documentadas aqui.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) y adhiere a [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-07-02

### Agregado

- `PaymentGatewayInterface` con `purchase`, `refund`, `payout`, `handleWebhook`, `health`
- `SubscriptionInterface` con `createSubscription`, `cancelSubscription`, `updateSubscription`, `getSubscription`
- `ConfigResolverInterface` + `DatabaseConfigResolver` para resolucion de credenciales desde BD con cache
- `WebhookHandlerInterface` para tipado especifico de procesamiento de webhooks
- Drivers de MercadoPago: `MercadoPagoCheckoutDriver` (Checkout Pro), `MercadoPagoApiDriver` (Bricks)
- Drivers de Stripe: `StripeCheckoutDriver` (Checkout), `StripePaymentIntentsDriver` (PaymentIntents)
- `FakePaymentDriver` para testing con aserciones `assertCalled`, `assertNotCalled`, `assertCalledCount`
- `PaymentManager` (Factory) con soporte multi-tenant via `forTenant()`
- `Payment` Facade para acceso estatico
- Traits: `RateLimitsApiCalls`, `RetriesApiCalls`, `VerifiesWebhookSignature`, `HandlesMercadoPagoWebhook`
- Middleware `EnforceIdempotencyKey` para validar `X-Idempotency-Key`
- Migraciones publicables: `currencies`, `payment_gateways`, `payment_gateway_methods`, `payment_method_currency`, `incoming_webhook_logs`
- Eventos: `PaymentSucceeded`, `PaymentFailed`, `RefundProcessed`, `PayoutProcessed`, `WebhookReceived`
- DTOs inmutables PHP 8.2+: `PaymentRequest`, `PaymentResponse`, `PayoutRequest`, `PayoutResponse`, `WebhookResult`, `SubscriptionRequest`, `SubscriptionResponse`
- Documentacion: README, `docs/architecture.md`, `docs/mercadopago.md`, `docs/stripe.md`, `docs/migrations.md`, `docs/host-integration.md`

[1.0.0]: https://github.com/abitech-dev/abitech-payments-laravel/releases/tag/v1.0.0
