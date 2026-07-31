-- Datum posledního prodeje kusu — zapisuje ho kasa (pos_checkout) i e-shop
-- (eshop_sale) při KAŽDÉM odečtu skladu, na rozdíl od pos_sold_at, který se
-- nastavuje jen při úplném vyprodání (a slouží importu z appky jako pojistka).
-- Sklad → Produkty ho ukazuje ve sloupci Dostupnost.
ALTER TABLE products ADD COLUMN last_sold_at DATETIME NULL DEFAULT NULL;
