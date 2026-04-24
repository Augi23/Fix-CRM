# CRM-CODER_MAP.md

Rychlá orientace v projektu `repair-crm` pro efektivní zásahy.

## 1) Architektura

- Stack: **PHP 8.x + MySQL/MariaDB**, server-rendered stránky, bez frameworku.
- UI: Bootstrap + jQuery + vlastní JS/CSS (`assets/js/main.js`, `assets/css/style.css`).
- Lokalizace: `includes/lang.php` + override `includes/lang_custom.php`, helper `__()`.
- Auth/session/DB/CSRF: `includes/config.php`.
- Sdílená business logika: `includes/functions.php`.

## 2) Klíčové vstupní body

- Dashboard: `index.php`
- Zakázky (list): `orders.php`
- Detail zakázky: `view_order.php`
- Zákazníci: `customers.php`
- Sklad: `inventory.php`
- Nákup/procurement: `procurement.php`
- Reporty: `reports.php`
- Nastavení: `settings.php`
- Login: `login.php`

Klientský portál (odděleně): `klient/*` (zejména `klient/dashboard.php`).

## 3) API endpointy podle domén

### Zakázky
- `api/add_order.php`
- `api/get_order_details.php`
- `api/update_order_full.php`
- `api/update_order_status.php`
- `api/search_orders.php`
- `api/upload_media.php`, `api/delete_media.php`

### Položky zakázky / díly
- `api/add_order_item.php`
- `api/update_order_item.php`
- `api/delete_order_item.php`

### Zákazníci
- `api/add_customer.php`
- `api/delete_customer.php`
- `api/search_customers.php`

### Sklad / procurement
- `api/add_inventory.php`
- `api/delete_inventory.php`
- `api/procurement_request.php`
- `api/search_catalog_items.php`

### Faktury
- `api/create_invoice.php`
- `api/create_express_invoice.php`
- `api/get_invoice_data.php`
- `api/get_invoice_details.php`
- `api/update_invoice.php`

### Integrace
- Telegram webhook: `tg_webhook.php`
- CRM↔Telegram chat: `fixer_chat.php`, `api/fixer_send.php`, `api/fixer_thread.php`
- Telegram AI bridge: `api/telegram_ai.php`
- IMEI check: `api/check_imei.php`
- Aktualizace přes git: `api/check_updates.php`, `api/run_update.php`

## 4) Permission model (důležité pro každou úpravu)

- `hasPermission()` a `getCurrentStaffRole()` jsou v `includes/functions.php`.
- Admin má vše.
- Technician/manager mají granular permissions (`tech_permissions`).
- Většina stránek používá guard v `includes/header.php`.
- API endpointy mají vlastní guardy, je nutné je při změnách zachovat.

## 5) DB a migrace

- Základ schématu: `migrations/001_bootstrap.sql`
- Dodatečné změny: `migrations/00x_*.sql`
- Runner: `run_migrations.php`
- Runtime schema helpers (fallback): např. `ensureProcurementSchema()`, `ensureOrderWorkTrackingSchema()`.

## 6) Jak dělat změny bezpečně

1. Najít page + API + helpery + překlady pro daný use-case.
2. Držet CSRF (`validateCsrfToken`, `csrfField`) a permission guard.
3. Zachovat lokalizace (`__('key')`) nebo doplnit klíče do `lang_custom.php`/`lang.php`.
4. Po změně lint: `php -l` nad dotčenými soubory.
5. Ověřit flow na `https://admin.applefix.cloud`.

## 7) Kde co typicky upravit

- Nové pole v zakázce: `migrations/*` + `orders.php` + `view_order.php` + `api/add_order.php` + `api/update_order_full.php`
- Nové oprávnění: `includes/functions.php` (`getAvailablePermissions`, implicit perms) + UI v `settings.php`
- Změna status flow: `view_order.php` (next status map), `api/update_order_full.php`, helpery status labelů v `includes/functions.php`
- Úprava notifikací: `includes/functions.php` + `tg_webhook.php` + `api/fixer_send.php`

## 8) Aktuální provozní poznámky

- Projekt je live dostupný z této složky na: `https://admin.applefix.cloud`
- Pracujeme přímo v tomto adresáři bez čekání na commit.
- Pokud bude úkol velký, lze rozdělit na subagenty, ale finální kontrola a integrace zůstává v hlavním vlákně.
