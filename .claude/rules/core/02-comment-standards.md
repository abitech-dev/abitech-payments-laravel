# 02. Comentarios (documentación, no historia)

DIRECTIVA: el código dice qué y cómo; el comentario dice solo lo que el
código no puede decir. Si no aporta al próximo lector, no se escribe.

## Estándares de referencia

- Clean Code, cap. 4 «Comments» (R. C. Martin): comentarios buenos y
  malos
- Google Style Guides: comentarios legibles como prosa, con mayúscula y
  punto; explican el porqué
- Kernighan y Pike, *The Practice of Programming* §1.6: no repetir lo
  obvio, no contradecir el código
- Forma de la documentación según el lenguaje: PSR-19 (PHPDoc), TSDoc /
  JSDoc, PEP 257 (docstrings), Javadoc, GoDoc

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| Repetir el código (`// Guarda el usuario`) | nada, o un nombre más claro |
| Historial: bug, fix, parche, «antes», «ahora», fechas | la restricción vigente; la historia va al commit |
| Código comentado | borrarlo: queda en git |
| Autor, fecha o firma | `git blame` |
| Separadores decorativos (`// =====`) | funciones y archivos más pequeños |
| Muletillas («simplemente», «correctamente», «es importante») | la frase sin ellas |
| Párrafo que justifica | una frase con la regla |
| «Etiqueta: explicación» | una oración completa |
| `@param`/`@return` que repiten la firma tipada | solo tipos que la firma no expresa (`array<int, User>`) |
| `TODO` sin referencia | `TODO(TICKET-123): acción concreta` |

## Reglas

1. **Cuándo sí.** Regla de negocio no evidente, restricción externa
   (navegador, API de terceros, norma legal), decisión que parece un error
   y no lo es, contrato público (DocBlock del kit o de la API) y enlace a
   la fuente de un algoritmo o norma.

2. **Cuándo no.** Lo que ya dicen nombres y tipos. Si un bloque necesita
   explicar qué hace, primero se renombra o se extrae una función.

3. **Documentación, no historia.** Se escribe en presente la restricción
   que rige hoy, no el incidente que la originó.
   - Mal: `// Antes fallaba con dos cierres a la vez; arreglado con lock.`
   - Bien: `// El lock impide que dos cierres dupliquen el código.`

4. **Claro y breve.** Frases completas y simples, palabras llanas, con
   mayúscula inicial y punto. Una idea por comentario. Texto ≤ 80
   caracteres por línea sin contar la sangría (las anotaciones de tipo
   quedan fuera); ≤ 2 líneas de comentario seguidas (`//`, `#`, `--`);
   DocBlock o docstring ≤ 3 líneas de prosa.

5. **Sin «:» como conector.** «Etiqueta: explicación» se lee como texto
   generado. Una oración, o dos separadas por punto. Quedan fuera las
   anotaciones de tipo, el código citado, las URL y la hora.

6. **Docblocks** solo en contrato público o para tipos que la firma no
   expresa. Con tipado nativo, sin `@param`/`@return` redundantes.

7. **Exacto.** Un comentario que no coincide con el código es peor que
   ninguno; se actualiza en el mismo cambio que el código.

8. **Lo vigila el hook** `check_comments.py` en cada edición, sobre las
   líneas nuevas; frases propias del proyecto en
   `.claude/hooks/comment-phrases.txt`.
