# Můstek pro čtení připojeného iPhonu/iPadu — Fix-CRM

Malá služba na Macu u pultu. Když k němu připojíš telefon kabelem, přečte přes
USB údaje o zařízení a pošle je do CRM. V **Naskladnit produkt** pak stačí
kliknout na „Načíst z připojeného zařízení".

## Instalace (zvládne kdokoli)

V CRM: **Nastavení → Tisk štítků → Načítání údajů z připojeného iPhonu/iPadu**
je připravený příkaz i s tokenem. Zkopíruj ho, otevři **Terminál** (Cmd+mezerník
→ „Terminál"), vlož a stiskni Enter. Instalace si sama doinstaluje Homebrew
(pokud chybí) a `libimobiledevice`, stáhne můstek a založí službu, která běží
i po restartu Macu.

Pak na telefonu **odemkni obrazovku a potvrď „Důvěřovat tomuto počítači"**.

Stejný příkaz spusť na libovolném dalším Macu — každý se přihlásí sám pod svým
názvem a v Nastavení je vidět jako samostatná stanice.

## Co se čte

IMEI (i druhé), sériové číslo, identifikátor modelu (iPhone13,2) a z něj
marketingový název, objednací číslo, kapacita, barva (jen u jistých kódů),
verze iOS, stav aktivace a **kondice baterie** — počet cyklů a skutečná
kapacita proti návrhové. Baterii žádná IMEI služba neřekne, tohle ano.

Model, kapacita i barva se v CRM ověří proti katalogu; co katalog nezná,
se do formuláře nevyplní (jen se ukáže jako tip).

## Proč to posílá můstek do CRM a ne naopak

Stránka CRM běží přes HTTPS a Safari i appka „Designed for iPad" blokují
volání na `http://127.0.0.1`. Opačný směr funguje všude — stejná zkušenost
jako u tisku štítků ([[applefix-print-bridge-safari]]).

## Proč ne 3uTools

3uTools je jen pro Windows; oficiální build pro macOS neexistuje (na Macu by
musel běžet přes Parallels). `libimobiledevice` je zdarma, open-source
a přečte i to, co 3uTools ukazuje.

## Provoz

- Log: `/tmp/afx-device-bridge.log`
- Zastavit: `launchctl unload ~/Library/LaunchAgents/cz.applefix.device-bridge.plist`
- Spustit: `launchctl load ~/Library/LaunchAgents/cz.applefix.device-bridge.plist`
- Nastavení: `~/.afx_device_bridge.json` (server, token, název stanice)
- Ruční zkouška bez CRM: `python3 ~/afx-device-bridge/afx_device.py`

Můstek se ptá po 3 s, ale posílá jen při změně (a jednou za 30 s se ozve,
že žije). Když se telefon odpojí, pošle prázdný stav — CRM pak nenabízí
staré zařízení.
