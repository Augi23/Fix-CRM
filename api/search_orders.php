<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['results' => []]);
    exit;
}

$q = trim((string)($_GET['q'] ?? $_GET['term'] ?? ''));
$limit = max(1, min(30, (int)($_GET['limit'] ?? 20)));

try {
    $closedStatuses = implode(',', array_map(static fn($status) => $pdo->quote($status), array_merge(getOrderStatusList('collected'), getOrderStatusList('cancelled'))));
    $where = ["o.status NOT IN ($closedStatuses)"];
    $params = [];

    addOrderBranchScope($where, $params, 'o');

    if ($q !== '') {
        $where[] = '(o.order_code LIKE ? OR o.id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_brand LIKE ? OR o.device_model LIKE ?)';
        $like = '%' . $q . '%';
        for ($i = 0; $i < 7; $i++) {
            $params[] = $like;
        }
    }

    $sql = "SELECT o.id, o.order_code, o.status, o.device_brand, o.device_model, c.first_name, c.last_name, t.name AS tech_name
            FROM orders o
            JOIN customers c ON c.id = o.customer_id
            LEFT JOIN technicians t ON t.id = o.technician_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                (CASE WHEN o.order_code REGEXP '^[A-Za-z]+[0-9]+$' THEN 0 ELSE 1 END),
                CAST(NULLIF(REGEXP_REPLACE(COALESCE(o.order_code, ''), '[^0-9]', ''), '') AS UNSIGNED) DESC,
                o.updated_at DESC,
                o.id DESC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($rows as $row) {
        $displayCode = trim((string)($row['order_code'] ?? ''));
        $label = ($displayCode !== '' ? $displayCode : '#' . (int)$row['id']) . ' ' . trim((string)($row['device_brand'] ?? '') . ' ' . (string)($row['device_model'] ?? ''));
        $client = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        if ($client !== '') {
            $label .= ' · ' . $client;
        }
        if (!empty($row['status'])) {
            $label .= ' · ' . $row['status'];
        }
        if (!empty($row['tech_name'])) {
            $label .= ' · ' . $row['tech_name'];
        }

        $results[] = [
            'id' => (int)$row['id'],
            'text' => $label,
            'status' => $row['status'] ?? '',
            'device' => trim((string)($row['device_brand'] ?? '') . ' ' . (string)($row['device_model'] ?? '')),
            'client' => $client,
        ];
    }

    echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['results' => [], 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
