# 03. Commits (historial limpio y atómico)

DIRECTIVA: un commit, un cambio lógico, con mensaje Conventional Commits.
Lo vigila `check_commit_message.py`; el procedimiento está en la skill
`committing-changes`.

## Estándares de referencia

- Conventional Commits 1.0.0 · Commits atómicos · Modo imperativo

## Reglas

1. **Formato.** `<tipo>(<ámbito>): <asunto>`. Los tipos son `feat` (algo
   nuevo), `fix` (corrige un fallo), `refactor` (ni corrige ni añade),
   `perf`, `test`, `docs`, `style` (formato sin cambio de
   comportamiento), `build` (compilación o dependencias), `ci`, `chore`
   (mantenimiento o configuración) y `revert`.
2. **Sin coautoría de IA.** Ningún trailer `Co-Authored-By`. El autor es
   quien hace el commit.
3. **Idioma.** Asunto y cuerpo en el idioma de `core/04`, por ejemplo
   `feat(clientes): agregar filtro por etiqueta`.
4. **Imperativo.** El asunto se lee como una orden (`agregar`,
   `corregir`, `eliminar`), nunca en pasado ni en gerundio.
5. **El cuerpo cuenta el porqué**, y solo si no es obvio (`core/02`
   regla 3). Lleva el motivo y el contexto del negocio, no el cómo que
   ya muestra el diff. Oraciones separadas por punto, sin «:» como
   conector salvo en pies estándar (`Refs:`, `BREAKING CHANGE:`),
   código y URL.
6. **Longitud.** Asunto de hasta 50 caracteres, contados con el tipo y
   el ámbito, y sin punto final. Cuerpo tras una línea en blanco, de una
   o dos líneas de hasta 72; los pies no cuentan.
   - Mal: `feat(bandeja): agregar filtro por fecha de programación` (55).
   - Bien: `feat(servicios): filtrar por fecha programada` (45).
   - Mal: un cuerpo de tres líneas que lista lo que agrega el cambio.
   - Bien: `La fecha la fija el centro según cupos.`
