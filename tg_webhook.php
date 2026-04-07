<?php
/**
 * Telegram Bot Webhook Handler for Repair CRM
 * Group-safe Fixie bot: no CRM technician coupling in group chats.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

ensureTechnicianTelegramSchema();

$content = file_get_contents('php://input');
$update = json_decode($content, true);
if (!$update) exit;

$message = $update['message'] ?? ($update['edited_message'] ?? null);
if (!$message) exit;

$chatId = (string)($message['chat']['id'] ?? '');
$chatType = (string)($message['chat']['type'] ?? 'private');
$text = trim((string)($message['text'] ?? ''));
$botUsername = trim((string)get_setting('fixer_bot_username', ''));
$fromId = (string)($message['from']['id'] ?? '');
$fromUsername = strtolower(ltrim(trim((string)($message['from']['username'] ?? '')), '@'));
$senderName = $message['from']['first_name'] ?? ($message['from']['username'] ?? 'telegram');

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

$chatTag = ($chatType === 'private') ? 'fixie_private_' . $chatId : 'fixie_group_' . $chatId;
$insert = $pdo->prepare("INSERT INTO fixer_chat_messages (chat_tag, direction, sender_type, sender_id, sender_name, message) VALUES (?, 'inbound', 'telegram', ?, ?, ?)");
$insert->execute([$chatTag, $fromId, $senderName, $text]);

$pairedTech = null;
$pairedByUsername = false;
try {
    $stmt = $pdo->prepare("SELECT id, name, telegram_id, telegram_username FROM technicians WHERE telegram_id = ? LIMIT 1");
    $stmt->execute([$chatId]);
    $pairedTech = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$pairedTech && $chatType === 'private' && $fromUsername !== '') {
        $stmt = $pdo->prepare("SELECT id, name, telegram_id, telegram_username FROM technicians WHERE LOWER(telegram_username) = LOWER(?) LIMIT 1");
        $stmt->execute([$fromUsername]);
        $pairedTech = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($pairedTech) {
            $upd = $pdo->prepare("UPDATE technicians SET telegram_id = ?, telegram_username = ? WHERE id = ?");
            $upd->execute([$chatId, $fromUsername, $pairedTech['id']]);
            $pairedByUsername = true;
            $pairedTech['telegram_id'] = $chatId;
            $pairedTech['telegram_username'] = $fromUsername;
        }
    } elseif ($pairedTech && $fromUsername !== '' && strtolower((string)($pairedTech['telegram_username'] ?? '')) !== $fromUsername) {
        $upd = $pdo->prepare("UPDATE technicians SET telegram_username = ? WHERE id = ?");
        $upd->execute([$fromUsername, $pairedTech['id']]);
        $pairedTech['telegram_username'] = $fromUsername;
    }
} catch (Throwable $e) {
}

if ($chatType === 'group' || $chatType === 'supergroup') {
    $mentioned = ($botUsername !== '' && stripos($text, '@' . $botUsername) !== false);
    $cmd = strtolower(strtok($text, ' '));
    if ($mentioned || strpos($text, '/') === 0) {
        if ($mentioned) {
            $text = trim(str_ireplace('@' . $botUsername, '', $text));
            $cmd = strtolower(strtok($text, ' '));
        }
        if ($cmd === '/help' || $cmd === '/start') {
            sendTelegramNotification($chatId, 'Jsem Fixie 3.0. Ve skupině fungují /help, /my a /view [ID].');
            exit;
        }
        if ($cmd === '/my') {
            sendTelegramNotification($chatId, 'Ve skupině nepoužívám /my. Napiš mi do soukromí.');
            exit;
        }
        if (preg_match('/^\/view\s+(\d+)$/', $text, $m)) {
            sendTelegramNotification($chatId, 'Ve skupině nepoužívám /view. Napiš mi do soukromí.');
            exit;
        }
        sendTelegramNotification($chatId, 'Jsem Fixie 3.0. Zkus /help.');
        exit;
    }
    exit;
}

// private chat
if ($text === '/start' || $text === '/help' || $text === '') {
    if ($pairedTech) {
        $msg = 'Ahoj, účet je spárovaný s CRM';
        if (!empty($pairedTech['name'])) {
            $msg .= ' pro zaměstnance ' . $pairedTech['name'];
        }
        $msg .= '.';
        if ($pairedByUsername) {
            $msg .= "\n\nPrávě jsem si uložila tvoje Telegram ID, takže notifikace z CRM už budou chodit sem.";
        }
        sendTelegramNotification($chatId, $msg);
    } else {
        $msg = 'Ahoj, já jsem Fixie 3.0.';
        if ($fromUsername !== '') {
            $msg .= "\n\nPokud tě má CRM uloženého pod @" . $fromUsername . ', právě jsi připravený na spárování.';
        } else {
            $msg .= "\n\nPro automatické spárování si nastav na Telegramu username nebo požádej admina o ruční vložení Telegram ID.";
        }
        sendTelegramNotification($chatId, $msg);
    }
    exit;
}

if ($pairedByUsername) {
    sendTelegramNotification($chatId, 'Hotovo, CRM si uložilo tvoje Telegram ID. Odteď ti sem můžou chodit notifikace.');
    exit;
}

sendTelegramNotification($chatId, 'Jsem Fixie 3.0. Napiš /help.');
