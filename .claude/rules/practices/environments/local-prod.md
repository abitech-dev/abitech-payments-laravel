---
paths:
  - ".github/workflows/**"
---

# Ambientes: `local` y `prod`

DIRECTIVA: cada persona prueba en su `local` y lo publicado corre en
`prod`; no hay ambiente intermedio.

## Estándares de referencia

- *The Twelve-Factor App*, «Config» · ISO/IEC 27001:2022 A.8.31

## Reglas

1. **Nombres.** `local` y `prod`, iguales en `APP_ENV`, en el
   *Environment* de GitHub y en la etiqueta de imagen.
   - Mal: `prd`, `production`, `dev` para la PC de cada persona.
   - Bien: `APP_ENV=prod`, `environment: prod`, imagen `:prod`.
2. **Archivos.** `.env.example` para `local` y `.env.prod.example`; los
   secretos, en el *Environment* `prod`.
3. **Cuándo conviene.** Nadie de fuera del equipo prueba antes de
   publicar. Si aparece quien apruebe, pasar a `local-uat-prod`.
