"""Revisa un README contra `tech/readme.md`: forma, enlaces, imágenes y relleno.

Uso:
    python check_readme.py [ruta/README.md]

Sale con 1 si hay errores. No juzga el contenido: eso lo hace quien lo lee.
"""

import re
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

TOPE_DE_LINEAS = 200
TOPE_DE_DESCRIPCION = 120
EMOJI = re.compile("[\U0001F000-\U0001FAFF☀-➿⬀-⯿]")
RELLENO = re.compile(
    r"(?i)\b(bienvenid[oa]s?|este proyecto es un|potente|robust[oa]|increíble|de última generación|"
    r"cabe destacar|en resumen|simplemente|fácilmente|generado con|hecho con|made with)\b")
COLOQUIAL = re.compile(r"(?i)\b(no hace falta|queda en|aquí tienes|ojo con|fíjate)\b")
# Una etiqueta seguida de dos puntos y de su explicación en minúscula.
DOS_PUNTOS = re.compile(r"[A-Za-zÁÉÍÓÚáéíóúñÑ)»]:\s+[a-záéíóúñ]")
EXCLAMACION = re.compile(r"¡|[A-Za-zÁÉÍÓÚáéíóúñÑ]!(\s|$)")
SEPARADOR = re.compile(r"^\s*(-{3,}|\*{3,}|_{3,})\s*$")
ETIQUETA_EN_NEGRITA = re.compile(r"^\s*[-*] \*\*[^*]+:\*\*")
IMAGEN = re.compile(r"!\[([^\]]*)\]\(([^)\s]+)[^)]*\)")
ENLACE = re.compile(r"(?<!!)\[[^\]]*\]\(([^)\s]+)[^)]*\)")
HTML_IMG = re.compile(r"<img\b[^>]*>", re.I)
HTML_ATRIBUTO = re.compile(r'\b(src|srcset|alt)\s*=\s*"([^"]*)"', re.I)
CERCO = re.compile(r"^\s*(```|~~~)(.*)$")
TITULO = re.compile(r"^# |<h1\b", re.I)


def es_local(destino: str) -> bool:
    return not re.match(r"^(https?:|mailto:|#|data:)", destino)


def existe(base: Path, destino: str) -> bool:
    limpio = destino.split("#", 1)[0].split("?", 1)[0]
    return not limpio or (base / limpio.lstrip("/")).exists()


def lineas_de_texto(lineas: list[str]) -> tuple[list[tuple[int, str]], list[str]]:
    """Las líneas fuera de bloques de código y los errores de los cercos."""
    fuera, errores, dentro = [], [], False
    for numero, linea in enumerate(lineas, 1):
        cerco = CERCO.match(linea)
        if cerco:
            if not dentro and not cerco.group(2).strip():
                errores.append(f"línea {numero}: bloque de código sin lenguaje")
            dentro = not dentro
            continue
        if not dentro:
            fuera.append((numero, linea))
    return fuera, errores


def prosa(linea: str) -> str:
    """La línea sin código, enlaces, imágenes ni etiquetas, que es lo que se lee."""
    sin_codigo = re.sub(r"`[^`]*`", "", linea)
    sin_imagenes = IMAGEN.sub("", sin_codigo)
    sin_enlaces = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", sin_imagenes)
    return re.sub(r"<[^>]+>|https?://\S+", "", sin_enlaces)


def revisar_encabezado(lineas: list[str], texto: list[tuple[int, str]]) -> list[str]:
    titulos = [n for n, l in texto if TITULO.search(l)]
    primera_seccion = next((n for n, l in texto if l.startswith("## ")), len(lineas) + 1)
    if len(titulos) != 1 or titulos[0] > primera_seccion:
        return ["debe haber un solo título (`# Título` o `<h1>`) y ir antes de las secciones"]
    bloque = "\n".join(lineas[titulos[0]:primera_seccion - 1])
    parrafos = [" ".join(prosa(l).strip() for l in p.splitlines() if prosa(l).strip())
                for p in re.split(r"\n\s*\n|</p>", bloque)]
    descripcion = next((p for p in parrafos if p), "")
    errores = []
    if len(descripcion) > TOPE_DE_DESCRIPCION or descripcion.startswith(">"):
        errores.append(f"la descripción pasa de {TOPE_DE_DESCRIPCION} caracteres o es una cita")
    return errores


def revisar(readme: Path) -> list[str]:
    lineas = readme.read_text(encoding="utf-8").splitlines()
    base = readme.parent
    texto, errores = lineas_de_texto(lineas)
    if len(lineas) > TOPE_DE_LINEAS:
        errores.append(f"{len(lineas)} líneas; el tope es {TOPE_DE_LINEAS}")
    errores += revisar_encabezado(lineas, texto)

    for numero, linea in texto:
        if re.match(r"^#{1,6} ", linea) and EMOJI.search(linea):
            errores.append(f"línea {numero}: emoji en un título")
    secundarios = [l[3:].strip() for _, l in texto if l.startswith("## ")]
    if secundarios and secundarios[-1] not in ("Licencia", "License"):
        errores.append("la última sección `##` debe ser «Licencia»")

    seguidas = 0
    for numero, linea in texto:
        legible = prosa(linea)
        if SEPARADOR.match(linea):
            errores.append(f"línea {numero}: separador `---` entre secciones")
        for coincidencia in RELLENO.finditer(legible):
            errores.append(f"línea {numero}: relleno «{coincidencia.group(0)}»")
        for coincidencia in COLOQUIAL.finditer(legible):
            errores.append(f"línea {numero}: coloquialismo «{coincidencia.group(0)}»")
        if not linea.lstrip().startswith("|") and DOS_PUNTOS.search(legible):
            errores.append(f"línea {numero}: dos puntos como conector; use una frase con verbo")
        if EXCLAMACION.search(legible):
            errores.append(f"línea {numero}: signo de exclamación")
        seguidas = seguidas + 1 if ETIQUETA_EN_NEGRITA.match(linea) else 0
        if seguidas == 3:
            errores.append(f"línea {numero}: viñetas en serie con «**Etiqueta:**»")
        for alternativo, destino in IMAGEN.findall(linea):
            if not alternativo.strip():
                errores.append(f"línea {numero}: imagen sin texto alternativo (alt)")
            if es_local(destino) and not existe(base, destino):
                errores.append(f"línea {numero}: no existe {destino}")
        for etiqueta in HTML_IMG.findall(linea):
            atributos = {k.lower(): v for k, v in HTML_ATRIBUTO.findall(etiqueta)}
            if not atributos.get("alt", "").strip():
                errores.append(f"línea {numero}: <img> sin alt")
        for clave, destino in HTML_ATRIBUTO.findall(linea):
            if clave.lower() in ("src", "srcset") and es_local(destino) and not existe(base, destino):
                errores.append(f"línea {numero}: no existe {destino}")
        for destino in ENLACE.findall(linea):
            if es_local(destino) and not existe(base, destino):
                errores.append(f"línea {numero}: enlace roto {destino}")
    return errores


def main() -> int:
    readme = Path(sys.argv[1] if len(sys.argv) > 1 else "README.md")
    errores = revisar(readme)
    for error in errores:
        print(f"ERROR  {error}")
    print(f"{readme}: {len(errores)} errores")
    return 1 if errores else 0


if __name__ == "__main__":
    sys.exit(main())
