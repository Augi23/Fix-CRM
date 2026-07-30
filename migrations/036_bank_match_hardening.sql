-- Banka: zpevnění párování plateb (v3.22.0)
--
-- 1) match_status 'ignored' — účetní může platbu vyřadit z automatického párování.
--    Dosud se odpárovaná platba vracela do stavu 'none', tedy přesně do stavu, který
--    auto-párování zpracovává: při nejbližší synchronizaci se párování samo obnovilo
--    a přebilo rozhodnutí člověka.
-- 2) tx_status + is_reversal — evidence zaúčtování a storna. Vrácená platba musí
--    fakturu vrátit mezi nezaplacené, dosud se storno mlčky zahodilo.
-- 3) matched_at / match_note — kdy a proč se platba spárovala (dohledatelnost).

ALTER TABLE bank_transactions
    MODIFY COLUMN match_status ENUM('none','auto','manual','review','ignored') NOT NULL DEFAULT 'none';

ALTER TABLE bank_transactions ADD COLUMN tx_status VARCHAR(16) NULL DEFAULT NULL;
ALTER TABLE bank_transactions ADD COLUMN is_reversal TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE bank_transactions ADD COLUMN reversal_done TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE bank_transactions ADD COLUMN matched_at DATETIME NULL DEFAULT NULL;
ALTER TABLE bank_transactions ADD COLUMN match_note VARCHAR(255) NULL DEFAULT NULL;

ALTER TABLE bank_transactions ADD INDEX idx_bank_amount (amount);
ALTER TABLE bank_transactions ADD INDEX idx_bank_invoice (matched_invoice_id);
