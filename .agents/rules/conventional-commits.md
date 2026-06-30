---
trigger: always_on
---

# Agent Commit Rules

Formato: `<tipo>(<ámbito>): <descripción>`
Una sola línea (sin cuerpo ni pie).

## Tipos

`feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `chore`, `ci`, `revert`.

## Reglas

- **Longitud**: Máx 72 caracteres.
- **Idioma**: La `<descripción>` (tras los dos puntos) **DEBE ser en español**.
- **Formato**: Todo minúsculas, sin punto final.
- **Verbos**: Imperativo en español ("añadir", "corregir", "eliminar", "actualizar").

## Ejemplos

✅ `feat(emails): crear plantilla password reset`
✅ `fix(users): corregir validación`
❌ `feat(auth): add login`
❌ `Feat: Añadido login.`

> **@Agent:** Respeta esto estrictamente en cada git commit o merge.
