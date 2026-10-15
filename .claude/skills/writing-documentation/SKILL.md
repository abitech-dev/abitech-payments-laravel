---
name: writing-documentation
description: Procedimiento para crear, reorganizar o revisar la documentación de un proyecto (README, CHANGELOG, docs/ con tutoriales, guías, referencia, explicación y ADR) según `tech/documentation.md`, con plantillas por tipo y comprobación de enlaces. Usar cuando se pida documentar algo, escribir una guía, registrar una decisión de arquitectura, actualizar el changelog u ordenar la carpeta docs.
---

# Escribir documentación

Norma: `tech/documentation.md`. Plantillas por tipo: [templates.md](templates.md).

## Flujo

```
- [ ] 1. ¿Qué necesita el lector? aprender, lograr, consultar o entender
- [ ] 2. Tipo Diátaxis y ruta según la tabla «Estructura» de la norma
- [ ] 3. ¿Ya existe un documento de ese tema? ampliarlo antes que crear
- [ ] 4. Escribir desde la plantilla del tipo
- [ ] 5. Enlazarlo desde docs/README.md (y desde README.md si es de entrada)
- [ ] 6. Comprobar (abajo)
- [ ] 7. Si cambió comportamiento visible: entrada en CHANGELOG.md
```

**2. Tipo.** El de la tabla «Estructura» de `tech/documentation.md`. Si
el texto mezcla dos necesidades, son dos documentos enlazados.

**6. Comprobar.**

- Un solo `# Título`; primeras líneas dicen para quién y qué resuelve.
- Todo enlace relativo apunta a un archivo que existe.
- Ningún dato copiado de otro documento: enlace a su dueño.
- Sin secretos, contraseñas reales ni datos personales.
- Guías: cada paso se ejecutó tal cual y dio el resultado escrito.
