#!/bin/zsh
# Nastavení termotiskárny Xprinter XP58-IIN na Macu u pokladny — verze 3.
#
# Architektura (přání majitele): tiskne VŽDY jen počítač, který má tiskárnu v USB.
# CRM v prohlížeči tohoto Macu si od serveru vezme hotové bajty účtenky a pošle je
# na lokální můstek http://127.0.0.1:9101/print → lp -o raw → USB. Nic nechodí
# přes síť z jiných počítačů, sdílení tiskárny je vypnuté.
#
# Skript je idempotentní — klidně ho spusť opakovaně.
set -e

echo "── Mažu čekající úlohy fronty xprinter…"
sudo cancel -a xprinter 2>/dev/null || true

echo "── Fronta xprinter (RAW, chybnou úlohu zahodit, sdílení vypnout)…"
if ! lpstat -p xprinter >/dev/null 2>&1; then
    URI=$(lpinfo -v 2>/dev/null | awk '/usb:\/\// {print $2}' | head -1)
    [ -z "$URI" ] && { echo "❌ USB tiskárna nenalezena — je zapojená a zapnutá?"; exit 1; }
    sudo lpadmin -p xprinter -E -v "$URI" -m raw 2>/dev/null || sudo lpadmin -p xprinter -E -v "$URI"
fi
sudo lpadmin -p xprinter -o printer-error-policy=abort-job -o printer-is-shared=false
sudo cupsenable xprinter 2>/dev/null || true
sudo cupsaccept xprinter 2>/dev/null || true

echo "── Můstek: HTTP 127.0.0.1:9101 → lokální tisk…"
sudo mkdir -p /usr/local/lib
sudo tee /usr/local/lib/xprinter9101.sh >/dev/null << 'EOS'
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
sudo chmod 755 /usr/local/lib/xprinter9101.sh

sudo tee /Library/LaunchDaemons/cz.applefix.xprinter9101.plist >/dev/null << 'EOP'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>cz.applefix.xprinter9101</string>
    <key>ProgramArguments</key><array><string>/usr/local/lib/xprinter9101.sh</string></array>
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
sudo launchctl bootout system/cz.applefix.xprinter9101 2>/dev/null || true
sudo launchctl bootstrap system /Library/LaunchDaemons/cz.applefix.xprinter9101.plist

# starý kanál 9100 (verze 2) už není potřeba — tiskne jen tento počítač
sudo launchctl bootout system/cz.applefix.xprinter9100 2>/dev/null || true
sudo rm -f /Library/LaunchDaemons/cz.applefix.xprinter9100.plist /usr/local/lib/xprinter9100.sh

sleep 1
echo "── Ověření můstku (nic se netiskne):"
RESP=$(curl -s -X OPTIONS http://127.0.0.1:9101/print -o /dev/null -w "%{http_code}")
if [ "$RESP" = "204" ]; then
    echo "✅ Můstek 9101 běží. V CRM na TOMTO počítači otevři Pokladnu a klikni „Test účtenky"."
else
    echo "❌ Můstek neodpovídá (HTTP $RESP) — napiš to Claudovi."
fi
