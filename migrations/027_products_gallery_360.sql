-- 027 — Galerie média u produktu (Sklad → Produkty)
-- studiová fotka (hlavní obrázek → eshop + Meta/Google katalog),
-- klasické fotky (JSON pole URL — Sbazar/Bazos + galerie na eshopu),
-- 360° video (nahrané video; z něj eshop vyrobí 360 — fáze 2) + odvozený flag has_360.
-- Stejné DDL drží i runtime guard ensureProductsCrmColumns() v includes/functions.php.
ALTER TABLE products ADD COLUMN IF NOT EXISTS studio_image_url VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS gallery_images   MEDIUMTEXT   NULL DEFAULT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS video_360_url    VARCHAR(500) NULL DEFAULT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS has_360          TINYINT(1)   NOT NULL DEFAULT 0;
