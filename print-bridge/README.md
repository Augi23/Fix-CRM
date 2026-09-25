# Štítkový můstek — Fix-CRM → Brother QL-8xx

Lokální služba na Macu u pultu (http://127.0.0.1:9110), přes kterou CRM
v prohlížeči tiskne štítky zakázek, reklamací a cenovky produktů na
Brother QL-810W / QL-820NWB. Knihovna `brother_ql`, 62mm role, stejné
parametry rastru jako aplikace „Naskladnění produktů".

## Kdy je potřeba

V **Nastavení → Tisk štítků** má každá pobočka vybráno, **kdo tiskne**:

- **Tiskne server** — tiskárna je v síti serveru (Karlín). Můstek není potřeba,
  slouží jen jako záloha.
- **Tiskne počítač u pultu** — pobočka síť se serverem nesdílí (Na Příkopě).
  Server se o tiskárnu nepokouší a štítek pošle na tisk prohlížeč přihlášené
  obsluhy. **Můstek je nutný** a CRM je potřeba otevírat v **Chromu**
  (Safari z HTTPS stránky na 127.0.0.1 nepustí).

## Instalace na pobočce (jeden příkaz v Terminálu)

Příkazy jsou v CRM připravené ke zkopírování (Nastavení → Tisk štítků, u pobočky
s volbou „Tiskne počítač u pultu").

```bash
# Brother zapojený USB kabelem do tohoto Macu
curl -fsSL https://admin.applefix.cloud/print-bridge/bootstrap.sh | bash -s -- usb

# Brother na Wi-Fi / v síti pobočky (IP vytiskne po podržení tlačítka Wi-Fi/střihu)
curl -fsSL https://admin.applefix.cloud/print-bridge/bootstrap.sh | bash -s -- tcp:192.168.1.220

# bez argumentu: ponechá dosavadní nastavení, jinak najde USB Brother sám
curl -fsSL https://admin.applefix.cloud/print-bridge/bootstrap.sh | bash
```

Instalace stáhne `stitek_bridge.py`, `stitek_product.py` (cenovky), `label_logo.png`
a `install.sh` do `~/stitek-bridge/`, vytvoří venv `~/.stitek_bridge_venv`
a LaunchAgent `cz.applefix.stitek-bridge` v doméně přihlášeného uživatele
(běží trvale, i po restartu). U USB varianty založí tiskovou frontu `brotherql`.
Novější macOS odmítá RAW fronty — instalace pak použije obecný ovladač; nevadí to,
můstek posílá data přes `lp -o raw`, které ovladač obejde.

Spouštět klidně opakovaně — **aktualizace můstku = tentýž příkaz znovu.**
Když si macOS vyžádá Command Line Tools (kvůli python3), potvrď a spusť znovu.

## Kam tiskne (cíl tisku)

Cíl je `tcp:<IP>` (síťová tiskárna, port 9100) nebo `cups:<fronta>` (tiskárna
připojená k tomuto Macu). Hledá se v tomto pořadí:

1. proměnná `STITEK_PRINTER_TARGET` (nebo `STITEK_PRINTER_IP` → tcp),
2. `~/Library/AppleFix/stitek_bridge.json` — `{"printer_target": "...", "printer_model": "QL-810W"}`,
3. `printer_ip` z `~/.naskladneni_produktu.json` (karlínský Mac s naskladňovací
   appkou — funguje beze změny jako dřív).

Cíl jde změnit bez Terminálu přes `POST /config` (volá ho CRM) nebo znovu
spuštěným instalátorem s argumentem.

## API můstku

| Metoda | Cesta | Co dělá |
|---|---|---|
| GET | `/health` | `{ok, target, kind: "tcp"\|"cups"\|null, printer_ip, printer_queue, printer_model, ready, error}` |
| GET | `/printers` | `{ok, queues: [{name, info, is_brother}], usb: [{uri, name}], suggestion}` |
| POST | `/config` | `{target, model}` → uloží cíl, vrátí stejné tělo jako `/health` |
| POST | `/adopt_usb` | `{uri, queue}` → založí frontu pro USB Brother a nastaví ji |
| POST | `/print` | štítek zakázky `{code, defect, date, client}` nebo cenovka `{product, copies}` |
| GET | `/preview?code=…&defect=…&date=…&client=…` | PNG náhled bez tisku |

Volat smí jen `https://admin.applefix.cloud` a localhost (CORS + Private Network Access).

## Údržba

- **Stav:** http://127.0.0.1:9110/health
- **Log:** `/tmp/stitek-bridge.log`
- **Restart:** `launchctl kickstart -k gui/$(id -u)/cz.applefix.stitek-bridge`
- **Test bez tisku:** http://127.0.0.1:9110/preview?code=TEST123&defect=zkouška&date=25.09.2026
