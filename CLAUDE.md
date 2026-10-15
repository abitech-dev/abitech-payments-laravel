# CLAUDE.md

## 1. Proyecto

**abitech/payments-laravel.** Paquete Composer con los drivers de
pasarelas de pago (Mercado Pago y Stripe) para apps Laravel. Traduce DTO
a la API de cada pasarela y devuelve DTO normalizados; la app host
persiste, deduplica y decide.

- Genérico de Abitech y reutilizable fuera de Arsy. Hoy lo instala solo
  `arsy-account-app`, como `dev-main` desde el repositorio VCS.
- Tareas en `../../arsy-architecture/tareas/payments-laravel.md` (PL-01
  a PL-07, versión 1.1), con sus diseños en `disenos/`.
- Contrato v1 sin capas de compatibilidad hasta producción (ADR 0005),
  aplicado en `project/architecture.md` regla 8.
- Idioma (excepción de `core/04` regla 3). Clases, métodos, variables,
  tablas y columnas en inglés; mensajes de excepción, comentarios de
  columna, docs y commits en español.

## 2. Comandos

```bash
composer install
vendor/bin/phpunit                     # Unit y Feature
vendor/bin/phpunit --testsuite Unit    # Unit | Feature
vendor/bin/phpunit --filter test_a_valid_mercadopago_signature_passes
composer validate
composer audit
```

PHPUnit y Orchestra Testbench (`phpunit.xml.dist`), sobre sqlite
`:memory:` y caché `array`, sin app host; las versiones las fija el lock
local (`project/testing.md` regla 1).

## 3. Puertas de calidad

Sin CI ni linters en el repositorio. Antes de entregar, estas dos con
cero errores.

```bash
vendor/bin/phpunit
composer validate
```

Formato, análisis estático y mutación se declaran no cubiertos
(`project/testing.md` regla 6).

## 4. Lo que sorprende

1. **El PHP de Windows no corre la suite sobre `\\wsl.localhost`.**
   PHPUnit falla con `Cannot open bootstrap script` y la distribución
   Ubuntu de WSL no trae PHP. Se corre con PHP 8.2 o superior y el
   paquete en un disco local.
2. **Un push a `main` es una publicación.** Account requiere
   `dev-main`, así que su próximo `composer update` toma lo que haya en
   `main` aunque no exista tag.
3. **`composer.lock` y `vendor/` no se versionan.** El lock local solo
   sirve para probar; cada app host resuelve sus versiones dentro de los
   rangos de `composer.json`.
4. **Los SDK de pasarela no vienen con el paquete.** Están en `suggest`
   y `require-dev`, así que la suite los tiene y una app host puede no
   tenerlos (`project/architecture.md` regla 5).

## 5. Configuración de Claude Code

Dónde va cada pieza, `core/06`; cómo se escribe, `core/08`. Proyecto en
**modo compartido**. `CLAUDE.md` y `.claude/` se versionan aquí para el
equipo, y su versión vive en el repositorio privado `abitech-dev/_claude`,
con el `stack` declarado en su `claude-sync.json`.

- `rules/core/`, `rules/tech/{testing,documentation}.md`, `agents/`,
  `hooks/*.py` y las skills son copia de la base global. Se editan en
  `~/.claude` y llegan con `sync.py guardar global` y luego
  `sync.py restaurar abitech-payments-laravel`.
- Las demás de `rules/tech/` vienen de la biblioteca `stacks/`;
  `tech/laravel-package.md` es idéntica en `arsy-sso-client`. Se editan aquí
  y vuelven con `sync.py guardar abitech-payments-laravel`.
- Lo propio vive en `rules/project/`, `settings.json` y
  `rule-cases.json`.
- Una buena práctica que convenga fijar se propone como cambio en su
  regla; nunca se aplica en silencio.
- `.agents/` guarda reglas de otra herramienta y Claude Code no las
  carga; ante una diferencia mandan `.claude/rules/` y, en commits,
  `core/03`.

## 6. Despliegue

- Un paquete no se despliega; se publica según
  `tech/laravel-package.md` regla 7. El push pide confirmación
  (`settings.json`).
- Account fija el commit en su `composer.lock`. Subir esa versión es un
  cambio de account, con su suite (`../../arsy-account-app/CLAUDE.md`
  §2 y §3).

## 7. Deuda conocida

Las tareas viven en el archivo de tareas de §1.

| Tema | Estado |
|---|---|
| Eventos desde los drivers | 13 `event(new ...)` en `src/Drivers/**` (p. ej. `StripePaymentIntentsDriver.php:113`, `MercadoPagoApiDriver.php:151`) contra `project/domain/drivers.md`, `docs/architecture.md:30` y el README; account no escucha ninguno |
| Montos en `float` | `$amount` de `PaymentRequest.php:10`, `PayoutRequest.php:10`, `SubscriptionRequest.php:15` y `WebhookResult.php:14`, y `refund(?float)` en `PaymentGatewayInterface.php:28` |
| Códigos reintentables | `retry()` usa `RetriesApiCalls::$retryableHttpCodes` (`RetriesApiCalls.php:27`); `abitech_payments.retry.retryable_http_codes` (`config/abitech_payments.php:79`) no se lee |
| Orden canónico | `StripeCheckoutDriver::purchase()` valida `success_url` y `cancel_url` después de `authenticate()` y `throttle()` (`StripeCheckoutDriver.php:94-105`) |
| SDK fuera de `retry()` | `HandlesMercadoPagoWebhook.php:79-82`, `StripeCheckoutDriver.php:318` y `StripePaymentIntentsDriver.php:237,251` llaman al SDK sin `retry()` ni `throttle()` |
| Estado de avisos en Stripe | un evento que no es `payment_intent.*` ni `charge.refunded` devuelve `pending` en vez de `ignored` (`StripePaymentIntentsDriver.php:195-197`); tarea PL-01 |
| Idempotencia en PaymentIntents | `purchase()` no envía `idempotencyKey` a Stripe (`StripePaymentIntentsDriver.php:91-100`) |
| Firma de Mercado Pago | opcional sin secreto (`HandlesMercadoPagoWebhook.php:51-55`) y sin tolerancia de `ts` (`VerifiesWebhookSignature.php:72-108`); tarea PL-02 |
| Avisos que no son pago | devuelven `status: 'unknown'` (`HandlesMercadoPagoWebhook.php:69-76`); tarea PL-01 |
| Middleware de idempotencia | `EnforceIdempotencyKey` solo exige la cabecera; tarea PL-03 lo elimina |
| `incoming_webhook_logs` | migración publicable que PL-01 retira del paquete |
| SDK ausente | `authenticate()` no comprueba el SDK (`MercadoPagoApiDriver.php:52-63`, `StripeCheckoutDriver.php:60-72`); sin él sale un `Error` de clase inexistente |
| FQN y strings de clase | `\Stripe\StripeClient` (`StripeCheckoutDriver.php:37`, `StripePaymentIntentsDriver.php:33`) y `'Stripe\StripeClient'` (`StripeCheckoutDriver.php:70`, `StripePaymentIntentsDriver.php:61`), `'Stripe\Webhook'` y `\Stripe\Exception\*` (`VerifiesWebhookSignature.php:24,41,51-52`), `\RuntimeException` (`FakePaymentDriver.php:98,110,127,141`), `\Throwable` (`RetriesApiCalls.php:53`) |
| `catch` mudo | `StripeCheckoutDriver.php:292` y `StripePaymentIntentsDriver.php:261` devuelven `[]` sin `Log::` (`tech/laravel`) |
| PHP nativo | `DateTime` y `date()` en `MercadoPagoCheckoutDriver.php:221-268`, `StripeCheckoutDriver.php:384-447` y `FakePaymentDriver.php:214-255`; `array_map`/`array_filter` en `MercadoPagoCheckoutDriver.php:121,338,386`, `MercadoPagoApiDriver.php:116` y `HandlesMercadoPagoRefund.php:20` |
| Comentarios de columna | 5 de más de 3 palabras (`create_currencies_table.php:25`, `create_payment_gateways_table.php:29`, `create_payment_gateway_methods_table.php:32,33,38`) |
| Versión | `composer.json:6` declara `"version": "1.0.0"` (aviso de `composer validate`) y el repo no tiene tags |
| Distribución | `.gitattributes:2` excluye `/.agent` (la carpeta es `.agents`) y no excluye `.claude/` ni `CLAUDE.md` (`tech/laravel-package.md` regla 8) |
| Pruebas | sin pruebas de los 4 drivers reales, `handleWebhook()`, `EnforceIdempotencyKey`, `DatabaseConfigResolver` ni publicaciones del provider; `RetriesApiCallsTest.php:39,53` comprueban tras la excepción y nunca corren; `PaymentRequestTest.php` lleva 4 clases (`:13,72,88,102`) |
| Docs | sin `docs/README.md`, nombres en inglés y sin prefijo de tipo (excepción de `project/documentation.md` regla 4); `docs/roadmap.md` es un plan de fases; `CHANGELOG.md:7` usa `[Unreleased]` en vez de `[Sin publicar]` |
| Rangos sin probar | `composer.json:8-14` admite PHP `^8.2\|^8.3\|^8.4` e `illuminate/*` `^11.0\|^12.0\|^13.0`; Testbench `^9.0\|^10.0` (`:18`) no cubre Laravel 13 y el lock local solo prueba Laravel 12 con PHP 8.3 (`tech/laravel-package.md` regla 5) |
| Dependencias de desarrollo | `composer audit` sobre el lock local da 20 avisos en `league/commonmark`, `guzzlehttp/guzzle`, `laravel/framework` y `league/flysystem` |
