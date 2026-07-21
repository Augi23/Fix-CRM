<?php
/**
 * ZÁPIS PRODEJE Z VLASTNÍHO E-SHOPU → CRM (odečet skladu).
 * Druhý směr obousměrného propojení: e-shop čte sklad z api/eshop_feed.php a při
 * dokončení objednávky sem POSTne prodej — CRM atomicky odečte kusy z `products`
 * (stejná logika jako kasa: guard proti souběhu + pos_sold_at při dosažení nuly),
 * takže se stejný kus nemůže prodat dvakrát (prodejna vs. e-shop).
 *
 * Auth (stačí jedno, shodné s feedem): localhost · přihlášené vedení · token
 *   (?token / hlavička X-Feed-Token proti settingu eshop_feed_token).
 *
 * Vstup: POST application/json
 *   {
 *     "order_ref": "AFX-2026-000123",   // povinné, UNIKÁTNÍ = idempotence
 *     "items": [ { "code": "<product_code>", "qty": 1 }, ... ],  // povinné
 *     "total": 14690,                    // volitelné (informativní, vč. dopravy/slev)
 *     "customer": { "name": "...", "email": "...", "phone": "..." },  // volitelné
 *     "note": "..."                      // volitelné
 *   }
 * Odpověď: { ok, order_ref, status, already_processed, items:[{code,qty,stock_after}] }
 * Chyba skladu → HTTP 409 { ok:false, error, code } (e-shop kupujícímu oznámí vyprodáno).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function eshopSaleFail(int $http, string $error, ?string $code = null): void {
    http_response_code($http);
    $out = ['ok' => false, 'error' => $error];
    if ($code !== null) $out['code'] = $code;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

ensureProductsTable();
if (function_exists('ensureProductsPosColumn')) { try { ensureProductsPosColumn(); } catch (Throwable $e) {} }
ensureEshopOrdersTable();

// ── auth (shodně s api/eshop_feed.php) ─────────────────────────────────────────
$remote    = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal   = in_array($remote, ['127.0.0.1', '::1', ''], true);
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && crmCanManageProducts();
$expected  = crmEshopFeedToken();
$provided  = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_FEED_TOKEN'] ?? ''));
$tokenOk   = ($expected !== '' && $provided !== '' && hash_equals($expected, $provided));
if (!$isLocal && !$sessionOk && !$tokenOk) {
    eshopSaleFail(403, 'forbidden');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    eshopSaleFail(405, 'method_not_allowed');
}

// ── vstup ──────────────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) eshopSaleFail(400, 'invalid_json');

$orderRef = trim((string)($body['order_ref'] ?? ''));
if ($orderRef === '' || mb_strlen($orderRef) > 64) eshopSaleFail(400, 'order_ref je povinné (max 64 znaků).');

$itemsIn = $body['items'] ?? null;
if (!is_array($itemsIn) || count($itemsIn) === 0) eshopSaleFail(400, 'items je povinné (neprázdné pole).');
if (count($itemsIn) > 200) eshopSaleFail(400, 'Příliš mnoho položek.');

// sluč stejné kódy (kdyby přišly duplicitně) a zvaliduj
$items = [];
foreach ($itemsIn as $it) {
    if (!is_array($it)) eshopSaleFail(400, 'Neplatná položka.');
    $code = trim((string)($it['code'] ?? ''));
    $qty  = (int)($it['qty'] ?? 0);
    if ($code === '') eshopSaleFail(400, 'Položka bez kódu produktu.');
    if ($qty < 1 || $qty > 999) eshopSaleFail(400, 'Neplatný počet kusů u „' . $code . '".');
    $items[$code] = ($items[$code] ?? 0) + $qty;
}

$total   = round((float)($body['total'] ?? 0), 2);
$cust    = is_array($body['customer'] ?? null) ? $body['customer'] : [];
$cName   = mb_substr(trim((string)($cust['name'] ?? '')), 0, 160) ?: null;
$cEmail  = mb_substr(trim((string)($cust['email'] ?? '')), 0, 160) ?: null;
$cPhone  = mb_substr(trim((string)($cust['phone'] ?? '')), 0, 48) ?: null;
$note    = mb_substr(trim((string)($body['note'] ?? '')), 0, 500) ?: null;

// ── transakce: idempotentní zápis objednávky + atomický odečet skladu ──────────
try {
    $pdo->beginTransaction();

    // 1) Rezervuj order_ref vložením objednávky. Duplicitní klíč = už zpracováno
    //    (opakovaný webhook) → vrať původní výsledek, sklad NEODEČÍTEJ podruhé.
    try {
        $ins = $pdo->prepare("INSERT INTO eshop_orders
            (order_ref, status, items_json, total, customer_name, customer_email, customer_phone, note)
            VALUES (?, 'paid', ?, ?, ?, ?, ?, ?)");
        $ins->execute([
            $orderRef,
            json_encode(array_map(fn($c, $q) => ['code' => $c, 'qty' => $q], array_keys($items), array_values($items)), JSON_UNESCAPED_UNICODE),
            $total, $cName, $cEmail, $cPhone, $note,
        ]);
    } catch (PDOException $e) {
        $dup = ($e->getCode() === '23000') || ((int)($e->errorInfo[1] ?? 0) === 1062);
        if ($dup) {
            $pdo->rollBack();
            $ex = $pdo->prepare("SELECT status, created_at FROM eshop_orders WHERE order_ref = ?");
            $ex->execute([$orderRef]);
            $row = $ex->fetch(PDO::FETCH_ASSOC) ?: [];
            echo json_encode([
                'ok' => true, 'order_ref' => $orderRef,
                'status' => (string)($row['status'] ?? 'paid'),
                'already_processed' => true,
                'processed_at' => $row['created_at'] ?? null,
                'items' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw $e;
    }

    // 2) Atomický odečet každého produktu (guard proti souběhu / zápornému stavu).
    $find = $pdo->prepare("SELECT id, title, stock_qty FROM products WHERE product_code = ? LIMIT 1");
    $dec  = $pdo->prepare("UPDATE products
        SET stock_qty = stock_qty - ?, pos_sold_at = IF(stock_qty = 0, NOW(), pos_sold_at)
        WHERE id = ? AND stock_qty >= ?");
    $after = $pdo->prepare("SELECT stock_qty FROM products WHERE id = ?");

    $results = [];
    foreach ($items as $code => $qty) {
        $find->execute([$code]);
        $p = $find->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $pdo->rollBack();
            eshopSaleFail(409, 'Produkt „' . $code . '" v CRM neexistuje.', $code);
        }
        $dec->execute([$qty, (int)$p['id'], $qty]);
        if ($dec->rowCount() === 0) {
            $pdo->rollBack();
            eshopSaleFail(409, '„' . $p['title'] . '" už není skladem v požadovaném počtu.', $code);
        }
        $after->execute([(int)$p['id']]);
        $results[] = ['code' => $code, 'qty' => $qty, 'stock_after' => (int)$after->fetchColumn()];
    }

    $pdo->commit();
    echo json_encode([
        'ok' => true, 'order_ref' => $orderRef, 'status' => 'paid',
        'already_processed' => false, 'items' => $results,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('eshop_sale: ' . $e->getMessage());
    eshopSaleFail(500, 'internal_error');
}
