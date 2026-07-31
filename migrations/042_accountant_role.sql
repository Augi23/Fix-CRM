-- 042: Role „účetní" (accountant) + uzávěrka účetních období (balík C, 31.7.2026)
--
-- PROČ: externí účetní potřebuje vidět faktury, platby, pokladnu a bankovní pohyby
-- NAPŘÍČ oběma pobočkami, párovat platby a doplňovat účetní údaje — ale nesmí sahat
-- na zakázky, klienty, sklad ani nastavení a nesmí NIKDY nic mazat. Dosud pro to
-- neexistovala role: jediná cesta k faktuře byla přes admin_access, což by účetní
-- pustilo i k mazání zakázek a k celému CRM.
--
-- Druhá část: uzávěrka měsíce. Po odevzdání přiznání se doklady daného měsíce
-- nesmí měnit — jinak by se rozešla čísla v CRM s tím, co je odevzdané na úřadě.
-- Uzamčení je měkký zámek na úrovni aplikace (afxAccountingAssertOpen), historie
-- zamčení/odemčení je navíc v audit_log; ODEMKNOUT smí jen administrátor.
--
-- Idempotentní: tabulka přes CREATE TABLE IF NOT EXISTS, rozšíření ENUM jen tehdy,
-- když hodnota ještě chybí. Runner navíc toleruje 1050/1060/1061/1091 a stejné
-- schéma umí založit i PHP pojistka afxEnsureAccountingSchema().

CREATE TABLE IF NOT EXISTS accounting_periods (
    id INT NOT NULL AUTO_INCREMENT,
    period_year SMALLINT UNSIGNED NOT NULL,      -- rok období (2026)
    period_month TINYINT UNSIGNED NOT NULL,      -- měsíc období (1–12)
    locked_at DATETIME NULL DEFAULT NULL,        -- kdy uzamčeno; NULL = období je OTEVŘENÉ
    locked_by VARCHAR(190) NULL DEFAULT NULL,    -- jméno člověka, který zamkl
    locked_by_type VARCHAR(20) NULL DEFAULT NULL,-- user / technician (jako v audit_log)
    locked_by_id INT NULL DEFAULT NULL,
    unlocked_at DATETIME NULL DEFAULT NULL,      -- poslední odemčení (historie zůstává v audit_log)
    unlocked_by VARCHAR(190) NULL DEFAULT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,         -- poznámka (např. „odevzdáno DPFO 2026")
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- jeden řádek na měsíc: zamknout/odemknout se pak dělá UPDATEm téhož záznamu
    -- a nemůže vzniknout dvojice „zamčeno + odemčeno" pro stejné období
    UNIQUE KEY uniq_accounting_period (period_year, period_month),
    KEY idx_accounting_period_locked (locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Rozšíření technicians.role o hodnotu 'accountant' ────────────────────────
-- Sloupec je na různých instalacích buď ENUM, nebo VARCHAR. U VARCHARu není co
-- řešit; u ENUMu se musí seznam rozšířit, jinak by MySQL uložilo prázdnou roli
-- (a účetní by se přihlásila jako obyčejný technik s právy na zakázky!).
-- Proto se ALTER skládá dynamicky z aktuální definice sloupce — natvrdo zapsaný
-- seznam hodnot by na instalaci s jiným výčtem tiše smazal existující role.
SET @afx_role_type := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'technicians' AND COLUMN_NAME = 'role'
);
SET @afx_role_null := (
    SELECT IF(IS_NULLABLE = 'YES', ' NULL', ' NOT NULL') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'technicians' AND COLUMN_NAME = 'role'
);
SET @afx_role_def := (
    SELECT CASE
        WHEN COLUMN_DEFAULT IS NULL THEN ''
        -- POZOR: MariaDB 10.2+ vrací výchozí hodnotu VČETNĚ apostrofů ('engineer'),
        -- MySQL bez nich (engineer). Bez tohohle rozlišení by se na MariaDB nastavil
        -- výchozí role doslovný řetězec i s apostrofy a nový zaměstnanec by dostal
        -- neplatnou roli (u ENUMu prázdnou).
        WHEN COLUMN_DEFAULT LIKE '''%''' THEN CONCAT(' DEFAULT ', COLUMN_DEFAULT)
        ELSE CONCAT(' DEFAULT ', QUOTE(COLUMN_DEFAULT))
    END
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'technicians' AND COLUMN_NAME = 'role'
);
SET @afx_role_sql := IF(
    @afx_role_type LIKE 'enum(%' AND @afx_role_type NOT LIKE '%''accountant''%',
    CONCAT('ALTER TABLE technicians MODIFY role ',
           LEFT(@afx_role_type, CHAR_LENGTH(@afx_role_type) - 1),
           ',''accountant'')', @afx_role_null, @afx_role_def),
    -- není ENUM nebo už hodnotu má → nedělej nic (SET je bezpečný „prázdný" příkaz)
    'SET @afx_role_skipped = 1'
);
PREPARE afx_role_stmt FROM @afx_role_sql;
EXECUTE afx_role_stmt;
DEALLOCATE PREPARE afx_role_stmt;
