<?php
/** Příjem hlášení od můstku na Macu (device-bridge/). Ověřuje se sdíleným
 *  tokenem z Nastavení → Systém → Integrace, ne přihlášením — můstek běží
 *  jako služba a žádnou session nemá.
 *  POST JSON { token, station, device:{…} } → { ok }  */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/device_bridge.php';
require_once __DIR__ . '/../includes/rate_limit.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// můstek hlásí po 3 s jen při změně, jinak po 30 s → 120/h s rezervou
checkApiRateLimit('device_push', 600, 3600);

$raw = (string)file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Neplatná data.'], JSON_UNESCAPED_UNICODE); exit;
}

$token = (string)($in['token'] ?? '');
$expected = trim((string)get_setting('device_bridge_token', ''));
// hash_equals: porovnání v konstantním čase, ať token nejde uhádat po znacích
if ($expected === '' || strlen($token) < 20 || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Neplatný token můstku.'], JSON_UNESCAPED_UNICODE); exit;
}

$device = is_array($in['device'] ?? null) ? $in['device'] : null;
if ($device !== null && count($device) > 40) { $device = array_slice($device, 0, 40, true); }
$ok = afxDeviceBridgeStore((string)($in['station'] ?? ''), $device, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
