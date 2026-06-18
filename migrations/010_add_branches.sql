-- Add branch separation for staff and orders
CREATE TABLE IF NOT EXISTS `branches` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_branch_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `branches` (`code`, `name`, `address`, `is_active`) VALUES
('karlin', 'Praha 8 - Karlín', 'Praha 8 - Karlín', 1),
('prikope', 'Praha 1 - Na Příkopě', 'Praha 1 - Na Příkopě', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `address` = VALUES(`address`), `is_active` = VALUES(`is_active`);

SET @karlin_id := (SELECT id FROM branches WHERE code = 'karlin' LIMIT 1);
SET @prikope_id := (SELECT id FROM branches WHERE code = 'prikope' LIMIT 1);

ALTER TABLE `technicians`
    ADD COLUMN IF NOT EXISTS `branch_id` INT(11) DEFAULT NULL AFTER `role`,
    ADD INDEX IF NOT EXISTS `idx_technicians_branch` (`branch_id`);

ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `branch_id` INT(11) DEFAULT NULL AFTER `technician_id`,
    ADD INDEX IF NOT EXISTS `idx_orders_branch` (`branch_id`);

UPDATE `technicians` SET branch_id = @karlin_id WHERE branch_id IS NULL;
UPDATE `technicians` SET branch_id = @prikope_id WHERE LOWER(TRIM(name)) = 'roman';

UPDATE `orders` o
LEFT JOIN `technicians` t ON t.id = o.technician_id
SET o.branch_id = COALESCE(t.branch_id, @karlin_id)
WHERE o.branch_id IS NULL;
