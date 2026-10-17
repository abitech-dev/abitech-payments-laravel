---
paths:
  - "tests/**"
  - "**/*.test.{js,ts}"
  - "**/*.spec.{js,ts}"
  - "**/{test_*,*_test}.py"
  - "**/*_test.go"
  - "**/src/test/**"
  - "phpunit.xml"
  - "pytest.ini"
  - "vitest.config.*"
  - "playwright.config.*"
---

# Pruebas — estrategia por capas

DIRECTIVA: cada cambio llega con la prueba de la capa más baja que pueda
demostrarlo. Feature, endpoint o componente sin prueba = incompleto.
Procedimiento: skill `testing-changes`.

## Estándares de referencia

- Fowler, *The Practical Test Pyramid* · Dodds, *Testing Trophy*
- ISTQB: unidad, integración, sistema, aceptación
- Testing Library: probar como lo usa la persona, no la implementación
- OWASP WSTG (pruebas de los controles de `core/05`) · WCAG 2.2 ·
  Core Web Vitals

## Tipos de prueba

| Tipo | Herramienta | Cuándo |
| --- | --- | --- |
| Estática | tipos y linter del lenguaje: PHPStan, `tsc`, mypy, ESLint, Ruff | siempre (puerta) |
| Arquitectura | Pest `arch()`, ArchUnit, dependency-cruiser, import-linter | al fijar una regla de capas |
| Unidad backend | runner del lenguaje: Pest, PHPUnit, pytest, JUnit, `go test` | lógica con ramas |
| Feature backend | runner + base de pruebas aislada | todo endpoint y job |
| Contrato de API | feature que fija la forma exacta, OpenAPI o Pact | endpoint de app nativa o terceros |
| Unidad frontend | Vitest, Jest | toda lógica de cliente |
| Componente | Testing Library del framework (Vue, React, Svelte) + jsdom | todo componente reutilizable |
| E2E | Playwright | flujos críticos; lo que solo pasa en el navegador |
| Compatibilidad | Playwright: Chromium, Firefox, WebKit | flujos críticos, antes de publicar |
| Responsivo | Playwright, anchos 320–1920 | pantalla nueva |
| Accesibilidad | `@axe-core/playwright` + teclado | pantalla nueva |
| Regresión visual | Playwright `toHaveScreenshot` | kit y pantallas estables |
| Rendimiento | Lighthouse CI / `web-vitals` (LCP ≤ 2.5 s, CLS ≤ 0.1, INP ≤ 200 ms) | pantallas críticas |
| Carga | k6 / Artillery | antes de producción |
| Dependencias (SCA) | `composer audit`, `npm audit`, `pip-audit`, `govulncheck` | cada publicación y paquete nuevo |
| Código (SAST) | Semgrep `p/owasp-top-ten` | en CI |
| Secretos | gitleaks sobre el historial | en CI |
| Dinámica (DAST) | OWASP ZAP baseline contra staging | antes de cada entrega |
| Pentest | manual guiado por WSTG | antes de producción y anual |
| Mutación | Pest `--mutate` / Infection / Stryker | módulos críticos |
| Recuperación | restaurar un respaldo en limpio | periódicamente |
| Humo | petición HTTP tras desplegar | cada despliegue |
| Aceptación | guion con el cliente | cada entrega |

## Qué exige cada cambio

| Cambio | Pruebas mínimas |
| --- | --- |
| Regla de negocio o servicio | unidad o feature, con el caso límite |
| Endpoint nuevo o cambiado | regla 5 completa + camino feliz |
| Permiso, rol o máquina de estados | cada acceso y transición permitida y prohibida |
| Subida de archivo | MIME real, tamaño, archivo disfrazado → 422 |
| Aviso, correo o job | destinatarios con fakes; nada sale a la red |
| Componente reutilizable | render, props, eventos, carga/vacío/error, teclado |
| Composable o utilidad | entradas y salidas, sin DOM |
| Pantalla | componente + E2E en móvil y escritorio |
| Sin conexión | pantalla y almacenamiento local a la vez |
| Dependencia nueva | SCA sin hallazgos altos |

## Reglas

1. **Estructura.** Espejo del código según la convención del runner; el
   nombre dice el comportamiento
   (`it('deshabilita el envío mientras procesa')`).

2. **Diseño.**
   - Arrange-Act-Assert; un comportamiento por test.
   - Deterministas: sin red real, sin orden entre tests, tiempo fijo
     (`Carbon::setTestNow`, `vi.useFakeTimers`, `freezegun`); prohibido
     `sleep`.
   - Falsos solo en el borde (red, correo, colas, reloj); nunca mockear
     la clase bajo prueba.
   - Datos con factories y BD aislada; nunca la BD de desarrollo.

3. **Componentes.** Probar lo visible (texto, atributos, eventos,
   peticiones), nunca `wrapper.vm`. Selectores por rol, texto o
   `data-test`. jsdom no trae `ResizeObserver`: `vi.stubGlobal`.

4. **E2E.**
   - Selectores por rol y nombre accesible, no por clase ni posición.
   - Esperar por estado visible o respuesta, nunca por tiempo.
   - Sesión reutilizada (`storageState`): el acceso limita intentos.
   - Lo que la prueba muta, lo restaura; marcar un estado distinto del
     actual o no se ejercita nada.
   - `fill`, no `type`, para textos largos.
   - Imagen: `naturalWidth > 0`. Offline: pantalla **y** IndexedDB.
   - Fallar ante errores de consola no provocados por la prueba.

5. **Seguridad por endpoint** (`core/05`). Sin sesión → 401; sin
   permiso → 403; **recurso ajeno → 403/404** (IDOR); campo no permitido
   ignorado; entrada inválida → 422 por campo sin traza; estado que no
   admite la acción → 409; cargas `<script>` y `' OR 1=1 --` vuelven
   como texto. Acceso: 429 tras el límite y POST sin CSRF → 419.

6. **Mantenimiento.**
   - Error corregido = prueba que lo reproduce, en la capa donde vive,
     vista en rojo antes del arreglo (`core/07`).
   - Suite verde antes de entregar, en CI si el proyecto la tiene; si
     no, en local antes de subir.
   - Tipo aplicable sin herramienta en el proyecto: se declara como no
     cubierto al entregar, no se simula.
