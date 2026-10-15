---
paths:
  - "src/Drivers/**"
  - "src/Concerns/**"
  - "src/DTO/**"
  - "src/Events/**"
  - "src/Http/**"
  - "src/Resolvers/**"
  - "src/PaymentManager.php"
---

# Drivers de pasarela

DIRECTIVA: un driver traduce y verifica, y guardar, deduplicar, cobrar
y reaccionar al resultado es de la app host.

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| Modelo Eloquent, `DB::`, `->save()` o tabla de negocio en el paquete | devolver el DTO; la app host persiste |
| `event(new PaymentSucceeded(...))` en un driver | devolver el DTO; la app host dispara el evento de `src/Events/` |
| `(int) round($monto * 100)` en un driver de Stripe | `$this->toStripeAmount($monto, $moneda)` |
| Llamar al SDK fuera de `retry()` | `return $this->retry(fn () => ...)` tras `throttle()` |
| `handleWebhook()` que acepta el aviso si falta el secreto | `PaymentGatewayException` de configuración, sin leer el cuerpo |
| Estado nuevo inventado (`'unknown'`, `'approved'`) | uno de los normalizados de la regla 3 |

## Reglas

1. **El paquete nunca persiste.** Ledger, facturas, wallet,
   suscripciones, claves de idempotencia y el log de webhooks son de la
   app host. `DatabaseConfigResolver` es la única lectura de BD y usa el
   modelo o el closure que le entrega el host.
2. **Orden canónico** de un método que llama a la pasarela.
   `validateCurrency()` → validaciones del DTO → `authenticate()` →
   `throttle('<slug>:<operacion>')` → `return $this->retry(...)`. Lo que
   falla en local no gasta token ni cupo del limitador.
   - Mal: `authenticate()` y `throttle()` antes de `validateCurrency()`.
   - Bien: `StripePaymentIntentsDriver::purchase()`, que valida moneda y
     `cardToken` antes de autenticar.
   - Sin moneda (`refund`, suscripciones) empieza en `authenticate()`.
   - Toda llamada al SDK, también las de `handleWebhook()`, va dentro de
     `retry()`.
3. **Estados normalizados.** `PaymentResponse` y `WebhookResult` usan
   `completed`, `pending`, `failed` y `refunded`. Cada driver los mapea
   en un `match` con `pending` por defecto; un aviso que no es un pago
   devuelve `ignored`.
4. **Montos.** El DTO trae el monto en unidad mayor. Mercado Pago lo
   recibe así; en Stripe toda conversión a la unidad menor y de vuelta
   pasa por `toStripeAmount()` y `fromStripeAmount()` de
   `ConvertsStripeAmounts`, que conoce las monedas sin decimales.
5. **Webhooks.**
   - `handleWebhook()` verifica la firma antes de leer el cuerpo, con
     `VerifiesWebhookSignature`. Sin secreto lanza excepción; sin
     cabeceras o con firma inválida, 403.
   - Tolerancia de 300 s sobre el instante firmado (Stripe con
     `constructEvent`; Mercado Pago con `ts`).
   - El `WebhookResult` lleva el evento crudo de la pasarela en `raw`.
6. **Errores reintentables.** `retry()` reintenta solo los códigos de
   `RetriesApiCalls::$retryableHttpCodes` (429, 500, 502, 503 y 504) con
   backoff exponencial. La excepción del SDK se envuelve en
   `PaymentGatewayException` con su código HTTP real, para que un 4xx no
   se reintente.
7. **Idempotencia.** `PaymentRequest::$idempotencyKey` viaja a la
   pasarela en toda operación que crea un cobro (`X-Idempotency-Key` en
   Mercado Pago, referencia externa en los checkout). Guardarla y
   comparar repetidos es del host; el middleware `abitech.idempotency`
   no se amplía.
8. **Credenciales.** Solo desde `$this->config`, nunca en un mensaje de
   excepción, en `raw` ni en un log.
9. **Estado en memoria.** `PaymentManager` es `scoped` y guarda cada
   driver creado durante la petición; un driver no guarda tenant, usuario
   ni petición en propiedades, y `forTenant()` devuelve otra instancia.
