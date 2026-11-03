-- 4.3.0: zálohy na výplaty zaměstnancům (výdej z kasy + evidence u zaměstnance).
-- Pojistka za běhu: ensureStaffAdvancesTable() (includes/staff_advances.php).
CREATE TABLE IF NOT EXISTS staff_advances (
    id INT NOT NULL AUTO_INCREMENT,
    technician_id INT NOT NULL,
    branch_id INT NULL DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    status ENUM('open','settled','cancelled') NOT NULL DEFAULT 'open',
    paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_by VARCHAR(100) NULL DEFAULT NULL,
    cash_movement_id INT NULL DEFAULT NULL,
    cash_doc_id INT NULL DEFAULT NULL,
    settled_at DATETIME NULL DEFAULT NULL,
    settled_by VARCHAR(100) NULL DEFAULT NULL,
    settled_note VARCHAR(255) NULL DEFAULT NULL,
    cancelled_at DATETIME NULL DEFAULT NULL,
    cancelled_by VARCHAR(100) NULL DEFAULT NULL,
    cancel_reason VARCHAR(160) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_sa_tech (technician_id, status),
    KEY idx_sa_paid (paid_at),
    KEY idx_sa_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
