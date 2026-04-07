<?php
/**
 * Telegram Bot Webhook Handler for Repair CRM
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$content = file_get_contents('php://input');
$update = json_decode($content, true);
if (!$update) exit;

$message = $update['message'] ?? ($update['edited_message'] ?? null);
if (!$message) exit;

$chatId = (string)($message['chat']['id'] ?? '');
$chatType = (string)($message['chat']['type'] ?? 'private');
$text = trim((string)($message['text'] ?? ''));
$botUsername = trim((string)get_setting('fixer_bot_username', ''));

$mentioned = ($botUsername !== '' && stripos($text, '@' . $botUsername) !== false);
$cleanText = $text;
if ($mentioned) {
    $cleanText = trim(str_ireplace('@' . $botUsername, '', $cleanText));
}

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

$chatTag = ($chatType === 'private') ? ('fixer_private_' . $chatId) : ('fixer_group_' . $chatId);
$fromId = (string)($message['from']['id'] ?? '');
$senderName = $message['from']['first_name'] ?? ($message['from']['username'] ?? 'telegram');

$insert = $pdo->prepare("INSERT INTO fixer_chat_messages (chat_tag, direction, sender_type, sender_id, sender_name, message) VALUES (?, 'inbound', 'telegram', ?, ?, ?)");
$insert->execute([$chatTag, $fromId, $senderName, $cleanText]);

if ($chatType === 'group' || $chatType === 'supergroup') {
    if (strpos($text, '/') === 0 || $mentioned) {
        $cmd = strtolower(strtok($cleanText, ' '));
        if ($cmd === '/help' || $cmd === '/start') {
            sendTelegramNotification($chatId, 'Jsem @' . $botUsername . '.');
            exit;
        }
        if ($cmd === '/my') {
            sendTelegramNotification($chatId, '');
            exit;
        }
        if (preg_match('/^\/view\s+(\d+)$/', $cleanText, $m)) {
            sendTelegramNotification($chatId, '');
            exit;
        }
        sendTelegramNotification($chatId, '');
    }
    exit;
}

if ($text === '/start' || $text === '/help' || $text === '') {
    sendTelegramNotification($chatId, '');
    exit;
}

sendTelegramNotification($chatId, 'Jsem Fixie 3.0. Napiš /help.');
