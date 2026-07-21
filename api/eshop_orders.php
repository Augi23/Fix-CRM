<?php
/**
 * ČTECÍ JSON přehled objednávek z vlastního e-shopu (tabulka eshop_orders).
 * Slouží administraci e-shopu (sekce Objednávky) — e-shop běží na stejném serveru.
 * Zápis objednávek dělá api/eshop_sale.php; tady jen čteme.
 *
 * Auth (stačí jedno, shodné s feedem): localhost · přihlášené vedení · token
 *   (?token / hlavička X-Feed-Token proti settingu eshop_feed_token).
 *
 * Parametry (GET): limit (max 500, default 100), offset, q (fulltext v ref/jméno/e-mail).
 * Odpověď: { ok, total, count, orders:[ { order_ref, status, total, customer_*, items:[…], note, created_at } ] }
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ensureEshopOrdersTable();

// ── auth (shodně s api/eshop_feed.php) ─────────────────────────────────────────
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

$limit  = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$q      = trim((string)($_GET['q'] ?? ''));

$where = ''; $params = [];
if ($q !== '') {
    $where = 'WHERE order_ref LIKE ? OR customer_name LIKE ? OR customer_email LIKE ?';
    $like = '%' . $q . '%';
    $params = [$like, $like, $like];
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM eshop_orders" . ($where ? " $where" : ''))->fetchColumn();
if ($where && $params) {
    $cst = $pdo->prepare("SELECT COUNT(*) FROM eshop_orders $where");
    $cst->execute($params);
    $total = (int)$cst->fetchColumn();
}

$sql = "SELECT order_ref, status, items_json, total, customer_name, customer_email, customer_phone, note, created_at
        FROM eshop_orders $where ORDER BY id DESC LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);

$orders = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $items = json_decode((string)($r['items_json'] ?? '[]'), true);
    $orders[] = [
        'order_ref'      => (string)$r['order_ref'],
        'status'         => (string)$r['status'],
        'total'          => (float)$r['total'],
        'customer_name'  => $r['customer_name'] !== null ? (string)$r['customer_name'] : null,
        'customer_email' => $r['customer_email'] !== null ? (string)$r['customer_email'] : null,
        'customer_phone' => $r['customer_phone'] !== null ? (string)$r['customer_phone'] : null,
        'note'           => $r['note'] !== null ? (string)$r['note'] : null,
        'items'          => is_array($items) ? $items : [],
        'created_at'     => (string)$r['created_at'],
    ];
}

echo json_encode(['ok' => true, 'total' => $total, 'count' => count($orders), 'orders' => $orders], JSON_UNESCAPED_UNICODE);
