-- Add supplier metadata to inventory items
ALTER TABLE `inventory`
    ADD COLUMN IF NOT EXISTS `source_supplier` VARCHAR(50) DEFAULT NULL AFTER `image_path`,
    ADD COLUMN IF NOT EXISTS `source_url` VARCHAR(255) DEFAULT NULL AFTER `source_supplier`;
