<?php
/* Registrace APNs device tokenu z nativní iOS appky pro přihlášeného uživatele.
   POST JSON: { device_token, platform?, app_version? }  → { ok: true } */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

// Dual-login: technik má v session user_id ve tvaru „t15" (viz login.php), takže
// (int) z něj udělá 0. Token pak skončil ve společné přihrádce user_id = 0 —
// cílený push technikovi nedorazil vůbec a hrozilo doručení cizímu člověku.
// Identitu proto bereme stejně jako zbytek CRM: technik má přednost.
$staffKey = function_exists('crmStaffKey') ? crmStaffKey() : '';
$staffId  = !empty($_SESSION['tech_id'])
    ? (int)$_SESSION['tech_id']
    : (int)(is_numeric($_SESSION['user_id'] ?? null) ? $_SESSION['user_id'] : 0);

if ($staffId <= 0 || $staffKey === '') {
    // Raději token neuložit než ho uložit pod nulu — takový řádek je nedoručitelný
    // a sdílel by ho s každým dalším pracovníkem bez rozpoznané identity.
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unknown_identity']);
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

// Samotné user_id nerozliší uživatele z tabulky `users` od technika z `technicians`
// (obě řady id začínají od 1). Vedle něj proto držíme i jednoznačný klíč
// „user:3" / „tech:3", aby se cílený push nemohl trefit do jiné osoby.
$hasStaffKey = false;
try {
    $hasStaffKey = (bool)$pdo->query("SHOW COLUMNS FROM push_tokens LIKE 'staff_key'")->fetch();
    if (!$hasStaffKey) {
        $pdo->exec("ALTER TABLE push_tokens ADD COLUMN staff_key VARCHAR(32) NULL AFTER user_id");
        $hasStaffKey = true;
        try { $pdo->exec("CREATE INDEX idx_push_staff_key ON push_tokens (staff_key)"); }
        catch (Throwable $eIdx) { /* index je jen pro rychlost, sloupec už existuje */ }
    }
} catch (Throwable $e) { error_log('register_push_token staff_key: ' . $e->getMessage()); }

try {
    if ($hasStaffKey) {
        $stmt = $pdo->prepare(
            "INSERT INTO push_tokens (user_id, staff_key, device_token, platform, app_version)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                staff_key = VALUES(staff_key),
                platform = VALUES(platform),
                app_version = VALUES(app_version),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$staffId, $staffKey, $token, $platform, $appVersion]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO push_tokens (user_id, device_token, platform, app_version)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                platform = VALUES(platform),
                app_version = VALUES(app_version),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$staffId, $token, $platform, $appVersion]);
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
