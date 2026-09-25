#!/bin/bash
# Instalace štítkového můstku Fix-CRM -> Brother QL-8xx (macOS)
# - vytvoří venv s brother_ql + python-barcode + Pillow
# - nastaví cíl tisku (argument, jinak si ho najde sám)
# - založí LaunchAgent přihlášeného uživatele, ať můstek běží trvale (i po restartu)
#
# Použití:  ./install.sh            … cíl si najde (existující config / USB Brother / naskladňovací appka)
#           ./install.sh usb        … Brother zapojený USB kabelem do TOHOTO Macu
#           ./install.sh tcp:IP     … Brother na Wi-Fi / v síti pobočky
#           ./install.sh cups:FRONTA… existující tisková fronta v systému
# Je idempotentní — spouštěj klidně opakovaně.
set -euo pipefail

VENV="$HOME/.stitek_bridge_venv"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLIST="$HOME/Library/LaunchAgents/cz.applefix.stitek-bridge.plist"
LABEL="cz.applefix.stitek-bridge"
CFG_DIR="$HOME/Library/AppleFix"
CFG="$CFG_DIR/stitek_bridge.json"
QUEUE="brotherql"
GENERIC_PPD="/System/Library/Frameworks/ApplicationServices.framework/Versions/A/Frameworks/PrintCore.framework/Versions/A/Resources/Generic.ppd"
ARG="${1:-}"

write_cfg() {   # $1 = cíl tisku (tcp:… / cups:…); model ponecháme, jestli už byl nastavený
    mkdir -p "$CFG_DIR"
    /usr/bin/python3 - "$CFG" "$1" <<'PY'
import json, os, sys
path, target = sys.argv[1], sys.argv[2]
try:
    cfg = json.load(open(path, encoding="utf-8"))
    if not isinstance(cfg, dict): cfg = {}
except Exception:
    cfg = {}
cfg["printer_target"] = target
cfg.setdefault("printer_model", "QL-810W")
tmp = path + ".tmp"
json.dump(cfg, open(tmp, "w", encoding="utf-8"), ensure_ascii=False, indent=2)
os.replace(tmp, path)
PY
}

usb_brother_uri() {
    lpinfo -v 2>/dev/null | awk '/usb:\/\// {print $2}' | grep -iE 'brother|QL-' | head -1 || true
}

# Založí RAW frontu pro USB Brother. Novější macOS RAW fronty odmítá — pak stačí
# obyčejná fronta s obecným ovladačem: můstek tiskne přes `lp -o raw`, který filtry
# obejde u jakékoli fronty.
make_usb_queue() {
    local uri="$1"
    if lpstat -p "$QUEUE" >/dev/null 2>&1; then return 0; fi
    lpadmin -p "$QUEUE" -E -v "$uri" -m raw 2>/dev/null && return 0
    if [ -f "$GENERIC_PPD" ]; then
        lpadmin -p "$QUEUE" -E -v "$uri" -P "$GENERIC_PPD" 2>/dev/null && return 0
    fi
    lpadmin -p "$QUEUE" -E -v "$uri" -m drv:///sample.drv/generic.ppd 2>/dev/null && return 0
    echo "   (bez práv to nešlo — zkouším se sudo, zeptá se na heslo k Macu)"
    sudo lpadmin -p "$QUEUE" -E -v "$uri" -m raw 2>/dev/null && return 0
    if [ -f "$GENERIC_PPD" ]; then
        sudo lpadmin -p "$QUEUE" -E -v "$uri" -P "$GENERIC_PPD" && return 0
    fi
    return 1
}

setup_usb() {
    local uri
    uri="$(usb_brother_uri)"
    if [ -z "$uri" ]; then
        echo "❌ USB Brother nenalezen — je zapojený kabelem do TOHOTO Macu a zapnutý?"
        echo "   Seznam USB tiskáren, které Mac vidí:"; lpinfo -v 2>/dev/null | grep usb:// || echo "   (žádná)"
        return 1
    fi
    echo "   nalezen: $uri"
    if ! make_usb_queue "$uri"; then
        echo "❌ Tiskovou frontu se nepodařilo založit."
        echo "   Přidej tiskárnu v Nastavení systému → Tiskárny a skenery a spusť instalaci znovu"
        echo "   s názvem té fronty:  ./install.sh cups:NAZEV_FRONTY"
        return 1
    fi
    lpadmin -p "$QUEUE" -o printer-error-policy=abort-job -o printer-is-shared=false 2>/dev/null \
        || sudo lpadmin -p "$QUEUE" -o printer-error-policy=abort-job -o printer-is-shared=false 2>/dev/null || true
    cupsenable "$QUEUE" 2>/dev/null || sudo cupsenable "$QUEUE" 2>/dev/null || true
    cupsaccept "$QUEUE" 2>/dev/null || sudo cupsaccept "$QUEUE" 2>/dev/null || true
    write_cfg "cups:$QUEUE"
}

echo "1/4 venv + závislosti…"
python3 -m venv "$VENV" 2>/dev/null || true
"$VENV/bin/pip" install --quiet --upgrade pip setuptools
"$VENV/bin/pip" install --quiet brother_ql python-barcode pillow

echo "2/4 cíl tisku…"
case "$ARG" in
    usb)
        setup_usb || exit 1 ;;
    tcp:*)
        IP="${ARG#tcp:}"
        if ! [[ "$IP" =~ ^(10|172|192)\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then
            echo "❌ „$IP\" není adresa z místní sítě (čekám třeba tcp:192.168.1.220)."; exit 1
        fi
        write_cfg "tcp:$IP"; echo "   síťová tiskárna $IP" ;;
    cups:*)
        Q="${ARG#cups:}"
        if ! lpstat -p "$Q" >/dev/null 2>&1; then echo "❌ Fronta „$Q\" v systému neexistuje (lpstat -p)."; exit 1; fi
        write_cfg "cups:$Q"; echo "   fronta $Q" ;;
    "")
        if [ -s "$CFG" ]; then
            echo "   ponechávám dosavadní nastavení ($CFG)"
        elif [ -s "$HOME/.naskladneni_produktu.json" ]; then
            echo "   tiskárnu zná naskladňovací appka (~/.naskladneni_produktu.json) — beru ji odtud"
        elif [ -n "$(usb_brother_uri)" ]; then
            echo "   vidím USB Brother, nastavuji ho…"; setup_usb || exit 1
        else
            echo "⚠️  Cíl tisku zatím není nastavený. Spusť instalaci s argumentem usb nebo tcp:IP,"
            echo "   nebo tiskárnu vyber později v CRM (Nastavení → Tisk štítků)."
        fi ;;
    *)
        echo "❌ Neznámý argument „$ARG\". Použij: usb | tcp:IP | cups:FRONTA"; exit 1 ;;
esac

echo "3/4 LaunchAgent…"
mkdir -p "$HOME/Library/LaunchAgents"
cat > "$PLIST" <<PLIST_EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>$LABEL</string>
    <key>ProgramArguments</key>
    <array>
        <string>$VENV/bin/python3</string>
        <string>$SCRIPT_DIR/stitek_bridge.py</string>
    </array>
    <key>WorkingDirectory</key><string>$SCRIPT_DIR</string>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
    <key>StandardOutPath</key><string>/tmp/stitek-bridge.log</string>
    <key>StandardErrorPath</key><string>/tmp/stitek-bridge.log</string>
</dict>
</plist>
PLIST_EOF
# gui doména přihlášeného uživatele (systémové daemony macOS po aktualizaci umí potichu vypnout)
launchctl bootout "gui/$UID/$LABEL" 2>/dev/null || launchctl unload "$PLIST" 2>/dev/null || true
launchctl bootstrap "gui/$UID" "$PLIST" 2>/dev/null || launchctl load "$PLIST"

echo "4/4 kontrola…"
sleep 2
HEALTH="$(curl -sf http://127.0.0.1:9110/health || true)"
if [ -z "$HEALTH" ]; then
    echo "❌ Můstek nenaběhl — viz /tmp/stitek-bridge.log"; tail -5 /tmp/stitek-bridge.log 2>/dev/null || true; exit 1
fi
/usr/bin/python3 - "$HEALTH" <<'PY'
import json, sys
try:
    h = json.loads(sys.argv[1])
except Exception:
    print("✅ Můstek běží."); sys.exit(0)
target = h.get("target") or "(nenastaveno)"
print("✅ Můstek běží.")
print(f"   cíl tisku : {target}")
print(f"   model     : {h.get('printer_model') or 'QL-810W'}")
if h.get("ready"):
    print("   tiskárna  : odpovídá ✅")
    print("   Hotovo — v CRM dej Nastavení → Tisk štítků → Zkušební štítek.")
else:
    print(f"   tiskárna  : NEODPOVÍDÁ ⚠️  {h.get('error') or ''}".rstrip())
    print("   Zkontroluj, že je Brother zapnutý, má roli a zavřený kryt; u Wi-Fi varianty i správnou IP.")
PY
