-- Naučené bankovní účty klientů (v3.24.0) — pomůcka pro platby BEZ variabilního symbolu.
--
-- Když účetní ručně spáruje platbu s fakturou, CRM si zapamatuje, že z tohoto účtu platí
-- tenhle klient. Příště u platby bez VS umí nabídnout jeho otevřené faktury.
-- POZOR: záznam slouží jen jako NÁVRH k prověření, nikdy k automatickému zaplacení —
-- z jednoho účtu může platit i třetí osoba za někoho jiného.

CREATE TABLE IF NOT EXISTS customer_bank_accounts (
    id INT NOT NULL AUTO_INCREMENT,
    customer_id INT NOT NULL,
    account VARCHAR(64) NOT NULL,
    matched_count INT NOT NULL DEFAULT 1,
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_customer_account (customer_id, account),
    KEY idx_cba_account (account)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
