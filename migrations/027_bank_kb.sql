-- Modul Banka (KB API, v2.4.0): lokální zrcadlo bankovních pohybů + párování faktur.
-- Stejné DDL drží i runtime guard ensureBankTables() (includes/kb_api.php).

CREATE TABLE IF NOT EXISTS bank_transactions (
    id INT NOT NULL AUTO_INCREMENT,
    entry_ref VARCHAR(150) NOT NULL,
    env VARCHAR(8) NOT NULL DEFAULT 'prod',
    account_id VARCHAR(64) NOT NULL DEFAULT '',
    booking_date DATE NULL DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency VARCHAR(8) NOT NULL DEFAULT 'CZK',
    direction ENUM('in','out') NOT NULL DEFAULT 'in',
    counterparty_name VARCHAR(190) NULL DEFAULT NULL,
    counterparty_account VARCHAR(64) NULL DEFAULT NULL,
    vs VARCHAR(20) NULL DEFAULT NULL,
    ss VARCHAR(20) NULL DEFAULT NULL,
    ks VARCHAR(10) NULL DEFAULT NULL,
    message VARCHAR(255) NULL DEFAULT NULL,
    matched_invoice_id INT NULL DEFAULT NULL,
    match_status ENUM('none','auto','manual','review') NOT NULL DEFAULT 'none',
    raw MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bank_entry (env, account_id, entry_ref),
    KEY idx_bank_date (booking_date),
    KEY idx_bank_vs (vs),
    KEY idx_bank_match (match_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
