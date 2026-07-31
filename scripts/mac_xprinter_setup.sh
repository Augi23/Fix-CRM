#!/bin/zsh
# Nastavení termotiskárny Xprinter XP58-IIN na Macu u pokladny — verze 2 (přímý kanál).
#
# Proč verze 2: tisk přes SDÍLENOU CUPS frontu (Linux server → Mac) data cestou
# překódovává — tiskárna pak místo účtenky chrlí rozsypané znaky a fronta s politikou
# retry-current-job úlohu donekonečna opakovala (nešlo to zastavit). Lokální tisk
# na Macu přitom funguje správně.
#
# Tahle verze proto:
#   1. SMAŽE všechny čekající úlohy (ať po zapnutí tiskárny nezačne znovu chrlit),
#   2. přepne frontu na abort-job (chybná úloha se zahodí, nikdy neopakuje),
#   3. VYPNE sdílení fronty (už se přes ni tisknout nebude),
#   4. postaví přímý kanál: launchd poslouchá na portu 9100 a příchozí bajty
#      pouští do lokálního `lp -o raw` (ten je ověřeně v pořádku),
#   5. vytiskne lokální test přes nový kanál.
# Server CRM pak tiskne na tcp:IP:9100 — bajty jdou beze změny.
set -e

echo "── Mažu čekající úlohy fronty xprinter…"
sudo cancel -a xprinter 2>/dev/null || true

echo "── Fronta: chybnou úlohu zahodit (žádné nekonečné opakování), sdílení vypnout…"
if ! lpstat -p xprinter >/dev/null 2>&1; then
    URI=$(lpinfo -v 2>/dev/null | awk '/usb:\/\// {print $2}' | head -1)
    [ -z "$URI" ] && { echo "❌ USB tiskárna nenalezena — je zapojená a zapnutá?"; exit 1; }
    sudo lpadmin -p xprinter -E -v "$URI" -m raw 2>/dev/null || sudo lpadmin -p xprinter -E -v "$URI"
fi
sudo lpadmin -p xprinter -o printer-error-policy=abort-job -o printer-is-shared=false
sudo cupsenable xprinter 2>/dev/null || true
sudo cupsaccept xprinter 2>/dev/null || true

echo "── Stavím přímý kanál (port 9100 → lokální tisk)…"
sudo mkdir -p /usr/local/lib
sudo tee /usr/local/lib/xprinter9100.sh >/dev/null << 'EOS'
#!/bin/zsh
# launchd (inetd režim) sem pustí TCP spojení jako stdin — bajty jdou beze změny
# do lokální RAW fronty. Lokální lp je ověřený, tiskne správně.
exec /usr/bin/lp -d xprinter -o raw -s - >/dev/null 2>&1
EOS
sudo chmod 755 /usr/local/lib/xprinter9100.sh

sudo tee /Library/LaunchDaemons/cz.applefix.xprinter9100.plist >/dev/null << 'EOP'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key><string>cz.applefix.xprinter9100</string>
    <key>ProgramArguments</key><array><string>/usr/local/lib/xprinter9100.sh</string></array>
    <key>inetdCompatibility</key><dict><key>Wait</key><false/></dict>
    <key>Sockets</key>
    <dict>
        <key>Listeners</key>
        <dict>
            <key>SockServiceName</key><string>9100</string>
            <key>SockType</key><string>stream</string>
        </dict>
    </dict>
</dict>
</plist>
EOP
sudo launchctl bootout system/cz.applefix.xprinter9100 2>/dev/null || true
sudo launchctl bootstrap system /Library/LaunchDaemons/cz.applefix.xprinter9100.plist
sleep 1

echo "── Test přes nový kanál…"
printf '\x1b\x40MUSTEK 9100 OK - tisk pres primy kanal\n\n\n\n\x1d\x56\x42\x10' | nc -w 3 127.0.0.1 9100 || true

IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1)
echo ""
echo "✅ Hotovo. Pokud vyjel lísteček „MUSTEK 9100 OK", nahlas do CRM:"
echo "   receipt_printer_target = tcp:${IP}:9100"
