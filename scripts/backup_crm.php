<?php
/**
 * Automatická záloha CRM (spouští crmBackupMaybeSchedule() na pozadí,
 * lze pustit i ručně: php scripts/backup_crm.php).
 * Kompletní DB + uploads + kód (při změně). Retence 48 h.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
ob_end_clean();

if (!isset($pdo)) { fwrite(STDERR, "Backup failed: DB connection unavailable\n"); exit(1); }

[$ok, $msg] = crmRunBackupNow('auto');
if (!$ok) {
    try { set_setting('backup_last_status', 'CHYBA ' . date('d.m.Y H:i:s') . ': ' . $msg); } catch (Throwable $e) {}
    fwrite(STDERR, "Backup failed: $msg\n");
    exit(1);
}
echo "Backup OK: $msg\n";
