-- Faktura bez klienta v CRM (v3.59.0): jednorázový odběratel se ukládá přímo
-- na doklad do cust_*_override, takže customer_id smí být prázdné. Přibývá
-- e-mail odběratele, aby šla faktura odeslat i bez karty klienta.
ALTER TABLE invoices MODIFY customer_id INT(11) NULL;
ALTER TABLE invoices ADD COLUMN cust_email_override VARCHAR(190) NULL;
