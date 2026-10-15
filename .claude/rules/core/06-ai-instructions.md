---
paths:
  - "**/.claude/**"
  - "**/CLAUDE.md"
  - "**/CLAUDE.local.md"
  - "**/AGENTS.md"
  - ".mcp.json"
  - "global/rules/**"
  - "global/skills/**"
  - "global/hooks/**"
---

# 06. Configuración de Claude Code (instrucciones, skills, hooks)

DIRECTIVA: cada cosa en el mecanismo que le corresponde y en un solo
sitio. Lo que carga en contexto se paga en tokens cada vez: entra solo lo
que la IA no deduce del código y cuya ausencia la haría equivocarse.

## Estándares de referencia

- Claude Code docs: *Extend Claude Code*, *Memory*, *Skills*, *Hooks*,
  *Subagents*, *Settings*, *Plugins*
- Anthropic: *Skill authoring best practices* · Agent Skills spec

## Mecanismos

| Necesidad | Mecanismo | Ubicación |
| --- | --- | --- |
| Hecho de toda sesión: comandos, puertas, trampas | `CLAUDE.md` | raíz del repo |
| Norma de un área del código | regla con `paths` | `.claude/rules/` |
| Procedimiento o referencia ocasional | skill | `.claude/skills/<nombre>/` |
| Debe ocurrir siempre, sin criterio de la IA | hook | `settings.json` → `hooks` |
| Prohibir herramientas, comandos o rutas | permisos `deny` | `settings.json` → `permissions` |
| Tarea que lee mucho y devuelve un resumen | subagente | `.claude/agents/<nombre>.md` |
| Sistema externo (BD, navegador, tickets) | servidor MCP | `.mcp.json` |
| Tono o formato de todas las respuestas | output style | `.claude/output-styles/` |
| Preferencia personal en un repo | `CLAUDE.local.md`, `settings.local.json` | raíz, fuera de git |
| Llevar lo anterior a otros repos | plugin + marketplace | repo propio |

- Un plugin no lleva reglas ni `CLAUDE.md`: las instrucciones viajan como
  skill.
- Lo que la IA aprende sola va a su memoria automática, no a las reglas.

## Capas — global o proyecto

| Capa | Global (`~/.claude/`) | Proyecto (`<repo>/.claude/`) |
| --- | --- | --- |
| `rules/core/` | conducta, nombres, comentarios, commits, idioma, seguridad, principios de diseño, esta norma y la de redacción; ningún lenguaje en concreto | — |
| `rules/tech/` | disciplinas de cualquier proyecto (pruebas, documentación) | tecnologías e interfaz, copiadas de la biblioteca `stacks/` según el `stack` del proyecto |
| `rules/project/` | — | dominio, diseño, inventario, adaptación de `tech/` |
| `skills/` | procedimientos de cualquier repo | los del proyecto y los de su stack, que llevan la tecnología en el nombre |
| hooks | scripts en Python sin dependencias, registrados en `settings.json` global | frases propias del hook de comentarios |

- **Una tecnología nunca es global.** Una regla de `~/.claude` carga en
  todo proyecto, y sus `paths` no saben qué stack usa (`**/*.php` sería
  Laravel en un WordPress). Cada proyecto declara su `stack` en
  `claude-sync.json`.
- **Adaptar sin duplicar.** Lo genérico vive en `tech/`; su aplicación
  (componentes del kit, comandos, medidas) va en `project/` y remite a la
  `tech/` por nombre. Una idea, un archivo.
- **`project/` concreta o endurece `tech/`, nunca la relaja en
  silencio.** Una excepción se anota en la regla del proyecto con su
  motivo. Ninguna capa concede excepciones de `core/05`.
- **Repositorio central privado** con la base global y cada proyecto;
  `sync.py` copia a cada destino (`estado`, `guardar`, `restaurar`). La
  copia física es obligatoria: una regla de proyecto enlazada desde fuera
  no se carga.
- **Proyecto privado.** `.claude/` y `CLAUDE.md` fuera de su git con
  `.git/info/exclude` (local, no se sube); la base actúa desde
  `~/.claude`. Nunca con `.gitignore`, que delata lo que oculta.
- **Proyecto compartido.** Versionado en su git para el equipo, con copia
  literal de la base, sus hooks registrados con `${CLAUDE_PROJECT_DIR}` y
  sus plugins (`enabledPlugins`, `extraKnownMarketplaces`). La base de la
  máquina se excluye con `claudeMdExcludes` y los hooks globales ceden
  ante la copia del proyecto: nada corre dos veces.
  - Una skill de `~/.claude` tapa a la del proyecto con el mismo nombre.
    Son la misma copia; `validate.py` avisa si divergen.
- **Plugins de uso general** (diseño de interfaz, por ejemplo) van en la
  base: `enabledPlugins` del `settings.json` global, versionado en el
  repositorio central.

## Nombres

1. **Archivos y carpetas en inglés, kebab-case** (identificadores
   técnicos, `core/04`): «testing.md», «design/typography.md».
2. **Excepción: `project/domain/<modulo>.md`** con el nombre del módulo
   en el código (lenguaje ubicuo): «actas.md», «informes.md».
3. **Skills.** `name` = carpeta, en gerundio inglés
   (`testing-changes`); la de una tecnología la nombra
   (`creating-laravel-endpoints`); `description` en español.
4. **Scripts.** Python con `snake_case` (PEP 8): `check_comments.py`.
5. **Contenido en español**, formal e impersonal: escrito para cualquier
   integrante del equipo. Etiquetas de decisión: «(decisión del
   cliente)» y «(decisión de producto)».

Cómo se escribe cada pieza, sus límites y su redacción: `core/08`.

## Una regla

1. **Un tema por archivo.** Si crece, se parte por tema; el límite de
   `core/08` no se sube.
2. **Frontmatter solo con `paths`**, estrechos y con archivos reales.
   Sin `paths` carga en toda sesión, y eso queda para `core/`; `06` y
   `08` llevan los suyos porque solo sirven al configurar.
3. **Referencias vivas.** `capa/archivo.md` debe existir; al mover una
   sección se actualiza quien la cita, código incluido.

## Una skill

1. **Procedimiento, no norma.** La norma vive en su regla y la skill la
   cita.
2. **Con efectos** (desplegar, commit, borrar):
   `disable-model-invocation: true`.

## Un hook

1. **Python 3 solo con biblioteca estándar**. Corre igual en cualquier
   stack y sistema. Lo que dependa de una herramienta (Pint, ESLint,
   Ruff…) se usa solo si el proyecto la tiene instalada.
2. **Forma `command` + `args`**, sin pasar por un shell:
   `"command": "python", "args": ["<ruta>/check_comments.py"]`. En
   Windows el hook corre en PowerShell: `$CLAUDE_PROJECT_DIR` sin llaves
   llega vacío y el código de salida se pierde.
3. **Bloquear = salir con 2** y el motivo por stderr. Si falta el script,
   `python` también sale con 2 y bloquea cada edición: registrar un hook
   solo cuando su script ya existe.
4. **Matcher completo.** Comandos de terminal con `Bash|PowerShell`;
   ediciones con `Edit|Write`.
5. **Probado dentro de Claude Code**, no solo a mano (skill
   `configuring-ai-setup`).

**Verificación.** Al cambiar cualquier archivo de este ámbito, skill
`configuring-ai-setup`: validador sin errores, carga real de reglas y
prueba de hooks.
