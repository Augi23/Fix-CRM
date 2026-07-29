-- Zapůjčeno / komisní prodej: kus fyzicky existuje, ale není náš k prodeji přes e-shop.
-- Dřív se řešilo nastavením stock_qty=0, jenže pak vypadá stejně jako vyprodané zboží
-- a nikdo neví, že kus někde je a u koho. (29.7.2026)
ALTER TABLE products
  ADD COLUMN loan_to    VARCHAR(120) NULL DEFAULT NULL COMMENT 'komu je kus zapůjčen / kdo ho má v komisi',
  ADD COLUMN loan_at    DATETIME     NULL DEFAULT NULL COMMENT 'od kdy; NULL = kus není zapůjčen',
  ADD COLUMN loan_note  VARCHAR(255) NULL DEFAULT NULL COMMENT 'poznámka (kontakt, do kdy, dohoda)',
  ADD COLUMN loan_by    VARCHAR(120) NULL DEFAULT NULL COMMENT 'kdo v CRM zápůjčku zapsal';

CREATE INDEX idx_products_loan_at ON products (loan_at);
