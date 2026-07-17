<?php
/**
 * Odemčení kasy po nečinnosti — ověří heslo PRÁVĚ přihlášeného zaměstnance
 * (session zůstává, nejde o nový login). Po 5 špatných pokusech session končí
 * a jde se na plné přihlášení.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!crmCanUsePos()) {
    echo json_encode(['ok' => false, 'redirect' => 'login.php']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$password = (string)($_POST['password'] ?? '');
if ($password === '') {
    echo json_encode(['ok' => false, 'message' => 'Zadej heslo.']); exit;
}

$hash = null;
try {
    if (!empty($_SESSION['tech_id'])) {
        $st = $pdo->prepare("SELECT password FROM technicians WHERE id = ? AND is_active = 1");
        $st->execute([(int)$_SESSION['tech_id']]);
        $hash = $st->fetchColumn();
    } elseif (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $st = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $st->execute([(int)$_SESSION['user_id']]);
        $hash = $st->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('pos_unlock: ' . $e->getMessage());
}

if (!$hash) {   // účet mezitím zmizel/deaktivován → plné přihlášení
    echo json_encode(['ok' => false, 'redirect' => 'login.php']); exit;
}

if (password_verify($password, (string)$hash)) {
    unset($_SESSION['pos_unlock_fails']);
    echo json_encode(['ok' => true]); exit;
}

// špatné heslo: brzda proti zkoušení + po 5 pokusech konec session
usleep(600000);
$fails = (int)($_SESSION['pos_unlock_fails'] ?? 0) + 1;
$_SESSION['pos_unlock_fails'] = $fails;
if ($fails >= 5) {
    crmAuditLog('auth.logout', ['entity_type' => 'auth', 'summary' => 'Kasa: 5× špatné heslo při odemykání — odhlášeno']);
    session_destroy();
    echo json_encode(['ok' => false, 'redirect' => 'login.php']); exit;
}
echo json_encode(['ok' => false, 'message' => 'Špatné heslo (' . $fails . '/5).']);
