<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean(); // discard any output/warnings
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$id = $_POST['id'] ?? null;
$new_qty = max(1, (int)($_POST['quantity'] ?? 1));
$new_price = (float)($_POST['price'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

ensureOrderItemStockFlag(); // DDL — před transakcí

try {
    $pdo->beginTransaction();

    // Fetch current state
    $stmt = $pdo->prepare("SELECT oi.*, o.status, o.technician_id, o.branch_id FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        throw new Exception("Item not found");
    }

    // Check permissions
    if (!canAccessOrderBranch($item) || (!hasPermission('edit_orders') && ($item['technician_id'] ?? 0) != ($_SESSION['tech_id'] ?? 0))) {
        throw new Exception(__('access_denied_msg'));
    }

    // Sklad se dorovnává o rozdíl, pokud je počet položky UŽ promítnutý do skladu:
    // - stock_deducted=1 (QR výdej — odečteno hned při výdeji), NEZÁVISLE na stavu zakázky
    // - jinak jen u dokončené zakázky (klasické položky se odečítají při dokončení)
    // Bez toho by úprava počtu QR-vydané položky rozbila párování „odečteno == počet"
    // a smazání položky by pak vracelo špatný počet kusů (fantomové zásoby).
    $__deductedNow = ((int)($item['stock_deducted'] ?? 0) === 1)
        || in_array($item['status'], getOrderStatusList('done'), true);
    $diff = $new_qty - (int)$item['quantity'];
    if ($__deductedNow && $diff !== 0) {
        changeInventoryQuantity($item['inventory_id'], -$diff);
        if (function_exists('crmLogInventoryMove')) {
            crmLogInventoryMove((int)$item['inventory_id'], -$diff, 'adjust', (int)$item['order_id'], 'Úprava počtu na zakázce (' . (int)$item['quantity'] . ' → ' . $new_qty . ' ks)');
        }
    }

    // Update item
    $upd_item = $pdo->prepare("UPDATE order_items SET quantity = ?, price = ? WHERE id = ?");
    $upd_item->execute([$new_qty, $new_price, $id]);

    $pdo->commit();
    crmAuditLog('order.item_update', [
        'entity_type' => 'order', 'entity_id' => (int)($item['order_id'] ?? 0),
        'summary' => 'Zakázka #' . (int)($item['order_id'] ?? 0) . ' — upraven díl (ks: ' . (int)($item['quantity'] ?? 0) . ' → ' . (int)$new_qty . ', cena: ' . $item['price'] . ' → ' . $new_price . ')',
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
