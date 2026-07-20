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

$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => __('missing_id')]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, t.name as tech_name 
                           FROM orders o 
                           JOIN customers c ON o.customer_id = c.id 
                           LEFT JOIN technicians t ON o.technician_id = t.id
                           WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'message' => __('order_not_found')]);
        exit;
    }

    if (!canAccessOrderBranch($order)) {
        echo json_encode(['success' => false, 'message' => __('access_denied_msg')]);
        exit;
    }

    // Fetch attachments
    $stmt = $pdo->prepare("SELECT * FROM order_attachments WHERE order_id = ? ORDER BY created_at DESC");
    $stmt->execute([$id]);
    $attachments = $stmt->fetchAll();

    // Fetch parts
    $stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    // Způsob předání přeložit do jazyka CRM (uloženo anglicky z webu/RepairPluginu)
    if (!empty($order['shipping_method'])) {
        $order['shipping_method'] = crmTranslateWebServiceMethod((string)$order['shipping_method']);
    }

    echo json_encode([
        'success' => true,
        'order' => $order,
        'attachments' => $attachments,
        'items' => $items,
        'role' => $_SESSION['role']
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

