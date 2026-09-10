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
 * Odpověď: { ok, total, count, orders:[ { order_ref, status, status_label, actions:{can_pay,can_ship,can_return,can_cancel},
 *            total, customer_*, items:[…], note, customer_note, pay_*, ship_*, address, access_point, created_at } ] }
 * Akce nad objednávkou (platba dorazila apod.) dělá api/eshop_order_paid.php (token nebo přihlášené vedení).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ensureEshopOrdersTable();
ensureEshopReservationSchema();   // pay_label / ship_* / addr_* / access_point_json (v3.77.2)

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

$sql = "SELECT order_ref, status, items_json, total, customer_name, customer_email, customer_phone, note, created_at,
               pay_id, pay_label, ship_id, ship_label, addr_street, addr_zip, addr_city, access_point_json, customer_note
        FROM eshop_orders $where ORDER BY id DESC LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Názvy položek: nové objednávky je mají uložené, starší jen kód → dohledat v products
$codesNeeded = [];
foreach ($rows as $r) {
    foreach ((json_decode((string)($r['items_json'] ?? '[]'), true) ?: []) as $it) {
        if (is_array($it) && empty($it['name']) && !empty($it['code'])) { $codesNeeded[(string)$it['code']] = true; }
    }
}
$nameByCode = [];
if ($codesNeeded) {
    $codes = array_keys($codesNeeded);
    $ph = implode(',', array_fill(0, count($codes), '?'));
    try {
        $pn = $pdo->prepare("SELECT product_code, title, price FROM products WHERE product_code IN ($ph)");
        $pn->execute($codes);
        foreach ($pn->fetchAll(PDO::FETCH_ASSOC) as $pr) { $nameByCode[(string)$pr['product_code']] = $pr; }
    } catch (Throwable $e) { /* názvy jsou bonus */ }
}

/** Starší objednávky mají dopravu/platbu/adresu jen slepené v note — rozebrat na části. */
function eshopParseLegacyNote(?string $note): array {
    $out = ['ship' => null, 'pay' => null, 'address' => null, 'access_point' => null, 'rest' => []];
    foreach (array_filter(array_map('trim', explode(' · ', (string)$note))) as $part) {
        if (stripos($part, 'Doprava:') === 0)            { $out['ship'] = trim(substr($part, 8)); }
        elseif (stripos($part, 'Platba:') === 0)         { $out['pay'] = trim(substr($part, 7)); }
        elseif (stripos($part, 'Adresa:') === 0)         { $out['address'] = trim(substr($part, 7)); }
        elseif (stripos($part, 'Výdejní místo:') === 0)  { $out['access_point'] = trim(mb_substr($part, 14)); }
        elseif (stripos($part, 'Tel:') === 0)            { /* telefon je ve vlastním sloupci */ }
        else                                             { $out['rest'][] = $part; }
    }
    return $out;
}

$orders = [];
foreach ($rows as $r) {
    $items = json_decode((string)($r['items_json'] ?? '[]'), true);
    $items = is_array($items) ? $items : [];
    foreach ($items as &$it) {
        if (is_array($it) && empty($it['name']) && !empty($it['code']) && isset($nameByCode[(string)$it['code']])) {
            $it['name'] = (string)$nameByCode[(string)$it['code']]['title'];
            if (!isset($it['price'])) { $it['price'] = (float)$nameByCode[(string)$it['code']]['price']; }
        }
    }
    unset($it);

    $legacy = eshopParseLegacyNote($r['note'] ?? null);
    $ap = $r['access_point_json'] ? json_decode((string)$r['access_point_json'], true) : null;
    $addr = ($r['addr_street'] || $r['addr_city'])
        ? ['street' => (string)$r['addr_street'], 'zip' => (string)$r['addr_zip'], 'city' => (string)$r['addr_city']]
        : null;

    $orders[] = [
        'order_ref'      => (string)$r['order_ref'],
        'status'         => (string)$r['status'],
        'total'          => (float)$r['total'],
        'customer_name'  => $r['customer_name'] !== null ? (string)$r['customer_name'] : null,
        'customer_email' => $r['customer_email'] !== null ? (string)$r['customer_email'] : null,
        'customer_phone' => $r['customer_phone'] !== null ? (string)$r['customer_phone'] : null,
        'note'           => $r['note'] !== null ? (string)$r['note'] : null,
        'note_extra'     => implode(' · ', $legacy['rest']) ?: null,   // co zbylo mimo dopravu/platbu/adresu
        'customer_note'  => ($r['customer_note'] !== null && $r['customer_note'] !== '') ? (string)$r['customer_note'] : null,   // volná poznámka zákazníka z pokladny
        'status_label'   => afxEshopStatusLabel((string)$r['status'], (string)($r['pay_id'] ?? '')),
        'actions'        => afxEshopOrderActions((string)$r['status'], (string)($r['pay_id'] ?? '')),   // co smí admin e-shopu udělat (v3.78.2)
        'pay_id'         => $r['pay_id'] !== null ? (string)$r['pay_id'] : null,
        'pay_label'      => $r['pay_label'] ?: $legacy['pay'],
        'ship_id'        => $r['ship_id'] !== null ? (string)$r['ship_id'] : null,
        'ship_label'     => $r['ship_label'] ?: $legacy['ship'],
        'address'        => $addr,
        'address_text'   => $addr ? trim($addr['street'] . ', ' . $addr['zip'] . ' ' . $addr['city'], ', ') : $legacy['address'],
        'access_point'   => is_array($ap) ? $ap : null,
        'access_point_text' => is_array($ap)
            ? trim(($ap['name'] ?? '') . ', ' . ($ap['street'] ?? '') . ', ' . ($ap['city'] ?? '') . (!empty($ap['code']) ? ' (' . $ap['code'] . ')' : ''), ', ')
            : $legacy['access_point'],
        'items'          => $items,
        'created_at'     => (string)$r['created_at'],
    ];
}

echo json_encode(['ok' => true, 'total' => $total, 'count' => count($orders), 'orders' => $orders], JSON_UNESCAPED_UNICODE);
