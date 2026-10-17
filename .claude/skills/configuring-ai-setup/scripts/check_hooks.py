"""Prueba de hooks dentro de Claude Code: abre una sesión sin interfaz con
`--debug hooks`, le pide la acción que el hook debe vigilar y muestra qué
hooks corrieron y con qué código de salida.

Uso:
    python check_hooks.py [raiz] "PROMPT" HERRAMIENTA [HERRAMIENTA ...]

Ejemplo:
    python check_hooks.py . "Usa PowerShell para: git commit --dry-run -m 'x'" "PowerShell(git commit:*)"

Pasa si cada hook esperado aparece con `status code 2` (bloqueó y la IA
recibió el motivo) o sin error (dejó pasar). Requiere `claude` en el PATH.
"""

import re
import subprocess
import sys
import time
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

TIEMPO_MAXIMO = 300
DEPURACION = Path.home() / ".claude" / "debug"
LINEA_DE_HOOK = re.compile(r"Hook \w+:|hook command|Could not open|Hooks: Found", re.I)


def main() -> int:
    argumentos = sys.argv[1:]
    raiz = Path(argumentos.pop(0)).resolve() if argumentos and Path(argumentos[0]).is_dir() else Path.cwd()
    if len(argumentos) < 2:
        print(__doc__)
        return 1
    prompt, herramientas = argumentos[0], argumentos[1:]
    inicio = time.time()
    resultado = subprocess.run(
        ["claude", "-p", prompt, "--model", "haiku", "--allowedTools", *herramientas,
         "--max-turns", "4", "--debug", "hooks"],
        cwd=raiz, stdin=subprocess.DEVNULL, capture_output=True, text=True, encoding="utf-8",
        timeout=TIEMPO_MAXIMO,
    )
    registros = [r for r in DEPURACION.glob("*.txt") if r.stat().st_mtime >= inicio]
    if not registros:
        print("Sin registro de depuración: ¿está `claude` en el PATH?")
        return 1
    lineas = registros[-1].read_text(encoding="utf-8", errors="ignore").splitlines()
    eventos = [l for l in lineas if LINEA_DE_HOOK.search(l)]
    print("\n".join(eventos) or "Ningún hook corrió.")
    print("\nRespuesta de la sesión:\n" + resultado.stdout.strip()[-600:])
    return 0


if __name__ == "__main__":
    sys.exit(main())
