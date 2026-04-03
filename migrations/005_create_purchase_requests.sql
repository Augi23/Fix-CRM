-- Shared procurement queue
CREATE TABLE IF NOT EXISTS `purchase_requests` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `order_id` INT NULL,
    `supplier_key` VARCHAR(50) NOT NULL,
    `inventory_id` INT NULL,
    `item_name` VARCHAR(255) NOT NULL,
    `sku` VARCHAR(80) DEFAULT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `priority` ENUM('today','this_week','later') NOT NULL DEFAULT 'this_week',
    `status` ENUM('pending','ordered','received','cancelled') NOT NULL DEFAULT 'pending',
    `notes` TEXT NULL,
    `requested_by` INT NULL,
    `ordered_by` INT NULL,
    `ordered_at` TIMESTAMP NULL DEFAULT NULL,
    `received_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_supplier` (`supplier_key`),
    KEY `idx_order` (`order_id`),
    KEY `idx_inventory` (`inventory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
