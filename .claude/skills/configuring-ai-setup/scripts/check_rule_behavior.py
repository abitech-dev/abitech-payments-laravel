"""Prueba de comportamiento: pide a una sesión real de Claude Code tareas
pequeñas y comprueba que la respuesta siga la regla que cada caso cita.

Uso:
    python check_rule_behavior.py [raiz] [--modelo haiku] [--muestras 3] [--solo core/01]
                                  [--casos archivo.json]

Con `--muestras N` cada caso corre N veces y pasa por mayoría: la respuesta
de un modelo no es determinista y una sola corrida confunde ruido con regla.

Casos de la base en `rule-cases.json`, junto a este script; del proyecto,
en `<raiz>/.claude/rule-cases.json`; los de la biblioteca, en `--casos`, que
arma `sync.py probar`. Gasta una llamada corta por caso.
"""

import json
import re
import subprocess
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

TIEMPO_MAXIMO = 300


def casos(raiz: Path, extra: list[Path] = ()) -> list[dict]:
    lista = []
    for archivo in (Path(__file__).with_name("rule-cases.json"), raiz / ".claude" / "rule-cases.json", *extra):
        if archivo.exists():
            lista += json.loads(archivo.read_text(encoding="utf-8"))
    return lista


def responder(raiz: Path, prompt: str, modelo: str) -> str:
    salida = subprocess.run(
        ["claude", "-p", prompt, "--model", modelo, "--allowedTools", "Read", "--max-turns", "8"],
        cwd=raiz, capture_output=True, text=True, encoding="utf-8", timeout=TIEMPO_MAXIMO,
    )
    return salida.stdout.strip()


def aprueba(aciertos: int, muestras: int) -> bool:
    return aciertos * 2 > muestras


def filtrar(lista: list[dict], texto: str | None) -> list[dict]:
    return [c for c in lista if not texto or texto in c["regla"] or texto in c["nombre"]]


def opcion(argumentos: list[str], nombre: str, defecto: str | None) -> tuple[str | None, list[str]]:
    if nombre not in argumentos:
        return defecto, argumentos
    i = argumentos.index(nombre)
    return argumentos[i + 1], argumentos[:i] + argumentos[i + 2:]


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
    modelo, argumentos = opcion(sys.argv[1:], "--modelo", "haiku")
    muestras, argumentos = opcion(argumentos, "--muestras", "1")
    solo, argumentos = opcion(argumentos, "--solo", None)
    extra, argumentos = opcion(argumentos, "--casos", None)
    muestras = int(muestras)
    raiz = Path(argumentos[0] if argumentos else ".").resolve()
    lista = filtrar(casos(raiz, [Path(extra)] if extra else []), solo)
    if not lista:
        print("Sin casos.")
        return 1
    fallidos = 0
    for caso in lista:
        corridas = [(r, fallos(caso, r)) for r in (responder(raiz, caso["prompt"], modelo) for _ in range(muestras))]
        aciertos = sum(not problemas for _, problemas in corridas)
        paso = aprueba(aciertos, muestras)
        fallidos += not paso
        marcador = f" ({aciertos}/{muestras})" if muestras > 1 else ""
        print(f"{'PASA ' if paso else 'FALLA'}  {caso['regla']}: {caso['nombre']}{marcador}")
        for respuesta, problemas in corridas if not paso else []:
            if problemas:
                print(f"         {'; '.join(problemas)}")
                print(f"         respuesta: {respuesta[:160]!r}")
    print(f"\n{len(lista) - fallidos} de {len(lista)} casos pasan ({modelo})")
    return 1 if fallidos else 0


if __name__ == "__main__":
    sys.exit(main())
