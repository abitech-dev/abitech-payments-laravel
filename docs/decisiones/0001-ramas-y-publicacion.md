# 0001. Ramas y publicación

- Estado: aceptada
- Fecha: 2026-11-27

## Contexto

Este repositorio es un paquete Composer público. No tiene servidor ni
imagen de Docker. Lo que se entrega es una versión en Packagist, que las
apps instalan con `composer require`. Solo la última versión recibe
arreglos, y nadie de fuera del equipo aprueba una entrega.

## Decisión

- **`branching/github-flow`, la estrategia 2 de la guía
  [Ramas y ambientes](https://abitech.dev/blog/ramas-y-ambientes).** Sus
  preguntas llevan aquí, porque hay una sola versión, sin oleadas y sin
  aprobación de fuera.
  - `main` es la única rama larga. El trabajo va en `feature/*` o `fix/*`,
    siempre por PR, y `ci.yml` corre en cada PR.
- **`release/merge-to-main`.** Publicar es unir el PR que pasa la sección
  `[Sin publicar]` del CHANGELOG a una versión con fecha. Al llegar a
  `main`, `release.yml` crea la etiqueta `vX.Y.Z` y Packagist la toma por
  su webhook.
- **`environments/local-prod`.** `local` es la PC o la app host que prueba
  el paquete y `prod` es la versión publicada en Packagist.
- **Forma B, no aplica.** No hay imagen que construir ni promover.

## Consecuencias

- Las mismas prácticas en los tres paquetes Composer.
- Una versión publicada no se vuelve a etiquetar; un arreglo sale en la
  siguiente.
- Si un día hay que mantener dos versiones a la vez, se revisa esta
  decisión con la skill `choosing-delivery-strategy`.
