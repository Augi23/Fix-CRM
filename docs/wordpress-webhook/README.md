# Propojení rezervací z applefix.cz (RepairPlugin Pro) → Fix-CRM

## Jak to funguje
1. Zákazník se objedná přes https://applefix.cz/servis-online/ (RepairPlugin Pro).
2. WordPress mu-plugin `afx-rezervace-webhook.php` pošle rezervaci (JSON) na
   `https://admin.applefix.cloud/api/website_booking.php` s klíčem `X-AFX-KEY`.
3. CRM ji uloží do tabulky `web_bookings` (dedup dle booking_id) a zobrazí
   **nahoře v Zakázkách**, seřazenou dle termínu vzestupně, s jemným oddělovačem.
4. „Vytvořit zakázku" předvyplní wizard; po dokončení se rezervace označí
   jako převzatá (`converted` + odkaz na zakázku). ✓ = vyřízeno/skrýt.

## Instalace na WordPressu
1. V CRM: Nastavení → Integrace → zkopírovat „Sdílený klíč".
2. Doplnit klíč do `afx-rezervace-webhook.php` (konstanta AFX_CRM_WEBHOOK_KEY).
3. **Ověřit názvy tabulky/sloupců** RepairPluginu (viz TODO v souboru) podle
   `wp-content/plugins/Repairplugin-pro/` — a ideálně přepnout na akční hook
   pluginu, pokud existuje (varianta A).
4. Nahrát do `wp-content/mu-plugins/` (složku vytvořit, pokud chybí).
5. Test: `curl -X POST https://admin.applefix.cloud/api/website_booking.php \
     -H "X-AFX-KEY: <klíč>" -H "Content-Type: application/json" \
     -d '{"booking_id":"test1","name":"Test Klient","phone":"777123456","device":"iPhone 13","service":"Výměna displeje","appointment":"2026-07-15 14:00"}'`
   → v CRM Zakázkách se objeví rezervace.

## Stavy rezervace v CRM
- `new` — zobrazena v panelu; `converted` — vytvořena zakázka; `dismissed` — skryta.
- WordPress status `cancelled` automaticky skryje (dismissed) nepřevzatou rezervaci.
