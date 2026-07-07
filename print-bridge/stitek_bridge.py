#!/usr/bin/env python3
"""
Štítkový můstek Fix-CRM -> Brother QL-810W
==========================================
Malý lokální HTTP server (port 9110), přes který CRM v prohlížeči tiskne
štítky zakázek na Brother QL-810W — ÚPLNĚ STEJNĚ jako aplikace
„Naskladnění produktů": stejná tiskárna (printer_ip z
~/.naskladneni_produktu.json), stejná knihovna brother_ql, stejné
parametry rastru (62mm role, threshold 70, dither off, cut).

Endpointy:
  GET  /health   -> {"ok": true, "printer_ip": "..."}
  GET  /preview?code=...&defect=...&date=...  -> PNG náhled štítku (bez tisku)
  POST /print    -> JSON {"code","defect","date"} -> tisk; {"ok":true} / {"ok":false,"error"}

Instalace: ./install.sh (venv + LaunchAgent, běží po startu Macu).
"""
import json
import os
import re
import socket
import textwrap
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

from PIL import Image, ImageDraw, ImageFont

# Pillow 10+ odstranila ANTIALIAS; brother_ql ho stále volá (stejný fix jako v naskladňovací appce)
if not hasattr(Image, "ANTIALIAS"):
    Image.ANTIALIAS = Image.Resampling.LANCZOS

PORT = int(os.environ.get("STITEK_BRIDGE_PORT", "9110"))
CONFIG = os.path.expanduser("~/.naskladneni_produktu.json")
ALLOWED_ORIGINS = {
    "https://admin.applefix.cloud",
    "http://localhost",
    "http://127.0.0.1",
}
W = 696  # šířka rastru pro 62mm roli (DK-22205) — shodné s naskladňovací appkou


def printer_ip() -> str:
    ip = (os.environ.get("STITEK_PRINTER_IP") or "").strip()
    if ip:
        return ip
    try:
        with open(CONFIG, encoding="utf-8") as fh:
            return (json.load(fh).get("printer_ip") or "").strip()
    except Exception:
        return ""


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


def render_label(code: str, defect: str, date: str) -> Image.Image:
    """Štítek zakázky: velký Code128 (naskenování otevře zakázku v CRM),
    číslo zakázky, krátký popis závady, datum přijetí."""
    code = re.sub(r"[^\x20-\x7E]", "", (code or "").strip()) or "?"
    defect = (defect or "").strip()
    date = (date or "").strip()

    bar = _code128_image(code)
    bar_w = W - 36
    bar = bar.resize((bar_w, int(bar.height * bar_w / bar.width)), Image.Resampling.LANCZOS)
    bar = bar.point(lambda p: 0 if p < 128 else 255)  # zpět na ostrou 1-bit hranu

    f_code = _font(44, bold=True)
    f_txt = _font(34)
    f_date = _font(30)

    defect_lines = textwrap.wrap(defect, width=38)[:2] if defect else []

    pad = 14
    y = pad
    h_bar = bar.height
    h_code = 52
    h_defect = len(defect_lines) * 40
    h_date = 38 if date else 0
    H = pad + h_bar + 8 + h_code + (8 + h_defect if defect_lines else 0) + (6 + h_date) + pad

    img = Image.new("L", (W, H), 255)
    d = ImageDraw.Draw(img)

    img.paste(bar, ((W - bar.width) // 2, y))
    y += h_bar + 8
    tw = d.textlength(code, font=f_code)
    d.text(((W - tw) // 2, y), code, font=f_code, fill=0)
    y += h_code
    if defect_lines:
        y += 8
        for line in defect_lines:
            d.text((pad + 4, y), line, font=f_txt, fill=0)
            y += 40
    if date:
        y += 6
        d.text((pad + 4, y), f"Přijato: {date}", font=f_date, fill=0)

    return img.convert("RGB")


def print_image(img: Image.Image) -> tuple[bool, str]:
    """Tisk shodný s naskladňovací appkou (brother_ql, tcp://ip, network backend)."""
    ip = printer_ip()
    if not ip:
        return False, "chybí printer_ip (~/.naskladneni_produktu.json)"
    try:
        socket.create_connection((ip, 9100), timeout=2).close()
    except Exception as e:
        return False, f"tiskárna {ip} neodpovídá na portu 9100 ({e})"
    try:
        from brother_ql.raster import BrotherQLRaster
        from brother_ql.conversion import convert
        from brother_ql.backends.helpers import send
        qlr = BrotherQLRaster("QL-810W")
        qlr.exception_on_warning = True
        instr = convert(qlr=qlr, images=[img], label="62", rotate="auto",
                        threshold=70, dither=False, cut=True)
        send(instructions=instr, printer_identifier=f"tcp://{ip}",
             backend_identifier="network", blocking=True)
        return True, ""
    except Exception as e:
        return False, str(e)


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
            self._json(200, {"ok": True, "printer_ip": printer_ip()})
            return
        if url.path == "/preview":
            q = {k: v[0] for k, v in parse_qs(url.query).items()}
            try:
                img = render_label(q.get("code", "TEST123"), q.get("defect", ""), q.get("date", ""))
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

    def do_POST(self):
        if urlparse(self.path).path != "/print":
            self._json(404, {"ok": False, "error": "not found"})
            return
        try:
            length = int(self.headers.get("Content-Length") or 0)
            data = json.loads(self.rfile.read(length).decode("utf-8") or "{}")
            img = render_label(str(data.get("code", "")), str(data.get("defect", "")), str(data.get("date", "")))
            ok, err = print_image(img)
            self._json(200 if ok else 500, {"ok": ok, "error": err})
        except Exception as e:
            self._json(500, {"ok": False, "error": str(e)})


if __name__ == "__main__":
    print(f"Štítkový můstek běží na http://127.0.0.1:{PORT} (tiskárna: {printer_ip() or 'NENASTAVENA'})")
    ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
