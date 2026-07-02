# Implementation Roadmap (abitech/payments-laravel)

Este documento detalla el plan secuencial de implementacion de pasarelas de pago utilizando el paquete `abitech/payments-laravel`.

---

## Principio de arquitectura

El paquete debe ser reutilizable entre aplicaciones Laravel:

- El paquete provee config, contratos, DTOs, excepciones, drivers, `PaymentManager` y migraciones publicables opcionales para infraestructura comun.
- El paquete no provee modelos Eloquent obligatorios ni ejecuta persistencia directa.
- La aplicacion host define modelos, relaciones, casts, policies, servicios, ledger, invoices, wallets, subscriptions y cualquier logica de negocio.
- Las migraciones publicables del paquete son plantillas de infraestructura; una vez publicadas, la aplicacion host puede adaptarlas.

---

## 1. Fase 1: Inicializacion del paquete (logica y migraciones publicables)
- **Estructura base** -> crear carpetas `src/Contracts/`, `src/Drivers/`, `src/DTO/`, `src/Exceptions/`, `src/Providers/` y `database/migrations/`.
- **Contratos** -> crear `PaymentGatewayInterface` (firmas para `purchase`, `refund`, `payout` y `handleWebhook`). Si la complejidad crece, separar `WebhookHandlerInterface`.
- **DTOs unificados** -> definir `PaymentRequest` con soporte para `idempotencyKey`.
- **Configuracion del paquete (`config/abitech_payments.php`)** -> crear archivo con las llaves base:
  * `primary_key_type` -> `'uuid'` o `'int'` para definir dinamicamente el tipo de claves primarias.
  * `connection` -> conexion de base de datos a usar (`null` usa la default, o nombre de conexion secundaria).
  * `default` -> driver por defecto (ej: `mercadopago_checkout`).
  * `gateways` -> credenciales de Stripe/Mercado Pago cargadas desde `.env`.
- **Migraciones publicables** -> crear migraciones base opcionales (`payment_gateways`, `payment_gateway_methods`, `currencies`, `incoming_webhook_logs`, etc.) estructuradas para verificar `primary_key_type` y `connection`.
- **Service Provider** -> registrar `PaymentsServiceProvider` y configurar la publicacion de config y migraciones usando `$this->publishes(...)`.
- **Drivers de Mercado Pago** -> escribir `MercadoPagoCheckoutDriver` (Checkout Pro) y `MercadoPagoApiDriver` (Bricks) desde cero.
- **Factory** -> implementar `PaymentManager` para resolver los drivers.

## 2. Fase 2: Base de datos en la aplicacion host
- **Configuracion en Host** -> configurar en `config/abitech_payments.php` el tipo de clave primaria requerida (ej. `'uuid'` para Arsy, o `'int'` en otros proyectos).
- **Publicacion de migraciones base** -> ejecutar en la **Aplicacion Host** el comando de publicacion para importar las migraciones publicables del paquete:
  ```bash
  php artisan vendor:publish --tag=abitech-payments-migrations
  ```
- **Migraciones de negocio (locales)** -> crear manualmente en la **Aplicacion Host** las tablas del negocio si aplica (ej: ledger, invoices, subscriptions, wallets).
- **Modelos del host** -> definir en la **Aplicacion Host** los modelos Eloquent, relaciones, scopes, policies y casts necesarios.
- **Credenciales cifradas** -> configurar cast `encrypted:json` para las credenciales en el modelo `PaymentGateway` definido por la **Aplicacion Host**.

## 3. Fase 3: Integracion en la aplicacion host
- **Composer link** -> configurar repositorio `path` local o repositorio VCS y requerir `abitech/payments-laravel:dev-main` en la **Aplicacion Host**.
- **Panel Administrativo** -> crear UI y endpoints API en la **Aplicacion Host** para configurar dinamicamente credenciales y estados de las pasarelas y modalidades.
- **Checkout Centralizado** -> crear endpoint de checkout (ej. `/api/v1/billing/checkout`) en la **Aplicacion Host** que interactue con el paquete para generar links/sesiones de pago con validacion de llave de idempotencia.

## 4. Fase 4: Despachador de Webhooks (Webhook Dispatcher)
- **Recepcion de Webhooks** -> procesar webhooks de pasarelas en la **Aplicacion Host** -> registrar logs en `incoming_webhook_logs` antes de validar/procesar -> actualizar base de datos local (Ledger/Invoices) -> disparar eventos/webhooks firmados con `X-Signature` o el encabezado definido por el estandar interno a las aplicaciones cliente/satelite si el sistema es multi-aplicacion.

## 5. Fase 5: Conexion y limpieza de aplicaciones cliente
- **Limpieza de codigo** -> borrar integraciones antiguas y directas de pasarelas en las **Aplicaciones Cliente** que ahora seran centralizadas.
- **Conexion API** -> modificar el flujo de cobros de las **Aplicaciones Cliente** para redirigir al checkout centralizado de la **Aplicacion Host** y escuchar los webhooks firmados de retorno.

## 6. Fase 6: Documentacion y guias de uso (README y /docs)
- **README.md** -> crear el manual general en la raiz del paquete con instalacion basica, publicacion de config/migraciones (`vendor:publish`), configuracion de composer y variables `.env`.
- **Guias especificas** -> documentar flujos de Mercado Pago (`docs/mercadopago.md`) y Stripe (`docs/stripe.md`) con enlaces a la documentacion oficial del SDK de cada pasarela.
