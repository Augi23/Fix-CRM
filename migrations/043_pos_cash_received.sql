-- Evidence hotovosti na dokladu kasy: kolik zákazník podal a kolik se mu vrátilo.
-- Vyplňuje se jen u platby hotově; NULL = obsluha částku nezadala (starý doklad,
-- nebo platba přesně). Účtenka z toho tiskne řádky Placeno / Vráceno.
ALTER TABLE pos_sales ADD COLUMN cash_received DECIMAL(10,2) NULL DEFAULT NULL;
ALTER TABLE pos_sales ADD COLUMN cash_change DECIMAL(10,2) NULL DEFAULT NULL;
