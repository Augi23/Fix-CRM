-- Předávání pokladny (směny): kdo pokladnu drží, s čím ji převzal (ANO/NE na
-- stav hotovosti + napočítaná částka) a s čím ji uzavřel. V jednu chvíli smí
-- mít pobočka nejvýš jednu otevřenou směnu (hlídá aplikace přes GET_LOCK).
CREATE TABLE IF NOT EXISTS pos_shifts (
    id INT NOT NULL AUTO_INCREMENT,
    branch_id INT NULL DEFAULT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    opened_by VARCHAR(100) NOT NULL DEFAULT '',
    opened_by_user INT NULL DEFAULT NULL,
    opened_by_tech INT NULL DEFAULT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opening_expected DECIMAL(12,2) NOT NULL DEFAULT 0,
    opening_counted DECIMAL(12,2) NOT NULL DEFAULT 0,
    opening_match TINYINT(1) NOT NULL DEFAULT 1,
    closed_at DATETIME NULL DEFAULT NULL,
    closed_by VARCHAR(100) NULL DEFAULT NULL,
    closing_expected DECIMAL(12,2) NULL DEFAULT NULL,
    closing_counted DECIMAL(12,2) NULL DEFAULT NULL,
    closing_note VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_shift_open (branch_id, status),
    KEY idx_shift_opened (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
