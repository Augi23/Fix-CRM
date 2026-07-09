-- ═══════════════════════════════════════════════════════════════════════════════
-- Notifikace „Připraveno k vyzvednutí" — klientský e-mail při dokončení opravy.
--   orders.pickup_notified_at : guard, aby se e-mail odeslal jen jednou
--   branches.opening_hours    : otevírací doba pobočky (do e-mailu)
-- Sloupce doplňuje i runtime helper ensurePickupReadyColumns() v functions.php.
-- ═══════════════════════════════════════════════════════════════════════════════

ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `pickup_notified_at` TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE `branches`
    ADD COLUMN IF NOT EXISTS `opening_hours` VARCHAR(255) NULL DEFAULT NULL;
