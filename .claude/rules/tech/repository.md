---
paths:
  - "LICENSE*"
  - "CHANGELOG.md"
  - "SECURITY.md"
  - "CONTRIBUTING.md"
  - "CODE_OF_CONDUCT.md"
  - "**/CODEOWNERS"
  - ".github/ISSUE_TEMPLATE/**"
  - ".github/pull_request_template.md"
---

# Repositorio: ficha, licencia y versiones

DIRECTIVA: todo repositorio se presenta igual: ficha clara, licencia
explícita, versiones con número y un changelog escrito para personas.

## Estándares de referencia

- GitHub Docs: *Classifying your repository with topics*, *Social media
  preview*, *Community health files*, *Best practices for repositories*
- choosealicense.com · SPDX (identificadores y `LicenseRef-`) · REUSE 3.3
- Keep a Changelog 1.1 · Semantic Versioning 2.0 · Conventional Commits
  (`core/03`)

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| Repositorio sin `LICENSE` | licencia explícita, abierta o propietaria |
| Descripción distinta en README, *About* y manifiesto | la misma frase en los tres |
| Tema con nombre de cliente o dato interno (es público) | temas genéricos de tecnología y dominio |
| Changelog copiado de `git log` | cambios notables agrupados por tipo |
| Versión por commit o por día | una versión por entrega publicada |
| `1.0.0` desde el primer commit | `0.1.0` hasta la primera entrega en producción |
| Volver a etiquetar una versión publicada | una nueva, `v1.4.1` |

## Ficha

1. **Descripción.** Una frase de 60 a 120 caracteres: qué es y para qué,
   sin repetir el nombre, sin punto final ni emojis, en el idioma del
   proyecto; una frase con verbo, sin dos puntos como conector.
   - Mal: «Backend de gestión y conexión a servidores SSH.» o «Pilot SSH:
     servidores y accesos».
   - Bien: «API que controla el acceso de cada equipo a sus servidores,
     con terminal en el navegador y auditoría».
2. **Temas.** De 5 a 10, en inglés: lenguaje o *framework*, dominio y
   tipo de artefacto. Solo `a-z`, dígitos y guion, hasta 50 caracteres.
   - Mal: `MiProyecto`, `awesome-app`, `cliente-x`.
   - Bien: `django`, `ssh`, `access-control`, `rest-api`.
3. **Sitio y vista social.** La URL pública del producto, si existe. En un
   repositorio público, imagen social de 1280×640 px en PNG de menos de
   1 MB (se sube desde *Settings*).

## Archivos y ajustes

| Pieza | Privado | Público |
| --- | --- | --- |
| `README.md`, `LICENSE`, `.gitignore` | sí | sí |
| `CHANGELOG.md` | si publica versiones | sí |
| `SECURITY.md` (cómo reportar una vulnerabilidad) | no | sí |
| `CONTRIBUTING.md` y plantillas de *issue* y PR | si colabora gente de fuera | si acepta contribuciones |
| Borrar la rama al unir el PR | sí | sí |
| Wiki, *Projects* y *Discussions* | apagados si no se usan | apagados si no se usan |
| Alertas de Dependabot | sí | sí |
| *Secret scanning* y *push protection* | plan de pago | sí |
| `main` protegida (PR y CI en verde) | plan de pago; si no, norma del equipo | sí |

## Licencia

4. **Siempre explícita.** `LICENSE` en la raíz; sin él rige «todos los
   derechos reservados» sin que nadie lo lea.
5. **Propietaria** en todo repositorio privado y en el público que no
   abre su código. Titular: la empresa dueña del sistema; año: el de la
   primera publicación.
   - `LICENSE`: `Copyright © <año> <titular>. Todos los derechos
     reservados.` y un párrafo, en tercera persona, que prohíbe copiar,
     modificar o distribuir sin autorización escrita del titular.
   - npm: `"license": "UNLICENSED"` y `"private": true`. Composer:
     `"license": "proprietary"`. Python: `license = "LicenseRef-Proprietary"`.
6. **Abierta** solo si el dueño lo decide: MIT por defecto; Apache-2.0
   si importa la concesión de patentes. El identificador SPDX va en el
   manifiesto.

## Versiones y changelog

7. **Número.** SemVer con etiqueta `vX.Y.Z` sobre `main`. `0.y.z` hasta la
   primera entrega con usuarios reales; `1.0.0` con ella. En una librería,
   `1.0.0` cuando su API pública es estable.
8. **Cuándo se escribe.** La entrada va en el PR que trae un cambio
   notable para quien usa el sistema, en `[Sin publicar]`. No llevan
   entrada los refactors, las pruebas ni la CI.
9. **Cuándo se corta.** Al publicar: cada paso a producción en una
   aplicación, cada publicación en el registro en una librería. Se publica
   cuando un conjunto coherente está listo, no por calendario.
10. **Forma.** Keep a Changelog: la versión más reciente arriba, fecha ISO,
    tipos `Añadido`, `Cambiado`, `Obsoleto`, `Eliminado`, `Corregido` y
    `Seguridad`, y enlaces de comparación al pie. Cada etiqueta lleva su
    *GitHub Release* con las notas de su sección.
11. **Desde cuándo.** El archivo nace con la primera entrega a un ambiente
    compartido (`uat`); antes basta el historial de git.
