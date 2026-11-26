<?php
/* Stanovení ceny zakázky přijaté jen na diagnostiku (v4.5.0).
   Jediné místo, kde se u takové zakázky zapisuje cena — obsluha musí výslovně říct,
   jestli oprava proběhla (a za kolik), nebo se účtuje jen diagnostika. */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Neplatný token, načti stránku znovu.']); exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$mode = trim((string)($_POST['mode'] ?? ''));
$amount = isset($_POST['amount']) ? (float)str_replace([' ', ','], ['', '.'], (string)$_POST['amount']) : null;
if ($orderId <= 0) { echo json_encode(['ok' => false, 'error' => 'Chybí zakázka']); exit; }

try {
    ensureOrderDiagColumns();
    $st = $pdo->prepare("SELECT id, branch_id, technician_id FROM orders WHERE id = ? LIMIT 1");
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) { echo json_encode(['ok' => false, 'error' => 'Zakázka nenalezena']); exit; }
    // stejné pravidlo jako změna stavu zakázky: smí každý přihlášený zaměstnanec, který zakázku vidí
    if (!canAccessOrderBranch($order)) { echo json_encode(['ok' => false, 'error' => 'Bez oprávnění']); exit; }

    [$ok, $msg] = crmOrderResolvePrice($orderId, $mode, $amount);
    echo json_encode($ok ? ['ok' => true, 'message' => $msg] : ['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('order_price_resolve: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
}
