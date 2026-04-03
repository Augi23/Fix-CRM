-- Add supplier metadata to inventory items
ALTER TABLE `inventory`
    ADD COLUMN `source_supplier` VARCHAR(50) DEFAULT NULL AFTER `image_path`,
    ADD COLUMN `source_url` VARCHAR(255) DEFAULT NULL AFTER `source_supplier`;
