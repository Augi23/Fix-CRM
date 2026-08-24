<?php
/**
 * Upozornění na nové objednávky z e-shopu (applefix.click) — pro adminy a Bosse.
 *
 * GET  → nepotvrzené objednávky PRO PŘIHLÁŠENÝ účet (per-účet potvrzování:
 *        Jan i Tomáš zavírají každý svoje). Objednávka se vrací pořád dokola,
 *        dokud ji dotyčný RUČNĚ nezavře — přesně to je účel (všimnout si včas).
 * POST action=ack, order_id → potvrzení („beru na vědomí") pro tento účet.
 *
 * Hlásí se jen objednávky od eshop_alerts_since (po nasazení nevyskočí historie).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$acc = afxEshopAlertAccountKey();
if ($acc === '' || !crmCanDeleteOrders()) {   // admin + Boss — stejné publikum jako mazání zakázek
    // POST (potvrzení) s vypršelou relací NESMÍ vrátit ok:true — popup by se tiše
    // zavřel bez zapsaného potvrzení a uživatel by dostal falešné „hotovo"
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']); exit;
    }
    echo json_encode(['ok' => true, 'items' => []]); exit;
}

ensureEshopOrdersTable();
ensureEshopOrderAlertsTable();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit;
    }
    if ((string)($_POST['action'] ?? '') !== 'ack') {
        echo json_encode(['ok' => false, 'error' => 'unknown_action']); exit;
    }
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) { echo json_encode(['ok' => false, 'error' => 'missing_order']); exit; }
    try {
        // potvrzovat jde jen existující objednávka — bez kontroly by šla „před-odkliknout"
        // budoucí ID (auto-increment je předvídatelný) a upozornění by nikdy nevyskočilo
        $chk = $pdo->prepare("SELECT id FROM eshop_orders WHERE id = ?");
        $chk->execute([$orderId]);
        if (!$chk->fetch()) { echo json_encode(['ok' => false, 'error' => 'not_found']); exit; }
        $pdo->prepare("INSERT IGNORE INTO eshop_order_alert_acks (order_id, account_key) VALUES (?, ?)")
            ->execute([$orderId, $acc]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $e) {
        error_log('eshop_order_alerts ack: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'db']);
    }
    exit;
}

try {
    $since = trim((string)get_setting('eshop_alerts_since', ''));
    if ($since === '') { $since = date('Y-m-d H:i:s'); }   // pojistka: bez settingu nic starého nevytahovat

    $st = $pdo->prepare("SELECT o.id, o.order_ref, o.total, o.customer_name, o.customer_email,
            o.customer_phone, o.note, o.items_json, o.created_at
        FROM eshop_orders o
        WHERE o.created_at >= ?
          AND NOT EXISTS (SELECT 1 FROM eshop_order_alert_acks a WHERE a.order_id = o.id AND a.account_key = ?)
        ORDER BY o.id ASC
        LIMIT 5");
    $st->execute([$since, $acc]);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC);

    // názvy položek: items_json nese jen kódy — dotáhnout titulky z products
    $codes = [];
    foreach ($orders as $o) {
        foreach ((json_decode((string)$o['items_json'], true) ?: []) as $it) {
            $c = trim((string)($it['code'] ?? ''));
            if ($c !== '') { $codes[$c] = true; }
        }
    }
    $titles = [];
    if ($codes) {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $ts = $pdo->prepare("SELECT product_code, title FROM products WHERE product_code IN ($ph)");
        $ts->execute(array_keys($codes));
        foreach ($ts as $t) { $titles[(string)$t['product_code']] = (string)$t['title']; }
    }

    $items = [];
    foreach ($orders as $o) {
        $lines = [];
        foreach ((json_decode((string)$o['items_json'], true) ?: []) as $it) {
            $c = trim((string)($it['code'] ?? ''));
            $q = max(1, (int)($it['qty'] ?? 1));
            $lines[] = $q . '× ' . ($titles[$c] ?? $c);
        }
        // dlouhá objednávka nesmí kartu roztáhnout přes obrazovku (tlačítko musí zůstat dosažitelné)
        if (count($lines) > 12) {
            $extra = count($lines) - 12;
            $lines = array_slice($lines, 0, 12);
            $lines[] = '… a dalších ' . $extra . ' položek — celé v administraci e-shopu';
        }
        $items[] = [
            'id'        => (int)$o['id'],
            'order_ref' => (string)$o['order_ref'],
            'total'     => (float)$o['total'],
            'customer'  => trim((string)($o['customer_name'] ?? '')) ?: '—',
            'phone'     => trim((string)($o['customer_phone'] ?? '')),
            'email'     => trim((string)($o['customer_email'] ?? '')),
            'note'      => trim((string)($o['note'] ?? '')),
            'lines'     => $lines,
            'time'      => date('j. n. Y H:i', strtotime((string)$o['created_at'])),
        ];
    }
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('eshop_order_alerts: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'items' => []]);
}
