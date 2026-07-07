-- Segmenty přidělení zakázky technikovi: od přidělení/přijetí do předání či dokončení.
-- Denní čas v Přehledech = průnik segmentu s přítomností technika v systému ten den.
CREATE TABLE IF NOT EXISTS `order_assignment_log` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `order_id`      INT NOT NULL,
    `technician_id` INT NULL,
    `started_at`    DATETIME NOT NULL,
    `ended_at`      DATETIME NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_oal_order` (`order_id`),
    INDEX `idx_oal_tech`  (`technician_id`),
    INDEX `idx_oal_ended` (`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
