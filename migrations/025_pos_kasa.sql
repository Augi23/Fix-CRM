-- Pokladna (kasa prodejna): přímý prodej dílů a produktů bez zakázky.
-- Stejné DDL drží i runtime guardy ensurePosTables() / ensureProductsPosColumn().

CREATE TABLE IF NOT EXISTS pos_sales (
    id INT NOT NULL AUTO_INCREMENT,
    sale_number VARCHAR(20) NOT NULL,
    branch_id INT NULL DEFAULT NULL,
    seller_name VARCHAR(100) NOT NULL DEFAULT '',
    customer_id INT NULL DEFAULT NULL,
    payment_method ENUM('cash','card','invoice') NOT NULL DEFAULT 'cash',
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    is_vat_payer TINYINT(1) NOT NULL DEFAULT 0,
    invoice_id INT NULL DEFAULT NULL,
    status ENUM('completed','cancelled') NOT NULL DEFAULT 'completed',
    cancelled_at DATETIME NULL DEFAULT NULL,
    cancelled_by VARCHAR(100) NULL DEFAULT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_pos_sale_number (sale_number),
    KEY idx_pos_created (created_at),
    KEY idx_pos_payment (payment_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_sale_items (
    id INT NOT NULL AUTO_INCREMENT,
    sale_id INT NOT NULL,
    item_type ENUM('part','product') NOT NULL,
    item_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_code VARCHAR(64) NULL DEFAULT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    is_used_goods TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_pos_items_sale (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- prodej na kase drží kus na nule i přes CSV import (zdroj pravdy = appka, dokud se nesladí)
ALTER TABLE products ADD COLUMN IF NOT EXISTS pos_sold_at DATETIME NULL DEFAULT NULL;
