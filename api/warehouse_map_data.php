<?php
/**
 * Data pro 3D MAPU SKLADU (vizualizace z Claude Design, sklad_mapa.php).
 *   GET api/warehouse_map_data.php?branch=<id>[&with_parts=1]
 * Vrací umístění pobočky (regály/police/krabičky) + obsazenost; s with_parts=1
 * i seznam dílů v každém umístění (pro boční panel / hover v mapě).
 * Mapa se vazbí na `code` (RegK1, RegK1-P2, KrK001…) — geometrie je ve scéně,
 * struktura a čísla VŽDY odsud (živá data, nic natvrdo).
 * Práva: přihlášení + manage_inventory (rozvržení skladu = provozní informace,
 * stejné pravidlo jako location_labels.php). Bez přihlášení HTTP 401 (fetch
 * v iframe jede same-origin, session cookie se posílá sama).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nepřihlášeno — otevři mapu z CRM.'], JSON_UNESCAPED_UNICODE); exit;
}
if (!hasPermission('manage_inventory')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('unauthorized')], JSON_UNESCAPED_UNICODE); exit;
}

ensureStockLocationsSchema();
ensureSkladBranchSchema();

$branchId = (int)skladBranchOrOwn();
if ($branchId <= 0) { $branchId = (int)getDefaultBranchId(); }
$withParts = !empty($_GET['with_parts']);

try {
    $locations = stockLocationsAll($pdo, true, $branchId);

    // obsazenost: počet karet dílů + součet kusů na umístění (jen díly téhle pobočky)
    $counts = [];
    $cq = $pdo->prepare("SELECT location_id, COUNT(*) c, COALESCE(SUM(quantity),0) q
                         FROM inventory WHERE location_id IS NOT NULL AND branch_id = ? GROUP BY location_id");
    $cq->execute([$branchId]);
    foreach ($cq as $r) { $counts[(int)$r['location_id']] = ['c' => (int)$r['c'], 'q' => (int)$r['q']]; }

    // volitelně díly per umístění (boční panel: co v krabičce je)
    $partsByLoc = [];
    if ($withParts) {
        // součástky uvnitř dílů-dárců (nevyjmuté) — hledání v mapě je pak najde
        $compByInv = [];
        try {
            ensureInventoryComponentsTable();
            $cc = $pdo->prepare("SELECT ic.inventory_id, GROUP_CONCAT(ic.name ORDER BY ic.id SEPARATOR ', ') names
                                 FROM inventory_components ic JOIN inventory i ON i.id = ic.inventory_id
                                 WHERE ic.is_used = 0 AND i.branch_id = ? GROUP BY ic.inventory_id");
            $cc->execute([$branchId]);
            foreach ($cc as $r) { $compByInv[(int)$r['inventory_id']] = (string)$r['names']; }
        } catch (Throwable $e) {}
        $pq = $pdo->prepare("SELECT id, location_id, part_name, sku, quantity, sale_price, device_model, image_path
                             FROM inventory WHERE location_id IS NOT NULL AND branch_id = ? ORDER BY part_name ASC");
        $pq->execute([$branchId]);
        foreach ($pq as $p) {
            $partsByLoc[(int)$p['location_id']][] = [
                'id' => (int)$p['id'],
                'name' => (string)$p['part_name'],
                'sku' => (string)($p['sku'] ?? ''),
                'qty' => (int)$p['quantity'],
                'price' => (float)$p['sale_price'],
                'model' => (string)($p['device_model'] ?? ''),
                'image' => (string)($p['image_path'] ?? ''),
                'components' => $compByInv[(int)$p['id']] ?? '',
            ];
        }
    }

    $out = [];
    foreach ($locations as $l) {
        $id = (int)$l['id'];
        $row = [
            'id' => $id,
            'code' => (string)$l['code'],
            'type' => (string)$l['type'],                     // regal | police | krabicka
            'name' => (string)$l['name'],
            'note' => (string)($l['note'] ?? ''),
            'parent_id' => (int)($l['parent_id'] ?? 0),       // 0 = bez rodiče
            'parent_code' => (string)($l['parent_code'] ?? ''),
            'parts_count' => $counts[$id]['c'] ?? 0,
            'units' => $counts[$id]['q'] ?? 0,
        ];
        if ($withParts) { $row['parts'] = $partsByLoc[$id] ?? []; }
        $out[] = $row;
    }

    $unplaced = 0;
    $uq = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE location_id IS NULL AND branch_id = ? AND " . inventoryStockedWhereSql());
    $uq->execute([$branchId]);
    $unplaced = (int)$uq->fetchColumn();

    // uložené rozmístění regálů v mapě (ukládá op=map_layout ve stock_locations API)
    $layout = null;
    try {
        $lr = json_decode((string)get_setting('warehouse3d_layout_' . $branchId, ''), true);
        if (is_array($lr) && isset($lr['racks']) && is_array($lr['racks'])) { $layout = $lr; }
    } catch (Throwable $e) {}

    echo json_encode([
        'success' => true,
        'layout' => $layout,
        'branch' => [
            'id' => $branchId,
            'label' => skladBranchLabel($branchId),
            'short' => skladBranchShort($branchId),           // K / CR — prefix v kódech
        ],
        'generated_at' => date('Y-m-d H:i:s'),
        'unplaced_parts' => $unplaced,
        'locations' => $out,
        // šablony odkazů pro kliknutí v mapě ({id}/{branch} nahradí mapa sama);
        // mapa běží v iframe → navigovat přes window.parent.location.href
        'links' => [
            'location' => 'sklad.php?loc={id}',
            'inventory' => 'inventory.php?branch={branch}&location={id}',
            'manage' => 'sklad_umisteni.php?branch={branch}',
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
