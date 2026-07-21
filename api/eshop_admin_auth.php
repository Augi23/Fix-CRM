<?php
/**
 * OVĚŘENÍ PŘIHLÁŠENÍ DO ADMINISTRACE VLASTNÍHO E-SHOPU proti účtům CRM.
 * E-shop (applefix.online) sem server-to-server pošle jméno + heslo zadané v jeho
 * přihlašovacím okně; CRM ověří heslo (stejně jako login.php) a vrátí, zda je to
 * účet s právem do administrace e-shopu. ZÁMĚRNĚ jen dvě role: administrátor a Boss.
 * (Rozhodnutí majitele 21.7.2026: do e-shop adminu smí jen admin a boss.)
 *
 * Zabezpečení volajícího: token (X-Feed-Token / ?token proti eshop_feed_token) nebo localhost —
 * aby proti tomuto loginu nemohl brute-forcovat kdokoli z internetu, jen náš e-shop server.
 *
 * Vstup: POST application/json { "username": "...", "password": "..." }
 * Odpověď: 200 { ok:true, user:{ username, name, role } }  |  401 { ok:false }
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function eshopAuthDeny(): void {
    usleep(400000); // ~0.4 s brzda proti hádání hesla
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_credentials'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── zabezpečení volajícího (náš e-shop server) ────────────────────────────────
$remote   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal  = in_array($remote, ['127.0.0.1', '::1', ''], true);
$expected = crmEshopFeedToken();
$provided = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_FEED_TOKEN'] ?? ''));
$tokenOk  = ($expected !== '' && $provided !== '' && hash_equals($expected, $provided));
if (!$isLocal && !$tokenOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');
if ($username === '' || $password === '') eshopAuthDeny();

// ── 1) users (administrátoři) ─────────────────────────────────────────────────
$st = $pdo->prepare("SELECT id, username, full_name, password FROM users WHERE username = ?");
$st->execute([$username]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if ($u && password_verify($password, (string)$u['password'])) {
    echo json_encode(['ok' => true, 'user' => [
        'username' => (string)$u['username'],
        'name'     => (string)($u['full_name'] ?: $u['username']),
        'role'     => 'admin',
    ]], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── 2) technicians — pustit JEN roli admin nebo boss ─────────────────────────
$st = $pdo->prepare("SELECT id, username, name, role, password FROM technicians WHERE username = ? AND is_active = 1");
$st->execute([$username]);
$t = $st->fetch(PDO::FETCH_ASSOC);
if ($t && password_verify($password, (string)$t['password'])) {
    $role = (string)($t['role'] ?? 'engineer');
    if ($role === 'admin' || $role === 'boss') {
        echo json_encode(['ok' => true, 'user' => [
            'username' => (string)$t['username'],
            'name'     => (string)($t['name'] ?: $t['username']),
            'role'     => $role,
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // správné heslo, ale nedostatečná role → nepouštět do e-shop adminu
    usleep(200000);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'insufficient_role'], JSON_UNESCAPED_UNICODE);
    exit;
}

eshopAuthDeny();
