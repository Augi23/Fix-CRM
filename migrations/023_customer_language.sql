-- Jazyk komunikace zákazníka (cs/en/ru) — volí se při zakládání zakázky,
-- e-maily klientovi (např. „Připraveno k vyzvednutí") odcházejí v tomto jazyce.
-- Živý server si sloupec doplní sám přes ensureCustomerLanguageColumn().
ALTER TABLE `customers` ADD COLUMN `preferred_language` VARCHAR(5) NOT NULL DEFAULT 'cs';
