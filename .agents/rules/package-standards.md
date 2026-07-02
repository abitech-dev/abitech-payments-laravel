---
trigger: always_on
---

# Package Rules (Composer Library + Laravel Strategy Pattern)

## 1. Arquitectura por capas y roles desacoplados
- **Controlador (Host App)** -> llama al **Servicio (Host App)**.
- **Servicio (Host App)** -> inyecta el `PaymentManager` del paquete -> consume el driver -> persiste los datos en base de datos local (ledger, invoices, wallets, subscriptions).
- **Drivers (Package)** -> traducen el `PaymentRequest` DTO -> llamada HTTP de la API de la pasarela (Stripe, Mercado Pago) -> devuelven `PaymentResponse` DTO.
- **Prohibido** realizar llamadas directas a APIs de pasarelas desde los controladores de la aplicacion host.

## 2. Patron Strategy y Factory (drivers unificados)
- Toda pasarela **SIEMPRE** debe implementar `PaymentGatewayInterface` y heredar de `AbstractPaymentDriver`.
- Los drivers concretos deben agruparse por pasarela en subcarpetas de `Drivers/` (ej: `Drivers/MercadoPago/`).
- El `PaymentManager` es el unico encargado de resolver y retornar la instancia de driver solicitada en tiempo de ejecucion.

## 3. Base de datos: infraestructura publicable, negocio en host
- El paquete puede incluir **configuracion y migraciones publicables opcionales** para infraestructura comun de pagos (ej: `payment_gateways`, `payment_gateway_methods`, `currencies`, `incoming_webhook_logs`).
- **Prohibido** declarar modelos Eloquent dentro del paquete.
- **Prohibido** ejecutar persistencia directa desde drivers, DTOs, contratos o manager del paquete.
- Los modelos Eloquent, relaciones, casts, scopes, policies y servicios de persistencia pertenecen exclusivamente a la aplicacion host.
- Las tablas de negocio como ledger, invoices, wallets, subscriptions o balances pertenecen exclusivamente a la aplicacion host.
- El paquete trabaja en runtime con DTOs, interfaces, excepciones, drivers y manager; la escritura/lectura de datos la orquesta la aplicacion host.

## 4. Importacion obligatoria de clases (prohibido namespaces inline)
- **Prohibido** calificar clases con namespaces inline en el cuerpo del codigo (ej: evitar `\App\Models\User::find()` o `\Abitech\Payments\DTO\PaymentRequest`).
- **SIEMPRE** importa todas las clases, interfaces y excepciones usando sentencias `use` al inicio de cada archivo.

## 5. Convenciones de codigo y mensajes
- Codigo (clases, metodos, variables, namespaces) -> **ingles**.
- Mensajes (mensajes de error de excepciones, validaciones) -> **espanol**.
- DTOs -> inmutables (propiedades `readonly` y tipado estricto, PHP 8.2+).
- Todos los archivos PHP deben declarar obligatoriamente `declare(strict_types=1);` al inicio.

## 6. Comentarios cortos y concisos
- **Prohibido** escribir comentarios explicativos obvios (ej: evitar `// guarda el usuario`).
- Los comentarios indispensables deben ser **cortos y directos** al grano.
- Docblocks (`/** ... */`) -> usar **SOLO** en interfaces y firmas de metodos complejos.

## 7. Manejo de errores con excepciones especificas
- **Prohibido** retornar arrays asociativos de error (ej: evitar `['success' => false, 'error' => '...']`).
- Ante fallos de comunicacion o rechazo de pago, arroja una excepcion que extienda de `PaymentGatewayException`.
- Mensaje de excepcion **SIEMPRE** descriptivo y en espanol.

## 8. Seguridad, idempotencia y auditoria
- **Idempotencia** -> Toda creacion de transaccion en la API **SIEMPRE** debe requerir un encabezado `X-Idempotency-Key`; la aplicacion host debe almacenarlo para prevenir doble cargo.
- **Cifrado de credenciales** -> **Prohibido** guardar credenciales de pasarela (Stripe/MP tokens) en texto plano. Usar cast `encrypted:json` en el modelo Eloquent de la Host App.
- **Auditoria de webhooks** -> Registrar **SIEMPRE** el payload crudo en `incoming_webhook_logs` antes de procesar su validacion de firma para diagnostico rapido. El registro lo realiza la aplicacion host.

## 9. Estandares de migraciones publicables
- Nombres de tablas y columnas -> **estrictamente en ingles** usando `snake_case`.
- Cada columna de las migraciones publicables (excepto `id` y `timestamps`) **SIEMPRE** debe incluir el modificador `->comment('...')`.
- El comentario debe ser **corto** (maximo 3 palabras) y en **espanol**.
- **Tipado de claves primarias configurable** -> Las migraciones publicables del paquete **SIEMPRE** deben verificar `abitech_payments.primary_key_type` para generar claves de tipo `UUID` o `BigIncrement` de forma dinamica.
- **Conexion de BD configurable** -> Las migraciones publicables del paquete **SIEMPRE** deben leer `abitech_payments.connection` para permitir ejecutar las tablas de infraestructura en una conexion separada si fuera necesario.
- Las migraciones publicables son infraestructura comun; no deben incluir reglas de negocio propias de una aplicacion host.

## 10. Respuestas y comportamiento de la IA
- La IA debe ser **extremadamente concisa** en sus explicaciones tecnicas.
- Mostrar codigo directo y limpio, evitando explicaciones extensas o rodeos innecesarios.
- Seguir el formato convencional de commits en espanol: `<tipo>(<ambito>): <descripcion>` en minusculas y sin punto final.
