<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

if (!hasPermission('admin_access') && getCurrentStaffRole() !== 'manager') {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$techId = (int)($_POST['tech_id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));
if ($techId <= 0 || $message === '') {
    echo json_encode(['success' => false, 'message' => 'Missing data']);
    exit;
}

$stmt = $pdo->prepare('SELECT telegram_id, name FROM technicians WHERE id = ? LIMIT 1');
$stmt->execute([$techId]);
$tech = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tech || empty($tech['telegram_id'])) {
    echo json_encode(['success' => false, 'message' => 'Technik nemá Telegram ID']);
    exit;
}

$chatTag = 'fixer_chat_' . $techId;
$fromStaffId = (string)($_SESSION['tech_id'] ?? ($_SESSION['user_id'] ?? 0));
$fromName = trim((string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'CRM')));

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fixer_chat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chat_tag VARCHAR(64) NOT NULL,
        direction ENUM('outbound','inbound') NOT NULL,
        sender_type ENUM('crm','telegram') NOT NULL,
        sender_id VARCHAR(64) NOT NULL,
        sender_name VARCHAR(100) DEFAULT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_chat_tag (chat_tag),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$insert = $pdo->prepare("INSERT INTO fixer_chat_messages (chat_tag, direction, sender_type, sender_id, sender_name, message) VALUES (?, 'outbound', 'crm', ?, ?, ?)");
$insert->execute([$chatTag, $fromStaffId, $fromName, $message]);

$safe = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$telegramChatId = ltrim((string)$tech['telegram_id'], '@');
include_once __DIR__ . '/../includes/functions.php';
$ok = sendTelegramNotification($telegramChatId, '💬 <b>Fixer / CRM</b>\n\n' . $safe);
if (!$ok) {
    error_log('Fixer CRM -> Telegram send failed for chat_id=' . $telegramChatId);
}


if ($ok) {
    echo json_encode(['success' => true, 'message' => 'Odesláno do Telegramu']);
} else {
    echo json_encode(['success' => false, 'message' => 'Telegram message failed']);
}
