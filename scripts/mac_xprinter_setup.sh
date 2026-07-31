#!/bin/zsh
# Jednorázové nastavení termotiskárny Xprinter XP58-IIN na Macu u pokladny.
# Spustit PO zapojení tiskárny do USB. Co udělá:
#   1. najde USB tiskárnu v CUPS,
#   2. založí RAW frontu „xprinter" (ESC/POS bajty projdou beze změny, driver netřeba),
#   3. nasdílí ji po síti (server CRM na ni pak tiskne přes lp -h mac:631),
#   4. vytiskne krátký test.
# Nakonec vypíše přesnou hodnotu nastavení receipt_printer_target pro CRM.
set -e

echo "── Hledám USB tiskárnu…"
URI=$(lpinfo -v 2>/dev/null | awk '/usb:\/\// {print $2}' | head -1)
if [ -z "$URI" ]; then
    echo "❌ Žádná USB tiskárna nenalezena. Je zapojená a zapnutá?"
    exit 1
fi
echo "   nalezeno: $URI"

echo "── Zakládám RAW frontu 'xprinter'…"
sudo lpadmin -p xprinter -E -v "$URI" -m raw -o printer-error-policy=retry-current-job 2>/dev/null \
  || sudo lpadmin -p xprinter -E -v "$URI" -o printer-error-policy=retry-current-job
sudo cupsenable xprinter 2>/dev/null || true
sudo cupsaccept xprinter 2>/dev/null || true

echo "── Zapínám sdílení tiskárny po síti…"
sudo cupsctl --share-printers
sudo lpadmin -p xprinter -o printer-is-shared=true

IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1)
echo "── Test tisku…"
printf '\x1b\x40AppleFix - zkouska tiskarny OK\n\n\n\n\x1d\x56\x42\x10' | lp -d xprinter -o raw -s -

echo ""
echo "✅ Hotovo. Do CRM patří nastavení:"
echo "   receipt_printer_target = cups:${IP}:631:xprinter"
echo ""
echo "Na serveru CRM to zapne příkaz:"
echo "   ssh augi@192.168.1.132 'cd /home/augi/repair-crm && php -r \"require \\\"includes/config.php\\\"; require \\\"includes/functions.php\\\"; set_setting(\\\"receipt_printer_target\\\",\\\"cups:${IP}:631:xprinter\\\");\"'"
echo ""
echo "POZOR: Mac musí mít pevnou IP (Nastavení → Síť → DHCP s manuální adresou),"
echo "jinak se po restartu routeru tisk rozpojí. Firewall: povolit příchozí spojení"
echo "pro cupsd (Nastavení → Síť → Firewall), případně firewall vypnout."
