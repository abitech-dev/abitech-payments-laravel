---
paths:
  - "docs/**"
  - "README.md"
  - "CHANGELOG.md"
---

# Documentación (abitech/payments-laravel)

DIRECTIVA: adapta `tech/documentation.md` a un `docs/` plano que lee
quien integra el paquete en una app host.

## Reglas

1. **Dueños.**
   - `README.md` dice qué es, requisitos, instalación, variables y la
     tabla «Drivers disponibles» con cada slug.
   - `docs/architecture.md` explica las capas y guía el alta de una
     pasarela; `docs/host-integration.md` guía la integración en el host;
     `docs/migrations.md` es la referencia de las tablas publicables.
   - Cada pasarela tiene su referencia, con credenciales, drivers,
     monedas, webhooks y SDK.
2. **Pasarela o driver nuevo.** En el mismo cambio entran su referencia,
   su fila en «Drivers disponibles» del README, su variable en el bloque
   `.env` del README y su línea en `CHANGELOG.md`.
3. **Documento nuevo.** Plano en `docs/`, en español, en `kebab-case` y
   con el tipo como prefijo (`tech/documentation.md`, «Estructura»), como
   `docs/referencia-<pasarela>.md` o `docs/guia-despliegue.md`.
4. **Excepciones de `tech/documentation.md`.** Se anotan aquí con su
   motivo y su fila de deuda en `CLAUDE.md` §7.
   - Los seis documentos actuales de `docs/` (`docs/architecture.md`,
     `docs/stripe.md` y los demás) conservan su nombre en inglés y sin
     prefijo, porque el README y los propios docs los enlazan.
     Renombrar uno corrige sus enlaces en el mismo cambio.
   - `docs/` no tiene índice `docs/README.md`; el README del paquete
     enlaza las referencias de pasarela.
5. **CHANGELOG.** Secciones de Keep a Changelog en español (`Agregado`,
   `Cambiado`, `Corregido`, `Eliminado`) bajo `## [Sin publicar]`, que
   es la sección de arriba (`tech/documentation.md`). Una sección de
   arriba con otro nombre se renombra a `[Sin publicar]` en el mismo
   cambio. Publicar sigue `tech/laravel-package.md` regla 7.
6. **Ejemplos.** El código de ejemplo usa la API real del paquete
   (`Payment::driver('fake')`, DTO con argumentos nombrados) y datos de
   ejemplo, nunca credenciales.
