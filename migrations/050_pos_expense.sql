-- v3.54.0: Výdaj z kasy — nový typ položky prodejky 'expense' (volná záporná
-- položka: záloha zaměstnanci, drobný nákup…). Aditivní rozšíření ENUM,
-- stejné DDL drží i runtime pojistky (api/pos_checkout.php, ensurePosTables).
ALTER TABLE pos_sale_items MODIFY COLUMN item_type ENUM('part','product','manual','order','vykup','expense') NOT NULL;
