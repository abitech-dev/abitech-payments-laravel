# 05. Seguridad base (universal)

DIRECTIVA: lo que todo código debe cumplir. Cómo se prueba cada control
lo dice `tech/testing.md`, regla 5.

## Estándares de referencia

- OWASP Top 10 · OWASP ASVS nivel 2 · OWASP API Security Top 10
- CWE Top 25 · NIST SSDF (SP 800-218)

## Reglas de seguridad

1. **Secretos.**
   - Prohibido credenciales, tokens o API keys en código, commits,
     ejemplos o documentación; van en variables de entorno.
   - Prohibido loguear secretos o PII sensible (contraseñas, tokens,
     datos biométricos).

2. **Entradas y salidas.**
   - Toda entrada externa se valida server-side; el frontend solo asiste.
   - Queries siempre parametrizadas; prohibido concatenar input en
     SQL o comandos de shell.
   - Render crudo (`v-html`, `{!! !!}`) prohibido con datos de usuario.
   - Solo se persiste lo validado: nada de asignación masiva del cuerpo.
   - La respuesta no lleva campos que el cliente no usa (claves, tokens,
     secretos de 2FA).

3. **Autorización.**
   - Toda ruta/acción nueva verifica permiso explícito en el servidor;
     ocultar UI no es control de acceso.
   - IDs de recursos validados contra el contexto del usuario/tenant
     (anti-IDOR).

4. **Autenticación y sesión.**
   - Límite de intentos en el acceso; mismo mensaje para usuario
     inexistente y clave errónea.
   - Sesión regenerada al entrar e invalidada al salir y al cambiar la
     clave.
   - Cookies `HttpOnly`, `Secure` y `SameSite=Lax`; CSRF en toda mutación
     con sesión.

5. **Archivos subidos.** Tipo validado por contenido (MIME real), tamaño
   máximo, nombre regenerado y fuera de `public/` salvo lo público.

6. **Cabeceras y errores.**
   - `Content-Security-Policy`, `Strict-Transport-Security`,
     `X-Content-Type-Options: nosniff`, `Referrer-Policy` y
     `frame-ancestors`.
   - En producción, un error no expone traza, rutas ni variables de
     entorno.

7. **Dependencias.**
   - No agregar paquetes sin necesidad real ni abandonados; revisar
     advisories al incorporar uno nuevo.
   - Hallazgo alto o crítico de auditoría bloquea la entrega.
