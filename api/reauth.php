<?php
/**
 * Obnovení přihlášení („neplatný token" flow) — když zaměstnanci vyprší session
 * nebo se rozejde CSRF token, místo záhadné hlášky vyskočí okno s heslem a tenhle
 * endpoint session obnoví (regeneruje) a vydá čerstvý CSRF token. Nejde o odhlášení
 * — rozdělaná práce na stránce zůstává, jen se obnoví přihlášení.
 *
 * DŮLEŽITÉ: tento endpoint se ZÁMĚRNĚ neověřuje CSRF tokenem — právě neplatný token
 * je důvod, proč sem uživatel jde. Chrání ho ověření hesla + brzda proti zkoušení.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

// na klientské doméně (applefix.help) se zaměstnanci nepřihlašují — stejně jako login.php
if (crmIsClientDomain()) {
    echo json_encode(['success' => false, 'message' => 'Zde se zaměstnanci nepřihlašují.']); exit;
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'Zadej jméno i heslo.']); exit;
}

// IP brzda přes SDÍLENOU tabulku login_attempts (5 pokusů / 5 min) — kdo je
// blokovaný na login.php, nesmí to obejít přes reauth.php a naopak
$reauthIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$ipBlocked = false;
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $c->execute([$reauthIp]);
    $ipBlocked = (int)$c->fetchColumn() >= 5;
} catch (Throwable $e) {}
if ($ipBlocked) {
    echo json_encode(['success' => false, 'message' => 'Příliš mnoho pokusů z tohoto zařízení. Zkus to za pár minut, nebo se přihlas na přihlašovací stránce.']); exit;
}

$recordFail = static function () use ($pdo, $reauthIp) {
    try { $pdo->prepare("INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW())")->execute([$reauthIp]); } catch (Throwable $e) {}
};

$ok = false; $sessionData = null;
try {
    ensureUsersBranchColumn();

    // 1) users / admin účet
    $st = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $st->execute([$username]);
    $user = $st->fetch();
    if ($user && password_verify($password, (string)$user['password'])) {
        $sessionData = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => 'admin',
            'full_name' => $user['full_name'],
            'tech_id' => null,
            'branch_id' => (int)($user['branch_id'] ?? 0) ?: getDefaultBranchId(),
        ];
        $ok = true;
    }

    // 2) technician účet (dual-login)
    if (!$ok) {
        $st = $pdo->prepare("SELECT * FROM technicians WHERE username = ? AND is_active = 1");
        $st->execute([$username]);
        $tech = $st->fetch();
        if ($tech && password_verify($password, (string)$tech['password'])) {
            $role = (($tech['role'] ?? 'engineer') === 'admin') ? 'admin' : 'technician';
            $sessionData = [
                'user_id' => 't' . $tech['id'],
                'username' => $tech['username'],
                'role' => $role,
                'full_name' => $tech['name'],
                'tech_id' => $tech['id'],
                'branch_id' => $tech['branch_id'] ?? null,
            ];
            if ($role === 'technician') { $sessionData['internal_role'] = $tech['role'] ?? 'engineer'; }
            $ok = true;
        }
    }
} catch (Throwable $e) {
    error_log('reauth: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Chyba serveru — zkus to znovu.']); exit;
}

if (!$ok) {
    usleep(600000);
    $recordFail();   // do sdílené login_attempts (per IP)
    echo json_encode(['success' => false, 'message' => 'Špatné jméno nebo heslo.']); exit;
}

// úspěch → vyčistit IP brzdu (stejně jako login.php)
try { $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$reauthIp]); } catch (Throwable $e) {}

// čerstvá session + nový CSRF token
session_regenerate_id(true);
foreach ($sessionData as $k => $v) { $_SESSION[$k] = $v; }
try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
catch (Throwable $e) { $_SESSION['csrf_token'] = bin2hex(md5(uniqid((string)mt_rand(), true)) . md5(uniqid((string)mt_rand(), true))); }
invalidatePermissionsCache();

crmAuditLog('auth.reauth', [
    'entity_type' => 'auth',
    'summary' => 'Obnoveno přihlášení po vypršení session (' . ($sessionData['full_name'] ?: $sessionData['username']) . ')',
]);

echo json_encode([
    'success' => true,
    'csrf_token' => $_SESSION['csrf_token'],
    'name' => (string)($sessionData['full_name'] ?: $sessionData['username']),
], JSON_UNESCAPED_UNICODE);
