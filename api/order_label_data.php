<?php
/**
 * API: data pro tisk štítku zakázky na Brother QL-810W (přes lokální můstek).
 * Vrací: code (č. zakázky pro Code128), defect (krátký popis závady), date (datum přijetí).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT o.id, o.order_code, o.problem_description, o.created_at,
    TRIM(CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, ""))) AS client_name, c.company
    FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'order not found']);
    exit;
}

$defect = trim((string)($order['problem_description'] ?? ''));
if (mb_strlen($defect) > 80) {
    $defect = mb_substr($defect, 0, 77) . '…';
}

$client = trim((string)($order['client_name'] ?? ''));
if ($client === '') { $client = trim((string)($order['company'] ?? '')); }

echo json_encode([
    'ok' => true,
    'code' => orderDisplayCode($order),
    'client' => $client,
    'defect' => $defect,
    'date' => $order['created_at'] ? date('d.m.Y', strtotime($order['created_at'])) : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
