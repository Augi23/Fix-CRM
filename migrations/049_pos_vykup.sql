-- Výkupy (9.8.2026): výkupní list → produkt ve skladu (kategorie „Výkupy")
-- + výplata výkupu přes pokladnu (záporná položka prodejky, protiúčet).
-- pos_sale_items.item_type: + 'vykup' (výplatní řádek vázaný na výkupní list).
-- products.is_vykup / vykup_document_id: rozlišení vykoupeného zboží a vazba
-- na dokument. (crm_documents je runtime-only tabulka — její nové sloupce
-- vykup_product_id / payout_sale_id přidává ensureCrmDocumentsTable().)
ALTER TABLE pos_sale_items MODIFY COLUMN item_type ENUM('part','product','manual','order','vykup') NOT NULL;

ALTER TABLE products ADD COLUMN is_vykup TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN vykup_document_id INT NULL DEFAULT NULL;
CREATE INDEX idx_products_vykup ON products (is_vykup);
