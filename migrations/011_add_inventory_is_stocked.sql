-- Separate real warehouse stock (Sklad) from the orderable supplier catalog (Nákupy).
-- is_stocked = 1 → part belongs in the warehouse view (manually added or received via procurement).
-- Sklad query rule: is_stocked = 1 OR quantity > 0. Catalog parts (source_supplier set) stay 0.
ALTER TABLE `inventory`
    ADD COLUMN IF NOT EXISTS `is_stocked` TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE `inventory`
    ADD INDEX IF NOT EXISTS `idx_inventory_stocked` (`is_stocked`);

-- Manually-added parts (no catalog source) are real stock from the start.
UPDATE `inventory`
    SET `is_stocked` = 1
    WHERE `source_supplier` IS NULL OR `source_supplier` = '';
