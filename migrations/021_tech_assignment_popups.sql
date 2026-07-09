-- ═══════════════════════════════════════════════════════════════════════════════
-- Popup „nová přidělená zakázka" pro technika (doručeno pollingem na zařízení
-- s otevřeným CRM). Tabulku zakládá i runtime helper ensureTechPopupTable().
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `tech_assignment_popups` (
    `id`            INT(11)   NOT NULL AUTO_INCREMENT,
    `technician_id` INT(11)   NOT NULL,
    `order_id`      INT(11)   NOT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `seen_at`       TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `tech_unseen` (`technician_id`, `seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
