#!/bin/zsh
# Nastavení termotiskárny Xprinter XP58-IIN na Macu u pokladny — verze 5.
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
    # Novější macOS RAW fronty odmítá („neformátované fronty už nejsou podporovány").
    # Nevadí: účtenky jdou přes `lp -o raw`, který filtry obejde u jakékoli fronty —
    # stačí obyčejná fronta s obecným ovladačem.
    GENERIC_PPD="/System/Library/Frameworks/ApplicationServices.framework/Versions/A/Frameworks/PrintCore.framework/Versions/A/Resources/Generic.ppd"
    lpadmin -p xprinter -E -v "$URI" -m raw 2>/dev/null \
        || { [ -f "$GENERIC_PPD" ] && lpadmin -p xprinter -E -v "$URI" -P "$GENERIC_PPD" 2>/dev/null; } \
        || sudo lpadmin -p xprinter -E -v "$URI" -m raw 2>/dev/null \
        || { [ -f "$GENERIC_PPD" ] && sudo lpadmin -p xprinter -E -v "$URI" -P "$GENERIC_PPD"; } \
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

echo "── Poller tiskové fronty (kasa v APPCE z TestFlightu / Safari)…"
# WKWebView ani Safari nepustí HTTPS stránku na místní můstek — kasa proto úlohu
# uloží na server a tenhle poller ji každé ~2 s stáhne a pošle do USB tiskárny.
# Token = tajemství pobočky; předává se argumentem: … | zsh -s -- TOKEN
TOKEN="${1:-}"
TOKEN_FILE="$HOME/Library/AppleFix/print_token"
if [ -n "$TOKEN" ]; then
    print -r -- "$TOKEN" > "$TOKEN_FILE"
    chmod 600 "$TOKEN_FILE"
fi
if [ -s "$TOKEN_FILE" ]; then
    cat > "$HOME/Library/AppleFix/xprintpoll.sh" << 'EOS2'
#!/bin/zsh
# Poller tiskové fronty: stáhne čekající účtenku své pobočky a pošle ji do USB.
TOKEN_FILE="$HOME/Library/AppleFix/print_token"
TMP="$(mktemp /tmp/afxprint.XXXXXX)"
trap 'rm -f "$TMP"' EXIT
while true; do
    TOKEN="$(cat "$TOKEN_FILE" 2>/dev/null)"
    if [ -n "$TOKEN" ]; then
        CODE=$(curl -s -o "$TMP" -w '%{http_code}' --max-time 8 "https://admin.applefix.cloud/api/print_poll.php?token=$TOKEN" || echo 000)
        if [ "$CODE" = "200" ] && [ -s "$TMP" ]; then
            /usr/bin/lp -d xprinter -o raw -s "$TMP" >/dev/null 2>&1
            continue   # ve frontě může čekat další úloha — hned se zeptat znovu
        fi
    fi
    sleep 2
done
EOS2
    chmod 755 "$HOME/Library/AppleFix/xprintpoll.sh"
    cat > "$HOME/Library/LaunchAgents/cz.applefix.xprintpoll.plist" << EOP2
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>cz.applefix.xprintpoll</string>
    <key>ProgramArguments</key><array><string>${HOME}/Library/AppleFix/xprintpoll.sh</string></array>
    <key>RunAtLoad</key><true/>
    <key>KeepAlive</key><true/>
</dict>
</plist>
EOP2
    launchctl bootout gui/$UID/cz.applefix.xprintpoll 2>/dev/null || true
    launchctl bootstrap gui/$UID "$HOME/Library/LaunchAgents/cz.applefix.xprintpoll.plist" \
        || echo "⚠️ Poller se nepodařilo spustit."
    echo "✅ Poller fronty běží — kasa v appce/Safari tiskne přes server (do ~2 s)."
else
    echo "ℹ️ Poller PŘESKOČEN (chybí token pobočky). Kasa v appce z TestFlightu pak netiskne!"
    echo "   Spusť skript takto: curl -fsSL https://admin.applefix.cloud/scripts/mac_xprinter_setup.sh | zsh -s -- TOKEN_POBOCKY"
fi

sleep 1
echo "── Zkušební tisk přímo z tohoto Macu (ověří frontu a tiskárnu):"
# ESC @ = reset, text, 4 řádky posuv, GS V A 0 = ustřihnout
if printf '\033@AppleFix: tiskarna OK\n%s\n\n\n\n\035VA\000' "$(date '+%d.%m.%Y %H:%M')" | /usr/bin/lp -d xprinter -o raw >/dev/null 2>&1; then
    echo "✅ Odesláno do tiskárny — MUSÍ vyjet lístek „AppleFix: tiskarna OK“."
    echo "   Když nevyjede: tiskárna je vypnutá, bez papíru, nebo fronta míří na jiné USB zařízení:"
    lpstat -v xprinter 2>/dev/null | sed 's/^/   /'
else
    echo "❌ Fronta xprinter tisk odmítla:"; lpstat -p xprinter -l 2>&1 | sed 's/^/   /'
fi

echo "── Ověření tokenu u serveru:"
if [ -s "$TOKEN_FILE" ]; then
    TMP_CHK="$(mktemp /tmp/afxchk.XXXXXX)"
    CODE=$(curl -s -o "$TMP_CHK" -w '%{http_code}' --max-time 8 "https://admin.applefix.cloud/api/print_poll.php?token=$(cat "$TOKEN_FILE")" || echo 000)
    case "$CODE" in
        200) /usr/bin/lp -d xprinter -o raw -s "$TMP_CHK" >/dev/null 2>&1
             echo "✅ Server token přijal a poslal čekající účtenku — právě se tiskne." ;;
        204) echo "✅ Server token přijal (fronta je prázdná). V CRM teď dej Zkušební účtenku." ;;
        403) echo "❌ Server token ODMÍTL. V CRM se mezitím nejspíš vygeneroval nový —"
             echo "   zkopíruj z Nastavení → Tisk štítků AKTUÁLNÍ příkaz a spusť ho znovu." ;;
        *)   echo "❌ Server neodpovídá (HTTP $CODE) — je Mac připojený k internetu?" ;;
    esac
    rm -f "$TMP_CHK"
fi

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
