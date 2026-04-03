-- Add image_path to inventory for catalog imports
ALTER TABLE `inventory`
    ADD COLUMN `image_path` VARCHAR(255) DEFAULT NULL AFTER `min_stock`;
