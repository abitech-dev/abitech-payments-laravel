---
paths:
  - "**/*.php"
---

# Laravel (backend)

DIRECTIVA: lo que trae el framework antes que PHP propio; controladores
delgados y la lógica en servicios.

## Estándares de referencia

- PSR-12 · SOLID (controladores delgados, servicios de dominio) · REST

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| `array_map`, `array_filter`, `array_reduce` | `collect()` |
| `str_*` o regex manual de textos | `Str::`, `Arr::` |
| `date()`, `DateTime` | `now()`, Carbon |
| `fopen`, `move_uploaded_file`, rutas físicas | `Storage::` |
| SQL crudo sin necesidad | Eloquent o Query Builder |
| URL escrita a mano | `route()` nombrada |
| FQN en línea (`\App\Models\User`) | `use` en la cabecera |
| Reglas con pipes (`'required\|max:50'`) | arreglo (`['required', 'max:50']`) |
| `try/catch` mudo | `Log::error()` con usuario, entidad y excepción |
| Modelo crudo en la respuesta | `JsonResource` o el contrato del proyecto |

## Reglas

1. **Limpieza.** Pint antes de entregar, clases con `php artisan make:*`
   y sin código muerto. Al tocar un archivo, todos sus FQN en línea pasan
   a `use`; es la única excepción al alcance quirúrgico de `core/00`.
2. **Modelos.**
   - Asignación masiva acotada, con `$fillable` o con `$guarded` y
     `validated()` como única fuente, según el proyecto.
   - `casts()` con enums backed para los estados; accessors y mutators
     para sanear, scopes para filtros repetidos y observers para el ciclo
     de vida.
   - Route Model Binding; `findOrFail` solo con consulta adicional.
   - `softDeletes()` solo donde el negocio restaura el registro; lo
     financiero y legal no se borra. La PK la define el proyecto.
     Volúmenes grandes con `chunk()` o `lazy()`.
3. **Rutas y controladores.** Rutas agrupadas (`Route::controller`,
   `prefix`, `middleware`). El controlador valida, autoriza y delega en
   `app/Services/`, que no conoce `Request` ni `Response`. Lo que toca
   varias tablas va en transacción.
4. **Validación.** FormRequest en toda mutación, con
   `prepareForValidation()` para sanear y `bail` en las pesadas. Reglas
   cruzadas en una Custom Rule; mensajes en `lang/`.
5. **Códigos HTTP.** `200`, `201` al crear, `204` sin cuerpo, `401` sin
   sesión, `403` sin permiso, `404`, `409` cuando el estado del registro
   no admite la acción, `422` en validación y `500` en error interno.
   Idempotencia en flujos críticos (pagos, correlativos, stock).
6. **Desacoplamiento.** Lo que tarda más de 1–2 s va a cola. Efectos
   secundarios por eventos, avisos por Notifications, rate limits en
   middleware, y `Cache::remember()` con su `Cache::forget()` al mutar.
7. **Seguridad** (`core/05` en Laravel). Policy al inicio de cada acción
   y permisos `modulo.accion`; `throttle` en mutaciones públicas; CORS con
   lista blanca; secretos con `Hash::` o `encrypt()`; CSRF en rutas web.
8. **Pruebas** según `tech/testing.md`.
