<?php
/**
 * NÁHLED skladového dílu (read-only) pro modal ve Skladu: karta dílu,
 * umístění (poziční kód R-P-B), součástky uvnitř (dárce) a posledních
 * 8 pohybů z deníku. Gate: přihlášený personál (prohlížet sklad smí
 * každý zaměstnanec — stejné pravidlo jako sklad.php?qr=).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}

ensureStockLocationsSchema();
ensureSkladBranchSchema();
ensureInventoryComponentsTable();
ensureInventoryMovesTable();

$id = (int)($_GET['id'] ?? 0);
try {
    $st = $pdo->prepare("SELECT i.*, sl.code AS loc_code, sl.name AS loc_name FROM inventory i LEFT JOIN stock_locations sl ON sl.id = i.location_id WHERE i.id = ?");
    $st->execute([$id]);
    $it = $st->fetch(PDO::FETCH_ASSOC);
    if (!$it) { echo json_encode(['success' => false, 'message' => 'Díl nenalezen.'], JSON_UNESCAPED_UNICODE); exit; }

    $pos = '';
    if (!empty($it['location_id'])) { $pos = stockLocationPosCode($pdo, (int)$it['location_id']); }

    $comps = [];
    try {
        $cq = $pdo->prepare("SELECT name, is_used FROM inventory_components WHERE inventory_id = ? ORDER BY is_used ASC, id ASC");
        $cq->execute([$id]);
        foreach ($cq as $c) { $comps[] = ['name' => (string)$c['name'], 'used' => (int)$c['is_used']]; }
    } catch (Throwable $e) {}

    $moves = [];
    try {
        $labels = ['restock' => 'naskladnění', 'issue' => 'výdej', 'return' => 'vráceno', 'adjust' => 'úprava počtu',
            'correction' => 'korekce', 'sale' => 'prodej (kasa)', 'sale_cancel' => 'storno prodeje',
            'stock_from_order' => 'ze zakázky', 'vykup_to_part' => 'z výkupu'];
        $mq = $pdo->prepare("SELECT delta, reason, order_id, actor_name, note, created_at FROM inventory_moves WHERE inventory_id = ? ORDER BY id DESC LIMIT 8");
        $mq->execute([$id]);
        foreach ($mq as $m) {
            $moves[] = [
                'delta' => (int)$m['delta'],
                'label' => $labels[$m['reason']] ?? (string)$m['reason'],
                'order_id' => (int)($m['order_id'] ?? 0),
                'actor' => (string)($m['actor_name'] ?? ''),
                'note' => (string)($m['note'] ?? ''),
                'at' => date('j.n.Y H:i', strtotime((string)$m['created_at'])),
            ];
        }
    } catch (Throwable $e) {}

    echo json_encode(['success' => true, 'item' => [
        'id' => (int)$it['id'],
        'part_name' => (string)$it['part_name'],
        'sku' => (string)($it['sku'] ?? ''),
        'quantity' => (int)$it['quantity'],
        'min_stock' => (int)$it['min_stock'],
        'cost_price' => $it['cost_price'] !== null && $it['cost_price'] !== '' ? (float)$it['cost_price'] : null,
        'sale_price' => $it['sale_price'] !== null && $it['sale_price'] !== '' ? (float)$it['sale_price'] : null,
        'device_model' => (string)($it['device_model'] ?? ''),
        'image' => (string)($it['image_path'] ?? ''),
        'branch' => skladBranchLabel((int)($it['branch_id'] ?? 0) ?: (int)getDefaultBranchId()),
        'location_id' => (int)($it['location_id'] ?? 0),
        'loc_code' => (string)($it['loc_code'] ?? ''),
        'loc_name' => (string)($it['loc_name'] ?? ''),
        'pos' => $pos,
        'supplier' => trim((string)($it['source_supplier'] ?? '')) !== '' ? supplierLabel((string)$it['source_supplier']) : '',
        'supplier_url' => (string)($it['source_url'] ?? ''),
        'availability' => (string)($it['supplier_availability'] ?? ''),
    ], 'components' => $comps, 'moves' => $moves], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
