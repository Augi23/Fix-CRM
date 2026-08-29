-- Pobočka faktury (v3.70.0) — pobočkový manažer smí vystavovat faktury, ale
-- vidět má jen doklady své provozovny. Runtime pojistka i jednorázový dopočet
-- ze zakázek a prodejů kasy je v afxEnsureInvoiceBranch() (includes/functions.php);
-- tahle migrace je pro čisté instalace.
ALTER TABLE invoices ADD COLUMN branch_id INT NULL DEFAULT NULL;
ALTER TABLE invoices ADD KEY idx_invoices_branch (branch_id);
