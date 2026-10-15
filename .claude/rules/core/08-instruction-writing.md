---
paths:
  - "**/.claude/**"
  - "**/CLAUDE.md"
  - "**/CLAUDE.local.md"
  - "**/AGENTS.md"
  - "global/rules/**"
  - "global/skills/**"
---

# 08. Escribir instrucciones (para que la IA cumpla)

DIRECTIVA: cada línea se paga en tokens en cada sesión y compite con las
demás; queda solo la que, al quitarla, haría equivocarse a la IA.

## Estándares de referencia

- Claude Code docs: *Memory* (instrucciones efectivas), *Best
  practices*, *Skills*
- Anthropic: *Prompting best practices* (claridad, motivo, ejemplos)
- Agent Skills spec

## Límites

| Pieza | Tope | Fuente |
| --- | --- | --- |
| `CLAUDE.md` | < 200 líneas | Claude Code, *Memory* |
| Regla | < 200 líneas; objetivo 50–150 | propio |
| `SKILL.md` | < 500 líneas; apoyo enlazado a un nivel | Claude Code, *Skills* |
| `description` de skill | ≤ 1024 caracteres, lo esencial primero | Agent Skills spec |
| Línea | ≤ 80 caracteres | propio |
| Reglas al tocar un archivo | objetivo ≤ 15 mil tokens; una pantalla, ≤ 20 mil | propio (`validate.py --costo`) |

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| «Formatea bien el código» | «Sangría de 4 espacios» |
| «CRÍTICO», «NUNCA», «SIEMPRE» en mayúsculas | la regla en tono normal y su motivo |
| Solo «no hagas X» | el reemplazo junto a lo prohibido |
| Párrafo que justifica | una frase con el motivo |
| Lo que la IA ya sabe del lenguaje | lo que el proyecto decide distinto |
| La misma idea en dos archivos | un dueño y una referencia |
| «Etiqueta: explicación» | una oración completa |

## Reglas

1. **La prueba de cada línea.** Si quitarla no haría equivocarse a la
   IA, se corta. Fuera lo deducible del código, lo estándar y el
   historial (tickets, fechas, «antes»).
2. **Concreto y verificable.** Nombre exacto de clase, token, comando o
   número. Lo concreto se comprueba; «hazlo bien» no.
3. **Qué hacer, no solo qué evitar.** Toda prohibición lleva su
   reemplazo; por eso la tabla `Prohibido | Usar`.
4. **El motivo, en una frase.** Cuando la regla parecería arbitraria o
   tiene excepciones. La IA generaliza desde el motivo.
5. **Énfasis escaso.** Los modelos actuales sobreactúan ante «CRÍTICO» o
   «DEBES». Si una regla se salta una y otra vez, se enfatiza esa sola
   línea o pasa a hook. Las mayúsculas quedan para las siglas.
6. **Formato fijo.** `# Título`, `DIRECTIVA:` de una oración y secciones
   `##` (anclas, tabla `Prohibido | Usar` cuando aplique, reglas). Cada
   regla, `N. **Título breve en minúscula.** Frase.` Viñetas, no
   párrafos.
7. **Ejemplos en pares.** «Mal» y «Bien» del mismo caso, variados para
   que no se copie un patrón accidental.
8. **Sin contradicciones.** Ante dos reglas opuestas la IA elige una al
   azar. Al cambiar una regla se busca quién dice lo contrario.
9. **Un idioma.** El de `core/04` en todos los archivos; los términos del
   dominio, como los dice el negocio.
10. **Una skill** describe en tercera persona qué hace y «Usar cuando…».
    Pasos numerados con checklist; operación frágil, script exacto y
    bucle validar → corregir → repetir.
