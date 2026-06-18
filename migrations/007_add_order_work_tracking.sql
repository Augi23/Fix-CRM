-- Track technician work sessions on orders
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `work_started_at` DATETIME NULL AFTER `updated_at`,
    ADD COLUMN IF NOT EXISTS `work_finished_at` DATETIME NULL AFTER `work_started_at`,
    ADD COLUMN IF NOT EXISTS `work_duration_seconds` INT NOT NULL DEFAULT 0 AFTER `work_finished_at`,
    ADD COLUMN IF NOT EXISTS `work_started_by` INT NULL AFTER `work_duration_seconds`,
    ADD COLUMN IF NOT EXISTS `work_finished_by` INT NULL AFTER `work_started_by`;
