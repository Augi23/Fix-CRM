-- Add supplier availability metadata to inventory imported from catalogs
ALTER TABLE `inventory`
    ADD COLUMN IF NOT EXISTS `supplier_availability` VARCHAR(120) DEFAULT NULL AFTER `source_url`,
    ADD COLUMN IF NOT EXISTS `supplier_stock_qty` INT NULL AFTER `supplier_availability`;
