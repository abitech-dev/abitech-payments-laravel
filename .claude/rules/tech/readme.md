---
paths:
  - "**/README.md"
---

# README

DIRECTIVA: quien llega al repositorio entiende en diez segundos qué es,
para quién es y cómo arrancarlo; cada dato es concreto, verificable y
escrito con el tono formal del equipo.

## Estándares de referencia

- Standard Readme (R. Litt), orden de secciones · Make a README ·
  Google, *READMEs*
- GitHub Docs: *About READMEs*, *Creating diagrams* (Mermaid) e imágenes
  por tema con `<picture>`
- Plugin `diagram-design` para diagramas SVG con la marca del proyecto
- Ficha, licencia y changelog: `tech/repository.md`; qué va en `docs/`:
  `tech/documentation.md`; cómo se arma: skill `preparing-repositories`

## Tabla anti-vanilla

| Prohibido | Usar |
| --- | --- |
| «Bienvenido a…», «Este proyecto es un…» | el nombre y una línea con qué es |
| Adjetivos vacíos («potente», «robusto», «moderno», «fácil») | el dato que lo demuestra |
| Emojis o signos de exclamación | texto plano |
| `---` entre secciones | solo los títulos `##` |
| Viñetas en serie con «**Etiqueta:** texto» | una tabla o frases directas |
| Muletillas («simplemente», «cabe destacar», «en resumen») | la frase sin ellas |
| Dos puntos como conector («Pilot SSH: servidores…») | una frase con verbo o dos oraciones |
| Coloquialismos («queda en», «no hace falta», «están en») | registro formal («se publica en», «no es necesario», «figuran en») |
| Insignias de adorno o que no cargan | de 0 a 4, con dato vivo |
| Instalación contada en prosa | bloque copiable con los comandos |
| Versiones vagas («Node reciente») | versión mínima exacta |
| Captura de maqueta o sin `alt` | captura real del sistema, con `alt` |
| Diagrama como captura PNG o sin su fuente | SVG de `diagram-design` con su HTML, o bloque `mermaid` |
| Toda la documentación dentro del README | un párrafo y el enlace a `docs/` |
| «Generado con…», firmas o créditos a herramientas | nada |

## Estructura

En este orden; la sección que no aplica se omite.

| Sección | Qué lleva |
| --- | --- |
| Encabezado | con marca, centrado: logo, título, descripción e insignias; sin marca, `# Título`, descripción e insignias |
| Título | el nombre del producto y su componente (`Pilot SSH Web`) |
| Descripción | una frase de menos de 120 caracteres, igual a la del *About* y del manifiesto |
| Insignias | CI, versión, licencia; sin título propio |
| Vista previa | captura real si hay interfaz; si no, un diagrama (regla 7); antes, si suma, una banda de cifras |
| Características | de 3 a 6 viñetas, o tarjetas en una tabla de 2×2 con ícono, título y una frase; lo que resuelve, no cómo |
| Requisitos | herramientas con su versión mínima |
| Inicio rápido | hasta 5 comandos que funcionan copiados tal cual |
| Uso | tabla `Comando` \| `Para qué`; en una librería, el ejemplo mínimo con su salida |
| Propias del proyecto | lo que solo ese proyecto necesita (ambientes, API), con título concreto |
| Documentación | tabla `Quiero…` \| `Documento`, con enlaces a `docs/` |
| Estructura | árbol corto de carpetas, una línea cada una, o su tabla dentro de `<details>` |
| Contribuir | ramas, PR y comandos de prueba, o enlace a `CONTRIBUTING.md` |
| Licencia | siempre al final, en tercera persona: «Copyright © 2026 ABItech Perú. Todos los derechos reservados.» y «Software propietario. Las condiciones de uso figuran en LICENSE.» |

- Mal: «## 🚀 Instalación» y un párrafo que explica qué instalar.
- Bien: «## Inicio rápido» y un bloque `bash` con el clon y el comando que
  lo levanta.

## Reglas

1. **Concreto y comprobado.** Cada comando se ejecutó tal como está
   escrito; cada cifra sale de una medición o del manifiesto.
2. **Corto.** Menos de 200 líneas, sin índice manual: GitHub arma el suyo
   con los títulos. Lo que crece pasa a `docs/`.
3. **Escaneable.** Títulos `##` en tipo frase, tablas para lo comparable,
   bloques de código con su lenguaje y enlaces relativos.
4. **Registro.** Formal, impersonal o de usted, en presente e igual en
   todo el repositorio. Frases cortas y completas, con verbo y con el
   término del dominio; nada que suene a conversación.
5. **Logo.** El de la marca en `<picture>`, con la versión para fondo
   claro y la de fondo oscuro si la marca las tiene, de 64 a 96 px de
   alto, dentro de un bloque
   `<p align="center">`; el título va en `<h1 align="center">`.
6. **Imágenes.**
   - En `docs/imagenes/`, PNG o WebP de menos de 300 KB y de 1280 a 1600
     px de ancho, con nombre en kebab-case.
   - Captura del sistema real (`uat`), sin datos personales; el `alt` dice
     lo que muestra.
   - Con tema claro y oscuro, las dos versiones en un `<picture>` con
     `prefers-color-scheme`.
   - Íconos de 24 px del set de `diagram-design` en `docs/iconos/`, con el
     color de la marca fijo, su versión `-dark` y la licencia del set.
7. **Diagramas.** Uno por idea, de hasta 12 nodos, con etiquetas en el
   idioma del proyecto.
   - Con el plugin `diagram-design`, el HTML y su SVG exportado con
     `--system-fonts`, claro y oscuro, en `docs/diagramas/`, dentro de un
     `<picture>`.
   - Sin el plugin, un bloque `mermaid`.
8. **Insignias.** Shields.io, estilo `flat`. En un repositorio privado
   solo las que cargan sin sesión.
9. **Avisos.** `> [!NOTE]`, `[!IMPORTANT]` o `[!WARNING]`, como mucho dos
   y nunca seguidos.
10. **Mismo cambio.** Un comando, una versión o una carpeta que cambia
   actualiza el README en el mismo commit.
11. **Comprobación.** `check_readme.py` de la skill
    `preparing-repositories` sin errores antes del commit.
12. **Plegables.** La referencia larga (todos los comandos, las claves,
    el detalle de carpetas) va en `<details>`, con un `<summary>` que dice
    qué contiene. Lo visible se entiende sin abrir ninguno.
