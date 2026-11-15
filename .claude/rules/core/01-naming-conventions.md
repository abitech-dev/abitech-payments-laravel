# 01. Nombres (lenguaje del negocio)

DIRECTIVA: el nombre dice qué es en el idioma del negocio y, por sí
solo, qué hace.

## Estándares de referencia

- Clean Code (R. C. Martin) · CQS · DDD, lenguaje ubicuo

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| `status`, `flag` o `state` para un sí o no | predicado `is`, `has`, `can`, `should` |
| `process`, `handle`, `manage`, `do` | el verbo exacto (`sync`, `dispatch`, `register`) |
| `userList`, `userArray` | plural natural (`users`) |
| `usr`, `idx`, `req`, `obj` | la palabra completa |
| `User::getUserProfile()` | `User::getProfile()` |

## Reglas

1. **Predicados.** Un booleano se nombra como pregunta de sí o no. Un
   estado de varios valores es un enum con nombre del dominio.
2. **Consultas y comandos (CQS).** Una consulta devuelve datos sin mutar
   y se nombra por lo que devuelve (`siguienteCodigo()`) o con un verbo de
   extracción (`getProfile()`, `calculate`). Un comando muta sin devolver
   y lleva verbo de acción (`sync`, `update`, `dispatch`, `register`).
   Los métodos que impone el framework, como `handle()` de jobs, comandos
   y middleware, conservan su nombre.
3. **Lenguaje ubicuo.** El nombre refleja el negocio y no la tecnología,
   con plurales naturales y sin abreviaturas crípticas.
4. **Sin tartamudeo.** El nombre no repite el contexto que ya da su
   clase o módulo.
5. **Repositorios.** `[empresa]-[producto]-[componente]` en kebab-case
   (minúsculas, dígitos y guiones medios), con un solo sufijo al final:
   `-web` (cliente), `-panel` (backoffice), `-mobile`, `-ui` (librería
   de componentes), `-api`, `-worker` (colas y cron), `-ws`, `-app`
   (monolito), `-collection` (Bruno o Postman), `-e2e`, `-auth`,
   `-payments`, `-notifications`, `-iac`, `-k8s`, `-docs`, `-cli`
   (herramienta de línea de comandos y sus librerías) y `-sdk` (librería
   que usan otros programas). Una librería para un framework lleva el
   framework como sufijo (`-laravel`, `-vue`).
   - Mal: `Arsy_CoworkerAPI`, `good-money-api-collection`, `openchasqui-lib`.
   - Bien: `arsy-coworker-api`, `good-money-collection`,
     `abitech-openchasqui-cli`, `abitech-antibot-laravel`.
6. **Paquetes publicados.** La marca va en el scope o vendor, igual en
   todos los registros, y el producto en el nombre:
   `@abitechdev/openchasqui-core` en npm, `abitechdev/antibot-laravel` en
   Composer.
