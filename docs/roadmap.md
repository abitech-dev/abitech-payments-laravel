# Implementation Roadmap (abitech/payments-laravel)

Este documento detalla el plan secuencial de implementación de pasarelas de pago utilizando el paquete `abitech/payments-laravel`.

---

## 1. Fase 1: Inicialización del Paquete (Lógica y Migraciones Flexibles)
- **Estructura base** → crear carpetas `src/Contracts/`, `src/Drivers/`, `src/DTO/`, `src/Exceptions/`, `src/Providers/` y `database/migrations/`.
- **Contratos** → crear `PaymentGatewayInterface` (firmas para `purchase`, `refund`, y `payout`) y `WebhookHandlerInterface`.
- **DTOs unificados** → definir `PaymentRequest` con soporte para `idempotencyKey`.
- **Configuración del Paquete (`config/abitech_payments.php`)** → crear archivo con las llaves base:
  * `primary_key_type` → `'uuid'` o `'int'` para definir dinámicamente el tipo de claves primarias.
  * `connection` → conexión de base de datos a usar (`null` usa la default, o nombre de conexión secundaria).
  * `default` → driver por defecto (ej: `mercadopago_checkout`).
  * `gateways` → credenciales de Stripe/Mercado Pago cargadas desde `.env`.
- **Migraciones Dinámicas** → crear archivos de migración base (`payment_gateways`, `payment_gateway_methods`, etc.) estructurados para verificar dinámicamente las configuraciones `primary_key_type` y `connection` antes de crear las tablas.
- **Service Provider** → registrar `PaymentsServiceProvider` y configurar la publicación de las migraciones base usando `$this->publishes(...)`.
- **Drivers de Mercado Pago** → escribir `MercadoPagoCheckoutDriver` (Checkout Pro) y `MercadoPagoApiDriver` (Bricks) desde cero.
- **Factory** → implementar `PaymentManager` para resolver los drivers.

## 2. Fase 2: Base de Datos en la Aplicación Host
- **Configuración en Host** → configurar en `config/abitech_payments.php` el tipo de clave primaria requerida (ej. `'uuid'` para Arsy, o `'int'` en otros proyectos).
- **Publicación de Migraciones Base** → ejecutar en la **Aplicación Host** el comando de publicación de assets para importar automáticamente las tablas del paquete adaptadas al tipo de clave configurado:
  ```bash
  php artisan vendor:publish --tag=abitech-payments-migrations
  ```
- **Migraciones de Negocio (Locales)** → crear manualmente en la **Aplicación Host** las tablas del negocio si aplica (ej: libro contable/ledger, invoices, suscripciones, wallets).
- **Modelos** → definir relaciones, scopes y configurar cast `encrypted:json` para las credenciales en el modelo `PaymentGateway` provisto por el paquete.

## 3. Fase 3: Integración en la Aplicación Host
- **Composer link** → configurar repositorio path local y requerir `abitech/payments-laravel:dev-main` mediante enlace simbólico en la **Aplicación Host**.
- **Panel Administrativo** → crear UI y endpoints API en la **Aplicación Host** para configurar dinámicamente credenciales y estados de las pasarelas y modalidades.
- **Checkout Centralizado** → crear endpoint de checkout (ej. `/api/v1/billing/checkout`) en la **Aplicación Host** que interactúe con el paquete para generar links/sesiones de pago con validación de llave de idempotencia.

## 4. Fase 4: Despachador de Webhooks (Webhook Dispatcher)
- **Recepción de Webhooks** → procesar webhooks de pasarelas en la **Aplicación Host** → registrar logs en `incoming_webhook_logs` (tabla del paquete) → actualizar base de datos local (Ledger/Invoices) → disparar eventos/webhooks firmados con `X-Signature` a las aplicaciones cliente/satélite si el sistema es multi-aplicación.

## 5. Fase 5: Conexión y Limpieza de Aplicaciones Cliente
- **Limpieza de código** → borrar integraciones antiguas y directas de pasarelas en las **Aplicaciones Cliente** que ahora serán centralizadas.
- **Conexión API** → modificar el flujo de cobros de las **Aplicaciones Cliente** para redirigir al checkout centralizado de la **Aplicación Host** y escuchar los webhooks firmados de retorno.

## 6. Fase 6: Documentación y Guías de Uso (README y /docs)
- **README.md** → crear el manual general en la raíz del paquete con la instalación básica, comando de publicación de migraciones (`vendor:publish`), link simbólico de composer y configuración del `.env`.
- **Guías Específicas** → documentar flujos de Mercado Pago (`docs/mercadopago.md`) y Stripe (`docs/stripe.md`) con enlaces a la documentación oficial del SDK de cada pasarela.
