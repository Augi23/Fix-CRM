-- Knihovna studiových fotek modelů: klíč „rodina|barva" → jedna studiová fotka.
-- Produkt bez vlastní studiovky ji ve feedu zdědí (viz productModelKey / crmModelPhotoMap).
-- Runtime guard ensureModelPhotosTable() vytváří totéž za běhu.
CREATE TABLE IF NOT EXISTS model_photos (
    model_key VARCHAR(160) COLLATE utf8mb4_bin NOT NULL,   -- byte-exact PK = shoda s PHP array-key
    studio_image_url VARCHAR(500) NULL DEFAULT NULL,
    label VARCHAR(200) NULL DEFAULT NULL,
    updated_by VARCHAR(64) NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (model_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
