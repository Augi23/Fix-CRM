<?php
/* Ceny dodavatelů (Nákupy): uložení nastavení přepočtu a ruční spuštění synchronizace.
   POST action=save   → price_vat_add[skey], discount_pct[skey], sync_enabled[skey]
   POST action=run    → spustí scripts/sync_supplier_catalogs.php na pozadí
   GET  action=status → { running, finished, suppliers:[{skey, last_sync_at, last_sync_info}] } */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/catalog_sync.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !crmCanManageCatalogs()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nemáš oprávnění spravovat katalogy.']); exit;
}
ensureSupplierSyncSchema();
$action = (string)($_REQUEST['action'] ?? 'status');

if ($action === 'status') {
    $rows = $pdo->query("SELECT skey, last_sync_at, last_sync_info FROM supplier_catalogs WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'running' => (string)get_setting('supplier_sync_running', ''),
        'finished' => (string)get_setting('supplier_sync_finished', ''), 'suppliers' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit;
}
session_write_close();

if ($action === 'save') {
    $vat = (array)($_POST['price_vat_add'] ?? []);
    $disc = (array)($_POST['discount_pct'] ?? []);
    $enabled = (array)($_POST['sync_enabled'] ?? []);
    $st = $pdo->prepare("UPDATE supplier_catalogs SET price_vat_add = ?, discount_pct = ?, sync_enabled = ? WHERE skey = ?");
    foreach (array_keys(getSupplierCatalogs()) as $skey) {
        if (!array_key_exists($skey, $vat)) { continue; }
        $v = max(0.0, min(30.0, (float)str_replace(',', '.', (string)$vat[$skey])));
        $d = max(0.0, min(90.0, (float)str_replace(',', '.', (string)($disc[$skey] ?? 0))));
        $st->execute([$v, $d, !empty($enabled[$skey]) ? 1 : 0, $skey]);
    }
    crmAuditLog('procurement.supplier_pricing', ['entity_type' => 'supplier', 'summary' => 'Upraveno nastavení cen dodavatelů']);
    echo json_encode(['ok' => true]); exit;
}

if ($action === 'run') {
    $running = (string)get_setting('supplier_sync_running', '');
    // běh, který spadl bez úklidu (restart serveru), neblokuje víc než 3 h
    if ($running !== '' && strtotime($running) > time() - 3 * 3600) {
        echo json_encode(['ok' => true, 'already' => true]); exit;
    }
    $skey = trim((string)($_POST['skey'] ?? ''));
    if ($skey !== '' && !isset(getSupplierCatalogs()[$skey])) { $skey = ''; }
    $ok = crmSupplierSyncStart($skey);
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => 'Synchronizaci se nepodařilo spustit (php-cli na serveru).']); exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Neznámá akce.']);
