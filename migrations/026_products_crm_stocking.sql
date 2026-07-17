-- Naskladňování produktů přímo v CRM (v2.3.0):
-- source: 'app' = řádek z CSV importu appky, 'crm' = založeno/spravováno v CRM
--         (import appky řádky se source='crm' NIKDY nepřepisuje)
-- Stejné DDL drží i runtime guard ensureProductsCrmColumns().

ALTER TABLE products ADD COLUMN IF NOT EXISTS source VARCHAR(16) NOT NULL DEFAULT 'app';
ALTER TABLE products ADD COLUMN IF NOT EXISTS created_by VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS pcr_status VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS pcr_checked_at DATETIME NULL DEFAULT NULL;
