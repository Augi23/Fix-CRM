<?php
// POZOR: config.php MUSÍ běžet před session_destroy — nastavuje vlastní úložiště
// sessions (viz prodloužení přihlášení). Holé session_start() tady dřív otevřelo
// session v jiném adresáři a odhlášení se nikdy nedotklo té skutečné.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Zaznamenat odhlášení, dokud je session ještě platná (aktéra bereme z ní).
if (function_exists('crmAuditLog')) {
    crmAuditLog('auth.logout', ['entity_type' => 'auth', 'summary' => 'Odhlášení ze systému']);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'] ?: '/', (string)($p['domain'] ?? ''), !empty($p['secure']), !empty($p['httponly']));
}
session_destroy();

header('Location: login.php');
exit;
