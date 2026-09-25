#!/usr/bin/env python3
"""
Štítkový můstek Fix-CRM -> Brother QL-8xx
==========================================
Malý lokální HTTP server (port 9110), přes který CRM v prohlížeči tiskne
štítky na Brother QL-810W / QL-820NWB. Tiskne VŽDY jen prohlížeč toho
počítače, u kterého tiskárna stojí — nic nechodí po síti z jiné pobočky.
Rastr je shodný s aplikací „Naskladnění produktů" (brother_ql, 62mm role,
threshold 70, dither off, cut) včetně dvoubarevného tisku pro AKCE.

Cíl tisku (pobočka si ho nastaví přímo z CRM):
  tcp:<IP>       … síťová tiskárna (Wi-Fi/LAN, port 9100) — Karlín
  cups:<fronta>  … tiskárna připojená k TOMUTO Macu (USB i jakákoli jiná
                   fronta v systému) — Na Příkopě („Černá růže")

Pořadí hledání cíle:
  1. STITEK_PRINTER_TARGET  (env; „tcp:…" / „cups:…")
  2. STITEK_PRINTER_IP      (env; zpětná kompatibilita -> tcp:)
  3. ~/Library/AppleFix/stitek_bridge.json  {"printer_target","printer_model"}
  4. printer_ip z ~/.naskladneni_produktu.json (-> tcp:, Karlín beze změny)

Endpointy:
  GET  /health    -> stav můstku a cíle tisku (viz _health_payload)
  GET  /printers  -> nabídka front a USB zařízení pro výběr v CRM
  POST /config    -> {"target":"tcp:192.168.1.50"|"cups:brotherql","model":"QL-810W"}
  POST /adopt_usb -> {"uri":"usb://Brother/QL-810W?serial=…","queue":"brotherql"}
  GET  /preview?code=...&defect=...&date=...  -> PNG náhled štítku (bez tisku)
  POST /print     -> JSON {"code","defect","date"} -> tisk; {"ok":true} / {"ok":false,"error"}

Instalace: ./install.sh (venv + LaunchAgent, běží po startu Macu).
"""
import json
import os
import re
import shlex
import socket
import subprocess
import tempfile
import textwrap
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs, unquote

from PIL import Image, ImageDraw, ImageFont

# Pillow 10+ odstranila ANTIALIAS; brother_ql ho stále volá (stejný fix jako v naskladňovací appce)
if not hasattr(Image, "ANTIALIAS"):
    Image.ANTIALIAS = Image.Resampling.LANCZOS

PORT = int(os.environ.get("STITEK_BRIDGE_PORT", "9110"))
# vlastní config můstku (má ho každá pobočka; mění se z CRM přes POST /config)
BRIDGE_DIR = os.path.expanduser("~/Library/AppleFix")
BRIDGE_CONFIG = os.path.join(BRIDGE_DIR, "stitek_bridge.json")
# starý sdílený config naskladňovací appky — drží ho jen karlínský Mac
CONFIG = os.path.expanduser("~/.naskladneni_produktu.json")
# Obecný ovladač macOS — záloha, když systém odmítne RAW frontu (tisk jde přes
# `lp -o raw`, takže na ovladači nezáleží).
GENERIC_PPD = ("/System/Library/Frameworks/ApplicationServices.framework/Versions/A/"
               "Frameworks/PrintCore.framework/Versions/A/Resources/Generic.ppd")
ALLOWED_ORIGINS = {
    "https://admin.applefix.cloud",
    "http://localhost",
    "http://127.0.0.1",
}
W = 696  # šířka rastru pro 62mm roli (DK-22205) — shodné s naskladňovací appkou
MODELS = ("QL-810W", "QL-820NWB")
QUEUE_RE = re.compile(r"^[A-Za-z0-9_.-]{1,60}$")
IPV4_RE = re.compile(r"^\d{1,3}(?:\.\d{1,3}){3}$")
USB_URI_RE = re.compile(r"^usb://[A-Za-z0-9%._~:/?#\[\]@!$&'()*+,;=-]{1,300}$")

LP = "/usr/bin/lp"
LPSTAT = "/usr/bin/lpstat"
LPOPTIONS = "/usr/bin/lpoptions"
LPADMIN = "/usr/sbin/lpadmin"
LPINFO = "/usr/sbin/lpinfo"
CUPSENABLE = "/usr/sbin/cupsenable"
CUPSACCEPT = "/usr/sbin/cupsaccept"


# ── systémové příkazy ────────────────────────────────────────────────────────

def _run(cmd: list, timeout: int = 15) -> tuple[int, str, str]:
    """Spustí systémový příkaz a vrátí (návratový kód, stdout, stderr).
    Nikdy nevyhodí výjimku — selhání vrací jako nenulový kód a text v stderr,
    aby žádný endpoint nemohl shodit server."""
    try:
        p = subprocess.run(cmd, capture_output=True, timeout=timeout)
        return (p.returncode,
                p.stdout.decode("utf-8", "replace"),
                p.stderr.decode("utf-8", "replace"))
    except FileNotFoundError:
        return 127, "", f"příkaz {cmd[0]} v tomto systému není"
    except subprocess.TimeoutExpired:
        return 124, "", f"příkaz {cmd[0]} neodpověděl do {timeout} s"
    except Exception as e:
        return 1, "", str(e)


# ── konfigurace cíle tisku ───────────────────────────────────────────────────

def _read_json(path: str) -> dict:
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
        return data if isinstance(data, dict) else {}
    except Exception:
        return {}


def bridge_config() -> dict:
    return _read_json(BRIDGE_CONFIG)


def save_bridge_config(target: str, model: str) -> tuple[bool, str]:
    """Zapíše ~/Library/AppleFix/stitek_bridge.json (atomicky přes dočasný soubor)."""
    cfg = bridge_config()
    cfg["printer_target"] = target
    cfg["printer_model"] = model
    try:
        os.makedirs(BRIDGE_DIR, exist_ok=True)
        fd, tmp = tempfile.mkstemp(dir=BRIDGE_DIR, prefix=".stitek_bridge.", suffix=".json")
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(cfg, fh, ensure_ascii=False, indent=2)
        os.replace(tmp, BRIDGE_CONFIG)
        return True, ""
    except Exception as e:
        return False, f"config {BRIDGE_CONFIG} se nepodařilo zapsat ({e})"


def parse_target(raw: str) -> tuple[str, str]:
    """„tcp:192.168.1.50" -> ("tcp","192.168.1.50"), „cups:brotherql" -> ("cups","brotherql").
    Holá IP (staré configy) se bere jako tcp. Nerozpoznaný tvar -> ("", "")."""
    t = (raw or "").strip()
    if not t:
        return "", ""
    low = t.lower()
    if low.startswith("tcp:"):
        return "tcp", t[4:].lstrip("/").strip()
    if low.startswith("cups:"):
        return "cups", t[5:].lstrip("/").strip()
    if IPV4_RE.match(t):
        return "tcp", t
    return "", ""


def _as_tcp(raw: str) -> str:
    raw = (raw or "").strip()
    return f"tcp:{raw}" if raw else ""


def resolve_target() -> tuple[str, str]:
    """Najde cíl tisku v pořadí env -> config můstku -> starý config Karlína."""
    for raw in (os.environ.get("STITEK_PRINTER_TARGET"),
                _as_tcp(os.environ.get("STITEK_PRINTER_IP")),
                bridge_config().get("printer_target"),
                _as_tcp(_read_json(CONFIG).get("printer_ip"))):
        kind, value = parse_target(raw or "")
        if kind and value:
            return kind, value
    return "", ""


def target_string() -> str:
    kind, value = resolve_target()
    return f"{kind}:{value}" if kind else ""


def printer_ip() -> str:
    """Zpětná kompatibilita: IP tiskárny, když je cíl síťový (jinak prázdné)."""
    kind, value = resolve_target()
    return value if kind == "tcp" else ""


def printer_queue() -> str:
    """Název CUPS fronty, když tiskárna visí na tomhle Macu (jinak prázdné)."""
    kind, value = resolve_target()
    return value if kind == "cups" else ""


def printer_model(override: str = "") -> str:
    model = (override or os.environ.get("STITEK_PRINTER_MODEL") or "").strip().upper()
    if not model:
        model = (bridge_config().get("printer_model") or "").strip().upper()
    if not model:
        cfg = _read_json(CONFIG)
        model = (cfg.get("printer_model") or cfg.get("model") or "").strip().upper()
    return model if model in MODELS else "QL-810W"


def is_private_ipv4(ip: str) -> bool:
    """Povolené jsou jen privátní/lokální rozsahy — tiskárna stojí vedle Macu."""
    ip = (ip or "").strip()
    if not IPV4_RE.match(ip):
        return False
    parts = ip.split(".")
    if any(p != str(int(p)) for p in parts):   # „192.168.01.5" je překlep, ne IP
        return False
    o = [int(p) for p in parts]
    if any(x > 255 for x in o):
        return False
    return (o[0] == 10
            or (o[0] == 172 and 16 <= o[1] <= 31)
            or (o[0] == 192 and o[1] == 168)
            or (o[0] == 169 and o[1] == 254)   # tiskárna přímo v kabelu / self-assigned
            or o[0] == 127)


# ── CUPS: fronty a USB zařízení ──────────────────────────────────────────────

def cups_queue_names() -> list:
    """Názvy front z `lpstat -p`. Hlášky CUPS jsou přeložené (čeština!), proto
    se z řádku bere jen druhé slovo — „tiskárna NÁZEV je nečinná" i
    „printer NÁZEV is idle" dají stejný výsledek."""
    rc, out, _ = _run([LPSTAT, "-p"], timeout=10)
    names = []
    for line in out.splitlines():
        if not line or line[0].isspace():
            continue
        parts = line.split()
        if len(parts) >= 2 and QUEUE_RE.match(parts[1]):
            names.append(parts[1])
    return names


def cups_queue_options(queue: str) -> dict:
    """`lpoptions -p <fronta>` -> dict. Na rozdíl od lpstat je strojově čitelné
    a nepřekládá se (printer-info, printer-state, printer-is-accepting-jobs…).
    POZOR: pro neexistující frontu vrací nesmysl s kódem 0 — existenci vždy
    ověřuj přes cups_queue_names()."""
    rc, out, _ = _run([LPOPTIONS, "-p", queue], timeout=10)
    opts = {}
    try:
        tokens = shlex.split(out.strip())
    except Exception:
        tokens = out.split()
    for tok in tokens:
        if "=" in tok:
            k, _, v = tok.partition("=")
            opts[k.strip()] = v.strip()
    return opts


def _looks_brother(*texts) -> bool:
    blob = " ".join(t or "" for t in texts).lower()
    return "brother" in blob or "ql-" in blob or "ql_" in blob


def cups_queues() -> list:
    """Seznam front pro výběr v CRM: [{"name","info","is_brother"}]."""
    out = []
    for name in cups_queue_names():
        opts = cups_queue_options(name)
        info = (opts.get("printer-info") or opts.get("printer-make-and-model") or name).strip()
        uri = opts.get("device-uri") or ""
        out.append({"name": name, "info": info, "is_brother": _looks_brother(name, info, uri)})
    return out


def _usb_name(uri: str) -> str:
    """Z „usb://Brother/QL-810W?serial=000G…" udělá „Brother QL-810W"."""
    body = unquote(uri[6:]) if uri.lower().startswith("usb://") else unquote(uri)
    body = body.split("?")[0].strip("/")
    return " ".join(p for p in body.split("/") if p) or uri


def cups_usb_devices() -> list:
    """USB tiskárny viditelné systémem: [{"uri","name","is_brother"}]."""
    rc, out, _ = _run([LPINFO, "--include-schemes", "usb", "-v"], timeout=20)
    lines = [l for l in out.splitlines() if "usb://" in l]
    if not lines:   # starší CUPS nemusí --include-schemes umět -> vypsat všechno
        rc, out, _ = _run([LPINFO, "-v"], timeout=25)
        lines = [l for l in out.splitlines() if "usb://" in l]
    devices, seen = [], set()
    for line in lines:
        for tok in line.split():
            if tok.lower().startswith("usb://") and tok not in seen:
                seen.add(tok)
                name = _usb_name(tok)
                devices.append({"uri": tok, "name": name, "is_brother": _looks_brother(name, tok)})
    return devices


def cups_queue_ready(queue: str) -> tuple[bool, str]:
    """Existuje fronta a je povolená a přijímá úlohy?"""
    if not QUEUE_RE.match(queue or ""):
        return False, f"neplatný název tiskové fronty „{queue}“"
    if queue not in cups_queue_names():
        return False, (f"tisková fronta „{queue}“ na tomhle Macu neexistuje — "
                       "vyber tiskárnu znovu v CRM (Nastavení → Tisk štítků)")
    opts = cups_queue_options(queue)
    if opts.get("printer-state") == "5":
        return False, (f"tisková fronta „{queue}“ je pozastavená — "
                       "v Nastavení systému → Tiskárny klikni na „Pokračovat“")
    if (opts.get("printer-is-accepting-jobs") or "true").lower() == "false":
        return False, f"tisková fronta „{queue}“ nepřijímá úlohy (cupsaccept {queue})"
    return True, ""


def target_ready(kind: str = "", value: str = "") -> tuple[bool, str]:
    """Odpovídá nastavený cíl tisku? Vrací (ready, česká hláška proč ne)."""
    if not kind:
        kind, value = resolve_target()
    if not kind:
        return False, ("není nastavený cíl tisku — vyber tiskárnu v CRM "
                       "(Nastavení → Tisk štítků)")
    if kind == "tcp":
        try:
            socket.create_connection((value, 9100), timeout=2).close()
        except Exception as e:
            return False, f"tiskárna {value} neodpovídá na portu 9100 ({e})"
        return True, ""
    return cups_queue_ready(value)


def _font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    cands = (
        ["/System/Library/Fonts/Supplemental/Arial Bold.ttf",
         "/Library/Fonts/Arial Bold.ttf",
         "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"]
        if bold else
        ["/System/Library/Fonts/Supplemental/Arial.ttf",
         "/Library/Fonts/Arial.ttf",
         "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"]
    )
    for p in cands:
        if os.path.exists(p):
            return ImageFont.truetype(p, size)
    return ImageFont.load_default()


def _code128_image(code: str) -> Image.Image:
    """Code128 přes python-barcode; bez textu (kreslíme si vlastní), ostré moduly."""
    import barcode
    from barcode.writer import ImageWriter

    bc = barcode.get("code128", code, writer=ImageWriter())
    img = bc.render(writer_options={
        "module_width": 0.35,     # mm — dost hrubé pro spolehlivé čtení ručními skenery
        "module_height": 17.0,    # mm
        "quiet_zone": 2.0,
        "write_text": False,
        "dpi": 300,
        "background": "white",
        "foreground": "black",
    })
    return img.convert("L")


def render_label(code: str, defect: str, date: str, client: str = "") -> Image.Image:
    """Štítek zakázky: velký Code128 (naskenování otevře zakázku v CRM),
    číslo zakázky, jméno klienta, krátký popis závady, datum přijetí."""
    code = re.sub(r"[^\x20-\x7E]", "", (code or "").strip()) or "?"
    defect = (defect or "").strip()
    date = (date or "").strip()
    client = (client or "").strip()

    bar = _code128_image(code)
    bar_w = W - 36
    bar = bar.resize((bar_w, int(bar.height * bar_w / bar.width)), Image.Resampling.LANCZOS)
    bar = bar.point(lambda p: 0 if p < 128 else 255)  # zpět na ostrou 1-bit hranu

    f_code = _font(44, bold=True)
    f_txt = _font(34)
    f_date = _font(30)

    f_client = _font(38, bold=True)
    defect_lines = textwrap.wrap(defect, width=38)[:2] if defect else []
    client_line = textwrap.shorten(client, width=32, placeholder="…") if client else ""

    pad = 14
    y = pad
    h_bar = bar.height
    h_code = 52
    h_client = 46 if client_line else 0
    h_defect = len(defect_lines) * 40
    h_date = 38 if date else 0
    H = pad + h_bar + 8 + h_code + (6 + h_client if client_line else 0) + (8 + h_defect if defect_lines else 0) + (6 + h_date) + pad

    img = Image.new("L", (W, H), 255)
    d = ImageDraw.Draw(img)

    img.paste(bar, ((W - bar.width) // 2, y))
    y += h_bar + 8
    tw = d.textlength(code, font=f_code)
    d.text(((W - tw) // 2, y), code, font=f_code, fill=0)
    y += h_code
    if client_line:
        y += 6
        d.text((pad + 4, y), client_line, font=f_client, fill=0)
        y += 46
    if defect_lines:
        y += 8
        for line in defect_lines:
            d.text((pad + 4, y), line, font=f_txt, fill=0)
            y += 40
    if date:
        y += 6
        d.text((pad + 4, y), f"Přijato: {date}", font=f_date, fill=0)

    return img.convert("RGB")


def build_instructions(img: Image.Image, model: str = "", red: bool = False):
    """Rastr shodný s naskladňovací appkou — 62mm role, threshold 70, dither off,
    cut. red=True = dvoubarevný rastr (62red) pro černo-červenou roli DK-22251,
    čistě červené pixely obrázku jedou do červené vrstvy pásky (štítek AKCE)."""
    from brother_ql.raster import BrotherQLRaster
    from brother_ql.conversion import convert
    qlr = BrotherQLRaster(printer_model(model))
    qlr.exception_on_warning = True
    if red and img.mode != "RGB":
        img = img.convert("RGB")   # dvoubarevný rastr čte barvy — šedotónový obrázek by neprošel
    return convert(qlr=qlr, images=[img], label="62red" if red else "62", rotate="auto",
                   threshold=70, dither=False, cut=True, red=red)


def _print_tcp(instr, ip: str) -> tuple[bool, str]:
    """Síťová tiskárna (Karlín) — beze změny: brother_ql send() na tcp://ip:9100."""
    from brother_ql.backends.helpers import send
    send(instructions=instr, printer_identifier=f"tcp://{ip}",
         backend_identifier="network", blocking=True)
    return True, ""


def _print_cups(instr, queue: str) -> tuple[bool, str]:
    """Tiskárna na tomhle Macu (USB i jiná fronta) — hotový rastr se pošle
    do RAW fronty přes `lp -d <fronta> -o raw`. Dočasný soubor vždy uklidíme."""
    ok, err = cups_queue_ready(queue)
    if not ok:
        return False, err
    path = ""
    try:
        fd, path = tempfile.mkstemp(prefix="stitek_", suffix=".prn")
        with os.fdopen(fd, "wb") as fh:
            fh.write(bytes(instr))
        rc, out, serr = _run([LP, "-d", queue, "-o", "raw", "-s", path], timeout=60)
        if rc != 0:
            detail = (serr or out or "").strip() or f"návratový kód {rc}"
            return False, f"lp selhal při tisku do fronty „{queue}“: {detail}"
        return True, ""
    except Exception as e:
        return False, f"tisk do fronty „{queue}“ selhal ({e})"
    finally:
        if path:
            try:
                os.unlink(path)
            except Exception:
                pass


def print_image(img: Image.Image, model: str = "", red: bool = False) -> tuple[bool, str]:
    """Vytiskne štítek na nastavený cíl — tcp:<IP> (síť) nebo cups:<fronta> (USB)."""
    kind, value = resolve_target()
    if not kind:
        return False, ("není nastavený cíl tisku — vyber tiskárnu v CRM "
                       "(Nastavení → Tisk štítků) nebo spusť instalátor s argumentem "
                       "usb / tcp:<IP>")
    if kind == "tcp":
        try:
            socket.create_connection((value, 9100), timeout=2).close()
        except Exception as e:
            return False, f"tiskárna {value} neodpovídá na portu 9100 ({e})"
    try:
        instr = build_instructions(img, model, red=red)
    except Exception as e:
        return False, f"rastr štítku se nepodařilo připravit ({e})"
    try:
        if kind == "tcp":
            return _print_tcp(instr, value)
        return _print_cups(instr, value)
    except Exception as e:
        return False, str(e)


# ── odpovědi pro CRM ─────────────────────────────────────────────────────────

def _health_payload() -> dict:
    """Tělo /health. Klíče printer_ip a printer_model zůstávají kvůli starším
    stránkám CRM, nové (target/kind/printer_queue/ready/error) jsou pro nové UI."""
    kind, value = resolve_target()
    ready, err = target_ready(kind, value)
    return {
        "ok": True,
        "target": f"{kind}:{value}" if kind else "",
        "kind": kind or None,
        "printer_ip": value if kind == "tcp" else "",
        "printer_queue": value if kind == "cups" else "",
        "printer_model": printer_model(),
        "ready": ready,
        "error": err or None,
    }


def _printers_payload() -> dict:
    """Tělo /printers — z čeho si obsluha v CRM vybírá tiskárnu."""
    queues = cups_queues()
    usb = cups_usb_devices()
    suggestion = None
    brother_queue = next((q for q in queues if q["is_brother"]), None)
    if brother_queue:
        suggestion = f"cups:{brother_queue['name']}"
    else:
        kind, value = resolve_target()   # jinak aspoň to, co už je nastavené (Karlín)
        if kind == "tcp" and value:
            suggestion = f"tcp:{value}"
    return {
        "ok": True,
        "queues": queues,
        "usb": [{"uri": d["uri"], "name": d["name"]} for d in usb],
        "suggestion": suggestion,
    }


def _apply_config(target: str, model: str) -> tuple[bool, str]:
    """Ověří a uloží cíl tisku z CRM. Vrací (ok, česká chyba)."""
    kind, value = parse_target(target)
    if not kind:
        return False, ("neplatný cíl tisku — čekám „tcp:<IP>“ (síťová tiskárna) "
                       "nebo „cups:<fronta>“ (tiskárna u tohoto Macu)")
    if kind == "tcp":
        if not is_private_ipv4(value):
            return False, (f"„{value}“ není platná IP z domácí sítě "
                           "(povolené jsou 10.x, 172.16–31.x, 192.168.x, 169.254.x, 127.x)")
    else:
        if not QUEUE_RE.match(value):
            return False, ("název tiskové fronty smí mít jen písmena, číslice, "
                           "tečku, podtržítko a pomlčku (max. 60 znaků)")
        if value not in cups_queue_names():
            return False, (f"tisková fronta „{value}“ na tomhle Macu neexistuje — "
                           "načti si nabídku tiskáren znovu")
    model = (model or "").strip().upper() or printer_model()
    if model not in MODELS:
        return False, f"neznámý model tiskárny „{model}“ (povolené: {', '.join(MODELS)})"
    return save_bridge_config(f"{kind}:{value}", model)


def _manual_queue_hint(queue: str, uri: str) -> str:
    """Co má obsluha udělat rukama, když si můstek frontu založit nesmí."""
    return ("Spusť prosím v Terminálu na tomhle Macu:\n"
            f"  sudo lpadmin -p {queue} -E -v '{uri}' -m raw && "
            f"sudo cupsenable {queue} && sudo cupsaccept {queue}\n"
            "Když i sudo odmítne („neformátované fronty už nejsou podporovány“ — "
            "novější macOS), přidej tiskárnu normálně v Nastavení systému → "
            "Tiskárny a skenery a pak ji v CRM vyber ze seznamu front. Můstek "
            "posílá data přes „lp -o raw“, takže funguje i s běžnou frontou.\n"
            "Potom v CRM klikni znovu na „Načíst tiskárny“.")


def _adopt_usb(uri: str, queue: str) -> tuple[bool, str]:
    """Založí CUPS frontu pro USB Brother (stejný postup jako
    scripts/mac_xprinter_setup.sh) a uloží ji jako cíl tisku. Je idempotentní —
    existující frontu jen dorovná a uloží."""
    uri = (uri or "").strip()
    queue = (queue or "brotherql").strip()
    if not QUEUE_RE.match(queue):
        return False, ("název fronty smí mít jen písmena, číslice, tečku, "
                       "podtržítko a pomlčku (max. 60 znaků)")
    if not USB_URI_RE.match(uri):
        return False, "neplatná USB adresa tiskárny (čekám tvar usb://…)"
    known = [d["uri"] for d in cups_usb_devices()]
    if known and uri not in known:
        return False, ("tahle USB tiskárna už není vidět — je zapojená do TOHOTO "
                       "Macu a zapnutá? Načti nabídku tiskáren znovu.")
    if queue not in cups_queue_names():
        rc, out, err = _run([LPADMIN, "-p", queue, "-E", "-v", uri, "-m", "raw"], timeout=30)
        detail = (err or out or "").strip() or f"návratový kód {rc}"
        if rc != 0:
            # Novější macOS RAW fronty zakládat odmítá („Neformátované fronty už
            # nejsou v prostředí macOS podporovány"). Nevadí: můstek posílá data
            # přes `lp -o raw`, což filtry obejde u JAKÉKOLI fronty — stačí tedy
            # obyčejná fronta s obecným ovladačem. „everywhere" nepomůže, USB
            # Brother IPP neumí.
            detail2 = ""
            for model_args in (["-P", GENERIC_PPD], ["-m", "drv:///sample.drv/generic.ppd"]):
                if model_args[0] == "-P" and not os.path.exists(GENERIC_PPD):
                    continue
                rc2, out2, err2 = _run([LPADMIN, "-p", queue, "-E", "-v", uri] + model_args, timeout=60)
                if rc2 == 0:
                    break
                detail2 = (err2 or out2 or "").strip() or f"návratový kód {rc2}"
            else:
                return False, (f"frontu „{queue}“ se nepodařilo založit "
                               f"(lpadmin -m raw: {detail}; obecný ovladač: {detail2}).\n"
                               + _manual_queue_hint(queue, uri))
    # chybnou úlohu zahodit (ať fronta nezůstane viset) a tiskárnu nesdílet
    _run([LPADMIN, "-p", queue, "-o", "printer-error-policy=abort-job",
          "-o", "printer-is-shared=false"], timeout=20)
    _run([CUPSENABLE, queue], timeout=20)
    _run([CUPSACCEPT, queue], timeout=20)
    if queue not in cups_queue_names():
        return False, (f"fronta „{queue}“ se po založení nehlásí.\n"
                       + _manual_queue_hint(queue, uri))
    return save_bridge_config(f"cups:{queue}", printer_model())


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):  # tišší log
        pass

    def _cors(self):
        origin = self.headers.get("Origin", "")
        base = origin.split("://")[0] + "://" + origin.split("://")[-1].split(":")[0] if "://" in origin else origin
        if origin in ALLOWED_ORIGINS or base in ALLOWED_ORIGINS:
            self.send_header("Access-Control-Allow-Origin", origin)
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        # Chrome Private Network Access: bez tohoto Chrome tiše blokuje
        # požadavky z HTTPS stránky na http://127.0.0.1 (preflight vyžaduje souhlas)
        self.send_header("Access-Control-Allow-Private-Network", "true")

    def _json(self, status: int, payload: dict):
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self._cors()
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    def do_GET(self):
        url = urlparse(self.path)
        if url.path == "/health":
            try:
                self._json(200, _health_payload())
            except Exception as e:
                self._json(200, {"ok": True, "target": "", "kind": None, "printer_ip": "",
                                 "printer_queue": "", "printer_model": "QL-810W",
                                 "ready": False, "error": f"stav tiskárny se nepodařilo zjistit ({e})"})
            return
        if url.path == "/printers":
            try:
                self._json(200, _printers_payload())
            except Exception as e:
                self._json(200, {"ok": False, "queues": [], "usb": [], "suggestion": None,
                                 "error": f"seznam tiskáren se nepodařilo načíst ({e})"})
            return
        if url.path == "/preview":
            q = {k: v[0] for k, v in parse_qs(url.query).items()}
            try:
                img = render_label(q.get("code", "TEST123"), q.get("defect", ""), q.get("date", ""), q.get("client", ""))
                import io
                buf = io.BytesIO()
                img.save(buf, "PNG")
                data = buf.getvalue()
                self.send_response(200)
                self._cors()
                self.send_header("Content-Type", "image/png")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
            except Exception as e:
                self._json(500, {"ok": False, "error": str(e)})
            return
        self._json(404, {"ok": False, "error": "not found"})

    def _body(self) -> dict:
        length = int(self.headers.get("Content-Length") or 0)
        data = json.loads(self.rfile.read(length).decode("utf-8") or "{}")
        return data if isinstance(data, dict) else {}

    def _saved_reply(self, ok: bool, err: str):
        """Po /config i /adopt_usb se vrací tělo /health, ať UI hned vidí výsledek."""
        if not ok:
            self._json(400, {"ok": False, "error": err})
            return
        payload = _health_payload()
        saved = (bridge_config().get("printer_target") or "").strip()
        if saved and saved != payload["target"]:
            # na tomhle Macu přebíjí config proměnná prostředí (STITEK_PRINTER_*)
            payload["note"] = (f"uloženo „{saved}“, ale můstek jede podle proměnné "
                               f"prostředí ({payload['target'] or 'nenastaveno'}) — "
                               "odeber STITEK_PRINTER_TARGET / STITEK_PRINTER_IP a restartuj můstek")
        self._json(200, payload)

    def _config(self):
        try:
            data = self._body()
            ok, err = _apply_config(str(data.get("target") or ""), str(data.get("model") or ""))
            self._saved_reply(ok, err)
        except Exception as e:
            self._json(500, {"ok": False, "error": f"nastavení se nepodařilo uložit ({e})"})

    def _adopt_usb(self):
        try:
            data = self._body()
            ok, err = _adopt_usb(str(data.get("uri") or ""), str(data.get("queue") or "brotherql"))
            self._saved_reply(ok, err)
        except Exception as e:
            self._json(500, {"ok": False, "error": f"USB tiskárnu se nepodařilo nastavit ({e})"})

    def do_POST(self):
        path = urlparse(self.path).path
        if path == "/config":
            self._config()
            return
        if path == "/adopt_usb":
            self._adopt_usb()
            return
        if path != "/print":
            self._json(404, {"ok": False, "error": "not found"})
            return
        try:
            data = self._body()
            model = str(data.get("printer_model") or "")
            # red_media = v tiskárně je černo-červená role DK-22251: tiskárna pak
            # odmítne běžný (jen černý) rastr, takže VŠECHNO jde dvoubarevně —
            # obyčejný štítek nemá červené pixely, vyjede tedy normálně černě
            red = bool(data.get("red_media"))
            if isinstance(data.get("product"), dict):
                from stitek_product import render_product_label
                img = render_product_label(data["product"])
                copies = max(1, min(20, int(data.get("copies") or 1)))
                red = red or bool(data["product"].get("akce"))   # AKCE = dvoubarevný tisk
            else:
                img = render_label(str(data.get("code", "")), str(data.get("defect", "")), str(data.get("date", "")), str(data.get("client", "")))
                copies = 1
            ok, err = True, ""
            for i in range(copies):
                ok, err = print_image(img, model, red=red)
                if not ok:
                    if i > 0:
                        err = f"{err} (vytištěno {i} z {copies})"
                    break
            self._json(200 if ok else 500, {"ok": ok, "error": err})
        except Exception as e:
            self._json(500, {"ok": False, "error": str(e)})


if __name__ == "__main__":
    _t = target_string() or "NENASTAVENA"
    print(f"Štítkový můstek běží na http://127.0.0.1:{PORT} (cíl tisku: {_t}, model: {printer_model()})")
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
