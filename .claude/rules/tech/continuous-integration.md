---
paths:
  - ".github/workflows/**"
  - ".gitlab-ci.yml"
  - "bitbucket-pipelines.yml"
  - "azure-pipelines.yml"
  - "Jenkinsfile"
  - ".circleci/**"
---
# Integración y despliegue continuos

DIRECTIVA: cada commit pasa una sola vez por las mismas puertas que en
local, nada se despliega en rojo y cada minuto del plan se cuida, porque
lo comparten todos los proyectos de la cuenta.

## Estándares de referencia

- Fowler, *Continuous Integration* · DORA (frecuencia y tasa de fallo)
- GitHub, *Security hardening for GitHub Actions* · *Reusing workflows*
- GitHub, *Billing for Actions* (cada job se cobra redondeado al minuto)

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| `push` y `pull_request` sobre el mismo commit | `pull_request` solo hacia la rama de trabajo |
| Despliegue que no espera a las pruebas | `uses: ./.github/workflows/ci.yml` y `needs: ci` |
| CI propia en la rama de entrega y otra en el despliegue | el despliegue llama a la CI una vez |
| Un job de segundos por herramienta | los baratos juntos en un job |
| Lo caro en paralelo con lo barato | lo caro con `needs` tras lo barato |
| Correr por un cambio de `docs/` o `.md` | `paths-ignore` |
| Workflow que borra, rehace o siembra la base | comando directo en el servidor, por SSH |
| `ubuntu-latest`, acción por etiqueta móvil (`@main`) | `ubuntu-24.04`, versión exacta |
| Job sin tope de tiempo | `timeout-minutes` en cada job |
| Comando distinto al de local | el script del proyecto (`composer`, `npm run`) |
| `continue-on-error` en una puerta | la puerta que bloquea |
| Permisos por defecto del token | `permissions: contents: read` |
| Secretos en el YAML | secretos del proveedor, solo donde se usan |
| Reintentar hasta que pase | arreglar la prueba inestable |
| `gitleaks-action` en una organización (pide licencia) | el binario con versión fija (regla 8) |

## Estructura y nombres

| Pieza | Convención | Ejemplo |
| --- | --- | --- |
| Archivo | inglés, kebab-case, verbo y destino | `ci.yml`, `deploy-staging.yml`, `deploy-production.yml` |
| ID de job (`needs:`) | inglés, kebab-case | `security`, `frontend`, `backend-static`, `backend-tests`, `deploy` |
| `name:` de workflow, job y paso | idioma del proyecto | «CI», «Desplegar a UAT», «Revisar tipos (PHPStan)» |
| Comentario | idioma del proyecto, de 3 a 6 palabras | `# 4. Pruebas, al final.` |

1. **`ci.yml` revisa; `deploy-*.yml` despliega.**
   - `ci.yml` corre en `push` a la rama de trabajo, en `pull_request`
     hacia ella y por `workflow_call`.
   - Cada `deploy-*.yml` corre en `push` a su rama y a mano
     (`workflow_dispatch`). Primero `ci` (`uses: ./.github/workflows/ci.yml`),
     luego `deploy` con `needs: ci` y una prueba de humo al final.
   - El PR de la rama de trabajo a la de entrega muestra el check del
     push; no hace falta otra corrida.
2. **Orden de los jobs.** `security` (secretos y auditoría de paquetes)
   primero; `frontend` y `backend-static` en paralelo; `backend-tests`
   al final, tras `backend-static` y, si usa la compilación, también tras
   `frontend`. Un rojo temprano no gasta lo caro.
3. **Comentarios cortos.** De 3 a 6 palabras, solo donde el `name:` no
   lo dice. Cada paso lleva `name:`, que es lo que se lee en Actions.

## Presupuesto de minutos

4. **Lo que cuesta.** En un repositorio privado cada job descuenta del
   plan de la cuenta, redondeado al minuto, más el arranque del runner.
   Un job de 10 s cuesta un minuto entero.
5. **Cómo se ahorra.**
   - Una corrida por commit (estructura, regla 1).
   - `paths-ignore: ['docs/**', '**.md']` en `push` y `pull_request`.
   - `concurrency` con `cancel-in-progress: true` en la CI; en el
     despliegue, `false`, para no cortar uno a medias.
   - Caché de `vendor` y de npm por el lockfile.
   - La compilación viaja como artefacto de un día; no se rehace en
     otro job.
6. **Fuera de la CI salvo plan que lo cubra.** Navegador (E2E,
   accesibilidad, Lighthouse), mutación y carga se corren en local, en
   los niveles 3 y 4 de `tech/testing.md`, con los scripts del proyecto.

## Reglas

7. **Mismas puertas que local, entorno efímero.** El workflow llama a los
   scripts del proyecto. Base en memoria, credenciales falsas y servicios
   externos con falsos; nada llega a un servicio real.
8. **Secretos con gitleaks en `security`.** `fetch-depth: 0` para todo el
   historial y el binario con versión fija:

   ```yaml
   - name: Buscar secretos
     run: |
       curl -sSfL https://github.com/gitleaks/gitleaks/releases/download/v8.30.1/gitleaks_8.30.1_linux_x64.tar.gz | tar -xz gitleaks
       ./gitleaks git --redact --no-banner
   ```

   - Un dato falso de las pruebas se permite en `.gitleaks.toml` con
     `paths` y `regexes` (`condition = "AND"`).
   - Un secreto real se revoca primero; reescribir el historial es
     opcional y destructivo.
9. **Versiones.** Acciones con Node 24 (`runs.using` de su `action.yml`)
   y publicadas hace más de dos semanas. Se valida con `actionlint`
   antes de subir.
10. **Prueba inestable.** Se arregla o va a cuarentena con su ticket; la
    CI no reintenta en silencio.
11. **Rama protegida.** Merge a la rama de entrega solo con la CI en
    verde, si el plan lo permite. La configura quien administra el
    repositorio y el proyecto anota hasta qué rama llega el equipo.
