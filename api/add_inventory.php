<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !hasPermission('manage_inventory')) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$part_name = trim($_POST['part_name'] ?? '');
$sku = trim($_POST['sku'] ?? '');
$quantity = (float)($_POST['quantity'] ?? 0);
$cost_price = (float)($_POST['cost_price'] ?? 0);
$sale_price = (float)($_POST['sale_price'] ?? 0);
$min_stock = (float)($_POST['min_stock'] ?? 5);
$device_model = mb_substr(trim((string)($_POST['device_model'] ?? '')), 0, 64);
$location_id = (int)($_POST['location_id'] ?? 0);

// Pobočka skladu — musí existovat a přihlášený do jejího skladu smí přidávat.
$branch_id = (int)($_POST['branch_id'] ?? 0);
$__validBranch = false;
foreach (getBranches(false) as $__b) { if ((int)$__b['id'] === $branch_id) { $__validBranch = true; break; } }
if (!$__validBranch) { $branch_id = getDefaultBranchId(); }
if (!crmCanModifyBranchStock($branch_id)) {
    echo json_encode(['success' => false, 'message' => 'Na tuto pobočku smí naskladňovat jen její zaměstnanci.']);
    exit;
}

if (empty($part_name)) {
    echo json_encode(['success' => false, 'message' => 'Part name is required']);
    exit;
}

try {
    ensureInventoryStockedSchema();
    ensureStockLocationsSchema();
    ensureSkladBranchSchema();
    if ($location_id > 0) {
        // umístění musí patřit STEJNÉ pobočce jako díl — jinak by díl Karlína
        // „ležel" v krabičce Na Příkopě (poslední neošetřená cesta zápisu)
        $lchk = $pdo->prepare("SELECT id FROM stock_locations WHERE id = ? AND is_active = 1 AND branch_id = ?");
        $lchk->execute([$location_id, $branch_id]);
        if (!$lchk->fetch()) { $location_id = 0; }
    }
    // Manually added parts are real warehouse stock → always visible in Sklad.
    $stmt = $pdo->prepare("INSERT INTO inventory (part_name, sku, quantity, cost_price, sale_price, min_stock, is_stocked, device_model, location_id, branch_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?)");
    $stmt->execute([$part_name, $sku, $quantity, $cost_price, $sale_price, $min_stock,
        $device_model !== '' ? $device_model : null, $location_id > 0 ? $location_id : null, $branch_id]);
    crmAuditLog('inventory.create', [
        'entity_type' => 'inventory', 'entity_id' => (int)$pdo->lastInsertId(), 'entity_label' => (string)$part_name,
        'summary' => 'Naskladněn nový díl „' . $part_name . '" (' . $quantity . ' ks)',
    ]);
    
    // Check if called from form (AJAX) or direct
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['success' => true, 'message' => 'Inventory added']);
    } else {
        header("Location: ../inventory.php?branch=" . (int)$branch_id);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
