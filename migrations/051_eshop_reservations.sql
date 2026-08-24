-- v3.56.0: REZERVACE z e-shopu (platba při vyzvednutí na pobočce).
-- Objednávka s platbou „odber" NENÍ prodej: kus se jen REZERVUJE (zmizí z e-shopu,
-- ale zůstává skladem) a kasou projde teprve při skutečném placení na prodejně.
ALTER TABLE products ADD COLUMN reserved_qty INT NOT NULL DEFAULT 0;
ALTER TABLE eshop_orders ADD COLUMN pay_id VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE eshop_orders ADD COLUMN pos_sale_id INT NULL DEFAULT NULL;
ALTER TABLE eshop_orders ADD COLUMN collected_at DATETIME NULL DEFAULT NULL;
ALTER TABLE eshop_orders ADD COLUMN paid_at DATETIME NULL DEFAULT NULL;
ALTER TABLE eshop_orders ADD COLUMN shipped_at DATETIME NULL DEFAULT NULL;
CREATE TABLE IF NOT EXISTS eshop_order_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NULL DEFAULT NULL,
    released_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_res_order (order_id),
    KEY idx_res_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
