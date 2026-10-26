---
paths:
  - "src/**"
  - "config/**"
  - "database/**"
  - "composer.json"
---

# Arquitectura (abitech/payments-laravel)

DIRECTIVA: `PaymentManager` resuelve un driver por slug (Strategy +
Factory) y cada driver traduce DTO a la API de una pasarela, como
concreción de `tech/laravel-package.md`.

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| `new StripeCheckoutDriver(...)` en la app o en otro driver | `$manager->driver('stripe_checkout')` o `$manager->forTenant($id)->driver(...)` |
| Driver suelto en `src/Drivers/` | `src/Drivers/<Pasarela>/<Pasarela><Producto>Driver.php` |
| Archivo PHP sin `declare(strict_types=1);` | la declaración tras `<?php` |
| DTO con propiedades mutables o sin tipo | `public readonly` tipado en el constructor |
| Clase del SDK en un string (`'Stripe\StripeClient'`) para no depender de él | `use Stripe\StripeClient;` arriba; `use` no carga la clase si el SDK falta |

## Reglas

1. **Capas.**
   - App host (controlador → servicio) → `PaymentManager` →
     `AbstractPaymentDriver` → driver concreto → API de la pasarela.
   - `src/` lleva `PaymentManager.php` en la raíz y las carpetas
     `Contracts`, `DTO`, `Drivers`, `Concerns`, `Events`, `Exceptions`,
     `Http`, `Resolvers`, `Providers` y `Facades`. Una carpeta nueva se
     justifica en esta regla.
2. **Resolución de drivers.**
   - `PaymentManager` extiende `Illuminate\Support\Manager`, que pasa el
     slug a StudlyCase. Así `stripe_paymentintents` exige el método
     `createStripePaymentintentsDriver()`, con esa mayúscula.
   - Un driver nuevo lleva su método `create<Slug>Driver()`, su entrada
     en `getPaymentMethodSchemas()` y su `paymentMethodSchema()`
     estático, que el panel del host usa para pintar el formulario.
   - Todo driver extiende `AbstractPaymentDriver` (que implementa
     `PaymentGatewayInterface`); el de suscripciones implementa además
     `SubscriptionInterface`.
3. **Credenciales.** `resolveGatewayConfig('<pasarela>')` las toma del
   `ConfigResolverInterface` si el host lo enlaza (el provider lo
   inyecta al resolver el manager) y si no de
   `abitech_payments.gateways.<pasarela>`. Las dos variantes de una
   pasarela comparten clave (`mercadopago`, `stripe`).
4. **Lógica compartida en `src/Concerns/`.** `RateLimitsApiCalls`,
   `RetriesApiCalls`, `VerifiesWebhookSignature`, `ValidatesAmounts`,
   `ConvertsStripeAmounts`, `ConvertsMercadoPagoAmounts`,
   `ReadsStripeCards` y los `HandlesMercadoPago*`. Un segundo driver que repite un bloque lo
   mueve a un trait; con un solo consumidor se queda en el driver
   (`core/07`).
5. **SDK opcionales.** `mercadopago/dx-php` y `stripe/stripe-php` van en
   `suggest` y `require-dev`. `authenticate()` comprueba que el SDK
   existe y, si falta, lanza `PaymentGatewayException` con el
   `composer require` exacto.
6. **Errores.** Nunca arreglos `['success' => false]`. Toda falla es una
   `PaymentGatewayException` o subclase, con mensaje en español y código
   HTTP; `toResponse()` la convierte en `{ success, message, errors }`.
7. **Migraciones publicables.** Son infraestructura común sin reglas de
   negocio (`currencies`, `payment_gateways`, `payment_gateway_methods`,
   `payment_method_currency`).
   - Leen `abitech_payments.connection` en el constructor y
     `abitech_payments.primary_key_type` (`uuid` o `int`) en `up()`.
   - Tablas y columnas en inglés `snake_case`; cada columna, salvo `id`
     y timestamps, con `->comment()` en español de hasta 3 palabras.
   - `credentials` es `text`, no `json`, porque el host la guarda con el
     cast `encrypted:json`.
8. **Contrato v1** (ADR 0005). Hasta producción, quitar o cambiar API
   pública no deja alias ni capa de compatibilidad. La versión sube la
   mayor según `tech/laravel-package.md` regla 7, como en 2.0.0, y
   account sube su restricción en el mismo trabajo.
