<?php
/**
 * Odemčení kasy po nečinnosti — ověří heslo PRÁVĚ přihlášeného zaměstnance
 * (session zůstává, nejde o nový login).
 * Blokace je PER ÚČET: po 10 špatných pokusech se na 15 minut zablokuje jen
 * konkrétní osoba (odemčení i login) — jiný zaměstnanec se přihlásí normálně
 * přes „Přihlásit jiného zaměstnance" a kasa jede dál.
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

$accountKey = !empty($_SESSION['tech_id']) ? 't' . (int)$_SESSION['tech_id']
    : (is_numeric($_SESSION['user_id'] ?? null) ? 'u' . (int)$_SESSION['user_id'] : '');
if ($accountKey === '') {
    echo json_encode(['ok' => false, 'redirect' => 'login.php']); exit;
}

// běžící blokace platí i při správném hesle — jinak by limit nic neznamenal
$remain = crmPosUnlockBlockRemaining($accountKey);
if ($remain > 0) {
    echo json_encode(['ok' => false, 'blocked' => true,
        'message' => 'Účet je zablokovaný ještě ' . (int)ceil($remain / 60) . ' min. Mezitím se může přihlásit jiný zaměstnanec.']);
    exit;
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
    } else {
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
    crmPosUnlockClearFails($accountKey);
    echo json_encode(['ok' => true]); exit;
}

// špatné heslo: brzda proti zkoušení + per-účet počítadlo (10 pokusů → 15 min blok)
usleep(600000);
[$fails, $blockSeconds] = crmPosUnlockRegisterFail($accountKey, 10, 900);
if ($blockSeconds > 0) {
    crmAuditLog('auth.logout', ['entity_type' => 'auth',
        'summary' => 'Kasa: 10× špatné heslo — účet „' . ($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') . '" na 15 minut zablokován']);
    echo json_encode(['ok' => false, 'blocked' => true,
        'message' => 'Účet je po 10 špatných pokusech na 15 minut zablokovaný. Mezitím se může přihlásit jiný zaměstnanec.']);
    exit;
}
echo json_encode(['ok' => false, 'message' => 'Špatné heslo (' . $fails . '/10).']);
