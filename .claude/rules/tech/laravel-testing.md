---
paths:
  - "tests/**/*.php"
  - "phpunit.xml"
  - "phpstan.neon*"
  - "pint.json"
---
# Pruebas en Laravel — herramientas

DIRECTIVA: la herramienta de cada tipo de `tech/testing.md` en un
proyecto Laravel, y cómo se usa para que el resultado sea fiable.

## Herramienta por tipo

| Tipo | Herramienta | Cómo |
| --- | --- | --- |
| Estática | Larastan (PHPStan) y Pint | `phpstan analyse`; `pint --test` en CI |
| Unidad y feature | Pest (`pest-plugin-laravel`) | `php artisan test --parallel` |
| Contrato | Pest | `assertExactJsonStructure` |
| Base de datos | Pest + migraciones | `RefreshDatabase`; la suite migra en limpio |
| Rendimiento | Pest + `DB::listen` | tope de consultas por petición |
| Arquitectura | Pest `arch()` | presets `php`, `security` y `laravel` |
| Mutación | Pest `--mutate` | `--min` con el umbral de `tech/testing.md` |
| Cobertura | Pest `--coverage` (pcov) | `--min` en los módulos críticos |
| Dependencias | `composer audit` | en CI; alto o crítico bloquea |
| Código (SAST) | Semgrep `p/php` | en CI |
| Carga | k6 | contra staging, nunca producción |

## Reglas

1. **Niveles de Larastan.** Proyecto nuevo arranca en 6. Uno vivo sube
   por escalones (6 → 8) con cero errores en cada uno, y el escalón va
   en su propio commit.
   - El 9 y el 10 exigen tipar cada arreglo (`array{…}`, DTO): se suben
     cuando el proyecto ya modela sus datos así.
   - El 8 obliga a tratar el nulo sin `?->` de relleno. Lo cubren
     `@property-read` para el `BelongsTo` de llave `NOT NULL`, un punto
     único que da el usuario autenticado o lanza 401, `refresh()` en vez
     de `fresh()` y el valor que `whenLoaded()` pasa a su closure.
   - Prohibido baseline nuevo y `@phpstan-ignore` para pasar de nivel.
2. **Falsos del framework, no mocks a mano.** `Http::fake`,
   `Mail::fake`, `Queue::fake`, `Notification::fake`, `Storage::fake` y
   `Carbon::setTestNow`. `Http::preventStrayRequests()` en la base de
   pruebas: ninguna petición sale a la red.
3. **N+1 visible.** `Model::preventLazyLoading()` activo en pruebas; la
   bandeja lleva su prueba con el número de consultas fijado.
4. **Base de pruebas aislada.** SQLite en memoria o una base propia de
   pruebas; nunca la de desarrollo. Lo que use SQL propio de un motor se
   prueba contra ese motor.
5. **Un archivo modelo por tipo de cambio** en la regla de pruebas del
   proyecto; el nuevo se copia de ahí.
