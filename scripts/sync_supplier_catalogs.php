<?php
/**
 * Denní synchronizace katalogů dodavatelů (ceny, dostupnost, zmizelé díly).
 * Spouští crmSupplierSyncMaybeSchedule() na pozadí jednou denně, nebo tlačítko
 * „Synchronizovat teď" v Nákupech. Lze pustit i ručně:
 *   php scripts/sync_supplier_catalogs.php                 — všichni aktivní dodavatelé
 *   php scripts/sync_supplier_catalogs.php mobilnidily     — jen jeden
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }

ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/catalog_sync.php';
ob_end_clean();

if (!isset($pdo)) { fwrite(STDERR, "Sync failed: DB connection unavailable\n"); exit(1); }
set_time_limit(0);

// jeden běh naráz (denní + ruční tlačítko se nesmí překrývat)
$lock = fopen(sys_get_temp_dir() . '/fixcrm_supplier_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo "Synchronizace už běží.\n"; exit(0); }

ensureSupplierSyncSchema();
$only = trim((string)($argv[1] ?? ''));
set_setting('supplier_sync_running', date('Y-m-d H:i:s'));
try {
    foreach ($pdo->query("SELECT skey, sync_enabled FROM supplier_catalogs WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if ($only !== '' ? $s['skey'] !== $only : (int)$s['sync_enabled'] !== 1) { continue; }
        $t0 = microtime(true);
        try {
            $st = crmCatalogSyncSupplier((string)$s['skey']);
            printf("%s: %d položek (%d nových, %d změn ceny, %d zmizelo), %d stránek, %s, %ds%s\n",
                $s['skey'], $st['added'] + $st['updated'], $st['added'], $st['price_changes'], $st['gone'], $st['pages'],
                $st['complete'] ? 'celý katalog' : 'NEDOKONČENO', (int)(microtime(true) - $t0), $st['error'] !== '' ? ' — ' . $st['error'] : '');
        } catch (Throwable $e) {
            fwrite(STDERR, $s['skey'] . ': ' . $e->getMessage() . "\n");
            try {
                $pdo->prepare("UPDATE supplier_catalogs SET last_sync_at = NOW(), last_sync_info = ? WHERE skey = ?")
                    ->execute([mb_substr('Chyba: ' . $e->getMessage(), 0, 500), $s['skey']]);
            } catch (Throwable $e2) {}
        }
    }
} finally {
    set_setting('supplier_sync_running', '');
    set_setting('supplier_sync_finished', date('Y-m-d H:i:s'));
    flock($lock, LOCK_UN);
    fclose($lock);
}
