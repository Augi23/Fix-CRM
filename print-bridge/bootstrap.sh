#!/bin/bash
# Instalace štítkového můstku Fix-CRM (Brother QL-810W) — spouští se z Nastavení CRM:
#   curl -fsSL https://admin.applefix.cloud/print-bridge/bootstrap.sh | bash
set -euo pipefail
BASE="${STITEK_BASE:-https://admin.applefix.cloud/print-bridge}"
DIR="$HOME/stitek-bridge"
echo "🏷️  Instalace štítkového můstku (Brother QL-810W)…"
mkdir -p "$DIR"; cd "$DIR"
curl -fsSL -o stitek_bridge.py "$BASE/stitek_bridge.py"
curl -fsSL -o install.sh "$BASE/install.sh"
chmod +x install.sh
./install.sh
