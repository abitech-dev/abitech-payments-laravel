---
paths:
  - "src/**"
  - "config/**"
  - "database/**"
  - "routes/**"
  - "tests/**"
  - "composer.json"
  - "CHANGELOG.md"
  - ".gitattributes"
  - "phpunit.xml.dist"
---

# Paquete Laravel (Composer)

DIRECTIVA: el paquete se instala en apps que no conoce y recibe lo que
necesita de ellas por configuración, contrato o evento, nunca por su
código.

## Estándares de referencia

- Laravel docs, *Package Development* · Composer docs, *The composer.json
  schema*
- Semantic Versioning 2.0 · Keep a Changelog 1.1 · PSR-4
- Orchestra Testbench

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| `App\Models\User` u otra clase `App\` en `src/` | la clase leída de la config del paquete (`config('<paquete>.user_model')`) |
| `env()` fuera de `config/` | `config()`; con `config:cache`, `env()` devuelve `null` |
| Pedir que la app registre el provider | `extra.laravel.providers` (package discovery) |
| Modelo, tabla de negocio o ruta que decide la app | contrato que la app enlaza, evento que escucha o clave de config |
| SDK opcional en `require` | `suggest` y `require-dev`, `class_exists()` antes de usarlo y excepción en español si falta |
| `"version"` en `composer.json` | tag git `vX.Y.Z` |
| `laravel/framework` en `require` | los `illuminate/*` que usa `src/` |
| Probar dentro de una app real | Orchestra Testbench con `getPackageProviders()` |
| `php artisan make:*` | la clase a mano en `src/`, en su namespace PSR-4 |

## Reglas

1. **Provider.**
   - `register()` solo hace `mergeConfigFrom()` y bindings; `boot()`
     carga rutas, migraciones, middleware, listeners y publicaciones.
   - `publishes()` dentro de `runningInConsole()`, con tags
     `<paquete>-config` y `<paquete>-migrations`.
2. **Configuración.**
   - Un solo `config/<paquete>.php` con valores por defecto seguros; los
     secretos solo como `env('NOMBRE')`, sin valor real.
   - Una clave nueva se lee con su default (`config('pkg.clave', 10)`).
     `mergeConfigFrom()` solo fusiona el primer nivel, así que la copia
     publicada de una app no recibe una clave anidada nueva.
3. **Independencia del host.**
   - `src/` no nombra clases, rutas, vistas ni tablas de la app; lo que
     varía entre apps entra por config, interfaz o evento.
   - Una migración que altera una tabla de la app comprueba
     `Schema::hasColumn()` y su `down()` quita solo lo suyo.
4. **Rutas del paquete.** Solo las que son contrato del paquete, con
   nombre propio (`<paquete>.`) o desactivables por config cuando pueden
   chocar con las de la app (`/login`, `/logout`).
5. **Dependencias.**
   - Rangos explícitos de `php` e `illuminate/*` (`^11.0|^12.0`); ampliar
     uno exige probar con la versión menor y la mayor del rango.
   - Una dependencia que usa `src/` se declara aunque llegue transitiva.
6. **Residente en memoria.** El host puede correr con Octane. Un
   `singleton()` del paquete no guarda credenciales, usuario ni petición
   que cambien en caliente; `register()` no lee la petición
   (`tech/octane` donde el host lo lleve).
7. **Versión y CHANGELOG.**
   - SemVer sobre la API pública, que son contratos, DTO, config, rutas,
     eventos y migraciones. Quitar o cambiar una de ellas sube la mayor.
   - Todo cambio visible entra en la sección sin publicar del
     `CHANGELOG.md` en el mismo commit. Publicar mueve esa sección a la
     versión con fecha y crea el tag `vX.Y.Z` sobre ese commit.
8. **Distribución.** `.gitattributes` con `export-ignore` para lo que la
   app no ejecuta (`tests/`, `phpunit.xml.dist`, `.claude/`, `CLAUDE.md`).
   Sin eso el `vendor/` del host lleva pruebas e instrucciones de IA.
9. **Pruebas** según `tech/testing.md`.
   - Un `TestCase` del paquete extiende `Orchestra\Testbench\TestCase` y
     devuelve su provider en `getPackageProviders()`.
   - Config por prueba en `defineEnvironment()` o `config([...])`; BD
     sqlite `:memory:`; red con `Http::fake()` o un doble del SDK.

## Relación con `tech/laravel`

Rige también en un paquete, salvo estas partes, que son de una app.

| De `tech/laravel` | En un paquete |
| --- | --- |
| Regla 1, Pint antes de entregar | solo si está en `require-dev`; si no, se declara no cubierto |
| Regla 2, modelos | el paquete no declara modelos salvo que sea dueño de la tabla |
| Regla 3, `app/Services/` | `src/Services/` del paquete |
| Regla 7, Policy y permisos `modulo.accion` | los decide la app; el paquete expone middleware, gate o evento |
