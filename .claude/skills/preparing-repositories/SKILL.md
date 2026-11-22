---
name: preparing-repositories
description: Prepara o revisa la presentación de un repositorio según `tech/readme.md` y `tech/repository.md`. Escribe el README con plantilla por tipo (aplicación, API, librería, CLI, configuración), capturas reales o diagramas Mermaid; fija la descripción, los temas y los ajustes de GitHub; agrega LICENSE, CHANGELOG y SECURITY.md según la visibilidad, y lo comprueba con un script. Usar cuando se cree un repositorio, se escriba o rehaga un README, se pida homologar la ficha, la licencia o el changelog, o se prepare una versión.
when_to_use: Al crear un repositorio, escribir o revisar su README, licencia, changelog, descripción o temas.
---

# Preparar un repositorio

Normas: `tech/readme.md` (README) y `tech/repository.md` (ficha,
licencia, versiones). Plantillas: [templates.md](templates.md).

## Flujo

```
- [ ] 1. Inventario: visibilidad, ficha actual, archivos de la raíz, manifiestos
- [ ] 2. Tipo: aplicación, API, librería, CLI o configuración
- [ ] 3. Hechos: versiones del manifiesto y comandos probados
- [ ] 4. Vista previa: capturas reales o diagrama
- [ ] 5. README desde la plantilla del tipo
- [ ] 6. LICENSE, CHANGELOG y SECURITY.md según la visibilidad
- [ ] 7. Comprobar con el script y a ojo
- [ ] 8. Ficha y ajustes en GitHub, con permiso del dueño
- [ ] 9. Commits por cambio lógico, por PR si el proyecto lo pide
```

**1. Inventario.**

```bash
gh repo view <dueño>/<repo> --json visibility,description,homepageUrl,repositoryTopics,hasWikiEnabled,hasProjectsEnabled,deleteBranchOnMerge,licenseInfo
ls -a
```

**3. Hechos.** Las versiones salen del manifiesto o del `Dockerfile`, no
de memoria. Cada comando de «Inicio rápido» se corre en una copia limpia
o en el entorno local del proyecto.

**4. Vista previa.**

- Con interfaz: capturas del ambiente `uat` con el navegador, a 1280×800,
  sin datos personales; tema claro y oscuro si el sistema los tiene.
  Se guardan en `docs/imagenes/` y se comprueba que pesen menos de 300 KB.
- Sin interfaz: un diagrama Mermaid de cómo encajan las piezas, de hasta
  12 nodos.

**5. README.** La plantilla del tipo; se borra la sección que no aplica.
La descripción es una sola frase de menos de 120 caracteres y se repite
igual en el *About* y en el `description` del manifiesto.

**6. Archivos.** Tabla «Archivos y ajustes» de `tech/repository.md`:
`LICENSE` siempre; `CHANGELOG.md` desde la primera entrega a `uat`;
`SECURITY.md` en un repositorio público.

**7. Comprobar.**

```bash
python ~/.claude/skills/preparing-repositories/scripts/check_readme.py README.md
```

- 0 errores. Revisa título, descripción, separadores, relleno, enlaces,
  imágenes, bloques de código y que «Licencia» cierre.
- A ojo: se lee como lo escribiría el equipo, sin frases genéricas ni
  adornos; el GitHub renderizado muestra bien tablas, imágenes y Mermaid.

**8. Ficha y ajustes.** Cambian lo que ve todo el que entra: se aplican
con el visto bueno del dueño.

```bash
gh repo edit <dueño>/<repo> --description "…" --homepage "…" \
  --add-topic <tema> --enable-wiki=false --enable-projects=false \
  --delete-branch-on-merge
gh api -X PUT repos/<dueño>/<repo>/vulnerability-alerts
```

En un repositorio público, además, *secret scanning* y *push protection*:

```bash
gh api -X PATCH repos/<dueño>/<repo> --input - <<'EOF'
{"security_and_analysis": {"secret_scanning": {"status": "enabled"},
 "secret_scanning_push_protection": {"status": "enabled"}}}
EOF
```

La imagen social de un repositorio público se sube a mano en
*Settings → Social preview*.

**9. Commits.** `docs(readme): …`, `chore(licencia): …` y
`docs(changelog): …` por separado (`core/03`).
