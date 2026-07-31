-- POKLADNÍ KNIHA — počáteční zůstatek + pokladní doklady PPD/VPD (balík B).
--
-- Dosud kasa počítala stav hotovosti od NULY každý den (prodeje hotově + vklady
-- − výdaje za dnešek). To není pokladní kniha: skutečná hotovost v zásuvce se
-- přenáší ze dne na den a účetně je to analytika účtu 211, která musí mít za
-- každé období POČÁTEČNÍ a KONEČNÝ zůstatek (§ 11 zák. 563/1991 Sb.).
--
-- Nově:
--   cash_registers  = jedna pokladna na pobočku s počátečním zůstatkem a datem,
--                     od kterého se počítá (inventarizace hotovosti = tady se
--                     zapíše skutečně napočítaný stav a od něj se jede dál).
--   cash_documents  = PPD/VPD v SOUVISLÉ číselné řadě za rok, pobočku a typ
--                     (P2026-0001 / V2026-0001). Doklad se NIKDY nemaže — chyba
--                     se opravuje vystavením STORNO dokladu opačného typu.
--
-- Stejné DDL drží i runtime pojistka afxEnsureCashBookTables() v includes/cash_book.php
-- (deploy nasadí kód dřív, než doběhne run_migrations.php).

CREATE TABLE IF NOT EXISTS cash_registers (
    id INT NOT NULL AUTO_INCREMENT,
    -- POZOR: branch_id je NOT NULL DEFAULT 0 (ne NULL) schválně — v UNIQUE klíči
    -- se NULL v MySQL neduplikuje, takže by šlo založit libovolně mnoho „pokladen
    -- bez pobočky" a stav hotovosti by se rozpadl do několika záznamů.
    -- 0 = pokladna pro pohyby bez určené pobočky (staré importy).
    branch_id INT NOT NULL DEFAULT 0,
    name VARCHAR(80) NOT NULL DEFAULT 'Hlavní pokladna',
    currency VARCHAR(8) NOT NULL DEFAULT 'CZK',
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    opening_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL DEFAULT NULL,
    updated_by VARCHAR(100) NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cash_register_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_documents (
    id INT NOT NULL AUTO_INCREMENT,
    register_id INT NOT NULL DEFAULT 0,
    branch_id INT NOT NULL DEFAULT 0,
    doc_type ENUM('income','expense') NOT NULL,
    -- doc_number je odvozené z doc_type/doc_year/doc_seq, ale ukládá se natvrdo:
    -- vytištěný doklad musí zůstat dohledatelný pod svým číslem i kdyby se formát
    -- čísla někdy v budoucnu změnil.
    doc_number VARCHAR(20) NOT NULL,
    doc_year SMALLINT NOT NULL,
    doc_seq INT NOT NULL,
    doc_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    purpose VARCHAR(255) NOT NULL DEFAULT '',
    counterparty VARCHAR(160) NULL DEFAULT NULL,
    issued_by VARCHAR(100) NULL DEFAULT NULL,
    -- vazba na doklad, ze kterého hotovost vznikla (nepovinná):
    -- pos_sale | cash_movement | invoice | order | document
    ref_type VARCHAR(20) NULL DEFAULT NULL,
    ref_id INT NULL DEFAULT NULL,
    ref_label VARCHAR(40) NULL DEFAULT NULL,
    -- storno_of  = TENTO doklad je stornem dokladu X
    -- storned_by = TENTO doklad byl stornován dokladem Y (a přestal platit)
    storno_of INT NULL DEFAULT NULL,
    storned_by INT NULL DEFAULT NULL,
    storned_at DATETIME NULL DEFAULT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- souvislá řada = číslo smí být na pobočce jen JEDNOU; zároveň je to zámek,
    -- o který se opře atomické přidělení dalšího čísla (INSERT + retry na 1062)
    UNIQUE KEY uniq_cash_doc_number (branch_id, doc_number),
    KEY idx_cash_doc_series (branch_id, doc_type, doc_year, doc_seq),
    KEY idx_cash_doc_date (doc_date),
    KEY idx_cash_doc_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Založení pokladny pro každou existující pobočku + jedné „bez pobočky" (branch_id = 0).
-- Počáteční zůstatek 0 k dnešnímu dni je ZÁMĚRNĚ neutrální: vedení ho přepíše
-- skutečně napočítanou hotovostí přes tlačítko „Nastavit počáteční zůstatek".
-- Kdyby se datum nastavilo do minulosti, sečetly by se k němu i staré prodeje
-- a kasa by ukazovala víc peněz, než v ní reálně je.
INSERT INTO cash_registers (branch_id, name, currency, opening_balance, opening_date)
SELECT b.id, CONCAT('Pokladna ', b.name), 'CZK', 0, CURDATE()
FROM branches b
WHERE NOT EXISTS (SELECT 1 FROM cash_registers r WHERE r.branch_id = b.id);

INSERT INTO cash_registers (branch_id, name, currency, opening_balance, opening_date)
SELECT 0, 'Pokladna bez pobočky', 'CZK', 0, CURDATE()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM cash_registers r WHERE r.branch_id = 0);
