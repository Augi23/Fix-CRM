#!/usr/bin/env python3
"""Cenový štítek PRODUKTU pro Brother QL-810W — 1:1 port renderu z naskladňovací
Mac appky (macapp/app.py: render_label / render_label_big / _label_logo).
Server je Linux → místo Arial se používá DejaVu Sans (rozměry zachované).
Vstup: dict(nazev, barva, stav, uloziste, baterie, ram, cpu, gpu, sn, cena, mac).
Výstup: PIL Image připravený k tisku (velký MacBook štítek už OTOČENÝ na 696 šířky).
"""
import os

from PIL import Image, ImageDraw, ImageFont

_DIR = os.path.dirname(os.path.abspath(__file__))


def _lblfont(sz, bold=False):
    cands = ([
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
        "/System/Library/Fonts/Supplemental/Arial Bold.ttf",
    ] if bold else [
        "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
        "/System/Library/Fonts/Supplemental/Arial.ttf",
    ])
    for p in cands:
        try:
            return ImageFont.truetype(p, sz)
        except Exception:
            pass
    return ImageFont.load_default()


def _label_logo():
    lg = Image.open(os.path.join(_DIR, "label_logo.png")).convert("RGBA")
    flat = Image.alpha_composite(Image.new("RGBA", lg.size, (255, 255, 255, 255)), lg).convert("L")
    z = Image.new("L", flat.size, 0)
    logo = Image.merge("RGBA", (z, z, z, flat.point(lambda p: 255 - p)))
    bb = logo.getbbox()
    return logo.crop(bb) if bb else logo


def render_label_big(data):
    """Velký MacBook štítek na šířku (1031×696), do tiskárny se otáčí o 90°."""
    W, H, BAR = 1031, 696, (0, 0, 0)
    logo = _label_logo()
    img = Image.new("RGB", (W, H), "white")
    d = ImageDraw.Draw(img)
    PAD = 44

    def fit(t, mw, st):
        s = st
        while s > 30:
            f = _lblfont(s, True)
            if d.textlength(t, font=f) <= mw:
                return f
            s -= 2
        return _lblfont(30, True)

    nazev = data["nazev"] or "—"
    d.text((PAD, 26), nazev, font=fit(nazev, W - 2 * PAD, 88), fill="black")
    DIV1 = 150
    d.line([PAD, DIV1, W - PAD, DIV1], fill="black", width=4)
    rows = [("Barva:", data.get("barva", "")), ("Stav:", data.get("stav", "")), ("Úložiště:", data.get("uloziste", ""))]
    if data.get("ram"): rows.append(("RAM:", data["ram"]))
    if data.get("cpu"): rows.append(("Jader CPU:", data["cpu"]))
    if data.get("gpu"): rows.append(("Jader GPU:", data["gpu"]))
    if data.get("baterie"): rows.append(("Baterie:", data["baterie"]))
    if data.get("sn"): rows.append(("SN/IMEI:", data["sn"]))
    rows = [(l, x) for l, x in rows if x]
    n = len(rows)
    if n <= 5:
        y, step, fs_l, fs_v = 176, 62, 42, 50
    elif n == 6:
        y, step, fs_l, fs_v = 172, 56, 40, 47
    elif n == 7:
        y, step, fs_l, fs_v = 168, 50, 36, 43
    else:
        y, step, fs_l, fs_v = 164, 45, 32, 39
    fl, fv = _lblfont(fs_l, False), _lblfont(fs_v, True)
    fv_sn = _lblfont(min(fs_v, 40), True)
    for lb, v in rows:
        vf = fv_sn if lb == "SN/IMEI:" else fv
        d.text((PAD, y + 5), lb, font=fl, fill="black")
        d.text((PAD + 300, y), v, font=vf, fill="black")
        y += step
    ph = 140
    py1 = H - 24
    py0 = py1 - ph
    lh = 250
    lr = logo.resize((max(1, int(logo.width * lh / logo.height)), lh))
    cy = (DIV1 + py0) // 2
    img.paste(lr, (W - PAD - lr.width, cy - lr.height // 2), lr)
    cena = data.get("cena") or "—"
    fp = _lblfont(92, True)
    cw = d.textlength(cena, font=fp)
    px1 = W - 12
    px0 = px1 - (cw + 130)
    d.rounded_rectangle([px0, py0, px1, py1], radius=66, fill=BAR, corners=(True, False, False, True))
    asc, desc = fp.getmetrics()
    tx = px0 + ((px1 - px0) - cw) / 2
    ty = py0 + (ph - (asc + desc)) // 2
    d.text((tx, ty), cena, font=fp, fill="white")
    return img


def render_product_label(data):
    """Malý štítek 696×470; MacBook = velký na šířku, vrací se UŽ otočený (696×1031)."""
    if data.get("mac"):
        big = render_label_big(data)
        return big.transpose(Image.Transpose.ROTATE_90)
    W, H, BAR = 696, 470, (0, 0, 0)
    logo = _label_logo()
    img = Image.new("RGB", (W, H), "white")
    d = ImageDraw.Draw(img)
    PAD = 30

    def fit(t, mw, st):
        s = st
        while s > 22:
            f = _lblfont(s, True)
            if d.textlength(t, font=f) <= mw:
                return f
            s -= 2
        return _lblfont(22, True)

    nazev = data["nazev"] or "—"
    d.text((PAD, 20), nazev, font=fit(nazev, W - 2 * PAD, 64), fill="black")
    DIV1 = 106
    d.line([PAD, DIV1, W - PAD, DIV1], fill="black", width=3)
    rows = [("Barva:", data.get("barva", "")), ("Stav:", data.get("stav", "")), ("Úložiště:", data.get("uloziste", ""))]
    if data.get("ram"): rows.append(("RAM:", data["ram"]))
    if data.get("cpu"): rows.append(("Jader CPU:", data["cpu"]))
    if data.get("gpu"): rows.append(("Jader GPU:", data["gpu"]))
    if data.get("baterie"): rows.append(("Baterie:", data["baterie"]))
    if data.get("sn"): rows.append(("SN/IMEI:", data["sn"]))
    rows = [(l, x) for l, x in rows if x]
    n = len(rows)
    if n <= 4:
        y, step, fs_l, fs_v = 124, 48, 31, 38
    elif n == 5:
        y, step, fs_l, fs_v = 118, 44, 31, 38
    elif n == 6:
        y, step, fs_l, fs_v = 112, 38, 27, 33
    elif n == 7:
        y, step, fs_l, fs_v = 110, 33, 24, 29
    else:
        y, step, fs_l, fs_v = 106, 29, 21, 26
    fl, fv = _lblfont(fs_l, False), _lblfont(fs_v, True)
    fv_sn = _lblfont(min(fs_v, 27), True)
    for lb, v in rows:
        vf = fv_sn if lb == "SN/IMEI:" else fv
        d.text((PAD, y + 5), lb, font=fl, fill="black")
        d.text((PAD + 200, y), v, font=vf, fill="black")
        y += step
    lh = 150
    lr = logo.resize((max(1, int(logo.width * lh / logo.height)), lh))
    cy = (DIV1 + 344) // 2
    img.paste(lr, (W - PAD - lr.width, cy - lr.height // 2), lr)
    cena = data.get("cena") or "—"
    fp = _lblfont(62, True)
    cw = d.textlength(cena, font=fp)
    ph = 104
    py1 = H - 14
    py0 = py1 - ph
    px1 = W - 8
    px0 = px1 - (cw + 96)
    d.rounded_rectangle([px0, py0, px1, py1], radius=50, fill=BAR, corners=(True, False, False, True))
    asc, desc = fp.getmetrics()
    tx = px0 + ((px1 - px0) - cw) / 2
    ty = py0 + (ph - (asc + desc)) // 2
    d.text((tx, ty), cena, font=fp, fill="white")
    return img
