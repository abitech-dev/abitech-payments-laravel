---
name: configuring-ai-setup
description: Crea, revisa o refactoriza la configuración de Claude Code de un proyecto (CLAUDE.md, reglas en .claude/rules, skills, hooks, permisos, subagentes, MCP) según el estándar oficial, decide qué va en global, en la biblioteca o en el proyecto, y la verifica con un validador, una prueba de carga real de reglas, pruebas de comportamiento y una prueba de hooks dentro de una sesión. Usar cuando se pida crear o cambiar una regla, skill, hook o CLAUDE.md, sumar un proyecto o un stack, auditar el costo de contexto o comprobar que la configuración funciona.
---

# Configurar Claude Code

Norma: `core/06-ai-instructions.md` (dónde va cada pieza) y
`core/08-instruction-writing.md` (cómo se escribe). Esta skill es el
procedimiento y sus comprobaciones.

## Flujo

```
- [ ] 1. Elegir mecanismo, capa y nombre (tablas de core/06)
- [ ] 2. Buscar el archivo del tema: ampliar antes que crear; una idea, un sitio
- [ ] 3. Escribir con el formato de core/08
- [ ] 4. scripts/validate.py <raiz>              → 0 errores
- [ ] 5. scripts/check_rule_loading.py <raiz> <archivo>   (si cambió una regla)
- [ ] 6. scripts/check_rule_behavior.py <raiz>   (si cambió lo que una regla ordena)
- [ ] 7. scripts/check_hooks.py <raiz> "<prompt>" <herramienta>   (si cambió un hook)
- [ ] 8. Cambio grande: subagente `auditing-instructions`
- [ ] 9. sync.py guardar y commit en el repositorio central
```

**Proyecto nuevo.** Copiar `_claude/projects/_plantilla/` al proyecto,
declararlo en `claude-sync.json` con su perfil en `extends` (`profiles/`),
lo que suma o quita en `mas` y `menos`, sus `practicas` (skill
`choosing-delivery-strategy`) y sus `muestras`, y `sync.py restaurar
<proyecto>`. Una tecnología sin regla se escribe en el proyecto; `guardar`
la deja en `library/tech/` y se mueve a su familia con `git mv`, nunca a
`~/.claude`.

Los scripts se ejecutan, no se leen:

```bash
S=~/.claude/skills/configuring-ai-setup/scripts
python $S/validate.py . --costo resources/js/Pages/X.vue
python $S/check_rule_loading.py . resources/js/Pages/X.vue app/Models/Y.php
python $S/check_rule_behavior.py . --modelo haiku
python $S/check_hooks.py . "Usa PowerShell para: git commit --dry-run -m 'x'" "PowerShell(git commit:*)"
python C:/laragon/www/_claude/sync.py validar    # todos los proyectos
```

**validate.py** — líneas (reglas y `CLAUDE.md` < 200, `SKILL.md` < 500),
frontmatter, `paths` sin archivos, referencias rotas, nombres fuera de
convención, títulos en mayúsculas, frases repetidas entre reglas, copias
desfasadas o cargadas dos veces, `name`/`description` de skills y forma
de los hooks. `--costo` dice qué reglas cargan al tocar un archivo,
cuánto pesan y si pasan el objetivo de `core/08`.

**check_rule_loading.py** — sesión real con el hook
`InstructionsLoaded`: pasa si la regla aparece al tocar su archivo y no
al tocar uno ajeno, y si las reglas `User` no salen cuando el repo lleva
su copia.

**check_rule_behavior.py** — sesión real por caso: pide una tarea corta y
comprueba la respuesta contra patrones. Casos de la base en
`scripts/rule-cases.json`, del proyecto en `.claude/rule-cases.json` y de
la biblioteca junto a cada regla (`<regla>.cases.json`), que suma
`sync.py probar <proyecto>`. Un caso vale si falla sin su regla. Uno que
falla con ella delata una regla ambigua: se corrige la regla, no el caso.

**check_hooks.py** — sesión real con `--debug hooks`: pasa si el hook
aparece con `status code 2` cuando debe bloquear y la respuesta de la
sesión cita su motivo. Probar cada vía (Bash y PowerShell).

## Trampas

- `paths` vacío o YAML roto: la regla carga en todas las sesiones.
- Regla de una tecnología en `~/.claude`: carga en todo proyecto, use o
  no esa tecnología.
- `@import` en `CLAUDE.md` ordena pero no ahorra contexto; los
  comentarios HTML de bloque sí se quitan antes de cargar.
- `sync.py restaurar` pisa con la versión del repositorio lo editado en
  el destino sin guardar: `guardar` primero.
- Skill con efectos sin `disable-model-invocation: true`: puede lanzarse
  sola.
- Hook probado solo a mano: en Windows puede no correr nunca dentro de
  Claude Code (`core/06`, «Un hook»).
- `check_hooks.py` usa `claude -p`, donde los hooks de proyecto no corren:
  solo prueba los globales. Los de proyecto, en sesión interactiva.
