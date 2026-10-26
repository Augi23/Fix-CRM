-- Chybový protokol CRM (Nastavení → Systém → Databáze), v4.1.2.
-- log_error() do tabulky zapisovala od začátku, tabulka ale nikdy nevznikla —
-- záznam tiše padal jen do error_logu serveru. Runtime pojistka pro už běžící
-- instalace je afxEnsureSystemErrorsTable() (includes/functions.php); tahle
-- migrace je pro čisté instalace.
CREATE TABLE IF NOT EXISTS system_errors (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  error_type VARCHAR(50) NOT NULL DEFAULT 'system',
  message TEXT NOT NULL,
  details MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_system_errors_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
