<?php
/**
 * RUČNÍ akce nad objednávkou z e-shopu (jen vedení): paid = platba dorazila,
 * ship = dobírka předána dopravci, return = nedoručená zásilka zpět na sklad,
 * cancel = zrušení rezervace (zboží se uvolní zpět do prodeje).
 * Peníze dorazily, ale automatické párování je nechytlo (jiný VS, platba z jiného
 * účtu, hotovost na ruku…) → tímto se rezervace překlopí na prodej: odečte se
 * sklad a zboží jde k expedici. Vyzvednutí na prodejně sem NEPATŘÍ — to jde přes
 * kasu (doklad, tržba). Smí jen vedení (admin/Boss).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!crmCanDeleteOrders()) {   // admin + Boss
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Označit platbu smí jen vedení (admin, Boss).'], JSON_UNESCAPED_UNICODE); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']); exit;
}
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit;
}

ensureEshopOrdersTable();
ensureEshopReservationSchema();

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) { echo json_encode(['ok' => false, 'error' => 'Chybí objednávka.']); exit; }
$action = (string)($_POST['action'] ?? 'paid');

$st = $pdo->prepare("SELECT pay_id, order_ref, status FROM eshop_orders WHERE id = ?");
$st->execute([$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { echo json_encode(['ok' => false, 'error' => 'Objednávka nenalezena.']); exit; }
$payId = (string)($o['pay_id'] ?? '');
$who = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

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
            : afxEshopReleaseAsSale($orderId, $who, 'ručně potvrzeno v CRM');
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

echo json_encode($res['ok']
    ? ['ok' => true, 'order_ref' => $res['order_ref'] ?? '']
    : ['ok' => false, 'error' => $res['error']], JSON_UNESCAPED_UNICODE);
