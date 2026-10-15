"""Valida reglas, skills y CLAUDE.md de un proyecto contra el estándar de
Claude Code (code.claude.com/docs/en/memory y /skills).

Uso:
    python validate.py [raiz] [--costo RUTA ...]

Sale con 1 si hay errores; las advertencias no cortan.
"""

import json
import re
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")

# Pasado el límite oficial baja la adherencia y Claude Code avisa.
MAX_LINEAS_REGLA = 200
MAX_LINEAS_CLAUDE_MD = 200
MAX_LINEAS_SKILL = 500
MAX_DESCRIPCION_SKILL = 1024
MAX_NOMBRE_SKILL = 64
# El español ronda 3.5 caracteres por token; da solo el orden de magnitud.
CARACTERES_POR_TOKEN = 3.5
# Presupuesto de core/08 para lo que carga al tocar un archivo. Una
# pantalla carga además el sistema de diseño.
OBJETIVO_TOKENS_COSTO = 15_000
OBJETIVO_TOKENS_PANTALLA = 20_000
EXTENSIONES_PANTALLA = (".vue", ".tsx", ".jsx")

IGNORADOS = {".git", "node_modules", "vendor", "storage", "dist", "build", ".next"}
GLOBAL = Path.home() / ".claude"

errores: list[str] = []
avisos: list[str] = []


def error(texto: str) -> None:
    errores.append(texto)


def aviso(texto: str) -> None:
    avisos.append(texto)


def expandir_llaves(patron: str) -> list[str]:
    m = re.search(r"\{([^{}]*)\}", patron)
    if not m:
        return [patron]
    resultado = []
    for opcion in m.group(1).split(","):
        resultado += expandir_llaves(patron[: m.start()] + opcion + patron[m.end():])
    return resultado


def glob_a_regex(patron: str) -> re.Pattern:
    i, salida = 0, ""
    while i < len(patron):
        if patron.startswith("**/", i):
            salida += r"(?:.*/)?"
            i += 3
        elif patron.startswith("**", i):
            salida += r".*"
            i += 2
        elif patron[i] == "*":
            salida += r"[^/]*"
            i += 1
        elif patron[i] == "?":
            salida += r"[^/]"
            i += 1
        else:
            salida += re.escape(patron[i])
            i += 1
    return re.compile(salida + r"\Z")


def coincide(patron: str, ruta: str) -> bool:
    return any(glob_a_regex(p).match(ruta) for p in expandir_llaves(patron))


def leer_frontmatter(texto: str) -> tuple[dict | None, str]:
    """Devuelve (campos, cuerpo); campos None si no hay frontmatter."""
    if not texto.startswith("---"):
        return None, texto
    fin = texto.find("\n---", 3)
    if fin == -1:
        return {"__roto__": True}, texto
    bloque, cuerpo = texto[3:fin], texto[fin + 4:]
    campos: dict = {}
    clave = None
    for linea in bloque.splitlines():
        if not linea.strip() or linea.lstrip().startswith("#"):
            continue
        lista = re.match(r"\s+-\s*(.+)", linea)
        if lista and clave and isinstance(campos.get(clave), list):
            campos[clave].append(lista.group(1).strip().strip("'\""))
            continue
        if linea[0].isspace() and clave:
            # Escalar en bloque (`>-`, `|`) o mapa anidado (`metadata:`).
            if isinstance(campos[clave], str):
                campos[clave] = (campos[clave] + " " + linea.strip()).strip()
            elif campos[clave] == []:
                campos[clave] = {}
            continue
        par = re.match(r"([A-Za-z_-]+):\s*(.*)", linea)
        if not par:
            campos["__roto__"] = True
            continue
        clave, valor = par.group(1), par.group(2).strip()
        if valor in (">", ">-", ">+", "|", "|-", "|+"):
            campos[clave] = ""
        else:
            campos[clave] = valor.strip("'\"") if valor else []
    return campos, cuerpo


def patrones_de(campos: dict | None) -> list[str]:
    if not campos or "paths" not in campos:
        return []
    valor = campos["paths"]
    if isinstance(valor, str):
        return [p.strip().strip("'\"") for p in valor.split(",") if p.strip()]
    return valor


def archivos_del_proyecto(raiz: Path) -> list[str]:
    rutas = []
    for ruta in raiz.rglob("*"):
        partes = ruta.relative_to(raiz).parts
        if any(p in IGNORADOS for p in partes) or not ruta.is_file():
            continue
        rutas.append("/".join(partes))
    return rutas


def exclusiones(raiz: Path) -> list[str]:
    patrones = []
    for nombre in ("settings.json", "settings.local.json"):
        archivo = raiz / ".claude" / nombre
        if archivo.exists():
            try:
                patrones += json.loads(archivo.read_text(encoding="utf-8")).get("claudeMdExcludes", [])
            except json.JSONDecodeError as e:
                error(f"{archivo}: JSON inválido ({e})")
    return patrones


def excluida(ruta: Path, patrones: list[str]) -> bool:
    absoluta = ruta.resolve().as_posix()
    return any(coincide(p, absoluta) for p in patrones)


def reglas_en(base: Path) -> list[Path]:
    carpeta = base / "rules"
    return sorted(carpeta.rglob("*.md")) if carpeta.exists() else []


def sin_cr(ruta: Path) -> str:
    return ruta.read_text(encoding="utf-8").replace("\r\n", "\n")


def copia_de_global(ruta: Path) -> Path | None:
    """La regla global de la que esta regla de proyecto es copia literal."""
    partes = ruta.as_posix().split("/.claude/rules/", 1)
    if len(partes) != 2:
        return None
    original = GLOBAL / "rules" / partes[1]
    return original if original.exists() and sin_cr(original) == sin_cr(ruta) else None


def validar_regla(ruta: Path, archivos: list[str], es_global: bool) -> None:
    texto = ruta.read_text(encoding="utf-8")
    nombre = ruta.as_posix()
    lineas = texto.count("\n") + 1
    if lineas > MAX_LINEAS_REGLA:
        error(f"{nombre}: {lineas} líneas (máximo {MAX_LINEAS_REGLA}); partir por tema o recortar")
    campos, cuerpo = leer_frontmatter(texto)
    if campos and campos.get("__roto__"):
        error(f"{nombre}: frontmatter que no parsea; se cargaría sin paths")
    for clave in (campos or {}):
        if clave not in ("paths", "__roto__"):
            aviso(f"{nombre}: campo «{clave}» ignorado; en una regla solo se lee paths")
    patrones = patrones_de(campos)
    # Solo core/ va sin paths y carga al abrir la sesión (core/06), también
    # en la copia literal que lleva un proyecto compartido.
    es_core = "/rules/core/" in nombre and (es_global or copia_de_global(ruta) is not None)
    if (campos is None or not patrones) and not es_core:
        aviso(f"{nombre}: sin paths, se carga en todas las sesiones")
    if "**/*" in patrones:
        aviso(f"{nombre}: «**/*» solo retrasa la carga al primer archivo leído")
    elif not es_global and patrones and copia_de_global(ruta) is None:
        # Una copia del stack cubre cualquier proyecto con esa tecnología y
        # basta con que un patrón cargue aquí. La de la base puede no cargar.
        es_copia = "/rules/tech/" in nombre
        vacios = [p for p in patrones if not any(coincide(p, a) for a in archivos)]
        if es_copia and len(vacios) == len(patrones):
            error(f"{nombre}: ningún patrón coincide; la regla no se carga nunca")
        elif not es_copia:
            for patron in vacios:
                error(f"{nombre}: el patrón «{patron}» no coincide con ningún archivo")
    if not re.search(r"^# ", cuerpo, re.M):
        aviso(f"{nombre}: sin título «# »")
    for marca in re.findall(r"SCRUM[- ]?\d+|\b20\d\d-\d\d-\d\d\b", cuerpo):
        aviso(f"{nombre}: «{marca}» es historial; la regla dice lo vigente")
    # Las siglas tienen hasta tres letras casi siempre; dos palabras largas
    # en mayúsculas son un título gritado (core/08).
    for titulo in re.findall(r"\*\*([^*`]+)\*\*", cuerpo):
        if len(re.findall(r"\b[A-ZÁÉÍÓÚÑ]{4,}\b", titulo)) >= 2:
            aviso(f"{nombre}: título en mayúsculas «{titulo[:40]}»")


def validar_skill(ruta: Path) -> None:
    texto = ruta.read_text(encoding="utf-8")
    nombre_archivo = ruta.as_posix()
    campos, cuerpo = leer_frontmatter(texto)
    if campos is None or campos.get("__roto__"):
        error(f"{nombre_archivo}: falta frontmatter válido")
        return
    nombre = campos.get("name") or ruta.parent.name
    if not re.fullmatch(r"[a-z0-9-]{1,%d}" % MAX_NOMBRE_SKILL, nombre):
        error(f"{nombre_archivo}: name «{nombre}» solo minúsculas, números y guiones, ≤ {MAX_NOMBRE_SKILL}")
    if re.search(r"anthropic|claude", nombre):
        error(f"{nombre_archivo}: name con palabra reservada")
    descripcion = campos.get("description")
    if not descripcion or not isinstance(descripcion, str):
        error(f"{nombre_archivo}: falta description (qué hace y cuándo usarla)")
    else:
        if len(descripcion) > MAX_DESCRIPCION_SKILL:
            error(f"{nombre_archivo}: description de {len(descripcion)} caracteres (máximo {MAX_DESCRIPCION_SKILL})")
        if re.search(r"<[a-zA-Z/]", descripcion):
            error(f"{nombre_archivo}: description con etiquetas XML")
        if not re.search(r"\b(usar|úsese|use|cuando|when)\b", descripcion, re.I):
            aviso(f"{nombre_archivo}: description sin el «cuándo usarla»")
    lineas = texto.count("\n") + 1
    if lineas > MAX_LINEAS_SKILL:
        error(f"{nombre_archivo}: {lineas} líneas (máximo {MAX_LINEAS_SKILL}); mover detalle a archivos de apoyo")
    for enlace in re.findall(r"\]\(([^)#:]+)\)", cuerpo):
        if "\\" in enlace:
            error(f"{nombre_archivo}: ruta con barra invertida «{enlace}»")
        elif not (ruta.parent / enlace).exists():
            error(f"{nombre_archivo}: enlace roto «{enlace}»")


ARCHIVOS_CONOCIDOS = {"CLAUDE.md", "CLAUDE.local.md", "SKILL.md", "AGENTS.md", "README.md", "MEMORY.md",
                      "CHANGELOG.md", "CONTRIBUTING.md", "SECURITY.md", "LICENSE.md"}


def validar_referencias(raiz: Path, rutas: list[Path]) -> None:
    for ruta in rutas:
        texto = ruta.read_text(encoding="utf-8", errors="ignore")
        for ref in set(re.findall(r"`?((?:core|tech|project)/[\w./-]+?\.md)", texto)):
            if not any((base / "rules" / ref).exists() for base in (raiz / ".claude", GLOBAL)):
                error(f"{ruta.as_posix()}: referencia a «{ref}», que no existe")
        # Un `hermano.md` sin carpeta se busca junto al archivo que lo cita.
        for ref in set(re.findall(r"`([\w-]+\.md)`", texto)):
            if ref not in ARCHIVOS_CONOCIDOS and not (ruta.parent / ref).exists():
                error(f"{ruta.as_posix()}: referencia a «{ref}», que no está en su carpeta")


def referentes_del_proyecto(raiz: Path) -> list[Path]:
    """Archivos cuyas referencias se comprueban en este proyecto."""
    skills = raiz / ".claude" / "skills"
    # La copia literal de una skill global cita la adaptación de cualquier
    # proyecto; un repo sin código no la tiene.
    propias = [s for s in skills.glob("**/*.md")
               if not ((GLOBAL / "skills" / s.relative_to(skills)).exists()
                       and sin_cr(GLOBAL / "skills" / s.relative_to(skills)) == sin_cr(s))]
    referentes = reglas_en(raiz / ".claude") + reglas_en(GLOBAL) + propias
    return referentes + [p for p in (raiz / "CLAUDE.md",) if p.exists()]


def validar_nombres(base: Path) -> None:
    for carpeta in ("rules", "skills", "hooks"):
        raiz = base / carpeta
        if not raiz.exists():
            continue
        for ruta in raiz.rglob("*"):
            if "__pycache__" in ruta.parts or ruta.name in ARCHIVOS_CONOCIDOS:
                continue
            nombre = ruta.stem if ruta.is_file() else ruta.name
            # Los scripts de Python van en snake_case (PEP 8); el resto, kebab-case.
            patron = r"[a-z0-9_]+" if ruta.suffix == ".py" else r"[a-z0-9][a-z0-9-]*"
            if not re.fullmatch(patron, nombre):
                error(f"{ruta.as_posix()}: nombre fuera de convención (inglés, minúsculas, kebab-case)")


def normalizar(linea: str) -> str:
    linea = re.sub(r"^[\s>*|#-]*(\d+\.\s*)?", "", linea).strip(" |").lower()
    return re.sub(r"[`*_«»\s]+", " ", linea).strip()


def validar_repeticion(base: Path) -> None:
    """Una misma frase en dos reglas distintas: la idea vive en dos sitios."""
    vistas: dict[str, set[str]] = {}
    for regla in reglas_en(base):
        cuerpo = leer_frontmatter(sin_cr(regla))[1]
        for linea in cuerpo.splitlines():
            clave = normalizar(linea)
            if len(clave) >= 60 and not set(clave) <= set("-: "):
                vistas.setdefault(clave, set()).add(regla.relative_to(base / "rules").as_posix())
    for clave, reglas in vistas.items():
        if len(reglas) > 1:
            aviso(f"texto repetido en {', '.join(sorted(reglas))}: «{clave[:70]}…»")


def validar_hooks(raiz: Path) -> None:
    for ajustes in (raiz / ".claude" / "settings.json", raiz / ".claude" / "settings.local.json", GLOBAL / "settings.json"):
        if not ajustes.exists():
            continue
        try:
            eventos = json.loads(ajustes.read_text(encoding="utf-8")).get("hooks", {})
        except json.JSONDecodeError:
            continue
        for evento, grupos in eventos.items():
            for grupo in grupos:
                matcher = grupo.get("matcher", "")
                if re.search(r"\bBash\b", matcher) and "PowerShell" not in matcher:
                    aviso(f"{ajustes.as_posix()}: {evento} vigila Bash y no PowerShell; en Windows se escapa")
                for hook in grupo.get("hooks", []):
                    if hook.get("type") != "command":
                        continue
                    comando, argumentos = hook.get("command", ""), hook.get("args")
                    todo = " ".join([comando, *(argumentos or [])])
                    if re.search(r"\$CLAUDE_PROJECT_DIR\b", todo):
                        error(f"{ajustes.as_posix()}: {evento} usa $CLAUDE_PROJECT_DIR sin llaves; en PowerShell llega vacío")
                    if argumentos is None and " " in comando.strip():
                        aviso(f"{ajustes.as_posix()}: {evento} pasa por shell; usar command + args conserva el código de salida")
                    # Un script ausente hace salir al intérprete con 2 y bloquearía todo.
                    for argumento in argumentos or []:
                        script = Path(argumento.replace("${CLAUDE_PROJECT_DIR}", str(raiz)))
                        if script.suffix in (".py", ".php", ".js", ".sh", ".ps1") and not script.exists():
                            error(f"{ajustes.as_posix()}: {evento} apunta a {script.as_posix()}, que no existe")


def validar_duplicados(raiz: Path, patrones_excluidos: list[str]) -> None:
    for global_ in reglas_en(GLOBAL):
        relativa = global_.relative_to(GLOBAL / "rules")
        local = raiz / ".claude" / "rules" / relativa
        if not local.exists():
            continue
        if sin_cr(local) != sin_cr(global_):
            aviso(f"rules/{relativa.as_posix()}: la copia del proyecto difiere de la global; sincronizar")
        if not excluida(global_, patrones_excluidos):
            aviso(f"rules/{relativa.as_posix()}: está en global y en el proyecto sin claudeMdExcludes; se carga dos veces")
    # En una skill copiada al repo gana la global por nombre, así que una
    # copia desfasada solo la ve quien no tiene la global.
    for carpeta in sorted((raiz / ".claude" / "skills").glob("*/")):
        original = GLOBAL / "skills" / carpeta.name
        if not original.exists():
            continue
        for archivo in sorted(original.rglob("*")):
            if archivo.is_file() and "__pycache__" not in archivo.parts:
                copia = carpeta / archivo.relative_to(original)
                if not copia.exists() or sin_cr(copia) != sin_cr(archivo):
                    aviso(f"skills/{carpeta.name}/{archivo.relative_to(original).as_posix()}: copia del proyecto desfasada de la global")


def costo(raiz: Path, objetivo: str, patrones_excluidos: list[str]) -> None:
    cargadas = []
    for base in (GLOBAL, raiz / ".claude"):
        for regla in reglas_en(base):
            if excluida(regla, patrones_excluidos):
                continue
            patrones = patrones_de(leer_frontmatter(regla.read_text(encoding="utf-8"))[0])
            if not patrones or any(coincide(p, objetivo) for p in patrones):
                cargadas.append(regla)
    for fijo in (GLOBAL / "CLAUDE.md", raiz / "CLAUDE.md", raiz / ".claude" / "CLAUDE.md"):
        if fijo.exists():
            cargadas.append(fijo)
    total = sum(r.stat().st_size for r in cargadas)
    tokens = total / CARACTERES_POR_TOKEN
    print(f"\nCOSTO al tocar {objetivo}: {len(cargadas)} archivos, {total / 1024:.1f} KB, ~{tokens / 1000:.1f}k tokens")
    objetivo_tokens = OBJETIVO_TOKENS_PANTALLA if objetivo.endswith(EXTENSIONES_PANTALLA) else OBJETIVO_TOKENS_COSTO
    if tokens > objetivo_tokens:
        print(f"  sobre el objetivo de {objetivo_tokens // 1000}k de core/08")
    for regla in cargadas:
        print(f"  {regla.stat().st_size / 1024:6.1f} KB  {regla.as_posix()}")


def main() -> int:
    argumentos = sys.argv[1:]
    objetivos = []
    if "--costo" in argumentos:
        i = argumentos.index("--costo")
        objetivos, argumentos = argumentos[i + 1:], argumentos[:i]
    raiz = Path(argumentos[0] if argumentos else ".").resolve()
    archivos = archivos_del_proyecto(raiz)
    patrones_excluidos = exclusiones(raiz)

    for regla in reglas_en(raiz / ".claude"):
        validar_regla(regla, archivos, es_global=False)
    for regla in reglas_en(GLOBAL):
        validar_regla(regla, archivos, es_global=True)
    for skill in sorted((raiz / ".claude" / "skills").glob("*/SKILL.md")):
        validar_skill(skill)
    for skill in sorted((GLOBAL / "skills").glob("*/SKILL.md")):
        if not skill.parent.name.startswith(("aws-", "synced")):
            validar_skill(skill)
    for claude_md in (raiz / "CLAUDE.md", raiz / ".claude" / "CLAUDE.md", GLOBAL / "CLAUDE.md"):
        if claude_md.exists():
            lineas = claude_md.read_text(encoding="utf-8").count("\n") + 1
            if lineas > MAX_LINEAS_CLAUDE_MD:
                error(f"{claude_md.as_posix()}: {lineas} líneas (máximo {MAX_LINEAS_CLAUDE_MD})")

    validar_referencias(raiz, referentes_del_proyecto(raiz))
    validar_duplicados(raiz, patrones_excluidos)
    validar_nombres(raiz / ".claude")
    validar_repeticion(raiz / ".claude")
    validar_hooks(raiz)

    for texto in avisos:
        print(f"AVISO  {texto}")
    for texto in errores:
        print(f"ERROR  {texto}")
    print(f"\n{len(errores)} errores, {len(avisos)} avisos")
    for objetivo in objetivos:
        costo(raiz, objetivo, patrones_excluidos)
    return 1 if errores else 0


if __name__ == "__main__":
    sys.exit(main())
