<?php
/* Registrace APNs device tokenu z nativní iOS appky pro přihlášeného uživatele.
   POST JSON: { device_token, platform?, app_version? }  → { ok: true } */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;

// APNs tokens are hex; strip anything else defensively.
$token      = preg_replace('/[^0-9a-fA-F]/', '', (string)($in['device_token'] ?? ''));
$platform   = substr((string)($in['platform'] ?? 'ios'), 0, 20);
$appVersion = substr((string)($in['app_version'] ?? ''), 0, 40);

if (strlen($token) < 32) {
    echo json_encode(['ok' => false, 'error' => 'bad_token']);
    exit;
}

require_once __DIR__ . '/../includes/push.php';
ensurePushTokensTable($pdo);

try {
    $stmt = $pdo->prepare(
        "INSERT INTO push_tokens (user_id, device_token, platform, app_version)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            platform = VALUES(platform),
            app_version = VALUES(app_version),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([(int)$_SESSION['user_id'], $token, $platform, $appVersion]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
