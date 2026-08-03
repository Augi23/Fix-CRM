-- Pokladna: ruční položky mimo sklad.
--
-- Ruční řádek na dokladu nemá vazbu na inventory/products, takže se ukládá jako
-- item_type='manual' a item_id=0. Sklad ani appkový import se ho nedotýká.

ALTER TABLE pos_sale_items MODIFY COLUMN item_type ENUM('part','product','manual') NOT NULL;
