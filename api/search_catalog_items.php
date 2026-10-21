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

// ── „Objednat díl" ze zakázky (v4.0.0): katalogy VŠECH dodavatelů najednou, nejdřív
//    díly skladem u dodavatele a nejlevnější; v popisku dodavatel, cena a dostupnost.
//    Díly, které dodavatel přestal nabízet, se nenabízí. Bez pobočkového filtru
//    (objednává se u dodavatele, ne vydává ze skladu).
if (!empty($_GET['for_order'])) {
    require_once __DIR__ . '/../includes/catalog_sync.php';
    ensureSupplierSyncSchema();
    $limit = max(1, min(40, (int)($_GET['limit'] ?? 20)));
    $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($q)) ?: [], fn($w) => mb_strlen($w) >= 2 || ctype_digit($w)));
    if (!$words) { echo json_encode(['results' => []]); exit; }
    // české názvy dílů → i anglické (MobileSentrix má katalog anglicky)
    $syn = [
        'displej' => ['displej', 'display', 'lcd', 'oled', 'screen'], 'display' => ['displej', 'display', 'lcd', 'oled', 'screen'],
        'lcd' => ['lcd', 'displej', 'display'], 'baterie' => ['baterie', 'battery', 'baterii'], 'battery' => ['battery', 'baterie'],
        'sklo' => ['sklo', 'glass'], 'konektor' => ['konektor', 'charging port', 'nabíjecí', 'dock'], 'nabijeci' => ['nabíjecí', 'charging'],
        'kamera' => ['kamera', 'camera', 'fotoaparát'], 'reproduktor' => ['reproduktor', 'speaker', 'loudspeaker'],
        'sluchatko' => ['sluchátko', 'earpiece', 'ear speaker'], 'sluchátko' => ['sluchátko', 'earpiece', 'ear speaker'],
        'kryt' => ['kryt', 'housing', 'back glass', 'back cover'], 'zadni' => ['zadní', 'back', 'rear'], 'zadní' => ['zadní', 'back', 'rear'],
        'tlacitko' => ['tlačítko', 'button'], 'tlačítko' => ['tlačítko', 'button'], 'flex' => ['flex', 'kabel', 'cable'],
        'mikrofon' => ['mikrofon', 'microphone'], 'vibrace' => ['vibrace', 'vibra', 'taptic'], 'antena' => ['anténa', 'antenna'],
    ];
    $where = ["i.source_supplier IS NOT NULL", "i.source_supplier <> ''", "i.supplier_gone_at IS NULL",
              "COALESCE(i.supplier_availability, '') <> 'Dodavatel už nenabízí'"];
    $exactRes = [];   // „13 pro" bez „max" → přesný model (13 Pro) před 13 Pro Max
    $params = [];
    $leadRe = '';   // první „typ dílu" ve hledání — díly, jejichž název jím začíná, půjdou nahoru
    // „13 pro (max)" = model, ne české „pro" (předložka) → číslo + přívlastek jako jedna fráze
    $merged = [];
    for ($k = 0; $k < count($words); $k++) {
        $w = $words[$k];
        if (preg_match('/^\d+$/', $w)) {
            while (isset($words[$k + 1]) && in_array($words[$k + 1], ['pro', 'max', 'mini', 'plus', 'air', 'ultra', 'se'], true)) {
                $w .= ' ' . $words[++$k];
            }
        }
        $merged[] = $w;
    }
    $words = $merged;
    foreach (array_slice($words, 0, 6) as $w) {
        if (preg_match('/^\d+ /', $w)) {
            $phrase = '(^|[^0-9])' . str_replace(' ', '[ /,-]*', preg_quote($w));
            $where[] = "(i.part_name REGEXP ?)";
            $params[] = $phrase . '([^a-z0-9]|$)';
            if (!preg_match('/(max|plus|mini)$/', $w)) { $exactRes[] = $phrase . '(?![ /,-]*(max|plus|mini))([^a-z0-9]|$)'; }
            continue;
        }
        if (preg_match('/^\d+$/', $w)) {
            // „11" bez přívlastku → nahoru přesně iPhone 11 (před 11 Pro / Pro Max)
            $exactRes[] = '(^|[^0-9])' . $w . '(?![ /,-]*(pro|max|plus|mini))([^a-z0-9]|$)';
            // číslo modelu jako CELÉ číslo: „13" nesmí najít 130, 6S ani „(13 ks)"
            $where[] = "(i.part_name REGEXP ? OR i.sku = ?)";
            $params[] = '(^|[^0-9])' . $w . '([^0-9]|$)';
            $params[] = $w;
            continue;
        }
        $alts = $syn[$w] ?? [$w];
        $re = implode('|', array_map(fn($a) => preg_quote($a), $alts));
        $where[] = "(i.part_name REGEXP ? OR i.sku LIKE ?)";
        $params[] = $re;
        $params[] = '%' . $w . '%';
        if ($leadRe === '' && isset($syn[$w])) { $leadRe = '^(' . $re . ')'; }
    }
    $orderLead = $leadRe !== '' ? "(i.part_name REGEXP " . $pdo->quote($leadRe) . ") DESC," : '';
    // drobné příslušenství (fólie, čipy, lepky, flexy na baterii…) dolů — pokud ho výslovně nehledá
    $acc = 'film|protective|ic chip|chip|\\bic\\b|adhesive|lepka|podlep|páska|tape|tag-on|lens|screw|šroub|bracket|sticker|fólie|folie|tool|nářadí|gasket|mesh|holder|cover film|fpc|bezel|\\bring\\b|bead|tester|testing|\\bmat\\b|spacer|shim|iheater|heater|mřížka|mrizka|laminat|programmer|daughter board|eeprom|heat shield|steel plate with|vacuum|dust|repair tray|stencil|reballing';
    $accTyped = (bool)preg_match('/' . $acc . '/iu', implode(' ', $words));
    $orderAcc = $accTyped ? '' : "(i.part_name REGEXP " . $pdo->quote($acc) . ") ASC,";
    // bez zadané značky mají přednost díly na iPhone (servis je hlavně Apple)
    $brandTyped = (bool)preg_match('/samsung|xiaomi|redmi|huawei|pixel|google|oneplus|motorola|ipad|watch|macbook|airpods|imac|honor|oppo|realme|sony|nokia/iu', implode(' ', $words));
    $orderBrand = $brandTyped ? '' : "(i.part_name LIKE '%iphone%') DESC,";
    // cena JEN ověřená synchronizací — stará importovaná (u MobileSentrix i v eurech) by mátla
    $priceSql = "CASE WHEN i.price_synced_at IS NOT NULL THEN i.supplier_price_czk END";
    $st = $pdo->prepare("SELECT i.id, i.part_name, i.sku, i.source_supplier, i.source_url, i.supplier_availability,
            i.supplier_stock_qty, i.quantity, $priceSql AS price, i.price_synced_at
        FROM inventory i WHERE " . implode(' AND ', $where) . "
        ORDER BY $orderAcc $orderBrand " . implode('', array_map(fn($re) => "(i.part_name REGEXP " . $pdo->quote($re) . ") DESC,", $exactRes)) . " $orderLead
                 ($priceSql IS NOT NULL) DESC,
                 (COALESCE(i.supplier_stock_qty, 1) > 0 AND COALESCE(i.supplier_availability, '') NOT LIKE '%yprod%') DESC,
                 $priceSql ASC
        LIMIT " . $limit);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $avail = trim((string)($r['supplier_availability'] ?? ''));
        $out[] = [
            'id' => (int)$r['id'],
            'text' => $r['part_name'] . ' — ' . supplierLabel((string)$r['source_supplier']) . ((float)$r['price'] > 0 ? ' — ' . number_format((float)$r['price'], 0, ',', ' ') . ' Kč' : ''),
            'part_name' => (string)$r['part_name'],
            'sku' => (string)($r['sku'] ?? ''),
            'supplier_key' => (string)$r['source_supplier'],
            'supplier' => supplierLabel((string)$r['source_supplier']),
            'price' => (float)$r['price'],
            'availability' => $avail,
            'in_stock_here' => (int)$r['quantity'],
            'url' => (string)($r['source_url'] ?? ''),
            'checked' => !empty($r['price_synced_at']) ? date('j. n.', strtotime((string)$r['price_synced_at'])) : '',
        ];
    }
    echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}
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

    // poziční kódy umístění (R3-P2) — u dílů se zobrazuje pozice, ne kód police
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
            $label .= ' · 📍 ' . $__pos;   // pozice R3-P2 (kde díl fyzicky leží)
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
