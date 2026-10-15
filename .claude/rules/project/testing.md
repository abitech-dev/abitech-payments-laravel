---
paths:
  - "tests/**"
  - "phpunit.xml.dist"
---

# Pruebas (abitech/payments-laravel)

DIRECTIVA: adapta `tech/testing.md` y la skill `testing-changes` a un
paquete sin app host; los comandos están en `CLAUDE.md` §2.

## Archivos modelo por cambio

| Cambio | Copiar de |
| --- | --- |
| Trait de `src/Concerns/` | `tests/Unit/Concerns/VerifiesWebhookSignatureTest.php` (`use` del trait en la prueba) |
| Reintentos o códigos HTTP | `tests/Unit/Concerns/RetriesApiCallsTest.php` |
| Montos de Stripe por moneda | `tests/Unit/Concerns/ConvertsStripeAmountsTest.php` |
| DTO | `tests/Unit/DTOs/PaymentRequestTest.php` |
| Resolución de drivers | `tests/Feature/PaymentManagerTest.php` |
| Driver falso o su API de aserciones | `tests/Feature/FakePaymentDriverTest.php` |
| Driver real con el SDK simulado | `tests/Feature/Drivers/Stripe/StripePaymentIntentsDriverTest.php` |

## Reglas

1. **PHPUnit sobre Testbench.** `composer.json` admite PHPUnit
   `^10|^11` y Testbench `^9|^10`; el lock local trae 11 y 10. Toda
   prueba extiende `Abitech\Payments\Tests\TestCase`, que registra
   `PaymentsServiceProvider`. Métodos `test_<comportamiento>` en inglés,
   por la excepción de idioma del `CLAUDE.md`.
2. **Espejo de `src/`.** `tests/Unit/` para traits, DTO y lógica pura;
   `tests/Feature/` para lo que pasa por el contenedor (manager,
   provider, middleware, drivers); `tests/Doubles/` para los dobles.
   Una clase por archivo, con el nombre del archivo.
3. **Nada sale a la red.** Un driver real se prueba con el transporte
   HTTP del SDK sustituido por `FakeStripeHttpClient` o
   `FakeMercadoPagoHttpClient` (`tests/Doubles/`), que se desinstalan en
   `tearDown()`; nunca con credenciales de sandbox. Cada operación cubre la respuesta feliz, el
   rechazo y el error HTTP reintentable.
4. **Webhooks.** Cada fuente prueba firma válida, firma falsa (403), sin
   cabeceras (403) y sin secreto configurado, como
   `VerifiesWebhookSignatureTest`.
5. **Excepción esperada.** Lo que se comprueba tras la llamada que lanza
   no se ejecuta; contar intentos o leer estado va en un `try/catch` o
   en `assertThrows()`.
   - Mal: `expectException()`, la llamada y luego `assertSame(3, $n)`.
   - Bien: `try { ...; } catch (PaymentGatewayException) {}` y después
     `assertSame(3, $n)`.
6. **Sin herramienta.** Se declaran como no cubiertos al entregar el
   formato (Pint), el análisis estático (PHPStan), la mutación y el SCA
   en CI; `composer audit` se corre en local.
