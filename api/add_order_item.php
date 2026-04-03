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

$order_id = $_REQUEST['order_id'] ?? null;
$inventory_id = $_REQUEST['inventory_id'] ?? null;
$qty = max(1, (int)($_REQUEST['quantity'] ?? 1));

if (!$order_id || !$inventory_id) {
    echo json_encode(['success' => false, 'message' => __('missing_data')]);
    exit;
}

try {
    // Check permissions
    $stmt = $pdo->prepare("SELECT technician_id FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception("Order not found");
    }
    
    if (($_SESSION['role'] ?? '') == 'technician' && !hasPermission('view_all_orders') && $order['technician_id'] != ($_SESSION['tech_id'] ?? 0)) {
        throw new Exception(__('access_denied_msg'));
    }

    // Get current price and stock from inventory
    $stmt = $pdo->prepare("SELECT sale_price, quantity, part_name, sku, source_supplier, source_url FROM inventory WHERE id = ?");
    $stmt->execute([$inventory_id]);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inventory) {
        throw new Exception('Inventory item not found');
    }
    $price = $inventory['sale_price'];

    $stmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, quantity, price) VALUES (?, ?, ?, ?)");
    $stmt->execute([$order_id, $inventory_id, $qty, $price]);

    $autoProcurementQueued = false;
    if ((int)($inventory['quantity'] ?? 0) <= 0) {
        try {
            $autoProcurementQueued = queueProcurementRequestFromOrder((int)$order_id, (int)$inventory_id, $qty, 'Automaticky přidáno při vložení do zakázky.');
        } catch (Throwable $autoQueueError) {
            log_error('Auto procurement queue failed', 'procurement', $autoQueueError->getMessage());
        }
    }

    // Notify technician about added part
    $stmt = $pdo->prepare("SELECT o.technician_id, t.telegram_id, i.part_name 
                           FROM orders o 
                           LEFT JOIN technicians t ON o.technician_id = t.id 
                           JOIN inventory i ON i.id = ?
                           WHERE o.id = ?");
    $stmt->execute([$inventory_id, $order_id]);
    $notify = $stmt->fetch();
    
    if ($notify && $notify['telegram_id']) {
        $msg = sprintf(__('tg_part_added'), $order_id) . "\n";
        $msg .= sprintf(__('tg_part_added_detail'), $notify['part_name'], $qty) . "\n";
        if ($autoProcurementQueued) {
            $msg .= "Automaticky jsem díl dala i do nákupu, protože skladem je 0 ks.\n";
        }
        sendTelegramNotification($notify['telegram_id'], $msg);
    }

    echo json_encode(['success' => true, 'auto_procurement_queued' => $autoProcurementQueued, 'message' => $autoProcurementQueued ? 'Díl byl přidán a automaticky zařazen do nákupu.' : 'Díl byl přidán do zakázky.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
