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
$stmt = $pdo->prepare('SELECT id, order_code, problem_description, created_at FROM orders WHERE id = ?');
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

echo json_encode([
    'ok' => true,
    'code' => orderDisplayCode($order),
    'defect' => $defect,
    'date' => $order['created_at'] ? date('d.m.Y', strtotime($order['created_at'])) : '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
