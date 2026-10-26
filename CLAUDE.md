# CLAUDE.md

## 1. Proyecto

**abitech/payments-laravel.** Paquete Composer con los drivers de
pasarelas de pago (Mercado Pago y Stripe) para apps Laravel. Traduce DTO
a la API de cada pasarela y devuelve DTO normalizados; la app host
persiste, deduplica y decide.

- Genérico de Abitech y reutilizable fuera de Arsy. Hoy lo instala solo
  `arsy-account-app`, con `^2.0` desde el repositorio VCS.
- Tareas en `../../arsy-architecture/tareas/payments-laravel.md` (PL-01
  a PL-07 y deuda DT-01 a DT-19), con sus diseños en `disenos/`.
- Contrato v1 sin capas de compatibilidad hasta producción (ADR 0005),
  aplicado en `project/architecture.md` regla 8.
- Idioma (excepción de `core/04` regla 3). Clases, métodos, variables,
  tablas y columnas en inglés; mensajes de excepción, comentarios de
  columna, docs y commits en español.

## 2. Comandos

```bash
composer install
composer test                          # PHPUnit, Unit y Feature
vendor/bin/phpunit --testsuite Unit    # Unit | Feature
vendor/bin/phpunit --filter test_a_valid_mercadopago_signature_passes
composer validate
composer audit
```

PHPUnit y Orchestra Testbench (`phpunit.xml.dist`), sobre sqlite
`:memory:` y caché `array`, sin app host; las versiones las fija el lock
local (`project/testing.md` regla 1).

## 3. Puertas de calidad

La CI (`.github/workflows/ci.yml`) corre en cada push a `main`, la rama
de trabajo, y en cada PR hacia ella. Primero busca secretos con gitleaks
(excepciones en `.gitleaks.toml`), valida `composer.json` y audita las
dependencias resueltas. Luego corre `composer test` con PHP 8.2 y
Laravel 12 en `--prefer-lowest`, PHP 8.3 con Laravel 12, y PHP 8.3 y 8.5
con Laravel 13, que exige PHP 8.3, con el bloqueo de avisos de Composer
activo. Sin linters. Antes de entregar, estas tres con cero errores;
`composer audit` sale con 0 tras actualizar el lock local con
`composer update -W` dentro de los rangos.

```bash
composer test
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
2. **`composer.lock` y `vendor/` no se versionan.** El lock local solo
   sirve para probar; cada app host resuelve sus versiones dentro de los
   rangos de `composer.json`.
3. **Los SDK de pasarela no vienen con el paquete.** Están en `suggest`
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

## 6. Despliegue

- Un paquete no se despliega; se publica según
  `tech/laravel-package.md` regla 7. El push pide confirmación
  (`settings.json`).
- Account fija el tag en su `composer.lock`. Subir esa versión es un
  cambio de account, con su suite (`../../arsy-account-app/CLAUDE.md`
  §2 y §3).

## 7. Deuda conocida

Las tareas viven en el archivo de tareas de §1.

| Tema | Estado |
|---|---|
| Estado de avisos en Stripe | un evento que no es `payment_intent.*` ni `charge.refunded` devuelve `pending` en vez de `ignored` (`StripePaymentIntentsDriver.php:191-193`); tarea PL-01 |
| Firma de Mercado Pago | opcional sin secreto (`HandlesMercadoPagoWebhook.php:50-54`) y sin tolerancia de `ts` (`VerifiesWebhookSignature.php:75-111`); tarea PL-02 |
| Avisos que no son pago | devuelven `status: 'unknown'` (`HandlesMercadoPagoWebhook.php:68-75`); tarea PL-01 |
| Middleware de idempotencia | `EnforceIdempotencyKey` solo exige la cabecera; tarea PL-03 lo elimina |
| `incoming_webhook_logs` | migración publicable que PL-01 retira del paquete |
| Docs | sin `docs/README.md`, nombres en inglés y sin prefijo de tipo; excepción vigente de `project/documentation.md` regla 4 |
