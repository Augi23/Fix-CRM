-- Store original imported order/ticket codes (e.g. APFAZ2500013) separately from internal DB id.
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `order_code` VARCHAR(32) NULL AFTER `id`;

UPDATE `orders`
SET `order_code` = TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(`technician_notes`, 'Importovaný kód: ', -1), CHAR(10), 1)),
    `updated_at` = `updated_at`
WHERE (`order_code` IS NULL OR `order_code` = '')
  AND `technician_notes` LIKE '%Importovaný kód:%'
  AND TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(`technician_notes`, 'Importovaný kód: ', -1), CHAR(10), 1)) REGEXP '^[A-Za-z]+[0-9]+$';

CREATE UNIQUE INDEX IF NOT EXISTS `idx_orders_order_code` ON `orders` (`order_code`);
