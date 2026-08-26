#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# AppleFix — instalace můstku pro čtení připojeného iPhonu/iPadu (macOS)
#
# Spouští se JEDNÍM příkazem, který je i s tokenem připravený v CRM:
#   Nastavení → Systém → Integrace → „Můstek pro čtení zařízení"
#
# Co udělá:
#   1) doinstaluje libimobiledevice (přes Homebrew; Homebrew případně nainstaluje)
#   2) stáhne můstek do ~/afx-device-bridge
#   3) uloží nastavení (server + token + název stanice)
#   4) založí LaunchAgent, aby běžel pořád — i po restartu Macu
#
# Použití:  ./install.sh <TOKEN> [SERVER] [NÁZEV STANICE]
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

TOKEN="${1:-}"
SERVER="${2:-https://admin.applefix.cloud}"
STATION="${3:-$(scutil --get ComputerName 2>/dev/null || hostname)}"

DIR="$HOME/afx-device-bridge"
CFG="$HOME/.afx_device_bridge.json"
PLIST="$HOME/Library/LaunchAgents/cz.applefix.device-bridge.plist"
LABEL="cz.applefix.device-bridge"

if [ -z "$TOKEN" ]; then
  echo "❌ Chybí token. Zkopíruj celý příkaz z CRM: Nastavení → Systém → Integrace."
  exit 1
fi

echo "1/5  Kontrola nástrojů…"
if ! command -v brew >/dev/null 2>&1; then
  echo "     Homebrew není nainstalovaný — instaluji (macOS se může zeptat na heslo)…"
  /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
  # po instalaci nemusí být brew v PATH (Apple Silicon vs. Intel)
  for p in /opt/homebrew/bin/brew /usr/local/bin/brew; do [ -x "$p" ] && eval "$($p shellenv)"; done
fi
if ! command -v ideviceinfo >/dev/null 2>&1; then
  echo "     Instaluji libimobiledevice…"
  brew install --quiet libimobiledevice >/dev/null
fi
command -v ideviceinfo >/dev/null 2>&1 || { echo "❌ libimobiledevice se nenainstaloval."; exit 1; }

echo "2/5  Stahuji můstek…"
mkdir -p "$DIR"
for f in afx_device.py afx_device_bridge.py; do
  curl -fsSL -o "$DIR/$f" "$SERVER/device-bridge/$f"
done
chmod +x "$DIR/afx_device_bridge.py"

echo "3/5  Ukládám nastavení…"
python3 - "$CFG" "$SERVER" "$TOKEN" "$STATION" <<'PY'
import json, sys
path, server, token, station = sys.argv[1:5]
with open(path, "w", encoding="utf-8") as fh:
    json.dump({"server": server, "token": token, "station": station}, fh, ensure_ascii=False, indent=2)
PY
chmod 600 "$CFG"

echo "4/5  Zakládám službu (poběží i po restartu)…"
mkdir -p "$HOME/Library/LaunchAgents"
PYBIN="$(command -v python3)"
cat > "$PLIST" <<PLIST_EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>$LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>$PYBIN</string>
        <string>$DIR/afx_device_bridge.py</string>
    </array>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>StandardOutPath</key><string>/tmp/afx-device-bridge.log</string>
    <key>StandardErrorPath</key><string>/tmp/afx-device-bridge.log</string>
    <key>EnvironmentVariables</key>
    <dict><key>PATH</key><string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string></dict>
</dict>
</plist>
PLIST_EOF

launchctl unload "$PLIST" 2>/dev/null || true
launchctl load "$PLIST"

echo "5/5  Zkouška spojení…"
sleep 3
if grep -q "Můstek běží" /tmp/afx-device-bridge.log 2>/dev/null; then
  echo
  echo "✅ Hotovo. Stanice: $STATION"
  echo "   Připoj iPhone nebo iPad kabelem, odemkni ho a potvrď „Důvěřovat tomuto počítači"."
  echo "   V CRM pak v Naskladnit produkt klikni na „Načíst z připojeného zařízení"."
  echo "   Log: /tmp/afx-device-bridge.log"
else
  echo
  echo "⚠️  Služba se spustila, ale zatím nehlásí start. Zkontroluj log:"
  echo "   tail -20 /tmp/afx-device-bridge.log"
fi
