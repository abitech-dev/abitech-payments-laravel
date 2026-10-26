"""Hook PostToolUse (Edit|Write): revisa los comentarios añadidos contra
`core/02-comment-standards.md` y bloquea con código 2 si alguno incumple.

Vale para cualquier lenguaje con comentarios `//`, `/* */`, `#`, `--`,
`<!-- -->` o `{{-- --}}`. Solo mira líneas nuevas: el archivo entero
sacaría deuda ajena. Frases propias del proyecto, una por línea, en
`.claude/hooks/comment-phrases.txt`.
"""

import json
import os
import re
import subprocess
import sys
from pathlib import Path

MAX_LARGO = 80
MAX_LINEAS_SEGUIDAS = 2
MAX_LINEAS_DOCBLOCK = 3
MAX_AVISOS = 12
AJENOS = re.compile(r"[/\\](vendor|node_modules|storage|public[/\\]build|dist|build|target|\.venv|venv|__pycache__)[/\\]", re.I)

ESTILO_C = {".php", ".js", ".mjs", ".cjs", ".ts", ".tsx", ".jsx", ".vue", ".java", ".kt", ".kts",
            ".cs", ".go", ".rs", ".swift", ".c", ".h", ".cpp", ".hpp", ".dart", ".scss", ".css", ".less"}
ESTILO_ALMOHADILLA = {".py", ".rb", ".sh", ".bash", ".ps1", ".yml", ".yaml", ".toml", ".r", ".pl", ".ex", ".exs"}
ESTILO_GUIONES = {".sql", ".lua", ".hs"}
ESTILO_HTML = {".html", ".vue", ".xml", ".svelte"}

MULETILLAS = ["exitosamente", "correctamente", "simplemente", "básicamente", "basicamente", "obviamente",
              "evidentemente", "en resumen", "cabe destacar", "cabe mencionar", "es importante",
              "hay que notar", "nótese que", "por supuesto", "mágicamente"]

# La historia del cambio pertenece al commit, no al código.
HISTORIAL = [
    r"\b(bug|bugs|fix|hotfix|workaround|hack|parche)\b",
    r"\b(se arregló|se corrigió|lo arreglé|lo corregí)\b",
    r"\b(fallaba|se rompía|ya funciona|ahora sí|ahora ya|ya no falla)\b",
    r"\bantes (se|era|hacía|fallaba|había|daba|salía)\b",
    r"\b(solía|ya ocurrió|ya pasó|era un error|quedó corregido)\b",
    r"\b(se me pasó|y con razón|no es cosmético|es una trampa)\b",
]
AUTORIA = re.compile(r"(@author\b|\b20\d\d-\d\d-\d\d\b|\b\d{1,2}/\d{1,2}/20\d\d\b)")
CODIGO = re.compile(
    r"^(\$?[\w.\->\[\]'\"]+\s*(=|\+=|-=)\s*\S.*|"
    r"(return|if|elif|for|foreach|while|def|class|import|from|const|let|var|function|func|fn|"
    r"echo|print|new|await|use|package|public|private|protected)\b.*[;{(:]\s*$|"
    r"[\w$.\->]+\(.*\)\s*;?\s*$|[{}]\s*$)"
)
# El conector de «Etiqueta y explicación». La hora y la URL no dejan espacio.
DOS_PUNTOS = re.compile(r"[^\s:]:(\s|$)")
TIPO_ANOTADO = re.compile(r"^@(return|param|var|property|phpstan|psalm|template|method|type|typedef)\b")
VACIAS = {"el", "la", "los", "las", "un", "una", "de", "del", "al", "a", "en", "y", "o", "que", "se", "su",
          "sus", "con", "por", "para", "lo", "es", "the", "and", "of", "to"}


def marcas_de(extension: str, nombre: str) -> list[str]:
    marcas = []
    if extension in ESTILO_C:
        marcas += ["//", "/*", "*"]
    if extension in ESTILO_ALMOHADILLA or extension == ".php":
        marcas.append("#")
    if extension in ESTILO_GUIONES:
        marcas.append("--")
    if extension in ESTILO_HTML:
        marcas.append("<!--")
    if nombre.endswith(".blade.php"):
        marcas += ["{{--", "<!--"]
    return marcas


def texto_de_comentario(linea: str, marcas: list[str]) -> str | None:
    """Texto del comentario sin sus marcas; None si la línea no abre uno."""
    limpia = linea.strip()
    # `#[` es un atributo de PHP o Rust y `#!` un shebang, no comentarios.
    if limpia.startswith(("#[", "#!")):
        return None
    for marca in sorted(marcas, key=len, reverse=True):
        if marca == "*" and (not limpia.startswith("*") or limpia.startswith("*/")):
            continue
        if limpia.startswith(marca):
            texto = limpia[len(marca):]
            texto = re.sub(r"^\*+", "", texto) if marca == "/*" else texto
            return re.sub(r"(\*/|--}}|-->)\s*$", "", texto).strip()
    return None


def lineas_anadidas(archivo: Path, total: int) -> list[int]:
    """Líneas añadidas respecto a HEAD; un archivo fuera de git va entero."""
    diff = subprocess.run(["git", "diff", "HEAD", "-U0", "--", str(archivo)], cwd=archivo.parent,
                          capture_output=True, text=True, encoding="utf-8", errors="ignore")
    if diff.returncode != 0:
        return list(range(1, total + 1))
    anadidas, cursor = [], 0
    for fila in diff.stdout.splitlines():
        cabecera = re.match(r"^@@ -\d+(?:,\d+)? \+(\d+)", fila)
        if cabecera:
            cursor = int(cabecera.group(1))
        elif cursor and fila.startswith("+") and not fila.startswith("+++"):
            anadidas.append(cursor)
            cursor += 1
    if anadidas:
        return anadidas
    # Git no distingue en su salida un archivo sin cambios de uno sin seguir.
    seguido = subprocess.run(["git", "ls-files", "--error-unmatch", "--", str(archivo)], cwd=archivo.parent,
                             capture_output=True, text=True)
    return [] if seguido.returncode == 0 else list(range(1, total + 1))


def frases_del_proyecto() -> list[str]:
    raiz = Path(os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd())
    archivo = raiz / ".claude" / "hooks" / "comment-phrases.txt"
    if not archivo.exists():
        return []
    lineas = [l.strip() for l in archivo.read_text(encoding="utf-8").splitlines()]
    return [l for l in lineas if l and not l.startswith("#")]


def raices(texto: str) -> set[str]:
    """Raíces de cuatro letras, sin tildes, para tolerar conjugaciones."""
    plano = texto.lower().translate(str.maketrans("áéíóúñ", "aeioun"))
    return {p if len(p) <= 2 else p[:4] for p in re.findall(r"[a-z]+", plano)}


def repite_el_codigo(texto: str, siguiente: str, marcas: list[str]) -> bool:
    if not siguiente.strip() or texto_de_comentario(siguiente, marcas) is not None:
        return False
    palabras = raices(texto) - VACIAS
    nombres = re.sub(r"([a-z])([A-Z])", r"\1 \2", siguiente)
    return len(palabras) >= 2 and palabras <= raices(nombres)


def motivo_de_linea(linea: str, texto: str, siguiente: str, marcas: list[str], frases: list[str]) -> str | None:
    if re.search(r"[=\-─_~*+#]{4,}", texto):
        return "separador decorativo"
    if not TIPO_ANOTADO.match(texto) and len(linea.strip()) > MAX_LARGO:
        return f"comentario de más de {MAX_LARGO} caracteres sin contar la sangría"
    # Lo citado entre comillas invertidas es código, no prosa del comentario.
    prosa = re.sub(r"`[^`]*`", "", texto)
    if not TIPO_ANOTADO.match(texto) and DOS_PUNTOS.search(re.sub(r"`[^`]*`|\w+://\S+", "x", texto)):
        return "usa «:» como conector; escriba una oración completa"
    for patron in HISTORIAL:
        giro = re.search(patron, prosa, re.I)
        if giro:
            return f"cuenta historia («{giro.group(0)}»); escriba la restricción vigente"
    if AUTORIA.search(prosa):
        return "autor o fecha; los da git blame"
    if re.search(r"\bTODO\b(?!\()", texto):
        return "TODO sin referencia; use TODO(TICKET-123)"
    es_de_linea = linea.lstrip().startswith(("//", "#", "--")) and not linea.lstrip().startswith("#!")
    if es_de_linea and CODIGO.match(texto):
        return "código comentado; bórrelo, queda en git"
    for palabra in MULETILLAS:
        if palabra in texto.lower():
            return f"muletilla «{palabra}»"
    for frase in frases:
        if frase.lower() in texto.lower():
            return f"repite una regla del proyecto («{frase}»)"
    if es_de_linea and repite_el_codigo(texto, siguiente, marcas):
        return "repite lo que dice el código de la línea siguiente"
    return None


def buscar_bloques(lineas: list[str], nuevas: set[int], marcas: list[str]) -> list[tuple[int, str]]:
    """Bloques largos de comentarios de línea o DocBlocks que tocan una línea nueva."""
    avisos, inicio, cuenta, en_docblock, marca_actual = [], None, 0, False, None
    # Solo las del lenguaje, porque en `.html` y `.vue` `--` es variable CSS.
    de_linea = [m for m in ("//", "--", "#") if m in marcas]

    def cerrar(desde: int | None, hasta: int, motivo: str) -> None:
        if desde is not None and any(n in nuevas for n in range(desde, hasta + 1)):
            avisos.append((desde, motivo))

    for indice, linea in enumerate(lineas):
        n, limpia = indice + 1, linea.strip()
        if limpia.startswith("/**") and "*/" not in limpia:
            en_docblock, inicio, cuenta = True, n, 0
            continue
        if en_docblock:
            if "*/" in limpia:
                if cuenta > MAX_LINEAS_DOCBLOCK:
                    cerrar(inicio, n, f"DocBlock de {cuenta} líneas de prosa (máximo {MAX_LINEAS_DOCBLOCK})")
                en_docblock, inicio, cuenta = False, None, 0
            elif not re.match(r"^\*\s*@", limpia) and limpia.strip("* "):
                cuenta += 1
            continue
        marca = next((m for m in de_linea if limpia.startswith(m)
                      and not limpia.startswith(("#!", "#["))), None)
        if marca and marca == (marca_actual or marca):
            inicio, cuenta, marca_actual = inicio or n, cuenta + 1, marca
            continue
        if cuenta > MAX_LINEAS_SEGUIDAS:
            cerrar(inicio, n - 1, f"comentario de {cuenta} líneas seguidas (máximo {MAX_LINEAS_SEGUIDAS})")
        inicio, cuenta, marca_actual = (n, 1, marca) if marca else (None, 0, None)
    return avisos


def tipos_repetidos(lineas: list[str], nuevas: set[int]) -> list[tuple[int, str]]:
    """PHP: `@param`/`@return` que repiten una firma ya tipada."""
    avisos, inicio, etiquetas = [], None, set()
    for indice, linea in enumerate(lineas):
        n, limpia = indice + 1, linea.strip()
        if limpia.startswith("/**"):
            inicio, etiquetas = n, set()
        if inicio is None:
            continue
        etiqueta = re.match(r"^\*\s*@(param|return)\s+(\S+)", limpia)
        # `callable(X): Y` y `array<…>` dicen más que la firma tipada.
        if etiqueta and not re.search(r"[<>|\[\]{(]", etiqueta.group(2)):
            etiquetas.add(etiqueta.group(1))
        if "*/" not in limpia:
            continue
        firma = lineas[n] if n < len(lineas) else ""
        partes = re.search(r"function\s+\w+\s*\(([^)]*)\)(\s*:\s*[^{]+)?", firma)
        if etiquetas and partes and not ("return" in etiquetas and not partes.group(2)):
            parametros = [p.strip() for p in partes.group(1).split(",") if p.strip()]
            if all(re.match(r"^[\w\\|?]+\s+[.&]*\$", p) for p in parametros):
                if any(m in nuevas for m in range(inicio, n + 1)):
                    avisos.append((inicio, "el DocBlock repite tipos que ya están en la firma"))
        inicio, etiquetas = None, set()
    return avisos


def cede_a_la_copia_del_proyecto() -> bool:
    """Con la copia en el proyecto (modo compartido) la global no se repite."""
    raiz = Path(os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd())
    copia = raiz / ".claude" / "hooks" / Path(__file__).name
    return copia.exists() and copia.resolve() != Path(__file__).resolve()


def main() -> int:
    # Claude Code manda UTF-8; en Windows la entrada se leería en cp1252.
    sys.stdin.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
    if cede_a_la_copia_del_proyecto():
        return 0
    entrada = json.loads(sys.stdin.read() or "{}")
    ruta = (entrada.get("tool_input") or {}).get("file_path", "")
    archivo = Path(ruta) if isinstance(ruta, str) and ruta else None
    if archivo is None or not archivo.is_file() or AJENOS.search(str(archivo)):
        return 0
    marcas = marcas_de(archivo.suffix.lower(), archivo.name.lower())
    if not marcas:
        return 0
    lineas = archivo.read_text(encoding="utf-8", errors="ignore").splitlines()
    nuevas = set(lineas_anadidas(archivo, len(lineas)))
    if not nuevas:
        return 0

    frases, avisos = frases_del_proyecto(), []
    for n in sorted(nuevas):
        linea = lineas[n - 1] if n <= len(lineas) else ""
        texto = texto_de_comentario(linea, marcas)
        if texto is None:
            continue
        siguiente = lineas[n] if n < len(lineas) else ""
        motivo = motivo_de_linea(linea, texto, siguiente, marcas, frases)
        if motivo:
            avisos.append((n, motivo))
    avisos += buscar_bloques(lineas, nuevas, marcas)
    if archivo.suffix.lower() == ".php":
        avisos += tipos_repetidos(lineas, nuevas)
    if not avisos:
        return 0

    sys.stderr.write(f"Comentarios que incumplen core/02-comment-standards.md en {archivo.name}:\n")
    for n, motivo in sorted(avisos)[:MAX_AVISOS]:
        sys.stderr.write(f"  línea {n}: {motivo}\n")
    sys.stderr.write("Reescríbalos como documentación: la restricción vigente, clara y breve, "
                     "solo donde el código no lo dice.\n")
    return 2


if __name__ == "__main__":
    sys.exit(main())
