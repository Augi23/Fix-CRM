-- Track technician work sessions on orders
ALTER TABLE `orders`
    ADD COLUMN `work_started_at` DATETIME NULL AFTER `updated_at`,
    ADD COLUMN `work_finished_at` DATETIME NULL AFTER `work_started_at`,
    ADD COLUMN `work_duration_seconds` INT NOT NULL DEFAULT 0 AFTER `work_finished_at`,
    ADD COLUMN `work_started_by` INT NULL AFTER `work_duration_seconds`,
    ADD COLUMN `work_finished_by` INT NULL AFTER `work_started_by`;
