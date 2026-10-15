"""Hook PostToolUse (Edit|Write): formatea el archivo editado con la
herramienta que el proyecto ya use. Si no la tiene instalada, no hace nada.

Lo que un linter no corrige solo vuelve a la IA con código 2.
Herramientas por lenguaje: `FORMATEADORES`.
"""

import json
import os
import re
import shutil
import subprocess
import sys
from pathlib import Path

AJENOS = re.compile(r"[/\\](vendor|node_modules|storage|public[/\\]build|dist|build|target|\.venv|venv)[/\\]", re.I)
ESTILO_JS = {".js", ".mjs", ".cjs", ".ts", ".tsx", ".jsx", ".vue", ".svelte"}
ESTILO_PRETTIER = ESTILO_JS | {".css", ".scss", ".less"}
TIEMPO = 50


def local(raiz: Path, *partes: str) -> Path | None:
    ruta = raiz.joinpath(*partes)
    return ruta if ruta.exists() else None


def configurado(raiz: Path, patrones: list[str], clave_package: str | None = None) -> bool:
    # `glob` devuelve un generador, que siempre es verdadero; se le pide uno.
    if any(next(raiz.glob(p), None) for p in patrones):
        return True
    package = raiz / "package.json"
    if clave_package and package.exists():
        return clave_package in json.loads(package.read_text(encoding="utf-8") or "{}")
    return False


def ejecutar(comando: list[str], raiz: Path) -> subprocess.CompletedProcess:
    return subprocess.run(comando, cwd=raiz, capture_output=True, text=True, encoding="utf-8",
                          errors="ignore", timeout=TIEMPO)


def php(raiz: Path, archivo: Path) -> str | None:
    pint = local(raiz, "vendor", "bin", "pint")
    if pint and shutil.which("php"):
        ejecutar(["php", str(pint), "--quiet", str(archivo)], raiz)
    return None


def alcance_de_lint(raiz: Path) -> list[Path]:
    """Carpetas que el script `lint` del proyecto revisa; vacío: todo."""
    package = raiz / "package.json"
    script = json.loads(package.read_text(encoding="utf-8")).get("scripts", {}).get("lint", "") if package.exists() else ""
    if "eslint" not in script:
        return []
    rutas = [parte for parte in script.split("eslint", 1)[1].split() if not parte.startswith("-")]
    return [(raiz / ruta).resolve() for ruta in rutas if (raiz / ruta).exists()]


def dentro_del_alcance(raiz: Path, archivo: Path) -> bool:
    alcance = alcance_de_lint(raiz)
    return not alcance or any(archivo.resolve().is_relative_to(carpeta) for carpeta in alcance)


def javascript(raiz: Path, archivo: Path) -> str | None:
    eslint = local(raiz, "node_modules", "eslint", "bin", "eslint.js")
    # Fuera de lo que el proyecto revisa (scripts sueltos, pruebas e2e) no
    # se aplica su configuración, que marcaría globales que no son errores.
    if (archivo.suffix in ESTILO_JS and eslint and dentro_del_alcance(raiz, archivo)
            and configurado(raiz, ["eslint.config.*", ".eslintrc*"])):
        resultado = ejecutar(["node", str(eslint), "--fix", "--no-warn-ignored", "--max-warnings", "0",
                              str(archivo)], raiz)
        if resultado.returncode == 1:
            return resultado.stdout + resultado.stderr
    prettier = local(raiz, "node_modules", "prettier", "bin", "prettier.cjs")
    if prettier and configurado(raiz, [".prettierrc*", "prettier.config.*"], "prettier"):
        # Solo con configuración propia; sin ella Prettier impondría su estilo.
        ejecutar(["node", str(prettier), "--write", str(archivo)], raiz)
    return None


def python(raiz: Path, archivo: Path) -> str | None:
    ruff = shutil.which("ruff")
    if ruff and configurado(raiz, ["ruff.toml", ".ruff.toml", "pyproject.toml"]):
        ejecutar([ruff, "format", str(archivo)], raiz)
        resultado = ejecutar([ruff, "check", "--fix", str(archivo)], raiz)
        return resultado.stdout if resultado.returncode == 1 else None
    black = shutil.which("black")
    if black and configurado(raiz, ["pyproject.toml"]):
        ejecutar([black, "-q", str(archivo)], raiz)
    return None


def con_binario(binario: str, *argumentos: str):
    def formatear(raiz: Path, archivo: Path) -> str | None:
        if shutil.which(binario):
            ejecutar([binario, *argumentos, str(archivo)], raiz)
        return None
    return formatear


FORMATEADORES = [
    ({".php"}, php),
    (ESTILO_PRETTIER, javascript),
    ({".py"}, python),
    ({".go"}, con_binario("gofmt", "-w")),
    ({".rs"}, con_binario("rustfmt")),
    ({".dart"}, con_binario("dart", "format")),
]


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
    raiz = Path(os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd())
    extension = archivo.suffix.lower()
    for extensiones, formatear in FORMATEADORES:
        if extension not in extensiones:
            continue
        try:
            pendiente = formatear(raiz, archivo)
        except (OSError, subprocess.TimeoutExpired):
            return 0
        if pendiente:
            sys.stderr.write(f"El linter deja problemas que no se corrigen solos en {archivo.name}:\n")
            sys.stderr.write("\n".join(pendiente.strip().splitlines()[:30]) + "\n")
            return 2
        return 0
    return 0


if __name__ == "__main__":
    sys.exit(main())
