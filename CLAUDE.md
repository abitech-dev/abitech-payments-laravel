# CLAUDE.md

## 1. Proyecto

**abitech/payments-laravel.** Paquete Composer con los drivers de
pasarelas de pago (Mercado Pago y Stripe) para apps Laravel. Traduce DTO
a la API de cada pasarela y devuelve DTO normalizados; la app host
persiste, deduplica y decide.

- Genérico de Abitech y reutilizable fuera de Arsy. Hoy lo instala solo
  `arsy-account-app`, como `dev-main` desde el repositorio VCS.
- Tareas en `../../arsy-architecture/tareas/payments-laravel.md` (PL-01
  a PL-07, versión 1.1, y deuda DT-01 a DT-15), con sus diseños en
  `disenos/`.
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

Sin CI ni linters en el repositorio. Antes de entregar, estas tres con
cero errores; `composer audit` sale con 0 tras actualizar el lock local
con `composer update -W` dentro de los rangos.

```bash
vendor/bin/phpunit
composer validate
composer audit --locked
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
| Códigos reintentables | `retry()` usa `RetriesApiCalls::$retryableHttpCodes` (`RetriesApiCalls.php:28`); `abitech_payments.retry.retryable_http_codes` (`config/abitech_payments.php:79`) no se lee; tarea DT-08 |
| Orden canónico | `StripeCheckoutDriver::purchase()` valida `success_url` y `cancel_url` después de `authenticate()` y `throttle()` (`StripeCheckoutDriver.php:96-110`); tarea DT-07 |
| Estado de avisos en Stripe | un evento que no es `payment_intent.*` ni `charge.refunded` devuelve `pending` en vez de `ignored` (`StripePaymentIntentsDriver.php:192-194`); tarea PL-01 |
| Firma de Mercado Pago | opcional sin secreto (`HandlesMercadoPagoWebhook.php:50-54`) y sin tolerancia de `ts` (`VerifiesWebhookSignature.php:75-111`); tarea PL-02 |
| Avisos que no son pago | devuelven `status: 'unknown'` (`HandlesMercadoPagoWebhook.php:68-75`); tarea PL-01 |
| Middleware de idempotencia | `EnforceIdempotencyKey` solo exige la cabecera; tarea PL-03 lo elimina |
| `incoming_webhook_logs` | migración publicable que PL-01 retira del paquete |
| `catch` mudo | `StripeCheckoutDriver.php:288` y `StripePaymentIntentsDriver.php:259` devuelven `[]` sin `Log::` (`tech/laravel`); tarea DT-09 |
| Versión | `composer.json:6` declara `"version": "1.0.0"` (aviso de `composer validate`) y el repo no tiene tags; tarea DT-14 |
| Distribución | `.gitattributes:2` excluye `/.agent` (la carpeta es `.agents`) y no excluye `.claude/` ni `CLAUDE.md` (`tech/laravel-package.md` regla 8); tarea DT-14 |
| Pruebas | de los 4 drivers reales solo se prueban `handleWebhook()`, el `health()` de Mercado Pago y, en `purchase()`, la clave de idempotencia y la ausencia de eventos (`tests/Feature/Drivers/`); sin pruebas de `EnforceIdempotencyKey`, `DatabaseConfigResolver` ni publicaciones del provider; `RetriesApiCallsTest.php:39,53` comprueban tras la excepción y nunca corren; `PaymentRequestTest.php` lleva 4 clases (`:16,94,110,124`); tarea DT-10 |
| Docs | sin `docs/README.md`, nombres en inglés y sin prefijo de tipo; excepción vigente de `project/documentation.md` regla 4 |
| Rangos sin probar | `composer.json:8-16` admite PHP `^8.2\|^8.3\|^8.4` e `illuminate/*` `^11.0\|^12.0\|^13.0`; Testbench `^9.0\|^10.0` (`:20`) no cubre Laravel 13 y el lock local solo prueba Laravel 12 con PHP 8.3 (`tech/laravel-package.md` regla 5); tarea DT-11 |
