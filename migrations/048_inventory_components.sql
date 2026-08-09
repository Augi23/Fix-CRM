-- Součástky uvnitř skladového dílu (9.8.2026): díl může být celé zařízení-dárce
-- (např. iPhone 15 Pro Max) a tady se eviduje, co použitelného v něm je
-- (displej, baterie, kamera…). is_used = součástka už vyjmutá/použitá — v hledání
-- se pak nenabízí. Za běhu totéž hlídá ensureInventoryComponentsTable().
CREATE TABLE IF NOT EXISTS inventory_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inv_components (inventory_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
