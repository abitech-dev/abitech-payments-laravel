---
name: committing-changes
description: Agrupa los cambios del árbol de trabajo en commits atómicos con Conventional Commits en español, según `core/03-git-commits.md`, después de pasar las puertas de calidad. Nunca hace push. Usar cuando se pida hacer, preparar o reordenar commits.
disable-model-invocation: true
---

# Hacer commits

Norma: `core/03-git-commits.md`. El hook `check_commit_message.py`
rechaza el mensaje que la incumpla.

## Flujo

```
- [ ] 1. git status y git diff: inventario de lo que cambió
- [ ] 2. Agrupar por cambio lógico, no por archivo ni por carpeta
- [ ] 3. Proponer la lista de commits y esperar el visto bueno
- [ ] 4. Puertas de calidad del proyecto en verde
- [ ] 5. Por cada grupo: git add de sus rutas exactas y git commit
- [ ] 6. git log --oneline del tramo y git status limpio de lo previsto
```

**2. Agrupar.** Un commit, un cambio que se pueda revertir solo:
dependencias aparte (`build`), migración con el código que la usa,
pruebas con su funcionalidad. Lo que no se entiende sin otro grupo va en
el mismo.

**3. Proponer** una tabla con asunto y archivos de cada commit, en el
orden en que se harán. No se hace ninguno sin visto bueno.

**5. Confirmar.**

- `git add <rutas>`; nunca `git add -A` ni `git add .`: arrastraría lo
  que no es del grupo.
- Mensaje según `core/03`; el hook lo rechaza si no cumple.

## Prohibido

- `git push`, `--force`, `--amend` de commits ya compartidos, rebase o
  reescribir historia sin pedido expreso.
- `--no-verify`: si un hook falla, se corrige la causa.
- Incluir archivos excluidos por el proyecto (`.git/info/exclude`,
  configuración privada de la IA).
