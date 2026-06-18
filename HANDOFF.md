# HANDOFF — Fix-CRM (předání mezi zařízeními)

> Tento soubor slouží jako přenos kontextu mezi PC a MacBookem. Po pullnutí na druhém
> zařízení řekni Claude Code: **„přečti HANDOFF.md a pokračujeme"**.
> Aktualizováno: 2026-06-18

## Co je projekt

Fix-CRM — interní CRM pro servis elektroniky **AppleFix**. Live: **https://admin.applefix.cloud**
(běží přímo z nasazené složky; self-update přes `git pull` z admin UI → Nastavení → Aktualizace).

- **Stack:** PHP 8 + MySQL/MariaDB, server-rendered, bez frameworku, Bootstrap + jQuery, vlastní design (AppleFix v2, SF Pro).
- **Repo:** https://github.com/Augi23/Fix-CRM (private). Větev `main`.
- **Jádro:** `includes/config.php` (PDO, CSRF, session), `includes/functions.php` (práva, statusy, pobočky, Telegram), `includes/lang.php` + `lang_custom.php` (CS/RU/EN, helper `__()`).

## Stav k tomuto handoffu

- Lokální i vzdálená větev synchronizované, pracovní strom čistý.
- Poslední commit: `chore: aktualizace odkazů na repozitář na Augi23/Fix-CRM`.
- Proběhlo: sloučení rozešlé historie (merge UI fixů s lokálním refaktorem), oprava odkazů na repo.

## Funkční mapa modulů

| Modul | Soubory | Poznámka |
|---|---|---|
| Zakázky | `orders.php`, `view_order.php`, `includes/modals/new_order_modal.php` | 3-krokový wizard, 15 stavů ve skupinách, work-tracking času, tisky (dílna/příjmový list/termo) |
| Zákazníci | `customers.php`, `edit_customer.php` | CRUD privát/firma, ARES lookup podle IČO |
| Sklad | `inventory.php` | díly, min. sklad, ceny, dodavatelé, parser katalogů |
| Nákup | `procurement.php` | purchase_requests: pending→ordered→received, auto-naskladnění |
| Fakturace | `accounting.php`, `models/InvoiceManager.php` | DPH plátce/neplátce, express i plná, dobropisy, export Pohoda |
| Reporty | `reports.php` | statistiky techniků, tržby/náklady/zisk, výdělky dle `engineer_rate` |
| Klient portál | `klient/dashboard.php`, `klient/includes/auth.php` | login číslo zakázky + PIN, klient vidí stav + fotky |
| Telegram | `tg_webhook.php`, `fixer_chat.php`, `api/fixer_*.php` | obousměrný fixer chat, notifikace, AI bridge |
| AI | `models/nvidia_ai.php`, `api/telegram_ai.php` | NVIDIA kimi-k2.5 / OpenRouter |
| IMEI | `api/check_imei.php` | Police.gov.cz + iFreeiCloud |
| Speciální | `vykup-zarizeni.php`, `zastava.php`, `reklamace.php` | výkup, zástava, reklamace |

Práva: admin (vše) · manager (implicitně edit/reports/inventory) · engineer (jen svá data + svá pobočka).
Pobočky: Karlín + Na Příkopě (filtrují zakázky i přiřazení techniků).

## TODO / backlog (priorit. od nejdůležitějšího)

### 🔴 Bezpečnost
- [ ] Klientský **PIN** se porovnává v plain-textu (`login.php`) — hashovat, přidat CAPTCHA / silnější limit pokusů.
- [ ] **Hardcoded fallback API klíč** iFreeiCloud v `includes/config.php:39`; klíče (Telegram, AI) plain v DB → přesunout do `.env`.
- [ ] **Default `admin`/`admin`** v `migrations/001_bootstrap.sql` — ověřit, že je na produkci změněné.
- [ ] Reálná **zákaznická data v CSV** ve `scripts/` jsou v gitu → zvážit vyřazení z gitu i historie.

### 🟡 Funkční mezery
- [ ] `final_cost` se nikdy nenastaví automaticky → reporty počítají tržby z `estimated_cost`. Nastavit při výdeji / z faktury.
- [ ] Status logika duplikovaná ve 3 souborech (`api/update_order_status.php`, `api/update_order_full.php`, `edit_order.php`) → refaktor do jednoho helperu v `functions.php`.
- [ ] Export do Pohody neověřený proti reálnému importu.
- [ ] `sync_from_site.php` nedokumentovaný (co a odkud synchronizuje).
- [ ] Tichý `catch` u logování změn stavů → audit trail nemusí být úplný.

### 🟢 Drobnosti
- [ ] Stav „Černá růže" bez vysvětlení.
- [ ] Chybí hromadný import katalogu/zákazníků v UI (skripty jsou ve `scripts/`).
- [ ] Žádné auto-vytvoření faktury při výdeji.

## Jak pokračovat na druhém zařízení

```bash
# Mac: poprvé
git clone https://github.com/Augi23/Fix-CRM.git
cd Fix-CRM
# nebo když už máš:
git pull origin main
```

Pak v Claude Code: „přečti HANDOFF.md a pokračujeme". Po práci commitni a pushni, ať se to vrátí na PC.
`.env` (DB hesla, API klíče) NENÍ v gitu — na novém stroji ho vytvoř z `.env.example`.
