<?php
/**
 * NEJNIŽŠÍ CENY OPRAV PRO E-SHOP (stránka Servis na applefix.click → „od … Kč").
 * Zdroj = ceník oprav `repair_pricelist` (ten samý, ze kterého čte wizard zakázky).
 * Vrací jen Apple a jen opravy s cenou; mapování na sekce stránky dělá e-shop.
 *
 * Auth stejně jako api/eshop_feed.php: lokální požadavek, přihlášené vedení,
 * nebo token `eshop_feed_token` (?token= / X-Feed-Token).
 *
 * Odpověď: { ok, generated_at, repairs: [ { repair_name, min_price, count } ] }
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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

try {
    ensureRepairPricelistTable();
    $st = $pdo->query("SELECT repair_name, MIN(price) AS min_price, COUNT(*) AS cnt
                       FROM repair_pricelist
                       WHERE brand = 'Apple' AND price > 0
                       GROUP BY repair_name
                       ORDER BY repair_name");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['repair_name' => (string)$r['repair_name'], 'min_price' => (float)$r['min_price'], 'count' => (int)$r['cnt']];
    }
    echo json_encode(['ok' => true, 'generated_at' => date('c'), 'repairs' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Chyba serveru'], JSON_UNESCAPED_UNICODE);
}
