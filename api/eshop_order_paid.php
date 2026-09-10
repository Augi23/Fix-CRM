<?php
/**
 * RUČNÍ akce nad objednávkou z e-shopu (jen vedení): paid = platba dorazila,
 * ship = dobírka předána dopravci, return = nedoručená zásilka zpět na sklad,
 * cancel = zrušení rezervace (zboží se uvolní zpět do prodeje).
 * Peníze dorazily, ale automatické párování je nechytlo (jiný VS, platba z jiného
 * účtu, hotovost na ruku…) → tímto se rezervace překlopí na prodej: odečte se
 * sklad a zboží jde k expedici. Vyzvednutí na prodejně sem NEPATŘÍ — to jde přes
 * kasu (doklad, tržba). Smí jen vedení (admin/Boss).
 *
 * Od v3.78.2 sem sahá i administrace e-shopu (applefix.click/admin → Objednávky):
 * server e-shopu se prokáže tokenem feedu (POST `token` nebo hlavička X-Feed-Token,
 * shodně s api/eshop_orders.php; na stejném serveru stačí localhost bez session jako
 * u eshop_sale.php) — pak se nevyžaduje CRM session ani CSRF. Objednávku
 * lze zadat jako `order_id` nebo `order_ref`; `by` = jméno přihlášeného uživatele adminu
 * (zapíše se do historie jako „admin e-shopu (jméno)"). Token NIKDY nebrat z GET.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']); exit;
}

// ── auth: server e-shopu (token feedu, nebo localhost bez session — shodně s eshop_sale.php)
//    NEBO přihlášené vedení + CSRF (nástěnka CRM) ──
$expected = crmEshopFeedToken();
$provided = (string)($_POST['token'] ?? ($_SERVER['HTTP_X_FEED_TOKEN'] ?? ''));
$tokenOk  = ($expected !== '' && $provided !== '' && hash_equals($expected, $provided));
$isLocal  = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1', ''], true);
$hasSession = !empty($_SESSION['user_id']) || !empty($_SESSION['tech_id']);
$viaEshop = $tokenOk || ($isLocal && !$hasSession);
if (!$viaEshop) {
    if (!crmCanDeleteOrders()) {   // admin + Boss
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Označit platbu smí jen vedení (admin, Boss).'], JSON_UNESCAPED_UNICODE); exit;
    }
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit;
    }
}

ensureEshopOrdersTable();
ensureEshopReservationSchema();

$orderId  = (int)($_POST['order_id'] ?? 0);
$orderRef = trim((string)($_POST['order_ref'] ?? ''));
if ($orderId <= 0 && $orderRef === '') { echo json_encode(['ok' => false, 'error' => 'Chybí objednávka.'], JSON_UNESCAPED_UNICODE); exit; }
if ($orderId <= 0 && !preg_match('/^[A-Za-z0-9._-]{6,64}$/', $orderRef)) {
    echo json_encode(['ok' => false, 'error' => 'Neplatné číslo objednávky.'], JSON_UNESCAPED_UNICODE); exit;
}
$action = (string)($_POST['action'] ?? 'paid');

$st = $orderId > 0
    ? $pdo->prepare("SELECT id, pay_id, order_ref, status FROM eshop_orders WHERE id = ?")
    : $pdo->prepare("SELECT id, pay_id, order_ref, status FROM eshop_orders WHERE order_ref = ?");
$st->execute([$orderId > 0 ? $orderId : $orderRef]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { echo json_encode(['ok' => false, 'error' => 'Objednávka nenalezena.'], JSON_UNESCAPED_UNICODE); exit; }
$orderId = (int)$o['id'];
$payId = (string)($o['pay_id'] ?? '');
if ($viaEshop) {
    // z adminu e-shopu: do historie i auditu jde, kdo to tam potvrdil (jen text, bez řídicích
    // znaků, krátké — audit summary má 255 znaků a u zrušení nese i důvod)
    $by  = mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($_POST['by'] ?? ''))), 0, 40);
    $who = 'admin e-shopu' . ($by !== '' ? ' (' . $by . ')' : '');
    // bez CRM session by audit ukázal „Systém" jako u automatického párování — aktér pro celý požadavek
    $GLOBALS['crmAuditActorOverride'] = ['actor_type' => 'user', 'actor_id' => null, 'actor_name' => $who, 'actor_role' => 'admin'];
} else {
    $who = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
}

switch ($action) {
    case 'paid':
        // Platba dorazila. U dobírky peníze posílá dopravce (zboží už odešlo),
        // u převodu se tím teprve odepíše sklad a zboží jde k odeslání.
        if ($payId === 'odber') {
            echo json_encode(['ok' => false, 'error' => 'Objednávka ' . $o['order_ref'] . ' se platí při vyzvednutí — natáhni ji v Pokladně („Rezervace e-shopu"), ať vznikne doklad a tržba.'], JSON_UNESCAPED_UNICODE); exit;
        }
        if ($payId === 'dobirka' && (string)$o['status'] === 'reserved') {
            echo json_encode(['ok' => false, 'error' => 'U dobírky nejdřív potvrď „Předáno dopravci" — peníze pošle dopravce až po doručení.'], JSON_UNESCAPED_UNICODE); exit;
        }
        $res = (string)$o['status'] === 'shipped'
            ? afxEshopMarkCodPaid($orderId, $who)
            : afxEshopReleaseAsSale($orderId, $who, $viaEshop ? 'ručně potvrzeno v adminu e-shopu' : 'ručně potvrzeno v CRM');
        break;
    case 'ship':
        if ($payId !== 'dobirka') {
            echo json_encode(['ok' => false, 'error' => 'Odeslat bez zaplacení jde jen dobírku — u převodu počkej na platbu.'], JSON_UNESCAPED_UNICODE); exit;
        }
        $res = afxEshopMarkShipped($orderId, $who);
        break;
    case 'return':
        $res = afxEshopReturnToStock($orderId, $who);
        break;
    case 'cancel':
        $res = afxEshopCancelReservation($orderId, $who, trim((string)($_POST['reason'] ?? '')));
        break;
    default:
        echo json_encode(['ok' => false, 'error' => 'Neznámá akce.']); exit;
}

if (!empty($res['ok'])) {
    // čerstvý stav + povolené akce, ať si admin e-shopu nemusí znovu stahovat celý přehled
    $st = $pdo->prepare("SELECT status FROM eshop_orders WHERE id = ?");
    $st->execute([$orderId]);
    $newStatus = (string)$st->fetchColumn();
    echo json_encode([
        'ok'           => true,
        'order_ref'    => (string)($res['order_ref'] ?? $o['order_ref']),
        'status'       => $newStatus,
        'status_label' => afxEshopStatusLabel($newStatus, $payId),
        'actions'      => afxEshopOrderActions($newStatus, $payId),
    ], JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Nepovedlo se.')], JSON_UNESCAPED_UNICODE);
}
