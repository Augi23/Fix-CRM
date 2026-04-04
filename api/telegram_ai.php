<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function telegramApiRequest(string $method, array $payload): array {
    if (!defined('TG_BOT_TOKEN') || TG_BOT_TOKEN === '') {
        return ['ok' => false, 'error' => 'Telegram token not configured'];
    }

    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $err ?: 'curl failed'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid Telegram response'];
    }

    return $decoded;
}

$token = $_SERVER['HTTP_X_FIXER_TOKEN'] ?? ($_POST['token'] ?? '');
$expected = trim((string)get_setting('fixer_api_token', ''));
if ($expected === '' || !hash_equals($expected, (string)$token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$action = $_POST['action'] ?? '';
$text = trim((string)($_POST['text'] ?? ''));
$chatId = trim((string)($_POST['chat_id'] ?? ''));
$voice = !empty($_POST['voice']);

if ($chatId === '' || $text === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing chat_id or text']);
    exit;
}

if ($voice) {
    $res = telegramApiRequest('sendVoice', [
        'chat_id' => $chatId,
        'voice' => $text,
    ]);
    echo json_encode($res);
    exit;
}

$res = telegramApiRequest('sendMessage', [
    'chat_id' => $chatId,
    'text' => $text,
    'parse_mode' => 'HTML',
    'disable_web_page_preview' => true,
]);

echo json_encode($res);
