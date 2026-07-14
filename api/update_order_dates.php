<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean(); 
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || (!hasPermission('edit_orders') && !hasPermission('admin_access'))) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$order_id = $_POST['order_id'] ?? null;
$created_at = $_POST['created_at'] ?? null;
$updated_at = $_POST['updated_at'] ?? null;

if (!$order_id || !$created_at || !$updated_at) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

try {
    $check = $pdo->prepare('SELECT technician_id, branch_id FROM orders WHERE id = ? LIMIT 1');
    $check->execute([$order_id]);
    $order = $check->fetch();
    if (!$order || !canAccessOrderBranch($order)) {
        echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
        exit;
    }

    $__oldDates = null;
    try { $ds = $pdo->prepare("SELECT created_at, updated_at FROM orders WHERE id = ?"); $ds->execute([$order_id]); $__oldDates = $ds->fetch(); } catch (Throwable $e) {}
    $stmt = $pdo->prepare("UPDATE orders SET created_at = ?, updated_at = ? WHERE id = ?");
    $stmt->execute([$created_at, $updated_at, $order_id]);

    crmAuditLog('order.dates_change', [
        'entity_type' => 'order', 'entity_id' => (int)$order_id,
        'summary' => 'Zpětná změna datumů zakázky #' . (int)$order_id . ' (vytvořeno: ' . (string)($__oldDates['created_at'] ?? '?') . ' → ' . $created_at . ')',
        'details' => ['stare' => $__oldDates, 'nove' => ['created_at' => $created_at, 'updated_at' => $updated_at]],
    ]);
    echo json_encode(['success' => true, 'message' => 'Dates updated']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
