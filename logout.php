<?php
// POZOR: config.php MUSÍ běžet před session_destroy — nastavuje vlastní úložiště
// sessions (viz prodloužení přihlášení). Holé session_start() tady dřív otevřelo
// session v jiném adresáři a odhlášení se nikdy nedotklo té skutečné.
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// ?switch=1 — PŘEPNUTÍ ZAMĚSTNANCE na sdíleném počítači (recepce): odhlásí JEN tento
// prohlížeč, ostatní zařízení (telefon, appka) zůstanou přihlášená. Převzatá pokladna
// (pos_shifts) se nemění — dál ji smí obsluhovat jen ten, kdo ji převzal (v3.95.3).
$__switch = !empty($_GET['switch']);

// Zaznamenat odhlášení, dokud je session ještě platná (aktéra bereme z ní).
if (function_exists('crmAuditLog')) {
    crmAuditLog('auth.logout', ['entity_type' => 'auth', 'summary' => $__switch
        ? 'Přepnutí zaměstnance — odhlášení jen na tomto zařízení'
        : 'Odhlášení ze systému (globálně — všechna zařízení)']);
}

// GLOBÁLNÍ odhlášení: zvednutím generace účtu umřou i sezení v ostatních
// prohlížečích a v appce (oddělené cookies dřív nechávaly stará přihlášení žít).
if (!$__switch && function_exists('afxAuthGlobalLogout')) {
    afxAuthGlobalLogout();
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'] ?: '/', (string)($p['domain'] ?? ''), !empty($p['secure']), !empty($p['httponly']));
}
session_destroy();

header('Location: login.php' . ($__switch ? '?switched=1' : ''));
exit;
