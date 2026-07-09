<?php
/**
 * Interní akce nad rezervacemi z webu (orders.php panel).
 * action: dismiss | restore | converted (+ order_id)
 */
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

ensureWebBookingsSchema();

$action = trim((string)($_POST['action'] ?? ''));
$id = (int)($_POST['id'] ?? 0);

try {
    if ($id <= 0) { throw new Exception('Missing id'); }

    if ($action === 'dismiss') {
        $pdo->prepare("UPDATE web_bookings SET status = 'dismissed' WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'restore') {
        $pdo->prepare("UPDATE web_bookings SET status = 'new' WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'converted') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $pdo->prepare("UPDATE web_bookings SET status = 'converted', order_id = ? WHERE id = ?")
            ->execute([$orderId > 0 ? $orderId : null, $id]);
        echo json_encode(['success' => true]);
        exit;
    }
    throw new Exception('Unknown action');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
