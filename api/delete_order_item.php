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

$id = $_POST['id'] ?? null; // ID of order_items record

if (!$id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Fetch the item and order status
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

    // Vrácení na sklad: QR-vydané položky (stock_deducted=1) byly odečtené hned
    // při výdeji → vrací se VŽDY; klasické položky jen pokud je zakázka dokončená
    // (odečetly se při dokončení). Jinak by kusy zmizely/přibyly dvakrát.
    $__wasDeducted = (int)($item['stock_deducted'] ?? 0) === 1;
    if ($__wasDeducted || in_array($item['status'], getOrderStatusList('done'), true)) {
        changeInventoryQuantity($item['inventory_id'], $item['quantity']);
        if ($__wasDeducted && function_exists('crmLogInventoryMove')) {
            crmLogInventoryMove((int)$item['inventory_id'], (int)$item['quantity'], 'restock', (int)$item['order_id'], 'Vráceno ze zakázky (smazána položka)');
        }
    }

    // Delete the item
    $del = $pdo->prepare("DELETE FROM order_items WHERE id = ?");
    $del->execute([$id]);

    $pdo->commit();
    crmAuditLog('order.item_delete', [
        'entity_type' => 'order', 'entity_id' => (int)($item['order_id'] ?? 0),
        'summary' => 'Zakázka #' . (int)($item['order_id'] ?? 0) . ' — odebrán díl (' . (int)($item['quantity'] ?? 0) . ' ks)',
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
