<?php
/* Odstranění katalogu dodavatele (koš u „Stávající katalogy" na Nákupech).
   Smaže dodavatele a uklidí jeho ČISTĚ katalogové položky — tj. ty, které
   nikdy nebyly naskladněné (quantity <= 0) a nejsou použité v žádné zakázce
   ani objednávce dílů. Naskladněné/použité položky ve Skladu zůstávají. */
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !crmCanManageCatalogs()) {
    die(__('unauthorized'));
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405); die('Method not allowed');
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403); die(__('csrf_token_invalid'));
}

function backToProcurement(array $params): void {
    header('Location: ../procurement.php?' . http_build_query($params));
    exit;
}

$skey = trim((string)($_POST['skey'] ?? ''));
if ($skey === '') {
    backToProcurement(['catalog_source_error' => 'invalid']);
}

try {
    ensureSupplierCatalogsTable();

    $st = $pdo->prepare("SELECT name, host FROM supplier_catalogs WHERE skey = ? LIMIT 1");
    $st->execute([$skey]);
    $supplier = $st->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        backToProcurement(['catalog_source_error' => 'invalid']);
    }

    $pdo->prepare("DELETE FROM supplier_catalogs WHERE skey = ?")->execute([$skey]);

    // úklid katalogových položek (bez zásoby a bez vazeb)
    $removed = 0;
    $kept = 0;
    try {
        $del = $pdo->prepare(
            "DELETE FROM inventory WHERE source_supplier = ? AND COALESCE(quantity, 0) <= 0
             AND id NOT IN (SELECT inventory_id FROM purchase_requests WHERE inventory_id IS NOT NULL)
             AND id NOT IN (SELECT inventory_id FROM order_items WHERE inventory_id IS NOT NULL)"
        );
        $del->execute([$skey]);
        $removed = (int)$del->rowCount();
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE source_supplier = ?");
        $cnt->execute([$skey]);
        $kept = (int)$cnt->fetchColumn();
    } catch (Throwable $e) {
        log_error('Catalog cleanup after supplier delete failed', 'inventory_import', $e->getMessage());
    }

    crmAuditLog('supplier_catalog.delete', [
        'entity_type' => 'supplier_catalog', 'entity_label' => (string)$supplier['name'],
        'summary' => 'Odstraněn katalog dodavatele „' . $supplier['name'] . '" (' . $supplier['host'] . ') — smazáno '
            . $removed . ' katalogových položek, ' . $kept . ' naskladněných/použitých ponecháno.',
    ]);

    backToProcurement(['catalog_source_deleted' => 1, 'catalog_removed_items' => $removed, 'catalog_kept_items' => $kept]);
} catch (Throwable $e) {
    backToProcurement(['catalog_source_error' => 'server']);
}
