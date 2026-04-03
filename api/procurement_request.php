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

        if (!in_array($supplierKey, array_keys(getSupplierCatalogs()), true)) {
            throw new Exception('Neplatný dodavatel.');
        }

        if ($inventoryId > 0) {
            $stmt = $pdo->prepare("SELECT id, part_name, sku, source_supplier FROM inventory WHERE id = ? LIMIT 1");
            $stmt->execute([$inventoryId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($inv) {
                $itemName = $itemName !== '' ? $itemName : (string)$inv['part_name'];
                $sku = $sku !== '' ? $sku : (string)($inv['sku'] ?? '');
                if (!empty($inv['source_supplier'])) {
                    $supplierKey = (string)$inv['source_supplier'];
                }
            }
        }

        $itemName = trim($itemName);
        if ($itemName === '') {
            throw new Exception('Zadejte název dílu nebo vyberte položku z katalogu.');
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

        echo json_encode(['success' => true, 'message' => 'Požadavek uložen.']);
        exit;
    }

    if ($action === 'update') {
        if (!hasPermission('procurement_manage')) {
            throw new Exception('Nemáte oprávnění objednávat nebo měnit stav nákupu.');
        }

        $id = (int)($_POST['id'] ?? 0);
        $status = trim((string)($_POST['status'] ?? 'pending'));
        $allowed = ['pending', 'ordered', 'received', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new Exception('Neplatný stav.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, status, inventory_id, quantity, item_name FROM purchase_requests WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception('Požadavek nebyl nalezen.');
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

        echo json_encode(['success' => true, 'message' => 'Stav aktualizován.']);
        exit;
    }

    if ($action === 'delete') {
        if (!hasPermission('procurement_manage')) {
            throw new Exception('Nemáte oprávnění mazat požadavky v nákupní frontě.');
        }

        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM purchase_requests WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Požadavek smazán.']);
        exit;
    }

    throw new Exception('Neznámá akce.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
