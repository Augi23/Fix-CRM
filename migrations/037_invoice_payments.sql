-- Evidence plateb k fakturám (v3.23.0) — základ částečných plateb.
--
-- Dosud nesl informaci o zaplacení jen stav faktury (zaplaceno / nezaplaceno) a jedno
-- datum. Nešlo tedy zaznamenat „klient poslal 3 000 z 8 500", doplatek se neměl kam
-- navázat a odpárování jedné platby shodilo celou fakturu mezi nezaplacené.
--
-- Nově je každá došlá platba samostatný záznam. Součet plateb se drží ve
-- invoices.paid_amount (kvůli rychlosti výpisů) a přepočítává ho JEDINÁ funkce
-- afxInvoiceRecalcPaid(). Stav faktury zůstává dvouhodnotový (zaplaceno / ne) —
-- „částečně zaplaceno" se odvozuje z paid_amount, aby se nerozbily existující
-- výpisy, filtry, reporty a exporty, které se stavem pracují.

CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT NOT NULL AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    paid_on DATE NULL DEFAULT NULL,
    kind ENUM('bank','cash','card','other') NOT NULL DEFAULT 'other',
    bank_transaction_id INT NULL DEFAULT NULL,
    note VARCHAR(255) NULL DEFAULT NULL,
    created_by VARCHAR(120) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- jeden bankovní pohyb smí být k jedné faktuře navázaný jen JEDNOU (proti dvojímu
    -- zaúčtování téže platby); ruční platby mají NULL a neomezují se
    UNIQUE KEY uniq_payment_bank (bank_transaction_id, invoice_id),
    KEY idx_payment_invoice (invoice_id),
    KEY idx_payment_date (paid_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices ADD COLUMN paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0;

-- Doplnění historie: faktury, které už jsou označené jako zaplacené, dostanou záznam
-- o platbě na plnou částku. Bez toho by je první přepočet vyhodnotil jako nezaplacené
-- (žádné platby = nic nedošlo) a systém by začal upomínat klienty, kteří dávno zaplatili.
INSERT INTO invoice_payments (invoice_id, amount, paid_on, kind, note, created_by)
SELECT i.id, i.total_amount, COALESCE(i.payment_date, i.date_issue),
       CASE WHEN i.payment_method = 'cash' THEN 'cash'
            WHEN i.payment_method = 'card' THEN 'card'
            ELSE 'other' END,
       'Doplněno při zavedení evidence plateb', 'systém'
FROM invoices i
WHERE i.status = 'paid'
  AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id);

UPDATE invoices i
SET i.paid_amount = COALESCE((SELECT SUM(p.amount) FROM invoice_payments p WHERE p.invoice_id = i.id), 0);
