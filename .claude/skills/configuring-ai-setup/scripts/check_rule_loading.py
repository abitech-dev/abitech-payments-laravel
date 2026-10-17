"""Prueba de carga real: abre una sesión de Claude Code sin interfaz, le
hace leer cada archivo y registra con el hook InstructionsLoaded qué
reglas cargó de verdad.

Uso:
    python check_rule_loading.py [raiz] RUTA [RUTA ...]

Requiere el CLI `claude` en el PATH. Gasta una llamada corta a Haiku por
archivo.
"""

import json
import os
import subprocess
import sys
import tempfile
import uuid
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

# Bastan pocas líneas, porque la regla carga al tocar el archivo y no por
# su contenido.
PROMPT = "Usa la herramienta Read para leer {ruta} (solo 5 líneas) y responde únicamente OK."
TIEMPO_MAXIMO = 300


def registrar() -> None:
    # Los hooks corren en paralelo y en Windows dos escrituras al mismo
    # archivo se pisan; cada evento va al suyo.
    carpeta = Path(os.environ["REGISTRO_CARGA"])
    (carpeta / f"{uuid.uuid4().hex}.json").write_text(sys.stdin.read(), encoding="utf-8")


def probar(raiz: Path, ruta: str, carpeta: Path) -> list[dict]:
    registro = carpeta / f"{abs(hash(ruta))}"
    registro.mkdir()
    ajustes = carpeta / "settings.json"
    comando = f'python "{Path(__file__).resolve().as_posix()}" --registrar'
    ajustes.write_text(json.dumps({"hooks": {"InstructionsLoaded": [{"hooks": [{"type": "command", "command": comando}]}]}}))
    entorno = dict(os.environ, REGISTRO_CARGA=str(registro))
    subprocess.run(
        ["claude", "-p", PROMPT.format(ruta=ruta), "--model", "haiku", "--settings", str(ajustes),
         "--allowedTools", "Read", "--max-turns", "8"],
        cwd=raiz, env=entorno, capture_output=True, text=True, encoding="utf-8", timeout=TIEMPO_MAXIMO,
    )
    return [json.loads(evento.read_text(encoding="utf-8")) for evento in registro.glob("*.json")]


def main() -> int:
    if "--registrar" in sys.argv:
        registrar()
        return 0
    argumentos = sys.argv[1:]
    raiz = Path(argumentos.pop(0)).resolve() if argumentos and Path(argumentos[0]).is_dir() else Path.cwd()
    if not argumentos:
        print("Indique al menos un archivo a tocar.")
        return 1
    with tempfile.TemporaryDirectory() as temporal:
        for ruta in argumentos:
            eventos = probar(raiz, ruta, Path(temporal))
            if not eventos:
                print(f"\n{ruta}: sin eventos; ¿está `claude` en el PATH y el hook permitido?")
                continue
            total = sum(Path(e["file_path"]).stat().st_size for e in eventos if Path(e["file_path"]).exists())
            print(f"\n{ruta}: {len(eventos)} archivos, {total / 1024:.1f} KB")
            for evento in sorted(eventos, key=lambda e: e["file_path"]):
                tamano = Path(evento["file_path"]).stat().st_size / 1024
                print(f"  {tamano:6.1f} KB  {evento['load_reason']:<16} {evento['memory_type']:<8} {evento['file_path']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
