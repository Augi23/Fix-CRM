#!/usr/bin/env python3
"""Serverový tisk štítku na Brother QL-810W — CLI pro api/print_label_server.php.
Vykreslení i tisk sdílí se štítkovým můstkem (stitek_bridge.py), jen běží na
serveru CRM a data dostává parametry. Výstup: JSON {"ok": bool, "error": str}."""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from stitek_bridge import render_label, print_image  # noqa: E402

def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--ip", required=True)
    p.add_argument("--code", required=True)
    p.add_argument("--defect", default="")
    p.add_argument("--date", default="")
    p.add_argument("--client", default="")
    a = p.parse_args()

    os.environ["STITEK_PRINTER_IP"] = a.ip
    try:
        img = render_label(a.code, a.defect, a.date, a.client)
        ok, err = print_image(img)
    except Exception as e:  # render/knihovny
        ok, err = False, str(e)

    print(json.dumps({"ok": bool(ok), "error": err or ""}, ensure_ascii=False))
    return 0 if ok else 1

if __name__ == "__main__":
    sys.exit(main())
