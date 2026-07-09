-- ═══════════════════════════════════════════════════════════════════════════════
-- Reklamace z klientské sekce.
-- Rozšiřuje `complaints` o napojení na zakázku, zdroj (klient/servis) a stopy
-- aktivity servisu. Sloupce doplňuje i runtime helper ensureComplaintsClientColumns()
-- v includes/functions.php, takže to funguje i bez spuštění této migrace.
--   order_id / order_code : ke které zakázce reklamace patří
--   source                : 'client' = založeno klientem v portálu, jinak 'staff'
--   updated_at            : čas poslední úpravy
--   staff_ack_at          : kdy technik/manažer poprvé zareagoval (uvolní „pin" nahoře)
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE `complaints`
    ADD COLUMN IF NOT EXISTS `order_id`     INT(11)     NULL AFTER `customer_id`,
    ADD COLUMN IF NOT EXISTS `order_code`   VARCHAR(30) NULL AFTER `order_id`,
    ADD COLUMN IF NOT EXISTS `source`       VARCHAR(20) NOT NULL DEFAULT 'staff' AFTER `complaint_status`,
    ADD COLUMN IF NOT EXISTS `updated_at`   TIMESTAMP   NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS `staff_ack_at` TIMESTAMP   NULL DEFAULT NULL;
