-- Pokladna: zakázky jako servisní položka v košíku a vazba účtenky zpět na zakázku.
--
-- pos_sales.order_id umožní detailu zakázky dotisknout stejnou účtenku z kasy.
-- pos_sale_items.item_type='order' je servisní řádek: nehýbe skladem a na účtence
-- se bere jako oprava, ne jako nový skladový produkt.

ALTER TABLE pos_sales ADD COLUMN IF NOT EXISTS order_id INT NULL DEFAULT NULL AFTER customer_id;
ALTER TABLE pos_sales ADD KEY IF NOT EXISTS idx_pos_order (order_id);

ALTER TABLE pos_sale_items MODIFY COLUMN item_type ENUM('part','product','manual','order') NOT NULL;
