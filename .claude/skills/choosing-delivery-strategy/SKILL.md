---
name: choosing-delivery-strategy
description: Elige con el usuario la estrategia de entrega de un proyecto (ramas, ambientes y forma de publicar en producción) con las preguntas de la guía «Ramas y ambientes», la registra como prácticas en claude-sync.json de _claude y en un ADR del proyecto, y dice qué workflows lleva. Usar cuando se cree un proyecto, se monte o rehaga su CI/CD, se pregunte qué ramas o ambientes usar, o un proyecto no tenga prácticas elegidas.
when_to_use: Al decidir o cambiar ramas, ambientes, despliegue o publicación de un proyecto.
---

# Elegir la estrategia de entrega

Fuente: [Ramas y ambientes](https://abitech.dev/blog/ramas-y-ambientes).
Las opciones son las reglas de `library/practices/` en
`C:/laragon/www/_claude`; lo invariable, `tech/continuous-integration`.

## Flujo

```
- [ ] 1. Ver lo que ya hay: workflows, ramas remotas, CLAUDE.md, ADR
- [ ] 2. Ramas: las cinco preguntas
- [ ] 3. Ambientes y forma de publicar
- [ ] 4. Proponer la elección y esperar el visto bueno
- [ ] 5. Registrar en claude-sync.json, restaurar y validar
- [ ] 6. ADR en el proyecto
- [ ] 7. Workflows según la tabla
```

**1. Lo que ya hay.** Un proyecto con estrategia propia la conserva: se
registra la que usa, no se cambia sin pedido (`git branch -r`,
`.github/workflows/`, `docs/decisiones/`).

**2. Ramas.** Vale la primera pregunta con «sí».

| Pregunta | Si es «sí» |
| --- | --- |
| ¿Mantiene varias versiones a la vez? | `branching/git-flow` |
| ¿Publica por oleadas a millones de usuarios? | `branching/release-flow` |
| ¿Alguien de fuera del equipo aprueba? | `branching/environment-branches` |
| ¿Tiene pruebas automáticas confiables? | `branching/trunk-based` |
| Ninguna de las anteriores | `branching/github-flow` |

- Si alguien de fuera aprueba pero la organización no exige ver cada
  ambiente en git, la guía prefiere no sumar ramas: `trunk-based` con
  `uat` y la imagen promovida.
- `production-branch` es la variante sin `staging` de
  `environment-branches`: `develop` arma la imagen y `main` publica.

**3. Ambientes y publicación.**

| Pregunta | Si es «sí» |
| --- | --- |
| ¿Alguien de fuera del equipo prueba antes de publicar? | `environments/local-uat-prod` |
| ¿Y el equipo necesita además un servidor compartido? | `environments/local-dev-uat-prod` |
| Ninguna | `environments/local-prod` |

| Ramas | Publicar |
| --- | --- |
| `trunk-based` con revisores en el plan (repo público, Team o Enterprise) | `release/reviewer` |
| `trunk-based` sin revisores (privado en Free) | `release/tag` |
| `github-flow`, `production-branch`, `environment-branches`, `git-flow` | `release/merge-to-main` |
| `release-flow` | la rama del sprint; ninguna de `release/` |

En toda estrategia, una sola imagen promovida (forma B).

**4. Proponer** la tabla con las respuestas, las tres prácticas y qué
cambia en ramas y workflows. Nada se escribe sin visto bueno.

**5. Registrar.** En `claude-sync.json`, el proyecto lleva
`"practicas": ["branching/…", "release/…", "environments/…"]`. Luego:

```bash
python C:/laragon/www/_claude/sync.py restaurar <proyecto>
python C:/laragon/www/_claude/sync.py validar <proyecto>
```

`validar` rechaza dos prácticas que chocan o una que falta
(`library/catalog.json`).

**6. ADR** en `docs/decisiones/` del proyecto (`tech/documentation`,
regla 6): las respuestas, la elección y sus consecuencias.

**7. Workflows.**

| Práctica | Archivos |
| --- | --- |
| `trunk-based` + `release/tag` | `ci.yml`, `build-image.yml`, `deploy.yml`, `deploy-<primer ambiente>.yml` en push a `main`, `deploy-prod.yml` en etiquetas `v*` |
| `trunk-based` + `release/reviewer` | lo mismo, con el job de `prod` tras el de `uat` en el mismo flujo |
| `github-flow` | `ci.yml`, `deploy-prod.yml` en push a `main` |
| `production-branch` | `ci.yml`, imagen en push a `develop`, `deploy-prod.yml` en push a `main` |
| `environment-branches` | `ci.yml` y un `deploy-<ambiente>.yml` por rama |
| `git-flow` | `ci.yml`, `deploy-dev.yml` en `develop`, `deploy-uat.yml` en `release/*`, `deploy-prod.yml` en `main` |

Referencia completa de `trunk-based` + `release/tag`: los workflows y
`docs/decisiones/0001-ramas-y-despliegue.md` de `pilot-ssh-api`.
