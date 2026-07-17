-- Sklad → záložka Produkty: bazarová elektronika pro e-shop.
-- Samostatná tabulka (NE inventory) — servisní logika výdeje dílů, QR skladu
-- a nákupů se produktů nesmí dotknout. Plní ji import CSV z naskladňovací appky.
-- Stejné DDL drží i runtime guard ensureProductsTable() v includes/functions.php.

CREATE TABLE IF NOT EXISTS products (
    id INT NOT NULL AUTO_INCREMENT,
    product_code VARCHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    manufacturer VARCHAR(64) DEFAULT NULL,
    category_code VARCHAR(64) DEFAULT NULL,
    model VARCHAR(128) DEFAULT NULL,
    capacity VARCHAR(32) DEFAULT NULL,
    color VARCHAR(64) DEFAULT NULL,
    grade VARCHAR(16) DEFAULT NULL,
    battery VARCHAR(16) DEFAULT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    stock_key VARCHAR(32) NOT NULL DEFAULT '',
    image_url VARCHAR(500) DEFAULT NULL,
    pcr_result VARCHAR(255) DEFAULT NULL,
    added_at DATETIME DEFAULT NULL,
    raw_csv MEDIUMTEXT DEFAULT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_products_code (product_code),
    KEY idx_products_stock (stock_qty),
    KEY idx_products_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
