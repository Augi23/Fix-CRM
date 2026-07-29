-- Fyzická organizace skladu dílů: regály → police → krabičky (29.7.2026).
-- Krabička má TRVALÝ kód (K001…) — štítek se tiskne jednou; na které polici
-- krabička je, drží parent_id v CRM (přesun = změna v CRM, bez přetisku štítku).
-- Díl dostává location_id (kde fyzicky leží) a device_model (třídění dle modelu
-- zařízení: iPhone 12, iPad Air…). Za běhu totéž hlídá ensureStockLocationsSchema().
CREATE TABLE IF NOT EXISTS stock_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL DEFAULT '',
    type VARCHAR(10) NOT NULL DEFAULT 'krabicka' COMMENT 'regal | police | krabicka',
    parent_id INT NULL DEFAULT NULL COMMENT 'police → regál, krabička → police/regál',
    note VARCHAR(255) NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_locations_parent (parent_id),
    KEY idx_locations_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE inventory ADD COLUMN location_id INT NULL DEFAULT NULL COMMENT 'FK stock_locations — kde díl fyzicky leží';
ALTER TABLE inventory ADD COLUMN device_model VARCHAR(64) NULL DEFAULT NULL COMMENT 'model zařízení (iPhone 12…) pro třídění skladu';
CREATE INDEX idx_inventory_location ON inventory (location_id);
CREATE INDEX idx_inventory_model ON inventory (device_model);
