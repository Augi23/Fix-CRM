#!/bin/bash
# Instalace štítkového můstku Fix-CRM -> Brother QL-810W (macOS)
# - vytvoří venv s brother_ql + python-barcode + Pillow
# - založí LaunchAgent, ať můstek běží trvale (i po restartu Macu)
set -euo pipefail

VENV="$HOME/.stitek_bridge_venv"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLIST="$HOME/Library/LaunchAgents/cz.applefix.stitek-bridge.plist"

echo "1/3 venv + závislosti…"
python3 -m venv "$VENV" 2>/dev/null || true
"$VENV/bin/pip" install --quiet --upgrade pip setuptools
"$VENV/bin/pip" install --quiet brother_ql python-barcode pillow

echo "2/3 LaunchAgent…"
mkdir -p "$HOME/Library/LaunchAgents"
cat > "$PLIST" <<PLIST_EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>cz.applefix.stitek-bridge</string>
    <key>ProgramArguments</key>
    <array>
        <string>$VENV/bin/python3</string>
        <string>$SCRIPT_DIR/stitek_bridge.py</string>
    </array>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>StandardOutPath</key><string>/tmp/stitek-bridge.log</string>
    <key>StandardErrorPath</key><string>/tmp/stitek-bridge.log</string>
</dict>
</plist>
PLIST_EOF

launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"

echo "3/3 kontrola…"
sleep 2
curl -sf http://127.0.0.1:9110/health && echo "" && echo "✅ Můstek běží." || {
  echo "❌ Můstek nenaběhl — viz /tmp/stitek-bridge.log"; exit 1; }
