#!/usr/bin/env python3
"""AppleFix — můstek pro čtení připojeného iPhonu/iPadu (macOS).

Běží trvale na Macu u pultu. Když se připojí telefon, přečte přes USB
(libimobiledevice) IMEI, sériové číslo, model, kapacitu, verzi iOS a kondici
baterie a POŠLE to do CRM. Naskladňovací formulář si pak údaje jen vyzvedne.

Proč posílá můstek do CRM a ne naopak: stránka běží přes HTTPS a Safari
i appka „Designed for iPad" blokují volání na http://127.0.0.1. Opačný směr
funguje všude (stejná zkušenost jako u tisku štítků).

Nastavení: ~/.afx_device_bridge.json
    {"server": "https://admin.applefix.cloud", "token": "…", "station": "Karlín – pult"}
Log: /tmp/afx-device-bridge.log
"""

from __future__ import annotations

import json
import os
import socket
import sys
import time
import urllib.error
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from afx_device import connected_udids, read_device   # noqa: E402

CONFIG_PATH = os.path.expanduser("~/.afx_device_bridge.json")
POLL_SECONDS = 3          # jak často se kouká, co je připojené
HEARTBEAT_SECONDS = 30    # i beze změny se stav obnoví, ať CRM ví, že žijeme


def log(msg: str) -> None:
    print(f"{time.strftime('%Y-%m-%d %H:%M:%S')}  {msg}", flush=True)


def load_config() -> dict:
    try:
        with open(CONFIG_PATH, encoding="utf-8") as fh:
            cfg = json.load(fh)
    except (OSError, ValueError) as exc:
        log(f"CHYBA: nejde přečíst {CONFIG_PATH} ({exc}). Spusť znovu install.sh.")
        raise SystemExit(1)
    if not cfg.get("token") or not cfg.get("server"):
        log("CHYBA: v nastavení chybí server nebo token. Spusť znovu install.sh.")
        raise SystemExit(1)
    cfg.setdefault("station", socket.gethostname())
    return cfg


def push(cfg: dict, payload: dict) -> bool:
    body = json.dumps({
        "token": cfg["token"],
        "station": cfg["station"],
        "device": payload,
    }, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        cfg["server"].rstrip("/") + "/api/device_push.php",
        data=body,
        headers={"Content-Type": "application/json", "User-Agent": "AppleFix-DeviceBridge/1.0"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as res:
            answer = json.loads(res.read().decode("utf-8") or "{}")
            if not answer.get("ok"):
                log(f"CRM odmítlo data: {answer.get('error') or 'neznámá chyba'}")
                return False
            return True
    except urllib.error.HTTPError as exc:
        log(f"CRM vrátilo HTTP {exc.code} — zkontroluj token v Nastavení → Systém → Integrace.")
    except (urllib.error.URLError, ValueError, OSError) as exc:
        log(f"Spojení s CRM selhalo: {exc}")
    return False


def main() -> None:
    cfg = load_config()
    log("Můstek běží. Stanice {} → {}".format(cfg["station"], cfg["server"]))
    last_key = None
    last_sent = 0.0

    while True:
        try:
            udids = connected_udids()
            snap = read_device(udids[0]) if udids else None
        except Exception as exc:                      # noqa: BLE001 — můstek nesmí spadnout
            log(f"Čtení zařízení selhalo: {exc}")
            snap = None

        # klíč stavu: co se posílá, se mění jen při výměně zařízení
        key = json.dumps(snap, sort_keys=True, ensure_ascii=False) if snap else ""
        now = time.time()
        if key != last_key or (now - last_sent) > HEARTBEAT_SECONDS:
            if push(cfg, snap or {}):
                last_key, last_sent = key, now
                if snap:
                    log("Odesláno: {} · {} · IMEI {} · baterie {}".format(
                        snap.get("model") or snap.get("product_type") or "?",
                        snap.get("capacity") or "?",
                        snap.get("imei") or "—",
                        f"{snap['battery_health']} %" if snap.get("battery_health") else "?"))
                else:
                    log("Odesláno: žádné zařízení není připojené.")
        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log("Konec.")
