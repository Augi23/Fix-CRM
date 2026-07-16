<?php
/** „Vzít díl skenem QR": zapamatuje si na 30 minut zakázku, na kterou se bude
 *  vydávat. Ukládá se PER-UŽIVATEL do DB (system_settings), NE do PHP session —
 *  typický postup je klik na počítači a sken telefonem (jiný prohlížeč/session).
 *  order_id=0 = zrušení připravené zakázky. */
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

$key = 'qr_arm_' . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_SESSION['user_id']);
$order_id = (int)($_POST['order_id'] ?? 0);

if ($order_id === 0) {
    set_setting($key, '');
    echo json_encode(['success' => true, 'message' => 'Připravená zakázka zrušena.'], JSON_UNESCAPED_UNICODE); exit;
}

$stmt = $pdo->prepare("SELECT id, order_code, status FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$o = $stmt->fetch();
if (!$o) { echo json_encode(['success' => false, 'message' => 'Zakázka nenalezena.']); exit; }
if (isOrderStatusIn((string)$o['status'], 'collected')) {
    echo json_encode(['success' => false, 'message' => 'Zakázka už je vydaná — díl na ni nejde připravit.'], JSON_UNESCAPED_UNICODE); exit;
}
if (isOrderStatusIn((string)$o['status'], 'cancelled')) {
    echo json_encode(['success' => false, 'message' => 'Zakázka je stornovaná — díl na ni nejde připravit.'], JSON_UNESCAPED_UNICODE); exit;
}

set_setting($key, json_encode(['id' => (int)$o['id'], 'code' => (string)$o['order_code'], 'expires' => time() + 1800]));
echo json_encode(['success' => true, 'message' => 'Připraveno — teď naskenuj QR kód dílu na regálu (klidně mobilem).'], JSON_UNESCAPED_UNICODE);
