-- 028: Objednávky z vlastního e-shopu (applefix.online).
-- E-shop čte sklad z api/eshop_feed.php a při dokončení objednávky přes
-- api/eshop_sale.php zapíše prodej → CRM odečte kus z products.stock_qty.
-- order_ref je UNIQUE = idempotence (opakovaný webhook neodečte sklad dvakrát).
CREATE TABLE IF NOT EXISTS eshop_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_ref VARCHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'paid',
    items_json MEDIUMTEXT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    customer_name VARCHAR(160) NULL DEFAULT NULL,
    customer_email VARCHAR(160) NULL DEFAULT NULL,
    customer_phone VARCHAR(48) NULL DEFAULT NULL,
    note VARCHAR(500) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_eshop_order_ref (order_ref),
    KEY idx_eshop_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
