-- ═══════════════════════════════════════════════════════════════════════════════
-- Reklamace: zakládání z CRM (modal „Nová reklamace") + fotodokumentace.
-- Tabulka `complaints` v produkci už existuje (vznikla importem) — IF NOT EXISTS
-- je tu pro čisté instalace. `complaint_attachments` je nová (fotky reklamací).
-- Pozn.: api/add_complaint.php si attachments tabulku umí založit i sám
-- (CREATE TABLE IF NOT EXISTS), takže funguje i bez spuštění migrace.
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `complaints` (
    `id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `complaint_code`   VARCHAR(30)  NOT NULL,
    `customer_id`      INT(11)      DEFAULT NULL,
    `phone`            VARCHAR(30)  DEFAULT NULL,
    `device`           VARCHAR(150) DEFAULT NULL,
    `serial_number`    VARCHAR(100) DEFAULT NULL,
    `complaint_reason` TEXT,
    `complaint_status` VARCHAR(50)  DEFAULT 'Přijato',
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `complaint_attachments` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT,
    `complaint_id` INT(11)      NOT NULL,
    `file_path`    VARCHAR(255) NOT NULL,
    `file_type`    VARCHAR(50)  DEFAULT NULL,
    `file_name`    VARCHAR(255) DEFAULT NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `complaint_id` (`complaint_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
