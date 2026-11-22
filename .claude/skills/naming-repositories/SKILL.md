---
name: naming-repositories
description: Elige o revisa el nombre de un repositorio Git o de un paquete npm o Composer según el estándar interno de nomenclatura v1.2 de abitech (empresa, producto y componente con un sufijo del catálogo). Usar cuando se cree un repositorio o un paquete, se proponga un nombre, se renombre uno o se revise si un nombre cumple el estándar.
when_to_use: Al crear, nombrar, renombrar o revisar un repositorio, un paquete npm o uno de Composer.
---

# Nombrar repositorios y paquetes

Norma corta en `core/01` reglas 5 y 6. Este es el estándar completo
(versión 1.2, vigente desde el 2026-11-13; alcance Git, GitHub, npm y
Composer).

## Alcance

| Caso | Qué se hace |
| --- | --- |
| Repositorio que crea o nombra abitech | se cumple el estándar, sin excepciones |
| El cliente pide crearlo y no da nombre | el estándar, con el cliente como empresa |
| El cliente o un tercero ya fija sus nombres | se sigue su convención; no se renombra |

El proyecto que sigue una convención ajena lo anota en su `CLAUDE.md`, con
quién la define. Sin esa nota, rige el estándar.

## Flujo

```
- [ ] 1. Empresa: la marca o el cliente dueño del sistema
- [ ] 2. Producto: el sistema o la herramienta, en una o dos palabras
- [ ] 3. Componente: el sufijo de «Cómo elegir el componente»
- [ ] 4. Escribir [empresa]-[producto]-[componente] y comprobarlo (abajo)
- [ ] 5. Si publica un paquete, su nombre según «Paquetes publicados»
```

## Estructura

`[empresa]-[producto]-[componente]`, siempre los tres segmentos y en ese
orden.

| Segmento | Qué es | Ejemplos |
| --- | --- | --- |
| Empresa | la marca o el cliente dueño del sistema | `abitech`, `arsy`, `elise` |
| Producto | el sistema o la herramienta, en una o dos palabras | `coworker`, `academy`, `openchasqui` |
| Componente | la pieza que contiene, con un solo sufijo del catálogo, al final | `-api`, `-web`, `-cli` |

- Mal: `Arsy_CoworkerAPI`, `openchasqui-cli`, `good-money-collection`.
- Bien: `arsy-coworker-api`, `abitech-openchasqui-cli`,
  `elise-actas-collection`.

## Reglas de escritura

- Kebab-case: todo en minúsculas, palabras separadas solo por guion medio.
- Solo letras `a-z` y dígitos `0-9`; sin mayúsculas, espacios, guiones
  bajos ni caracteres especiales (`.`, `@`, `/`).

## Cómo elegir el componente

| Si el repositorio contiene | Sufijo |
| --- | --- |
| La aplicación que usa el cliente final, en navegador o celular | `-web`, `-mobile` |
| El backoffice del equipo interno | `-panel` |
| Una API que consumen otras aplicaciones | `-api` |
| Frontend y backend en el mismo código | `-app` |
| Colas, tareas programadas o tiempo real | `-worker`, `-ws` |
| Peticiones para probar una API, o pruebas de punta a punta | `-collection`, `-e2e` |
| La infraestructura o su documentación | `-iac`, `-k8s`, `-docs` |
| Una herramienta de comandos o una librería | `-cli`, `-sdk`, `-laravel` |

Si ningún sufijo describe el repositorio, se propone uno nuevo y se agrega
al catálogo (esta skill y `core/01`) antes de crearlo.

## Catálogo de sufijos

| Categoría | Sufijo | Uso |
| --- | --- | --- |
| Frontend | `-web` | aplicación principal orientada al cliente |
| | `-panel` | backoffice o panel de administración |
| | `-mobile` | aplicación móvil, iOS y Android unificados |
| | `-ui` | librería de componentes visuales compartidos |
| Backend | `-api` | API REST o GraphQL principal |
| | `-worker` | segundo plano, colas y tareas programadas |
| | `-ws` | servidor de WebSockets, en tiempo real |
| Full-stack | `-app` | frontend y backend en un repositorio (Laravel e Inertia) |
| Pruebas y APIs | `-collection` | peticiones HTTP (Bruno, Postman, Insomnia) |
| | `-e2e` | pruebas de punta a punta (Playwright, Cypress) |
| Microservicios (opcional) | `-auth`, `-payments`, `-notifications` | autenticación, pagos, correos y mensajes |
| DevOps | `-iac`, `-k8s`, `-docs` | Terraform o CloudFormation, Kubernetes, documentación |
| Herramientas | `-cli` | herramienta de línea de comandos y sus librerías |
| | `-sdk` | librería que usan otros programas, sin framework |
| | `-laravel`, `-vue` | librería para un framework; el sufijo es el framework |

## Paquetes publicados

La marca va en el scope o vendor; el producto y su componente, en el
nombre. Todos los paquetes quedan bajo la misma marca.

| Registro | Forma | Ejemplo |
| --- | --- | --- |
| npm | `@abitechdev/[producto]-[componente]` | `@abitechdev/openchasqui-core` |
| npm, paquete principal | `@abitechdev/[producto]` | `@abitechdev/openchasqui` |
| Composer | `abitech/[producto]-[framework]` | `abitech/antibot-laravel` |

## Ejemplos prácticos

| Caso | Repositorios |
| --- | --- |
| Producto separado | `arsy-coworker-api`, `arsy-coworker-web`, `arsy-coworker-panel`, `arsy-coworker-collection` |
| Monolito | `arsy-academy-app`, `arsy-academy-collection`, `elise-actas-collection` |
| Herramienta | `abitech-openchasqui-cli`, que publica `@abitechdev/openchasqui` |
| Librería de framework | `abitech-antibot-laravel`, que publica `abitech/antibot-laravel` |

## Comprobar

- Tres segmentos o más, con el último en el catálogo:
  `^[a-z0-9]+(-[a-z0-9]+)+-(web|panel|mobile|ui|api|worker|ws|app|collection|e2e|auth|payments|notifications|iac|k8s|docs|cli|sdk|laravel|vue)$`.
- La empresa es la dueña del sistema, no quien lo desarrolla, si son
  distintas.
- Un repositorio existente que no cumple se informa con su nombre
  correcto; renombrarlo lo decide el usuario.
