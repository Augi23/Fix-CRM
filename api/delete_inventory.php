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

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

try {
    // Check if item is used in orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE inventory_id = ?");
    $stmt->execute([$id]);
    $usage = $stmt->fetchColumn();

    if ($usage > 0) {
        // If used in orders, we can't delete
        echo json_encode(['success' => false, 'message' => __('item_hidden_in_orders')]);
    } else {
        // If not used, delete permanently — jméno pro historii PŘED smazáním
        $__pn = '';
        try { $ns = $pdo->prepare("SELECT part_name FROM inventory WHERE id = ?"); $ns->execute([$id]); $__pn = (string)$ns->fetchColumn(); } catch (Throwable $e) {}
        $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        crmAuditLog('inventory.delete', [
            'entity_type' => 'inventory', 'entity_id' => (int)$id, 'entity_label' => $__pn,
            'summary' => 'Smazán skladový díl ' . ($__pn !== '' ? '„' . $__pn . '"' : ('#' . (int)$id)),
        ]);
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
