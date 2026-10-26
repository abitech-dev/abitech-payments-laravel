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
# Pruebas — tipos por capa y cuándo se exige cada uno

DIRECTIVA: cada cambio llega con la prueba de la capa más baja que pueda
demostrarlo. Feature, endpoint o componente sin prueba = incompleto.
Las herramientas de cada tecnología están en su regla `*-testing.md`; el
procedimiento, en la skill `testing-changes`.

## Estándares de referencia

- Fowler, *The Practical Test Pyramid* · Dodds, *Testing Trophy*
- ISTQB: unidad, integración, sistema, aceptación
- Testing Library: probar como lo usa la persona, no la implementación
- OWASP WSTG (los controles de `core/05`) · WCAG 2.2 AA · Core Web Vitals

## Backend

| Tipo | Qué verifica | Obligatorio | Umbral |
| --- | --- | --- | --- |
| Estática | tipos, estilo y errores sin ejecutar | siempre (puerta) | cero errores, sin baseline nueva |
| Unidad | una función o clase con ramas, aislada | regla de negocio o cálculo | cada rama y el caso límite |
| Feature / API | ruta, permiso, validación, respuesta y efectos | todo endpoint y job | regla 5 completa + camino feliz |
| Contrato | la forma exacta del JSON | API de app nativa o tercero | estructura exacta |
| Base de datos | migraciones, restricciones, transacción, integridad | migración o consulta con lógica | migra en limpio; sin N+1 |
| Rendimiento | consultas y latencia de una petición | endpoint de bandeja o crítico | consultas acotadas; p95 ≤ 300 ms |
| Carga | el servidor con usuarios concurrentes | antes de producción | p95 dentro de presupuesto sin errores |

## Frontend

| Tipo | Qué verifica | Obligatorio | Umbral |
| --- | --- | --- | --- |
| Unidad | utilidad, composable o formateo | toda lógica de cliente | entradas y salidas, sin DOM |
| Componente | render, props, eventos, carga/vacío/error, teclado | componente reutilizable | lo visible, nunca el estado interno |
| Integración de UI | piezas de una pantalla y su estado compartido | pantalla con flujo propio | el flujo entero sin servidor real |
| E2E | el recorrido real en un navegador | flujo crítico; lo que solo pasa ahí | sin errores de consola |
| Accesibilidad | WCAG 2.2 AA automático y teclado | pantalla nueva o cambiada | cero violaciones serias o críticas |
| Responsivo | 320 a 1920 px | pantalla nueva | sin scroll horizontal |
| Regresión visual | capturas contra la referencia | kit y pantallas estables | sin diferencia no aprobada |
| Rendimiento web | carga y fluidez de laboratorio | pantallas críticas | LCP ≤ 2,5 s, CLS ≤ 0,1, TBT ≤ 200 ms, Lighthouse ≥ 90 |
| Compatibilidad | Chromium, Firefox y WebKit | flujos críticos, antes de publicar | mismo resultado en los tres |

## Transversal

| Tipo | Qué verifica | Obligatorio | Umbral |
| --- | --- | --- | --- |
| Arquitectura | capas y dependencias permitidas | al fijar una regla de capas | ninguna violación |
| Dependencias (SCA) | advisories de lo instalado | cada publicación y paquete nuevo | ningún alto o crítico |
| Código (SAST) | patrones inseguros | en CI | ningún alto |
| Secretos | credenciales en el historial | en CI | cero |
| Dinámica (DAST) | la app desplegada | antes de cada entrega | ningún alto |
| Mutación | si las pruebas detectan cambios | módulos críticos | ≥ 70 % de mutantes muertos |
| Humo | la app responde tras desplegar | cada despliegue | rutas clave en 200 |
| Recuperación | restaurar un respaldo en limpio | periódicamente | datos íntegros |
| Pentest | manual guiado por WSTG | antes de producción y anual | sin críticos abiertos |
| Aceptación | guion con el cliente | cada entrega | firmado |

## Qué exige cada cambio

| Cambio | Pruebas mínimas |
| --- | --- |
| Regla de negocio o servicio | unidad o feature, con el caso límite |
| Endpoint nuevo o cambiado | regla 5 completa + camino feliz |
| Bandeja o listado | feature con el número de consultas acotado |
| Permiso, rol o máquina de estados | cada acceso y transición permitida y prohibida |
| Subida de archivo | MIME real, tamaño, archivo disfrazado → 422 |
| Aviso, correo o job | destinatarios con fakes; nada sale a la red |
| Componente reutilizable | render, props, eventos, carga/vacío/error, teclado |
| Composable o utilidad | entradas y salidas, sin DOM |
| Pantalla | componente + E2E en móvil y escritorio + accesibilidad |
| Sin conexión | pantalla y almacenamiento local a la vez |
| Dependencia nueva | SCA sin hallazgos altos |

## Niveles de ejecución

Cada nivel corre solo si pasó el anterior, así lo rápido protege a lo
caro. Dónde corre cada uno, local o en la CI, lo dice
`tech/continuous-integration.md` regla 6.

| Nivel | Cuándo | Qué corre | Tope |
| --- | --- | --- | --- |
| 1. Cambio | antes de cada commit | estática y pruebas del área tocada | 2 min |
| 2. Push | en cada push | estática, unidad, feature, componentes y compilación | 10 min |
| 3. Integración | antes del PR a la rama de entrega | E2E de los flujos en un celular y una laptop | 10 min |
| 4. Entrega | antes de cada entrega o cada noche | E2E en todos los anchos, accesibilidad, visual, rendimiento y auditorías | sin tope |

## Reglas

1. **Estructura.** Espejo del código según la convención del runner; el
   nombre dice el comportamiento
   (`it('deshabilita el envío mientras procesa')`).
2. **Diseño.**
   - Arrange-Act-Assert; un comportamiento por test.
   - Deterministas: sin red real, sin orden entre tests, reloj falso del
     framework; prohibido `sleep`.
   - Falsos solo en el borde (red, correo, colas, reloj); nunca mockear
     la clase bajo prueba.
   - Datos con factories y BD aislada; nunca la BD de desarrollo.
3. **Componentes.** Probar lo visible (texto, atributos, eventos,
   peticiones), nunca el estado interno. Selectores por rol, texto o
   `data-test`.
4. **E2E.**
   - Selectores por rol y nombre accesible, no por clase ni posición.
   - Esperar por estado visible o respuesta, nunca por tiempo.
   - Sesión reutilizada: el acceso limita intentos.
   - Lo que la prueba muta, lo restaura; marcar un estado distinto del
     actual o no se ejercita nada.
   - Fallar ante errores de consola no provocados por la prueba.
5. **Seguridad por endpoint** (`core/05`). Sin sesión → 401; sin
   permiso → 403; **recurso ajeno → 403/404** (IDOR); campo no permitido
   ignorado; entrada inválida → 422 por campo sin traza; estado que no
   admite la acción → 409; cargas `<script>` y `' OR 1=1 --` vuelven
   como texto. Acceso: 429 tras el límite y POST sin CSRF → 419.
6. **Mantenimiento.**
   - Error corregido = prueba que lo reproduce, en la capa donde vive,
     vista en rojo antes del arreglo (`core/07`).
   - Suite verde antes de entregar. La CI corre las mismas puertas
     (`tech/continuous-integration.md`).
   - Tipo aplicable sin herramienta en el proyecto: se declara como no
     cubierto al entregar, no se simula.
