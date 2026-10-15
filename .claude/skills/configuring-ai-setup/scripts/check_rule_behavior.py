"""Prueba de comportamiento: pide a una sesión real de Claude Code tareas
pequeñas y comprueba que la respuesta siga la regla que cada caso cita.

Uso:
    python check_rule_behavior.py [raiz] [--modelo haiku]

Casos de la base en `rule-cases.json`, junto a este script, y del proyecto
en `<raiz>/.claude/rule-cases.json`. Gasta una llamada corta por caso.
"""

import json
import re
import subprocess
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

TIEMPO_MAXIMO = 300


def casos(raiz: Path) -> list[dict]:
    lista = []
    for archivo in (Path(__file__).with_name("rule-cases.json"), raiz / ".claude" / "rule-cases.json"):
        if archivo.exists():
            lista += json.loads(archivo.read_text(encoding="utf-8"))
    return lista


def responder(raiz: Path, prompt: str, modelo: str) -> str:
    salida = subprocess.run(
        ["claude", "-p", prompt, "--model", modelo, "--allowedTools", "Read", "--max-turns", "8"],
        cwd=raiz, capture_output=True, text=True, encoding="utf-8", timeout=TIEMPO_MAXIMO,
    )
    return salida.stdout.strip()


def fallos(caso: dict, respuesta: str) -> list[str]:
    encontrados = []
    primera = respuesta.splitlines()[0].strip() if respuesta else ""
    for patron in caso.get("debe", []):
        if not re.search(patron, respuesta, re.M):
            encontrados.append(f"falta «{patron}»")
    for patron in caso.get("no_debe", []):
        if re.search(patron, respuesta, re.M):
            encontrados.append(f"sobra «{patron}»")
    tope = caso.get("max_primera_linea")
    if tope and len(primera) > tope:
        encontrados.append(f"primera línea de {len(primera)} caracteres (tope {tope})")
    return encontrados


def main() -> int:
    argumentos = sys.argv[1:]
    modelo = "haiku"
    if "--modelo" in argumentos:
        i = argumentos.index("--modelo")
        modelo, argumentos = argumentos[i + 1], argumentos[:i] + argumentos[i + 2:]
    raiz = Path(argumentos[0] if argumentos else ".").resolve()
    lista = casos(raiz)
    if not lista:
        print("Sin casos.")
        return 1
    fallidos = 0
    for caso in lista:
        respuesta = responder(raiz, caso["prompt"], modelo)
        problemas = fallos(caso, respuesta)
        fallidos += bool(problemas)
        print(f"{'FALLA' if problemas else 'PASA '}  {caso['regla']}: {caso['nombre']}")
        for problema in problemas:
            print(f"         {problema}")
        if problemas:
            print(f"         respuesta: {respuesta[:160]!r}")
    print(f"\n{len(lista) - fallidos} de {len(lista)} casos pasan ({modelo})")
    return 1 if fallidos else 0


if __name__ == "__main__":
    sys.exit(main())
