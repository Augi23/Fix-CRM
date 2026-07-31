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

// Reklamace: stejná data v témže tvaru — můstek na počítači obsluhy tiskne
// generický štítek {code, defect, date, client}, takže RK štítek zvládne taky.
$cmplId = (int)($_GET['complaint_id'] ?? 0);
if ($cmplId > 0) {
    $cs = $pdo->prepare('SELECT k.complaint_code, k.device, k.complaint_reason, k.created_at,
        TRIM(CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, ""))) AS client_name,
        o.branch_id, o.technician_id
        FROM complaints k
        LEFT JOIN customers c ON c.id = k.customer_id
        LEFT JOIN orders o ON o.id = k.order_id
        WHERE k.id = ?');
    $cs->execute([$cmplId]);
    $cm = $cs->fetch();
    if (!$cm) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'complaint not found']); exit;
    }
    // pobočková izolace stejně jako u serverového tisku — jinak by šlo iterací id
    // vytáhnout jména klientů a závady z obou poboček
    if (!empty($cm['branch_id']) && !canAccessOrderBranch($cm)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
    }
    $cDefect = trim((string)($cm['device'] ?? '') . ' · ' . (string)($cm['complaint_reason'] ?? ''), ' ·');
    if (mb_strlen($cDefect) > 80) { $cDefect = mb_substr($cDefect, 0, 77) . '…'; }
    echo json_encode([
        'ok' => true,
        'code' => (string)$cm['complaint_code'],
        'client' => trim((string)($cm['client_name'] ?? '')),
        'defect' => $cDefect,
        'date' => $cm['created_at'] ? date('d.m.Y', strtotime((string)$cm['created_at'])) : '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT o.id, o.order_code, o.problem_description, o.created_at, o.branch_id, o.technician_id,
    TRIM(CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, ""))) AS client_name, c.company
    FROM orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
$stmt->execute([$id]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'order not found']);
    exit;
}

if (!canAccessOrderBranch($order)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']); exit;
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
