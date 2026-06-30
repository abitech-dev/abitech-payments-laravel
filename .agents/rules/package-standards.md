---
trigger: always_on
---

# Package Rules (Composer Library + Strategy Pattern)

## 1. Arquitectura por capas y roles desacoplados
- **Controlador (Host App)** → llama al **Servicio (Host App)**.
- **Servicio (Host App)** → inyecta el `PaymentManager` del paquete → consume el driver → persiste los datos en base de datos local (Ledger/Invoices).
- **Drivers (Package)** → traducen el `PaymentRequest` DTO → llamada HTTP de la API de la pasarela (Stripe, MP) → devuelven `PaymentResponse` DTO.
- **Prohibido** realizar llamadas directas a APIs de pasarelas desde los controladores de la aplicación host.

## 2. Patrón Strategy y Factory (Drivers unificados)
- Toda pasarela **SIEMPRE** debe implementar `PaymentGatewayInterface` y heredar de `AbstractPaymentDriver`.
- Los drivers concretos deben agruparse por pasarela en subcarpetas de `Drivers/` (ej: `Drivers/MercadoPago/`).
- El `PaymentManager` es el único encargado de resolver y retornar la instancia de driver solicitada en tiempo de ejecución.

## 3. Desacoplamiento absoluto de Base de Datos
- **Prohibido** declarar modelos de Eloquent, migraciones o lógica de base de datos dentro del paquete.
- El paquete trabaja **SÓLO** con DTOs e Interfaces. La persistencia de datos es responsabilidad exclusiva de la aplicación host.

## 4. Importación obligatoria de clases (Prohibido namespaces inline)
- **Prohibido** calificar clases con namespaces inline en el cuerpo del código (ej: evitar `\App\Models\User::find()` o `\Abitech\Payments\DTO\PaymentRequest`).
- **SIEMPRE** importa todas las clases, interfaces y excepciones usando sentencias `use` al inicio de cada archivo.

## 5. Convenciones de código y mensajes
- Código (clases, métodos, variables, namespaces) → **inglés**.
- Mensajes (mensajes de error de excepciones, validaciones) → **español**.
- DTOs → inmutables (propiedades `readonly` y tipado estricto, PHP 8.2+).
- Todos los archivos PHP deben declarar obligatoriamente `declare(strict_types=1);` al inicio.

## 6. Comentarios cortos y concisos
- **Prohibido** escribir comentarios explicativos obvios (ej: evitar `// guarda el usuario`).
- Los comentarios indispensables deben ser **cortos y directos** al grano.
- Docblocks (`/** ... */`) → usar **SÓLO** en interfaces y firmas de métodos complejos.

## 7. Manejo de errores con excepciones específicas
- **Prohibido** retornar arrays asociativos de error (ej: evitar `['success' => false, 'error' => '...']`).
- Ante fallos de comunicación o rechazo de pago, arroja una excepción que extienda de `PaymentGatewayException`.
- Mensaje de excepción **SIEMPRE** descriptivo y en español.

## 8. Seguridad, Idempotencia y Auditoría
- **Idempotencia** → Toda creación de transacción en la API **SIEMPRE** debe requerir un encabezado `X-Idempotency-Key` y almacenarse en BD para prevenir doble cargo.
- **Cifrado de Credenciales** → **Prohibido** guardar credenciales de pasarela (Stripe/MP tokens) en texto plano. Usar cast `encrypted:json` en el modelo Eloquent de la Host App.
- **Auditoría de Webhooks** → Registrar **SIEMPRE** el payload crudo en `incoming_webhook_logs` antes de procesar su validación de firma para diagnóstico rápido.

## 9. Estándares de Base de Datos y Flexibilidad Dinámica
- Nombres de tablas y columnas → **estrictamente en inglés** usando `snake_case`.
- Cada columna de las migraciones (excepto `id` y `timestamps`) **SIEMPRE** debe incluir el modificador `->comment('...')`.
- El comentario debe ser **corto** (máximo 3 palabras) y en **español**.
- **Tipado de Claves Primarias Configurable** → Las migraciones del paquete **SIEMPRE** deben verificar la configuración `abitech_payments.primary_key_type` para generar claves de tipo `UUID` o `BigIncrement` de forma dinámica, adaptándose al estándar de la aplicación host.
- **Conexión de BD Configurable** → Las migraciones del paquete **SIEMPRE** deben leer la configuración `abitech_payments.connection` para permitir ejecutar las tablas de cobros en una base de datos/conexión separada si fuera necesario.

## 10. Respuestas y comportamiento de la IA
- La IA debe ser **extremadamente concisa** en sus explicaciones técnicas.
- Mostrar código directo y limpio, evitando explicaciones extensas o rodeos innecesarios.
- Seguir el formato convencional de commits en español: `<tipo>(<ámbito>): <descripción>` en minúsculas y sin punto final.
