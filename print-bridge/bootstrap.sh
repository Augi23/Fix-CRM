#!/bin/bash
# Instalace štítkového můstku Fix-CRM (Brother QL-8xx) — spouští se z Nastavení CRM:
#   curl -fsSL https://admin.applefix.cloud/print-bridge/bootstrap.sh | bash                      (najde se samo)
#   curl -fsSL https://admin.applefix.cloud/print-bridge/bootstrap.sh | bash -s -- usb             (Brother v USB)
#   curl -fsSL https://admin.applefix.cloud/print-bridge/bootstrap.sh | bash -s -- tcp:192.168.1.220 (Brother na Wi-Fi)
# Argument se předá install.sh beze změny. Spouštět klidně opakovaně.
set -euo pipefail
BASE="${STITEK_BASE:-https://admin.applefix.cloud/print-bridge}"
DIR="$HOME/stitek-bridge"
echo "🏷️  Instalace štítkového můstku (Brother QL-8xx)…"
mkdir -p "$DIR"; cd "$DIR"
# stitek_product.py + label_logo.png = cenovky produktů; bez nich tiskl můstek
# na nové pobočce jen štítky zakázek a cenovka skončila chybou
for f in stitek_bridge.py stitek_product.py label_logo.png install.sh; do
    curl -fsSL -o "$f" "$BASE/$f"
done
chmod +x install.sh
./install.sh "$@"
