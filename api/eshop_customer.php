<?php
/**
 * ČTECÍ profil zákazníka pro vlastní e-shop (fáze 2 zákaznického loginu).
 * Přihlášený zákazník na applefix.click se identifikuje e-mailem (z Apple/Google) — e-shop
 * si sem server-to-server řekne o:
 *   - profil z tabulky `customers` (jméno, telefon, adresa) → PŘEDVYPLNĚNÍ pokladny,
 *   - historii jeho objednávek z `eshop_orders` (dle customer_email).
 * ŽÁDNÉ zakládání klienta — jen čtení (klient v CRM vzniká až reálnou objednávkou přes eshop_sale.php).
 *
 * Auth (shodně s feedem): localhost · přihlášené vedení · token (?token / X-Feed-Token).
 * GET: email (povinné). Odpověď: { ok, profile:{…}|null, orders:[…] }
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ensureEshopOrdersTable();

// ── auth (shodně s api/eshop_orders.php) ───────────────────────────────────────
$remote    = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal   = in_array($remote, ['127.0.0.1', '::1', ''], true);
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && crmCanManageProducts();
$expected  = crmEshopFeedToken();
$provided  = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_FEED_TOKEN'] ?? ''));
$tokenOk   = ($expected !== '' && $provided !== '' && hash_equals($expected, $provided));
if (!$isLocal && !$sessionOk && !$tokenOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = mb_strtolower(trim((string)($_GET['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => true, 'profile' => null, 'orders' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── profil z customers (na předvyplnění pokladny) ──────────────────────────────
$profile = null;
try {
    $st = $pdo->prepare("SELECT first_name, last_name, phone, email, company, address
                         FROM customers WHERE email = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$email]);
    if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
        $profile = [
            'first_name' => (string)($c['first_name'] ?? ''),
            'last_name'  => (string)($c['last_name'] ?? ''),
            'phone'      => (string)($c['phone'] ?? ''),
            'email'      => (string)($c['email'] ?? ''),
            'company'    => (string)($c['company'] ?? ''),
            'address'    => (string)($c['address'] ?? ''),
        ];
    }
} catch (Throwable $e) { /* profil je best-effort */ }

// ── historie objednávek z eshop_orders (dle e-mailu) ───────────────────────────
$orders = [];
try {
    $st = $pdo->prepare("SELECT order_ref, status, items_json, total, created_at
                         FROM eshop_orders WHERE customer_email = ? ORDER BY id DESC LIMIT 50");
    $st->execute([$email]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items = json_decode((string)($r['items_json'] ?? '[]'), true);
        $orders[] = [
            'order_ref'  => (string)$r['order_ref'],
            'status'     => (string)$r['status'],
            'total'      => (float)$r['total'],
            'items'      => is_array($items) ? $items : [],
            'created_at' => (string)$r['created_at'],
        ];
    }
} catch (Throwable $e) { /* žádné objednávky / starší DB */ }

echo json_encode(['ok' => true, 'profile' => $profile, 'orders' => $orders], JSON_UNESCAPED_UNICODE);
