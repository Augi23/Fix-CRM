<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$baseUrl = rtrim((string)get_setting('fixer_webhook_url', ''), '/');
$expectedToken = trim((string)get_setting('fixer_webhook_secret', ''));

if ($baseUrl === '' || $expectedToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing fixer_webhook_url or fixer_webhook_secret in system_settings']);
    exit;
}

$webhookUrl = $baseUrl . '/tg_webhook.php';
$url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/setWebhook';
$payload = http_build_query([
    'url' => $webhookUrl,
    'secret_token' => $expectedToken,
    'drop_pending_updates' => true,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 20,
]);
$response = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $err ?: 'curl failed']);
    exit;
}

echo $response;
