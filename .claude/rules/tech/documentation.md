---
paths:
  - "docs/**"
  - "README.md"
  - "CHANGELOG.md"
  - "CONTRIBUTING.md"
  - "SECURITY.md"
---

# Documentación del proyecto

DIRECTIVA: cada documento responde a una sola necesidad del lector y vive
en un solo sitio. La documentación se versiona y se revisa con el código
(docs-as-code).

## Estándares de referencia

- Diátaxis: tutorial, guía práctica, referencia, explicación
- ADR (M. Nygard; plantilla MADR) para decisiones de arquitectura
- Keep a Changelog 1.1 + Semantic Versioning 2.0
- Google Developer Documentation Style Guide · Microsoft Writing Style
  Guide
- C4 model para diagramas de arquitectura · Mermaid como código

## Estructura

| Ruta | Contenido |
| --- | --- |
| `README.md` | qué es, requisitos, puesta en marcha, enlaces a `docs/`; nada más |
| `CHANGELOG.md` | cambios por versión (Keep a Changelog); `[Sin publicar]` arriba |
| `docs/README.md` | índice de `docs/` con una línea por documento |
| `docs/tutoriales/` | aprender haciendo: un recorrido completo que funciona |
| `docs/guias/` | lograr una tarea: despliegue, restaurar, alta de un recurso |
| `docs/referencia/` | consultar datos exactos: API, configuración, plantillas |
| `docs/explicacion/` | entender: arquitectura, flujos de negocio, conceptos |
| `docs/decisiones/` | ADR numerados: «0001-titulo-corto.md» |

Una carpeta sin documentos no se crea. En un proyecto pequeño basta
`README.md` + `docs/` plano con el prefijo del tipo en el nombre.

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| Guía que además explica y lista referencia | un documento por tipo, enlazados |
| Copiar un dato en dos documentos | un dueño y enlaces a él |
| Historial dentro de un documento vivo | `CHANGELOG.md`, ADR o git |
| Nombres «Doc1.md», «NOTAS FINAL v2.md» | `kebab-case` descriptivo |
| Captura de pantalla de un diagrama | Mermaid o C4 en texto |
| Ruta o URL absoluta a otro documento | enlace relativo |
| Secretos, contraseñas o datos personales | variables nombradas y datos de ejemplo |

## Reglas

1. **Nombres.** `kebab-case`, minúsculas, sin tildes ni espacios, en el
   idioma del contenido; dicen el tema («despliegue-uat.md») y nunca la
   fecha. En un `docs/` plano llevan además el tipo como prefijo
   («guia-despliegue-uat.md»). Únicas en mayúsculas: `README`, `CHANGELOG`,
   `CONTRIBUTING`, `SECURITY`, `LICENSE`.
2. **Esqueleto de todo documento.** Un solo `# Título` en tipo frase;
   una o dos líneas de para quién es y qué resuelve; secciones `##` en
   el orden en que se leen. Más de ~300 líneas: se parte.
3. **Guía práctica.** Requisitos previos, pasos numerados en imperativo
   con un comando o acción por paso, resultado esperado y cómo
   comprobarlo; problemas frecuentes al final.
4. **Referencia.** Tablas y listas completas, sin narración; generada
   desde el código cuando se pueda (OpenAPI, esquemas).
5. **Explicación.** El porqué y el contexto, con diagrama si aclara; sin
   pasos.
6. **ADR.** Título, estado (propuesta, aceptada, reemplazada por NNNN),
   contexto, decisión y consecuencias. No se edita una aceptada: se
   escribe otra que la reemplaza.
7. **Estilo** (Google). Presente, voz activa, frases cortas, términos
   del dominio iguales en todo el proyecto; código en bloques con
   lenguaje; fechas ISO 8601; imágenes con texto alternativo.
8. **Vigencia.** Un cambio de comportamiento actualiza su documento en
   el mismo cambio; un documento que ya no aplica se borra, no se marca
   «obsoleto».
