<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['results' => []]);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

ensureProcurementSchema();
ensureStockLocationsSchema();


$q = trim((string)($_GET['q'] ?? $_GET['term'] ?? ''));
$supplier = trim((string)($_GET['supplier'] ?? ''));
$stockOnly = filter_var($_GET['stock_only'] ?? false, FILTER_VALIDATE_BOOL);
$limit = max(1, min(30, (int)($_GET['limit'] ?? 20)));

// DVA REŽIMY, DVA RŮZNÉ POBOČKOVÉ NÁROKY:
//  • objednávka u dodavatele (Nákupy pošlou parametr `supplier`, i prázdný) — vybírá se
//    z katalogu dodavatele, zápis (api/procurement_request.php) pobočku nehlídá, takže
//    filtrovat nesmíme, jinak by manažer druhé pobočky neobjednal vůbec nic;
//  • výdej ze skladu (díl na zakázku, přiřazení do umístění) — zápis vynucuje
//    crmCanModifyBranchStock nad pobočkou dílu, takže musíme nabízet TOTÉŽ, jinak
//    technik vybere díl, který mu server odmítne, a nedokončí opravu.
$catalogMode = array_key_exists('supplier', $_GET);
// Rozhoduje pobočka PRACOVNÍKA, ne zakázky: server při zápisu porovnává díl proti
// pobočce přihlášeného (api/add_order_item.php → crmCanModifyBranchStock), a technik
// může být na zakázku přiřazený i z druhé pobočky. Admin a Boss vidí obojí.
$branchScoped = !$catalogMode && !isBranchGlobalViewer();
if ($branchScoped) { ensureSkladBranchSchema(); }   // jen když filtr opravdu použijeme
$myBranch = (int)getCurrentStaffBranchId();
// Díly bez pobočky (0 z doby před rozdělením skladů) patří Karlínu — stejně je
// dopočítává crmInventoryBranchId, podle kterého se pak povoluje zápis.
$defBranch = (int)getDefaultBranchId();

try {
    $sql = "SELECT inventory.id, part_name, sku, quantity, sale_price, source_supplier, inventory.location_id, sl.code AS loc_code FROM inventory LEFT JOIN stock_locations sl ON sl.id = inventory.location_id WHERE 1=1";
    $params = [];

    if ($supplier !== '') {
        $sql .= " AND source_supplier = ?";
        $params[] = $supplier;
    }

    if ($stockOnly) {
        $sql .= " AND quantity > 0";
    }

    if ($q !== '') {
        // hledá i v součástkách uvnitř dílů-dárců (jen nevyjmuté)
        ensureInventoryComponentsTable();
        $sql .= " AND (part_name LIKE ? OR sku LIKE ? OR EXISTS (SELECT 1 FROM inventory_components ic WHERE ic.inventory_id = inventory.id AND ic.is_used = 0 AND ic.name LIKE ?))";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($branchScoped) {
        $sql .= " AND COALESCE(NULLIF(inventory.branch_id, 0), ?) = ?";
        $params[] = $defBranch;
        $params[] = $myBranch;
    }

    $sql .= " ORDER BY part_name ASC LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // poziční kódy umístění (R3-P2-B4) — u dílů se zobrazuje pozice, ne kód krabičky
    $posByLoc = [];
    try { $posByLoc = stockLocationPosCodes($pdo, array_column($items, 'location_id')); } catch (Throwable $e) {}

    // součástky uvnitř nalezených dílů (dárci) — do popisku, ať je jasné,
    // proč se „displej" našel v celém iPhonu
    $compByInv = [];
    try {
        $__ids = array_map('intval', array_column($items, 'id'));
        if ($__ids) {
            $__cq = $pdo->prepare("SELECT inventory_id, name FROM inventory_components WHERE is_used = 0 AND inventory_id IN (" . implode(',', array_fill(0, count($__ids), '?')) . ") ORDER BY id ASC");
            $__cq->execute($__ids);
            foreach ($__cq as $__c) { $compByInv[(int)$__c['inventory_id']][] = (string)$__c['name']; }
        }
    } catch (Throwable $e) {}

    $results = [];
    foreach ($items as $item) {
        $label = $item['part_name'];
        if (!empty($item['sku'])) {
            $label .= ' [' . $item['sku'] . ']';
        }
        if (!empty($item['sale_price'])) {
            $label .= ' — ' . number_format((float)$item['sale_price'], 0, ',', ' ') . ' Kč';
        }
        $__pos = $posByLoc[(int)($item['location_id'] ?? 0)] ?? ($item['loc_code'] ?? '');
        if ($__pos !== '') {
            $label .= ' · 📍 ' . $__pos;   // pozice R3-P2-B4 (kde díl fyzicky leží)
        }
        $__comps = $compByInv[(int)$item['id']] ?? [];
        if ($__comps) {
            $label .= ' · uvnitř: ' . implode(', ', array_slice($__comps, 0, 3)) . (count($__comps) > 3 ? ' +' . (count($__comps) - 3) : '');
        }

        $results[] = [
            'id' => (int)$item['id'],
            'text' => $label,
            'part_name' => $item['part_name'],
            'sku' => $item['sku'] ?? '',
            'quantity' => (int)($item['quantity'] ?? 0),
            'sale_price' => (float)($item['sale_price'] ?? 0),
            'supplier_key' => $item['source_supplier'] ?? '',
            'loc_code' => $item['loc_code'] ?? '',
            'pos_code' => $__pos,
            'components' => $__comps,
        ];
    }

    // Prázdný seznam kvůli pobočce se musí vysvětlit — jinak obsluha marně hledá díl,
    // který v CRM vidí, jen leží ve skladu druhé pobočky.
    $message = '';
    if ($branchScoped && !$results && $q !== '') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM inventory
            WHERE (part_name LIKE ? OR sku LIKE ?) AND COALESCE(NULLIF(branch_id, 0), ?) <> ?"
            . ($stockOnly ? " AND quantity > 0" : ''));
        $chk->execute(['%' . $q . '%', '%' . $q . '%', $defBranch, $myBranch]);
        if ((int)$chk->fetchColumn() > 0) {
            $message = 'Ve skladu pobočky ' . skladBranchLabel($myBranch) . ' takový díl není. '
                . 'Hledaný díl má na skladě druhá pobočka — odsud ho vydat nelze, musí se nejdřív převést sem.';
        }
    }

    echo json_encode(['results' => $results, 'message' => $message]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['results' => [], 'message' => $e->getMessage()]);
}
