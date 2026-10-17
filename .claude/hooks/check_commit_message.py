"""Hook PreToolUse (Bash|PowerShell): rechaza con código 2 un `git commit`
cuyo mensaje incumpla `core/03-git-commits.md`.

Corre antes del comando: un mensaje mal formado nunca llega al historial.
Solo biblioteca estándar, para cualquier proyecto y sistema.
"""

import json
import os
import re
import sys
from pathlib import Path

MAX_ASUNTO = 50
MAX_CUERPO = 72
TIPOS = ["feat", "fix", "docs", "style", "refactor", "perf", "test", "build", "ci", "chore", "revert"]
# Terminaciones que delatan pasado o gerundio en el verbo inicial.
NO_IMPERATIVO = ("ado", "ido", "ando", "endo")

# Los pies de Conventional Commits y de git, como «Refs #12», sí los llevan.
PIE = re.compile(r"^\s*(BREAKING[ -]CHANGE|Refs|Closes|Fixes|See-also|Reviewed-by|Acked-by):\s", re.I)

COMMIT = re.compile(r"(^|[;&|]\s*)git\s+(-\S+\s+)*commit\b")
OPCION_DE_MENSAJE = re.compile(r"(?<!\S)(-m|--message=|-F|--file=)")
# Se prueban desde la opción de mensaje, así no cuenta un heredoc ajeno
# al commit, como un script en el mismo comando.
PATRONES_DE_MENSAJE = [
    # Here-string de PowerShell, @'…'@ y @"…"@.
    (r"(?:-m|--message=)\s*@(['\"])\r?\n(.*?)\r?\n\1@", 2),
    # Heredoc que alimenta el mensaje, -m "$(cat <<'X' … X)" o -F - <<X.
    (r"(?:-m|--message=)\s*\"\$\(cat\s+<<-?['\"]?(\w+)['\"]?\s*\n(.*?)\n\s*\1", 2),
    (r"(?:-F|--file=)\s*-\s+<<-?['\"]?(\w+)['\"]?\s*\n(.*?)\n\s*\1", 2),
    (r"(?:-m|--message=)\s*\"((?:[^\"\\]|\\.)*)\"", 1),
    (r"(?:-m|--message=)\s*'([^']*)'", 1),
]


def tramos_de_commit(comando: str) -> list[str]:
    """Lo que sigue a cada `git commit`, hasta el siguiente. Un comando con
    dos commits no presta el mensaje del segundo al primero."""
    cortes = list(COMMIT.finditer(comando))
    finales = [m.start() for m in cortes[1:]] + [len(comando)]
    return [comando[m.end():fin] for m, fin in zip(cortes, finales)]


def extraer_mensaje(tramo: str) -> str | None:
    """Mensaje del tramo; varios `-m` son párrafos, como los une git. None
    si no viaja en el comando (editor o archivo)."""
    # --no-edit reutiliza el mensaje anterior, así que no hay nada nuevo.
    if re.search(r"\s--no-edit\b", tramo):
        return None
    parrafos, fin = [], 0
    for opcion in OPCION_DE_MENSAJE.finditer(tramo):
        if opcion.start() < fin:
            continue
        for patron, grupo in PATRONES_DE_MENSAJE:
            coincidencia = re.match(patron, tramo[opcion.start():], re.S)
            if coincidencia:
                texto = coincidencia.group(grupo)
                parrafos.append(texto.replace('\\"', '"') if grupo == 1 else texto)
                fin = opcion.start() + coincidencia.end()
                break
    return "\n\n".join(parrafos) if parrafos else None


def revisar_asunto(asunto: str) -> list[str]:
    partes = re.match(r"^([a-z]+)\(([^)]+)\):\s(.+)$", asunto)
    if not partes:
        return ["el asunto no sigue «tipo(ámbito): descripción»"]
    tipo, ambito, descripcion = partes.groups()
    fallos = []
    if tipo not in TIPOS:
        fallos.append(f"el tipo «{tipo}» no existe (válidos: {', '.join(TIPOS)})")
    if "," in ambito or " " in ambito:
        fallos.append("el ámbito trae más de uno: sepárelos en commits distintos")
    if len(asunto) > MAX_ASUNTO:
        fallos.append(f"el asunto tiene {len(asunto)} caracteres y el tope es {MAX_ASUNTO}")
    if asunto.endswith("."):
        fallos.append("el asunto termina en punto")
    if descripcion[:1] != descripcion[:1].lower():
        fallos.append("la descripción empieza en mayúscula")
    verbo = descripcion.split(" ")[0].lower()
    if verbo.endswith(NO_IMPERATIVO):
        fallos.append(f"«{verbo}» está en pasado o gerundio: use el imperativo")
    return fallos


def usa_dos_puntos(linea: str) -> bool:
    """«Etiqueta: explicación» en prosa. Código, URL y pies no cuentan."""
    if PIE.match(linea):
        return False
    prosa = re.sub(r"`[^`]*`|\w+://\S+", "", linea)
    return bool(re.search(r"[^\s:]:\s", prosa))


def revisar_cuerpo(lineas: list[str]) -> list[str]:
    if not lineas:
        return []
    fallos = []
    if lineas[0].strip():
        fallos.append("falta la línea en blanco entre el asunto y el cuerpo")
    for indice, linea in enumerate(lineas):
        if len(linea.rstrip()) > MAX_CUERPO:
            fallos.append(f"la línea {indice + 2} del cuerpo pasa de {MAX_CUERPO} caracteres")
            break
    for indice, linea in enumerate(lineas):
        if usa_dos_puntos(linea):
            fallos.append(f"la línea {indice + 2} del cuerpo usa «:» como conector; escriba oraciones separadas por punto")
            break
    return fallos


def revisar(mensaje: str) -> list[str]:
    lineas = re.split(r"\r?\n", mensaje.strip())
    fallos = revisar_asunto(lineas[0]) + revisar_cuerpo(lineas[1:])
    if re.search(r"^\s*(co-authored-by|generated with|signed-off-by:\s*claude)", mensaje, re.I | re.M):
        fallos.append("lleva un pie de coautoría: el autor es quien hace el commit")
    return fallos


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
    comando = (entrada.get("tool_input") or {}).get("command", "")
    if not isinstance(comando, str):
        return 0
    mensajes = [extraer_mensaje(tramo) for tramo in tramos_de_commit(comando)]
    fallos = [fallo for mensaje in mensajes if mensaje is not None for fallo in revisar(mensaje)]
    if not fallos:
        return 0
    sys.stderr.write("El mensaje incumple core/03-git-commits.md:\n")
    sys.stderr.writelines(f"  · {fallo}\n" for fallo in fallos)
    sys.stderr.write(f"Formato: <tipo>(<ámbito>): <descripción>, en minúsculas, máx. {MAX_ASUNTO} "
                     "caracteres, verbo imperativo y sin punto final.\n")
    return 2


if __name__ == "__main__":
    sys.exit(main())
