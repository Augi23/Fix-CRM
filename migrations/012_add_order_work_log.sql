-- Per-technician work segments so reports credit time/earnings to whoever actually did the work,
-- even after a job is reassigned. orders.work_duration_seconds stays as the order's cumulative total.
CREATE TABLE IF NOT EXISTS `order_work_log` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `order_id`         INT NOT NULL,
    `technician_id`    INT NULL,
    `started_at`       DATETIME NOT NULL,
    `ended_at`         DATETIME NULL,
    `duration_minutes` INT NOT NULL DEFAULT 0,
    `rate_snapshot`    DECIMAL(10,2) NULL DEFAULT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_owl_order` (`order_id`),
    INDEX `idx_owl_tech`  (`technician_id`),
    INDEX `idx_owl_ended` (`ended_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
