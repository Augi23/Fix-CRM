-- 4.2.0: stav tisku účtenky na dokladu + vazba tiskové úlohy na doklad
-- (kasa dohledá nevytištěné účtenky; agent pokladního Macu označí převzatou úlohu).
-- Pojistky za běhu: afxEnsurePosReceiptColumns() a afxEnsurePrintJobsTable().
ALTER TABLE pos_sales
    ADD COLUMN IF NOT EXISTS receipt_printed_at DATETIME NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS receipt_print_via VARCHAR(16) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS pos_print_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    taken_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_ppj (branch_id, taken_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pos_print_jobs
    ADD COLUMN IF NOT EXISTS sale_id INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS kind VARCHAR(20) NOT NULL DEFAULT 'receipt',
    ADD COLUMN IF NOT EXISTS printed_at DATETIME NULL DEFAULT NULL,
    ADD INDEX IF NOT EXISTS idx_ppj_sale (sale_id);
