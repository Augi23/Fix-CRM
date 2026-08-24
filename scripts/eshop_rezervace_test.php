<?php
/**
 * TEST NANEČISTO — rezervace z e-shopu (po vzoru scripts/kb_test_parovani.php).
 * Nic nemění; jen ověří, že sedí invariant „reserved_qty = součet otevřených
 * rezervací" a hlásí podezřelé stavy (rezervace na neexistující kus, rezervace
 * nad rámec skladu, dlouho čekající objednávky).
 *   php scripts/eshop_rezervace_test.php
 */
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';

ensureEshopOrdersTable();
ensureEshopReservationSchema();
$problems = 0;

echo "── Invariant: products.reserved_qty = součet otevřených rezervací ──\n";
$rows = $pdo->query("SELECT p.id, p.product_code, p.title, p.stock_qty, COALESCE(p.reserved_qty,0) AS reserved_qty,
        COALESCE((SELECT SUM(r.qty) FROM eshop_order_reservations r
                  JOIN eshop_orders o ON o.id = r.order_id
                  WHERE r.product_id = p.id AND r.released_at IS NULL AND o.status = 'reserved'), 0) AS open_res
    FROM products p
    WHERE COALESCE(p.reserved_qty,0) <> 0
       OR EXISTS (SELECT 1 FROM eshop_order_reservations r2 JOIN eshop_orders o2 ON o2.id = r2.order_id
                  WHERE r2.product_id = p.id AND r2.released_at IS NULL AND o2.status = 'reserved')")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "  (žádné rezervace — nic ke kontrole)\n"; }
foreach ($rows as $r) {
    $ok = (int)$r['reserved_qty'] === (int)$r['open_res'];
    if (!$ok) { $problems++; }
    echo ($ok ? '  ✅ ' : '  ❌ ') . $r['product_code'] . ' — ' . mb_substr((string)$r['title'], 0, 40)
        . ': reserved_qty=' . (int)$r['reserved_qty'] . ', otevřené rezervace=' . (int)$r['open_res'] . "\n";
    if ((int)$r['reserved_qty'] > (int)$r['stock_qty']) {
        $problems++;
        echo "     ❌ rezervováno víc, než je skladem (stock=" . (int)$r['stock_qty'] . ")\n";
    }
}

echo "\n── Rezervace na kus, který ve skladu není ──\n";
$orph = $pdo->query("SELECT r.order_id, o.order_ref, r.product_id FROM eshop_order_reservations r
    JOIN eshop_orders o ON o.id = r.order_id
    LEFT JOIN products p ON p.id = r.product_id
    WHERE r.released_at IS NULL AND o.status = 'reserved' AND p.id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
foreach ($orph as $o) { $problems++; echo "  ❌ " . $o['order_ref'] . " → produkt #" . (int)$o['product_id'] . " neexistuje\n"; }
if (!$orph) { echo "  ✅ žádná osiřelá rezervace\n"; }

echo "\n── Dlouho čekající rezervace (víc než 7 dní) ──\n";
$old = $pdo->query("SELECT order_ref, pay_id, customer_name, created_at FROM eshop_orders
    WHERE status = 'reserved' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
foreach ($old as $o) {
    echo "  ⚠️  " . $o['order_ref'] . ' (' . (string)$o['pay_id'] . ', ' . (string)$o['customer_name'] . ') čeká od '
        . date('j. n. Y', strtotime((string)$o['created_at'])) . " — zvaž zrušení rezervace\n";
}
if (!$old) { echo "  ✅ žádná zapomenutá rezervace\n"; }

echo "\n" . ($problems === 0 ? "VŠE SEDÍ.\n" : "NALEZENO PROBLÉMŮ: $problems\n");
exit($problems === 0 ? 0 : 1);
