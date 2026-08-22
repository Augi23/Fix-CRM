#!/bin/zsh
# Nastavení termotiskárny Xprinter XP58-IIN na Macu u pokladny — verze 4.
#
# Architektura (přání majitele): tiskne VŽDY jen počítač, který má tiskárnu v USB.
# CRM v prohlížeči tohoto Macu si od serveru vezme hotové bajty účtenky a pošle je
# na lokální můstek http://127.0.0.1:9101/print → lp -o raw → USB. Nic nechodí
# přes síť z jiných počítačů, sdílení tiskárny je vypnuté.
#
# v4: můstek běží jako LaunchAgent PŘIHLÁŠENÉHO UŽIVATELE (gui doména) místo
# systémového daemona — macOS po aktualizacích umí systémové daemony potichu
# vypnout v „Background items" a tisk pak záhadně přestane. Starší systémové
# varianty (9100 i 9101) skript uklidí. Je idempotentní — spouštěj klidně opakovaně.
set -e

echo "── Mažu čekající úlohy fronty xprinter…"
cancel -a xprinter 2>/dev/null || sudo cancel -a xprinter 2>/dev/null || true

echo "── Fronta xprinter (RAW, chybnou úlohu zahodit, sdílení vypnout)…"
if ! lpstat -p xprinter >/dev/null 2>&1; then
    URI=$(lpinfo -v 2>/dev/null | awk '/usb:\/\// {print $2}' | head -1)
    if [ -z "$URI" ]; then
        echo "❌ USB tiskárna nenalezena — je zapojená do TOHOTO počítače a zapnutá?"
        exit 1
    fi
    lpadmin -p xprinter -E -v "$URI" -m raw 2>/dev/null \
        || sudo lpadmin -p xprinter -E -v "$URI" -m raw \
        || { echo "❌ Frontu se nepodařilo založit."; exit 1; }
fi
lpadmin -p xprinter -o printer-error-policy=abort-job -o printer-is-shared=false 2>/dev/null \
    || sudo lpadmin -p xprinter -o printer-error-policy=abort-job -o printer-is-shared=false \
    || true
cupsenable xprinter 2>/dev/null || sudo cupsenable xprinter 2>/dev/null || true
cupsaccept xprinter 2>/dev/null || sudo cupsaccept xprinter 2>/dev/null || true

echo "── Uklízím starší SYSTÉMOVÉ můstky (vyžaduje heslo; přeskočí se, když nejsou)…"
if [ -f /Library/LaunchDaemons/cz.applefix.xprinter9101.plist ] || [ -f /Library/LaunchDaemons/cz.applefix.xprinter9100.plist ]; then
    sudo launchctl bootout system/cz.applefix.xprinter9101 2>/dev/null || true
    sudo launchctl bootout system/cz.applefix.xprinter9100 2>/dev/null || true
    sudo rm -f /Library/LaunchDaemons/cz.applefix.xprinter9101.plist \
               /Library/LaunchDaemons/cz.applefix.xprinter9100.plist \
               /usr/local/lib/xprinter9101.sh /usr/local/lib/xprinter9100.sh
fi

echo "── Můstek: HTTP 127.0.0.1:9101 jako agent přihlášeného uživatele…"
mkdir -p "$HOME/Library/AppleFix" "$HOME/Library/LaunchAgents"
cat > "$HOME/Library/AppleFix/xprinter9101.sh" << 'EOS'
#!/bin/zsh
# launchd (inetd režim): stdin/stdout = TCP spojení. Minimalistické HTTP:
# OPTIONS = CORS preflight (Chrome vyžaduje i Allow-Private-Network),
# POST /print = tělo požadavku beze změny do lokální RAW fronty.
IFS= read -r reqline
method=${reqline%% *}
clen=0
while IFS= read -r line; do
    line=${line%$'\r'}
    [ -z "$line" ] && break
    lower=${(L)line}
    case $lower in
        content-length:*) clen=${line#*: } ;;
    esac
done
cors=$'Access-Control-Allow-Origin: *\r\nAccess-Control-Allow-Methods: POST, OPTIONS\r\nAccess-Control-Allow-Headers: content-type\r\nAccess-Control-Allow-Private-Network: true\r\n'
if [ "$method" = "OPTIONS" ]; then
    printf 'HTTP/1.1 204 No Content\r\n%sConnection: close\r\n\r\n' "$cors"
    exit 0
fi
if [ "$method" != "POST" ] || [ "$clen" -le 0 ] 2>/dev/null; then
    printf 'HTTP/1.1 400 Bad Request\r\n%sConnection: close\r\n\r\n' "$cors"
    exit 0
fi
head -c "$clen" | /usr/bin/lp -d xprinter -o raw -s - >/dev/null 2>&1
printf 'HTTP/1.1 200 OK\r\n%sContent-Type: application/json\r\nConnection: close\r\n\r\n{"ok":true}' "$cors"
EOS
chmod 755 "$HOME/Library/AppleFix/xprinter9101.sh"

cat > "$HOME/Library/LaunchAgents/cz.applefix.xprinter9101.plist" << EOP
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>cz.applefix.xprinter9101</string>
    <key>ProgramArguments</key><array><string>${HOME}/Library/AppleFix/xprinter9101.sh</string></array>
    <key>inetdCompatibility</key><dict><key>Wait</key><false/></dict>
    <key>Sockets</key>
    <dict>
        <key>Listeners</key>
        <dict>
            <key>SockNodeName</key><string>127.0.0.1</string>
            <key>SockServiceName</key><string>9101</string>
            <key>SockType</key><string>stream</string>
        </dict>
    </dict>
</dict>
</plist>
EOP
launchctl bootout gui/$UID/cz.applefix.xprinter9101 2>/dev/null || true
launchctl bootstrap gui/$UID "$HOME/Library/LaunchAgents/cz.applefix.xprinter9101.plist" \
    || echo "⚠️ Agenta se nepodařilo spustit — diagnostika níže."

sleep 1
echo "── Ověření můstku (nic se netiskne):"
RESP=$(curl -s -X OPTIONS http://127.0.0.1:9101/print -o /dev/null -w '%{http_code}' || echo 000)
if [ "$RESP" = "204" ]; then
    echo "✅ Můstek 9101 běží. V CRM na TOMTO počítači otevři Pokladnu a klikni na Test účtenky."
else
    echo "❌ Můstek neodpovídá (HTTP $RESP). Vyfoť následující diagnostiku a pošli ji:"
    echo "· stav agenta:"
    launchctl print gui/$UID/cz.applefix.xprinter9101 2>&1 | head -12
    echo "· kdo drží port 9101:"
    lsof -nP -iTCP:9101 2>/dev/null | head -5 || true
fi
