<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

if (!hasPermission('admin_access') && getCurrentStaffRole() !== 'manager') {
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$techId = (int)($_GET['tech_id'] ?? 0);
$since = (int)($_GET['since'] ?? 0);
if ($techId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing tech_id']);
    exit;
}

$chatTag = 'fixer_chat_' . $techId;

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

if ($since > 0) {
    $stmt = $pdo->prepare("SELECT direction, sender_type, sender_name, message, created_at FROM fixer_chat_messages WHERE chat_tag = ? AND UNIX_TIMESTAMP(created_at) > ? ORDER BY created_at ASC, id ASC LIMIT 200");
    $stmt->execute([$chatTag, $since]);
} else {
    $stmt = $pdo->prepare("SELECT direction, sender_type, sender_name, message, created_at FROM fixer_chat_messages WHERE chat_tag = ? ORDER BY created_at ASC, id ASC LIMIT 200");
    $stmt->execute([$chatTag]);
}
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'messages' => $messages]);
