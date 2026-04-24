<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

ensureProcurementSchema();

$action = trim((string)($_POST['action'] ?? 'add'));

try {
    if ($action === 'add') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $supplierKey = trim((string)($_POST['supplier_key'] ?? ''));
        $inventoryId = (int)($_POST['inventory_id'] ?? 0);
        $itemName = trim((string)($_POST['item_name'] ?? ''));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $priority = trim((string)($_POST['priority'] ?? 'this_week'));
        $notes = trim((string)($_POST['notes'] ?? ''));

        $validSuppliers = array_keys(getSupplierCatalogs());

        if ($inventoryId > 0) {
            $stmt = $pdo->prepare("SELECT id, part_name, sku, source_supplier, source_url FROM inventory WHERE id = ? LIMIT 1");
            $stmt->execute([$inventoryId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($inv) {
                $itemName = $itemName !== '' ? $itemName : (string)$inv['part_name'];
                $sku = $sku !== '' ? $sku : (string)($inv['sku'] ?? '');

                $resolvedSupplier = trim((string)($inv['source_supplier'] ?? ''));
                if ($resolvedSupplier === '') {
                    $resolvedSupplier = supplierKeyFromUrl((string)($inv['source_url'] ?? ''));
                }
                if ($resolvedSupplier === '') {
                    $catalogUrl = trim((string)get_setting('inventory_catalog_url', ''));
                    $resolvedSupplier = supplierKeyFromUrl($catalogUrl);
                }
                if ($resolvedSupplier === '' && !empty($validSuppliers)) {
                    $resolvedSupplier = $validSuppliers[0];
                }
                if ($resolvedSupplier !== '') {
                    $supplierKey = $resolvedSupplier;
                }
            }
        }

        if (!in_array($supplierKey, $validSuppliers, true)) {
            throw new Exception('Invalid supplier.');
        }

        $itemName = trim($itemName);
        if ($itemName === '') {
            throw new Exception('Enter part name or select an item from catalog.');
        }

        $allowedPriority = ['today', 'this_week', 'later'];
        if (!in_array($priority, $allowedPriority, true)) {
            $priority = 'this_week';
        }

        $stmt = $pdo->prepare("INSERT INTO purchase_requests (order_id, supplier_key, inventory_id, item_name, sku, quantity, priority, status, notes, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
        $stmt->execute([
            $orderId > 0 ? $orderId : null,
            $supplierKey,
            $inventoryId > 0 ? $inventoryId : null,
            $itemName,
            $sku !== '' ? $sku : null,
            $quantity,
            $priority,
            $notes !== '' ? $notes : null,
            $_SESSION['user_id'] ?? ($_SESSION['tech_id'] ?? null),
        ]);

        echo json_encode(['success' => true, 'message' => 'Request saved.']);
        exit;
    }

    if ($action === 'update') {
        if (!hasPermission('procurement_manage')) {
            throw new Exception('You do not have permission to order or change procurement status.');
        }

        $id = (int)($_POST['id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'pending'));
        $allowed = ['pending', 'ordered', 'received', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new Exception('Invalid status.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, status, inventory_id, quantity, item_name FROM purchase_requests WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception('Request not found.');
        }

        $stmt = $pdo->prepare("UPDATE purchase_requests SET status = ?, ordered_by = CASE WHEN ? = 'ordered' THEN ? ELSE ordered_by END, ordered_at = CASE WHEN ? = 'ordered' AND ordered_at IS NULL THEN NOW() ELSE ordered_at END, received_at = CASE WHEN ? = 'received' AND received_at IS NULL THEN NOW() ELSE received_at END WHERE id = ?");
        $stmt->execute([
            $status,
            $status,
            $_SESSION['user_id'] ?? ($_SESSION['tech_id'] ?? null),
            $status,
            $status,
            $id,
        ]);

        $wasReceived = (($request['status'] ?? '') === 'received');
        $isNowReceived = ($status === 'received');
        $inventoryId = (int)($request['inventory_id'] ?? 0);
        $quantity = max(1, (int)($request['quantity'] ?? 1));

        if (!$wasReceived && $isNowReceived && $inventoryId > 0) {
            changeInventoryQuantity($inventoryId, $quantity);
        }

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Status updated.']);
        exit;
    }

    if ($action === 'assign_order') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $orderId = (int)($_POST['order_id'] ?? 0);
        $qty = max(1, (int)($_POST['quantity'] ?? 1));

        if ($requestId <= 0 || $orderId <= 0) {
            throw new Exception('Missing request or target order.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, order_id, status, inventory_id, quantity, item_name FROM purchase_requests WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception('Request not found.');
        }

        if (($request['status'] ?? '') === 'cancelled') {
            throw new Exception('Cancelled request cannot be assigned to an order.');
        }

        $inventoryId = (int)($request['inventory_id'] ?? 0);
        if ($inventoryId <= 0) {
            throw new Exception('This request is not linked to a catalog part.');
        }

        $stmt = $pdo->prepare("SELECT id, technician_id, status FROM orders WHERE id = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new Exception('Order not found.');
        }

        if (in_array((string)($order['status'] ?? ''), ['Collected', 'Cancelled'], true)) {
            throw new Exception('Part can only be assigned to the current order.');
        }

        if (($_SESSION['role'] ?? '') === 'technician' && !hasPermission('view_all_orders') && (int)($order['technician_id'] ?? 0) !== (int)($_SESSION['tech_id'] ?? 0)) {
            throw new Exception(__('access_denied_msg'));
        }

        $stmt = $pdo->prepare("SELECT sale_price, quantity FROM inventory WHERE id = ? LIMIT 1");
        $stmt->execute([$inventoryId]);
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inventory) {
            throw new Exception('Part was not found in stock.');
        }

        $stockQty = (int)($inventory['quantity'] ?? 0);
        $requestStatus = (string)($request['status'] ?? 'pending');
        if (getCurrentStaffRole() === 'engineer' && $stockQty <= 0 && !in_array($requestStatus, ['ordered', 'received'], true)) {
            throw new Exception('A technician can assign an out-of-stock part only if it is already ordered or received.');
        }

        $stmt = $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt->execute([$orderId, $inventoryId, $qty, (float)($inventory['sale_price'] ?? 0)]);

        $stmt = $pdo->prepare("UPDATE purchase_requests SET order_id = ? WHERE id = ?");
        $stmt->execute([$orderId, $requestId]);

        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'Part assigned to order.']);
        exit;
    }

    if ($action === 'delete') {
        if (!hasPermission('procurement_manage')) {
            throw new Exception('You do not have permission to delete requests in procurement queue.');
        }

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM purchase_requests WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Request deleted.']);
        exit;
    }

    throw new Exception('Unknown action.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
