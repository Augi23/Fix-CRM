-- Daňový režim zboží na kase + nákupní cena (Balík A).
--
-- PROČ: kasa dosud označovala KAŽDÝ prodaný produkt jako použité zboží (§ 90).
-- U nového zboží (stav „Nový“) by to po přechodu na plátcovství DPH znamenalo
-- prodej nového kusu ve zvláštním režimu bez DPH = krácení daně. Režim se nově
-- odvozuje ze stavu (grade), a aby se dal zpětně doložit, ukládá se stav rovnou
-- na doklad — nastavení produktu se totiž může kdykoli změnit nebo kus zmizí.
--
-- purchase_price: § 90 zdaňuje PŘIRÁŽKU (prodejní − nákupní cena). Bez uložené
-- nákupní ceny v okamžiku prodeje ji zpětně nikdo nespočítá.
--
-- Stejné DDL drží i runtime pojistka afxEnsurePosGoodsTaxColumns()
-- (api/pos_checkout.php, api/product_create.php) — deploy nasadí kód dřív,
-- než doběhne run_migrations.php.

-- Snapshot stavu zboží na dokladu (Nový / Zánovní / A / B / C / D).
ALTER TABLE pos_sale_items ADD COLUMN IF NOT EXISTS grade VARCHAR(16) NULL DEFAULT NULL;

-- Nákupní cena kusu v okamžiku prodeje (podklad pro daň z přirážky).
ALTER TABLE pos_sale_items ADD COLUMN IF NOT EXISTS purchase_price DECIMAL(12,2) NULL DEFAULT NULL;

-- Nákupní cena kusu ve skladu produktů; nepovinná (u starších kusů ji nikdo nezadal).
ALTER TABLE products ADD COLUMN IF NOT EXISTS purchase_price DECIMAL(12,2) NULL DEFAULT NULL;
