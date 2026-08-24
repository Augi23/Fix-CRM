<?php
/**
 * Prodeje z e-shopu pro dlaždici na Nástěnce (počty + rozkliknutý seznam).
 * GET → { ok, today, month, month_total, reserved, orders:[ {…, items:[{name,qty}] } ] }
 * Čtecí endpoint pro přihlášené vedení (stejné publikum jako sklad Produkty).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!crmCanManageProducts()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'orders' => []]); exit;
}

ensureEshopOrdersTable();
ensureEshopReservationSchema();

try {
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 60)));
    // Nevyřízené objednávky (rezervace + dobírky na cestě) se do výpisu berou VŽDY,
    // i kdyby byly starší než limit — jinak by zapadly a nikdo by je neuvolnil.
    $cols = "id, order_ref, status, items_json, total, customer_name, customer_phone,
            pay_id, created_at, collected_at, paid_at, shipped_at";
    $st = $pdo->prepare("SELECT $cols FROM eshop_orders
        WHERE status IN ('reserved', 'shipped')
           OR id IN (SELECT id FROM (SELECT id FROM eshop_orders ORDER BY id DESC LIMIT $limit) t)
        ORDER BY (status IN ('reserved','shipped')) DESC, id DESC");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // názvy položek z kódů (items_json nese jen product_code)
    $codes = [];
    foreach ($rows as $r) {
        foreach ((json_decode((string)$r['items_json'], true) ?: []) as $it) {
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

    $orders = [];
    foreach ($rows as $r) {
        $items = [];
        foreach ((json_decode((string)$r['items_json'], true) ?: []) as $it) {
            $c = trim((string)($it['code'] ?? ''));
            $items[] = ['name' => $titles[$c] ?? $c, 'code' => $c, 'qty' => max(1, (int)($it['qty'] ?? 1))];
        }
        $orders[] = [
            'id' => (int)$r['id'],
            'order_ref' => (string)$r['order_ref'],
            'status' => (string)$r['status'],
            'total' => (float)$r['total'],
            'customer' => trim((string)($r['customer_name'] ?? '')) ?: '—',
            'phone' => trim((string)($r['customer_phone'] ?? '')),
            'pay_id' => (string)($r['pay_id'] ?? ''),
            'reason' => (string)$r['status'] === 'reserved' ? afxEshopReservationReason((string)($r['pay_id'] ?? '')) : '',
            'status_label' => afxEshopStatusLabel((string)$r['status'], (string)($r['pay_id'] ?? '')),
            // co s objednávkou jde udělat (vyzvednutí řeší kasa, proto u 'odber' jen zrušení)
            'can_pay' => ((string)$r['status'] === 'reserved' && (string)($r['pay_id'] ?? '') === 'prevod')
                || (string)$r['status'] === 'shipped',
            'can_ship' => (string)$r['status'] === 'reserved' && (string)($r['pay_id'] ?? '') === 'dobirka',
            'can_return' => (string)$r['status'] === 'shipped',
            'can_cancel' => (string)$r['status'] === 'reserved',
            'waiting_days' => (string)$r['status'] === 'reserved'
                ? (int)floor((time() - strtotime((string)$r['created_at'])) / 86400) : 0,
            'date' => date('j. n. Y H:i', strtotime((string)$r['created_at'])),
            'collected' => !empty($r['collected_at']) ? date('j. n. Y H:i', strtotime((string)$r['collected_at'])) : '',
            'items' => $items,
        ];
    }

    // Počty: rezervace a zrušené se do „prodejů" nepočítají (zboží ještě není
    // zaplacené / se vrátilo). Datum prodeje = kdy se objednávka skutečně uzavřela
    // (vyzvednutí/platba/odeslání), ne kdy vznikla — jinak by vyzvednutí z minulého
    // měsíce spadlo do minulého měsíce a dnešní čítač by se nehnul.
    $sold = "status IN ('paid', 'collected', 'shipped')";
    $when = "COALESCE(collected_at, paid_at, shipped_at, created_at)";
    $today = (int)$pdo->query("SELECT COUNT(*) FROM eshop_orders WHERE $sold AND DATE($when) = CURDATE()")->fetchColumn();
    $month = (int)$pdo->query("SELECT COUNT(*) FROM eshop_orders WHERE $sold AND YEAR($when) = YEAR(CURDATE()) AND MONTH($when) = MONTH(CURDATE())")->fetchColumn();
    // Tržba dlaždice = JEN peníze, které opravdu dorazily a nejsou v kase:
    // 'paid' (karta, převod, doplacená dobírka). 'collected' je účtenka v pokladně
    // (sečíst obojí by tržbu zdvojilo) a 'shipped' je dobírka na cestě — nezaplacená.
    $monthTotal = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM eshop_orders
        WHERE status = 'paid' AND YEAR(COALESCE(paid_at, created_at)) = YEAR(CURDATE())
          AND MONTH(COALESCE(paid_at, created_at)) = MONTH(CURDATE())")->fetchColumn();
    $reserved = (int)$pdo->query("SELECT COUNT(*) FROM eshop_orders WHERE status = 'reserved'")->fetchColumn();
    $shipped = (int)$pdo->query("SELECT COUNT(*) FROM eshop_orders WHERE status = 'shipped'")->fetchColumn();

    echo json_encode(['ok' => true, 'today' => $today, 'month' => $month,
        'month_total' => round($monthTotal, 2), 'reserved' => $reserved, 'shipped' => $shipped,
        'orders' => $orders], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('eshop_dashboard: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'orders' => []]);
}
