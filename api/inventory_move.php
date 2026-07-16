<?php
/**
 * Skladové pohyby (QR i desktop):
 *   op=restock — naskladnění (+qty), smí každý zaměstnanec
 *   op=issue   — výdej na zakázku (-qty): přidá díl k zakázce s cenou,
 *                OKAMŽITĚ odečte sklad (order_items.stock_deducted=1) a zapíše pohyb
 *   op=correct — korekce absolutního stavu (jen vedení/admin)
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$op = (string)($_POST['op'] ?? '');
$inventory_id = (int)($_POST['inventory_id'] ?? 0);
$qty = (int)($_POST['qty'] ?? 0);
$order_id = (int)($_POST['order_id'] ?? 0);
$note = trim((string)($_POST['note'] ?? ''));

ensureInventoryMovesTable();
ensureOrderItemStockFlag(); // DDL před transakcí

try {
    if ($inventory_id <= 0) { throw new Exception('Chybí díl.'); }
    $item = $pdo->prepare("SELECT id, part_name, sku, quantity, sale_price FROM inventory WHERE id = ?");
    $item->execute([$inventory_id]);
    $inv = $item->fetch();
    if (!$inv) { throw new Exception('Díl nenalezen.'); }

    if ($op === 'restock') {
        if ($qty < 1 || $qty > 10000) { throw new Exception('Zadej počet naskladněných kusů (1–10000).'); }
        changeInventoryQuantity($inventory_id, $qty);
        crmLogInventoryMove($inventory_id, $qty, 'restock', null, $note);
        crmAuditLog('inventory.restock', [
            'entity_type' => 'inventory', 'entity_id' => $inventory_id, 'entity_label' => (string)$inv['part_name'],
            'summary' => 'Naskladněno ' . $qty . ' ks „' . $inv['part_name'] . '" (nový stav: ' . ((int)$inv['quantity'] + $qty) . ' ks)',
        ]);
        echo json_encode(['success' => true, 'new_quantity' => (int)$inv['quantity'] + $qty,
            'message' => 'Naskladněno ' . $qty . ' ks — skladem nyní ' . ((int)$inv['quantity'] + $qty) . ' ks.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'issue') {
        if ($qty < 1 || $qty > 1000) { throw new Exception('Zadej počet odebíraných kusů.'); }
        if ($order_id <= 0) { throw new Exception('Vyber zakázku, na kterou díl bereš.'); }
        $o = $pdo->prepare("SELECT id, order_code, status FROM orders WHERE id = ?");
        $o->execute([$order_id]);
        $order = $o->fetch();
        if (!$order) { throw new Exception('Zakázka nenalezena.'); }
        if (isOrderStatusIn((string)$order['status'], 'collected')) { throw new Exception('Zakázka už je vydaná — díl na ni nejde přidat.'); }
        if ((int)$inv['quantity'] < $qty) { throw new Exception('Skladem je jen ' . (int)$inv['quantity'] . ' ks.'); }

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO order_items (order_id, inventory_id, quantity, price, stock_deducted) VALUES (?, ?, ?, ?, 1)")
            ->execute([$order_id, $inventory_id, $qty, $inv['sale_price']]);
        changeInventoryQuantity($inventory_id, -$qty);   // fyzicky vzato TEĎ
        $pdo->commit();

        crmLogInventoryMove($inventory_id, -$qty, 'issue', $order_id);
        $oc = trim((string)$order['order_code']) !== '' ? $order['order_code'] : ('#' . $order_id);
        crmAuditLog('order.item_add', [
            'entity_type' => 'order', 'entity_id' => $order_id, 'entity_label' => $oc,
            'summary' => 'QR výdej: „' . $inv['part_name'] . '" ' . $qty . ' ks na zakázku ' . $oc . ' (sklad: ' . ((int)$inv['quantity'] - $qty) . ' ks)',
        ]);
        echo json_encode(['success' => true, 'new_quantity' => (int)$inv['quantity'] - $qty,
            'order_code' => $oc, 'order_url' => 'view_order.php?id=' . $order_id,
            'message' => 'Vydáno ' . $qty . ' ks na zakázku ' . $oc . ' (' . number_format((float)$inv['sale_price'], 0, ',', ' ') . ' Kč/ks). Skladem zbývá ' . ((int)$inv['quantity'] - $qty) . ' ks.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'correct') {
        if (!hasPermission('admin_access') && !isBranchGlobalViewer()) { throw new Exception('Korekci stavu smí jen vedení.'); }
        if ($qty < 0 || $qty > 100000) { throw new Exception('Neplatný cílový stav.'); }
        $delta = $qty - (int)$inv['quantity'];
        $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?")->execute([$qty, $inventory_id]);
        crmLogInventoryMove($inventory_id, $delta, 'correction', null, $note !== '' ? $note : 'Ruční korekce stavu');
        crmAuditLog('inventory.update', [
            'entity_type' => 'inventory', 'entity_id' => $inventory_id, 'entity_label' => (string)$inv['part_name'],
            'summary' => 'Korekce stavu „' . $inv['part_name'] . '": ' . (int)$inv['quantity'] . ' → ' . $qty . ' ks',
        ]);
        echo json_encode(['success' => true, 'new_quantity' => $qty, 'message' => 'Stav opraven na ' . $qty . ' ks.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Neznámá operace.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
