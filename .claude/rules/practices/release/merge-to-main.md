---
paths:
  - ".github/workflows/**"
---

# Publicar: al unir a `main`

DIRECTIVA: el PR que entra a `main` es la aprobación, y su push publica en
`prod`.

## Estándares de referencia

- GitHub, *GitHub flow* · GitLab, *GitLab flow*

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| Push directo a `main` | PR revisado con la CI en verde |
| Recompilar si la imagen ya existe | promoverla por la huella del árbol |

## Reglas

1. **Disparo.** `deploy-prod.yml` corre en `push` a `main` y a mano.
2. **Imagen.** Si la rama anterior (`develop`, `staging`) ya armó la
   imagen de ese árbol, se promueve; si no, `ci` y se arma.
3. **Volver atrás.** Revertir el merge con un PR; el despliegue vuelve a
   correr.
4. **Cuándo conviene.** Con `github-flow`, `production-branch`,
   `environment-branches` o `git-flow`, donde `main` es lo publicado.
