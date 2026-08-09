<?php
/**
 * WEBHOOK Z UPGATES E-SHOPU (applefix.online) → upozornění v CRM + push.
 *
 * Upgates při vytvoření objednávky POSTne JSON na tuhle URL (nastavuje se
 * v administraci Upgates: Doplňky → API → Webhooky). Objednávka se uloží do
 * `eshop_orders` (stejná tabulka jako u vlastního e-shopu applefix.click),
 * takže se sama objeví v sekci upozornění (zvoneček) — a všem zařízením
 * s mobilní appkou odejde push notifikace.
 *
 * Na rozdíl od api/eshop_sale.php se tady NEODEČÍTÁ sklad — sklad applefix.online
 * si vede Upgates sám. Jen evidence + upozornění.
 *
 * Auth: ?token=<setting upgates_webhook_token>. Idempotence přes UNIQUE order_ref
 * (opakované doručení webhooku nevyvolá druhý push).
 *
 * Payload Upgates se může lišit verzí — parser je záměrně defenzivní: hledá
 * číslo objednávky, jméno zákazníka a částku v běžných klíčích (i vnořeně)
 * a celý surový JSON ukládá do items_json pro pozdější dohledání.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function upgFail(int $http, string $error): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── auth ───────────────────────────────────────────────────────────────────────
$expected = (string)get_setting('upgates_webhook_token', '');
$provided = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ''));
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    upgFail(403, 'forbidden');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    upgFail(405, 'method_not_allowed');
}

$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 1024 * 1024) upgFail(400, 'invalid_body');
$body = json_decode($raw, true);
if (!is_array($body)) upgFail(400, 'invalid_json');

/** Rekurzivně najde první hodnotu pod některým z klíčů (case-insensitive). */
function upgFind(array $data, array $keys, int $depth = 0) {
    if ($depth > 6) return null;
    foreach ($data as $k => $v) {
        foreach ($keys as $want) {
            if (is_string($k) && strcasecmp($k, $want) === 0 && !is_array($v) && $v !== '' && $v !== null) {
                return $v;
            }
        }
    }
    foreach ($data as $v) {
        if (is_array($v)) {
            $found = upgFind($v, $keys, $depth + 1);
            if ($found !== null) return $found;
        }
    }
    return null;
}

$orderRef = (string)(upgFind($body, ['order_number', 'number', 'order_num', 'code']) ?? '');
$orderRef = mb_substr(trim($orderRef), 0, 60);
if ($orderRef === '') $orderRef = 'UPG-' . gmdate('YmdHis');

$total = upgFind($body, ['total_with_vat', 'order_total', 'total_price', 'price_with_vat', 'total']);
$total = is_numeric($total) ? round((float)$total, 2) : 0.0;

$first = (string)(upgFind($body, ['firstname', 'first_name']) ?? '');
$last  = (string)(upgFind($body, ['surname', 'lastname', 'last_name']) ?? '');
$cName = trim($first . ' ' . $last);
if ($cName === '') $cName = trim((string)(upgFind($body, ['customer_name', 'fullname', 'company']) ?? ''));
$cName = mb_substr($cName, 0, 160) ?: null;

$cEmail = mb_substr(trim((string)(upgFind($body, ['email']) ?? '')), 0, 160) ?: null;
$cPhone = mb_substr(trim((string)(upgFind($body, ['phone', 'telephone']) ?? '')), 0, 48) ?: null;

ensureEshopOrdersTable();

// ── idempotentní zápis (duplicitní webhook → žádný druhý push) ────────────────
try {
    $ins = $pdo->prepare("INSERT INTO eshop_orders
        (order_ref, status, items_json, total, customer_name, customer_email, customer_phone, note)
        VALUES (?, 'new', ?, ?, ?, ?, ?, 'applefix.online (Upgates)')");
    $ins->execute([
        $orderRef,
        mb_substr(json_encode(['upgates_raw' => $body], JSON_UNESCAPED_UNICODE), 0, 500000),
        $total, $cName, $cEmail, $cPhone,
    ]);
    $eshopOrderId = (int)$pdo->lastInsertId();
} catch (PDOException $e) {
    $dup = ($e->getCode() === '23000') || ((int)($e->errorInfo[1] ?? 0) === 1062);
    if ($dup) {
        echo json_encode(['ok' => true, 'order_ref' => $orderRef, 'already_processed' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    error_log('upgates_webhook: ' . $e->getMessage());
    upgFail(500, 'internal_error');
}

// ── push všem zařízením s appkou (bezpečný no-op bez APNs) ────────────────────
try {
    require_once __DIR__ . '/../includes/notify_push.php';
    crmPushEshopOrder($pdo, $eshopOrderId, $orderRef, $total, (string)($cName ?? ''));
} catch (Throwable $e) { /* push nesmí shodit webhook */ }

echo json_encode(['ok' => true, 'order_ref' => $orderRef, 'already_processed' => false], JSON_UNESCAPED_UNICODE);
