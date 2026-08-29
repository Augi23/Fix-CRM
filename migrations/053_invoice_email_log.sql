-- Evidence odeslání faktury e-mailem (v3.70.0).
-- Runtime pojistka je afxEnsureInvoiceEmailColumns() v includes/functions.php —
-- tahle migrace je pro čisté instalace, aby sloupce vznikly rovnou.
ALTER TABLE invoices ADD COLUMN emailed_at DATETIME NULL DEFAULT NULL;
ALTER TABLE invoices ADD COLUMN emailed_to VARCHAR(190) NULL DEFAULT NULL;
