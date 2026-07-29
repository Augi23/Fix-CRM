-- „Provedená oprava" — jak technik zakázku vyřešil (řešení opravy).
-- Zobrazuje se pod popisem závady (detail, tisky, klientský portál) a je
-- povinná před přechodem zakázky do „Připraveno k převzetí" / „Vydáno".
-- Zrcadlí runtime guard ensureOrderRepairSolutionColumn() ve functions.php.
ALTER TABLE orders ADD COLUMN IF NOT EXISTS repair_solution TEXT NULL AFTER problem_description;
