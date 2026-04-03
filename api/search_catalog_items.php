<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['results' => []]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

ensureProcurementSchema();

$q = trim((string)($_GET['q'] ?? $_GET['term'] ?? ''));
$supplier = trim((string)($_GET['supplier'] ?? ''));
$limit = max(1, min(30, (int)($_GET['limit'] ?? 20)));

try {
    $sql = "SELECT id, part_name, sku, quantity, sale_price, source_supplier FROM inventory WHERE 1=1";
    $params = [];

    if ($supplier !== '') {
        $sql .= " AND source_supplier = ?";
        $params[] = $supplier;
    }

    if ($q !== '') {
        $sql .= " AND (part_name LIKE ? OR sku LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY part_name ASC LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($items as $item) {
        $label = $item['part_name'];
        if (!empty($item['sku'])) {
            $label .= ' [' . $item['sku'] . ']';
        }
        if (!empty($item['sale_price'])) {
            $label .= ' — ' . number_format((float)$item['sale_price'], 2, '.', ' ') . ' Kč';
        }

        $results[] = [
            'id' => (int)$item['id'],
            'text' => $label,
            'part_name' => $item['part_name'],
            'sku' => $item['sku'] ?? '',
            'quantity' => (int)($item['quantity'] ?? 0),
            'sale_price' => (float)($item['sale_price'] ?? 0),
            'supplier_key' => $item['source_supplier'] ?? '',
        ];
    }

    echo json_encode(['results' => $results]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['results' => [], 'message' => $e->getMessage()]);
}
