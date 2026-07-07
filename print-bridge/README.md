# Štítkový můstek — Fix-CRM → Brother QL-810W

Lokální služba na recepčním Macu, přes kterou CRM tiskne štítky zakázek
na Brother QL-810W **stejně jako aplikace „Naskladnění produktů"**
(stejná tiskárna z `~/.naskladneni_produktu.json` → `printer_ip`,
stejná knihovna `brother_ql`, 62mm role, stejné parametry rastru).

## Instalace (jednorázově, na Macu u tiskárny)

```bash
cd ~/Fix-CRM/print-bridge && ./install.sh
```

Vytvoří venv `~/.stitek_bridge_venv` a LaunchAgent
`cz.applefix.stitek-bridge` (běží trvale, i po restartu). Log: `/tmp/stitek-bridge.log`.

## Jak to funguje

- Po založení zakázky v CRM prohlížeč zavolá `http://127.0.0.1:9110/print`
  → můstek vyrenderuje štítek (Code128 = č. zakázky + závada + datum přijetí)
  a pošle ho na tiskárnu (TCP:9100).
- Tlačítko „Štítek — Brother QL" je i v detailu zakázky (menu tisků).
- Naskenování kódu otevře zakázku (vyhledávání v CRM zná č. zakázky).
- Na počítačích bez můstku se auto-tisk tiše ohlásí hláškou — tisknout
  umí každý Mac, kde se spustí `install.sh`.

## Údržba

- **Aktualizace můstku**: přijde s `git pull` repa; pak
  `launchctl kickstart -k gui/$(id -u)/cz.applefix.stitek-bridge`
- **Test bez tisku**: http://127.0.0.1:9110/preview?code=TEST123&defect=zkouška&date=07.07.2026
- **Stav**: http://127.0.0.1:9110/health
- Jiná IP tiskárny: změň `printer_ip` v `~/.naskladneni_produktu.json`
  (sdílené s naskladňovací appkou) a restartuj můstek.
