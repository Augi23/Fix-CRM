<?php
/**
 * Telegram Bot Webhook Handler for Repair CRM
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$message = $update['message'] ?? null;
if (!$message) {
    $message = $update['edited_message'] ?? null;
}
if (!$message) exit;

$chatId = $message['chat']['id'];
$text = trim((string)($message['text'] ?? ''));
$botUsername = trim((string)get_setting('fixer_bot_username', ''));
$fromId = $message['from']['id'];
$username = isset($message['from']['username']) ? '@' . $message['from']['username'] : '';

$stmt = $pdo->prepare("SELECT id, name FROM technicians WHERE (telegram_id = ? OR telegram_id = ?) AND is_active = 1");
$stmt->execute([$fromId, $username !== '' ? $username : '---']);
$tech = $stmt->fetch();

if (!$tech) {
    $msg = "❌ Nejseš ještě propojený s CRM.\n\n";
    $msg .= "Tvoje Telegram ID: <code>$fromId</code>\n";
    if ($username !== '') {
        $msg .= "Tvůj nick: <code>$username</code>\n";
    }
    $msg .= "\nPošli to administrátorovi, ať tě přidá jako technika.\n";
    sendTelegramNotification($chatId, $msg);
    exit;
}

if ($text === '/start' || $text === '/help' || $text === '') {
    $msg = "👋 Ahoj <b>{$tech['name']}</b>, jsem Fixer.\n\n";
    $msg .= "Pomůžu ti s přehledem zakázek, stavem práce i rychlou komunikací.\n\n";
    $msg .= "<b>Rychlé příkazy:</b>\n";
    $msg .= "• /my — tvoje aktivní zakázky\n";
    $msg .= "• /view [ID] — detail zakázky\n";
    $msg .= "• /me — profil v CRM\n";
    $msg .= "\n<small>Když napíšeš normální zprávu, odpovím stručně a věcně.</small>";
    sendTelegramNotification($chatId, $msg);
    exit;
}

if ($text === '/my') {
    $stmt = $pdo->prepare("SELECT id, device_brand, device_model, status FROM orders WHERE technician_id = ? AND status NOT IN ('Collected', 'Cancelled') ORDER BY created_at DESC");
    $stmt->execute([$tech['id']]);
    $orders = $stmt->fetchAll();
    
    if (empty($orders)) {
        sendTelegramNotification($chatId, "✅ Nemáš žádné aktivní zakázky.");
    } else {
        $msg = "📂 <b>Tvoje aktivní zakázky:</b>\n\n";
        foreach ($orders as $o) {
            $msg .= "#{$o['id']} - {$o['device_brand']} {$o['device_model']} [{$o['status']}]\n";
        }
        sendTelegramNotification($chatId, $msg);
    }
    exit;
}

if (preg_match('/^\/view (\d+)$/', $text, $matches)) {
    $orderId = (int)$matches[1];
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ? AND o.technician_id = ?");
    $stmt->execute([$orderId, $tech['id']]);
    $order = $stmt->fetch();
    
    if (!$order) {
        sendTelegramNotification($chatId, "❌ Zakázka #$orderId nebyla nalezena nebo není přiřazená tobě.");
    } else {
        $msg = "📑 <b>Zakázka #{$order['id']}</b>\n";
        $msg .= "👤 Klient: {$order['first_name']} {$order['last_name']} ({$order['phone']})\n";
        $msg .= "📱 Zařízení: {$order['device_brand']} {$order['device_model']}\n";
        $msg .= "📝 Problém: {$order['problem_description']}\n";
        $msg .= "📍 Stav: {$order['status']}\n";
        if (!empty($order['final_cost'])) {
            $msg .= "💰 Cena: " . formatMoney((float)$order['final_cost']) . "\n";
        }
        sendTelegramNotification($chatId, $msg);
    }
    exit;
}

if (preg_match('/^(\/whoami|\/me)$/', $text)) {
    $msg = "🪪 <b>Profil v CRM</b>\n";
    $msg .= "Jméno: {$tech['name']}\n";
    $msg .= "Telegram ID: <code>$fromId</code>\n";
    $msg .= "Status: aktivní\n";
    sendTelegramNotification($chatId, $msg);
    exit;
}

if ($botUsername !== '') {
    sendTelegramNotification($chatId, "Jsem Fixer. Když chceš pomoct, napiš /help nebo /my.");
} else {
    sendTelegramNotification($chatId, "Nerozumím příkazu. Zkus /help, /my nebo /view [ID].");
}
