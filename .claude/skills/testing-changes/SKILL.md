---
name: testing-changes
description: Procedimiento para probar un cambio de código en una aplicación web, escribir las pruebas que exige `tech/testing.md`, correrlas junto con las puertas de calidad, verificar pantallas en el navegador e informar qué quedó sin cubrir. Usar antes de dar por terminado un cambio, cuando se pida probar, verificar o capturar una pantalla, o al revisar la cobertura de pruebas.
---

# Probar un cambio

Norma: `tech/testing.md` (tipos, qué exige cada cambio, reglas) y
`core/05-security-baseline.md`. Adaptación del proyecto: `CLAUDE.md` y
`.claude/rules/project/testing.md` (comandos, archivos modelo, cuidados
con los datos), que prevalecen.

## Flujo

```
- [ ] 1. Leer la adaptación del proyecto
- [ ] 2. Ubicar el cambio en «Qué exige cada cambio» de tech/testing.md
- [ ] 3. Escribir las pruebas, copiando el archivo modelo del proyecto
- [ ] 4. Si es un error o una regla de negocio, verlas fallar antes del código
- [ ] 5. Escribir el código y correrlas con filtro hasta verde
- [ ] 6. Correr completas las puertas de calidad del proyecto
- [ ] 7. Si cambió una pantalla: verificación en navegador (abajo)
- [ ] 8. Informar
```

**4. Rojo primero** (`core/07`). Una prueba que pasa antes del cambio no
lo prueba. Se corrige la prueba antes de seguir.

**7. Verificación en navegador.**

1. Aplicación sirviendo en la URL de pruebas del proyecto.
2. Crear o ampliar el script de la pantalla y ejecutarlo hasta verde.
3. Si cambió el diseño, capturas a ~390 px y ≥ 1280 px; revisarlas. Si
   la pantalla tiene referencia visual, se rehace con `--update-snapshots`
   y se mira cada imagen nueva antes del commit.
4. Datos restaurados; capturas y scripts de depuración borrados.

**8. Informe al entregar.**

- Pruebas añadidas y resultado de cada puerta, con cifras.
- Tipos aplicables de `tech/testing.md` no cubiertos y el motivo (sin
  herramienta, fuera de alcance). Nunca se omiten en silencio.
