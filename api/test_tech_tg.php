<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

ensureTechnicianTelegramSchema();

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$tech_id = $_POST['id'] ?? null;
if (!$tech_id) {
    echo json_encode(['success' => false, 'message' => 'No technician ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT name, telegram_id, telegram_username FROM technicians WHERE id = ?");
    $stmt->execute([$tech_id]);
    $tech = $stmt->fetch();

    if ($tech && $tech['telegram_id']) {
        if (!is_numeric($tech['telegram_id'])) {
            throw new Exception(__('tg_id_must_be_number'));
        }

        $msg = sprintf(__('tg_test_msg'), $tech['name']);
        $res = sendTelegramNotification($tech['telegram_id'], $msg);
        if ($res) {
            echo json_encode(['success' => true]);
        } else {
            throw new Exception(__('tg_send_error'));
        }
    } elseif ($tech && !empty($tech['telegram_username'])) {
        throw new Exception('Zatím je uložený jen @username. Zaměstnanec musí nejdřív napsat botovi, aby se jeho Telegram ID spárovalo automaticky.');
    } else {
        throw new Exception(__('tg_id_missing'));
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
