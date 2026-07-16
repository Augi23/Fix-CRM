<?php
/** „Vzít díl skenem QR": zapamatuje si na 30 minut zakázku, na kterou se
 *  bude vydávat — stránka skladu (sklad.php) ji pak nabídne předvybranou. */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) { echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit; }
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, order_code FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$o = $stmt->fetch();
if (!$o) { echo json_encode(['success' => false, 'message' => 'Zakázka nenalezena.']); exit; }

$_SESSION['qr_issue_order'] = ['id' => (int)$o['id'], 'code' => (string)$o['order_code'], 'expires' => time() + 1800];
echo json_encode(['success' => true, 'message' => 'Připraveno — teď naskenuj QR kód dílu na regálu.'], JSON_UNESCAPED_UNICODE);
