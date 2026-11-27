---
paths:
  - ".github/workflows/**"
---

# Ramas: GitHub Flow

DIRECTIVA: todo cambio sale de `main` y vuelve a `main` por un PR revisado,
y lo que entra a `main` se publica.

## Estándares de referencia

- GitHub, *GitHub flow* · Conventional Branch

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| Rama `develop` o `staging` | ramas cortas hacia `main` |
| Merge sin revisión ni CI | PR con `ci.yml` en verde |

## Reglas

1. **Ramas.** `main` es lo publicado; el trabajo va en `feature/*` o
   `fix/*`, y lo urgente en `hotfix/*`, todas hacia `main` por PR.
2. **Ambientes.** `local`, una copia temporal por PR si el proveedor la
   da, y `prod`.
3. **Publicar.** `deploy-prod.yml` corre en `push` a `main` y llama a
   `ci` antes de desplegar.
4. **Cuándo no.** Si alguien de fuera del equipo aprueba antes de `prod`,
   hace falta `uat`: elegir `trunk-based` con `release/reviewer` o
   `release/tag`.
