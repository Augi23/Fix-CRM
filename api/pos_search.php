<?php
/**
 * Pokladna — živé vyhledávání prodejných položek.
 * Vrací JEN to, co je fyzicky skladem: servisní díly (inventory, quantity > 0)
 * a produkty pro e-shop (products, stock_qty > 0). Katalogové položky bez zásoby
 * se nenabízejí — na kase se prodává jen to, co lze hned podat přes pult.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}

ensureProductsTable();
ensureProductsPosColumn();
ensurePosTables();
ensureOrderPaymentMethodColumn();


// POBOČKA: kasa smí prodat jen zboží SVÉ pobočky — api/pos_checkout.php položku
// z cizí pobočky odmítne, takže nabízet ji je past (obsluha naskládá košík, který
// na konci neprojde). Admin a Boss prodávají napříč, těm se nefiltruje.
// Řádky s branch_id 0 (z doby před rozdělením skladů) patří Karlínu — stejně to
// dopočítává crmInventoryBranchId(), aby filtr seděl s tím, co povolí checkout.
$branchScoped = !isBranchGlobalViewer();
$myBranch = (int)getCurrentStaffBranchId();
$defBranch = (int)getDefaultBranchId();
// runtime pojistka na sloupec branch_id jen když ho opravdu použijeme —
// jinak by tři SHOW COLUMNS běžely při KAŽDÉM úhozu ve vyhledávání
if ($branchScoped) { ensureSkladBranchSchema(); }
$branchSql = $branchScoped ? " AND COALESCE(NULLIF(branch_id, 0), ?) = ?" : '';
$branchArgs = $branchScoped ? [$defBranch, $myBranch] : [];

$qRaw = trim((string)($_GET['q'] ?? ''));
$like = '%' . $qRaw . '%';
$codeTerms = [];
if ($qRaw !== '') {
    $codeTerms[] = $qRaw;
    if (function_exists('scanNormalizeCandidates')) {
        $rawCodeLen = function_exists('mb_strlen') ? mb_strlen($qRaw, 'UTF-8') : strlen($qRaw);
        $minCandidateLen = $rawCodeLen >= 6 ? max(6, $rawCodeLen - 3) : 1;
        foreach (scanNormalizeCandidates($qRaw) as $candidate) {
            if ($rawCodeLen >= 6 && strlen((string)$candidate) < $minCandidateLen) continue;
            $codeTerms[] = (string)$candidate;
        }
    }
    $codeTerms = array_values(array_unique(array_filter(array_map('trim', $codeTerms), static fn($v) => $v !== '')));
}
$codeLikeSql = static function (string $column, array $terms, array &$params): string {
    if (!$terms) return '0=1';
    $parts = [];
    foreach ($terms as $term) {
        $parts[] = $column . ' LIKE ?';
        $params[] = '%' . $term . '%';
    }
    return '(' . implode(' OR ', $parts) . ')';
};
$results = [];

try {
    // ── servisní díly ──
    if ($qRaw === '') {
        $st = $pdo->prepare("SELECT id, part_name, sku, quantity, sale_price FROM inventory
            WHERE quantity > 0" . $branchSql . " ORDER BY part_name ASC LIMIT 8");
        $st->execute($branchArgs);
    } else {
        $params = [$like];
        $skuSql = $codeLikeSql('sku', $codeTerms, $params);
        $st = $pdo->prepare("SELECT id, part_name, sku, quantity, sale_price FROM inventory
            WHERE quantity > 0 AND (part_name LIKE ? OR $skuSql)" . $branchSql . " ORDER BY part_name ASC LIMIT 15");
        $st->execute(array_merge($params, $branchArgs));
    }
    foreach ($st->fetchAll() as $r) {
        $results[] = [
            'type' => 'part',
            'id' => (int)$r['id'],
            'name' => (string)$r['part_name'],
            'code' => (string)($r['sku'] ?? ''),
            'stock' => (int)$r['quantity'],
            'price' => (float)$r['sale_price'],
            'used' => false,
        ];
    }

    // ── produkty (použitá elektronika + příslušenství) ──
    if ($qRaw === '') {
        $st = $pdo->prepare("SELECT id, title, product_code, stock_qty, price, grade FROM products
            WHERE stock_qty > 0" . $branchSql . " ORDER BY added_at DESC, id DESC LIMIT 8");
        $st->execute($branchArgs);
    } else {
        $params = [$like, $like];
        $productCodeSql = $codeLikeSql('product_code', $codeTerms, $params);
        $st = $pdo->prepare("SELECT id, title, product_code, stock_qty, price, grade FROM products
            WHERE stock_qty > 0 AND (title LIKE ? OR model LIKE ? OR $productCodeSql)" . $branchSql . " ORDER BY title ASC LIMIT 15");
        $st->execute(array_merge($params, $branchArgs));
    }
    foreach ($st->fetchAll() as $r) {
        $results[] = [
            'type' => 'product',
            'id' => (int)$r['id'],
            'name' => (string)$r['title'] . (!empty($r['grade']) ? ' (stav ' . $r['grade'] . ')' : ''),
            'code' => (string)$r['product_code'],
            'stock' => (int)$r['stock_qty'],
            'price' => (float)$r['price'],
            'used' => true,   // §90 — použité zboží
        ];
    }

    // ── hotové zakázky k úhradě přes kasu ──
    // Nabízet až při konkrétním hledání, aby prázdné kliknutí do pole nezahltilo
    // kasu seznamem všech připravených oprav.
    if ($qRaw !== '') {
        $statusSql = orderStatusSqlIn($pdo, 'completed') . "," . $pdo->quote('Vydáno - čeká na platbu');
        $codeParams = [];
        $orderCodeSql = $codeLikeSql('o.order_code', $codeTerms, $codeParams);
        $legacyCodeSql = $codeLikeSql('o.legacy_code', $codeTerms, $codeParams);
        $st = $pdo->prepare("SELECT o.id, o.order_code, o.legacy_code, o.device_brand, o.device_model,
                   o.final_cost, o.estimated_cost, c.first_name, c.last_name, c.company
            FROM orders o
            JOIN customers c ON c.id = o.customer_id
            WHERE o.status IN ($statusSql)
              AND (o.payment_method IS NULL OR o.payment_method = '')
              AND COALESCE(NULLIF(o.final_cost, 0), NULLIF(o.estimated_cost, 0), 0) > 0
              AND NOT EXISTS (SELECT 1 FROM pos_sales ps WHERE ps.order_id = o.id AND ps.status = 'completed')
              AND ($orderCodeSql OR $legacyCodeSql OR o.device_brand LIKE ? OR o.device_model LIKE ?
                   OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.company LIKE ?)"
              . orderBranchScopeSql('o.branch_id', 'o.technician_id') . "
            ORDER BY o.updated_at DESC, o.id DESC LIMIT 10");
        $st->execute(array_merge($codeParams, [$like, $like, $like, $like, $like]));
        foreach ($st->fetchAll() as $r) {
            $cust = trim((string)($r['company'] ?? '')) ?: trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
            $results[] = [
                'type' => 'order',
                'id' => (int)$r['id'],
                'name' => crmOrderPosItemName($r) . ($cust !== '' ? ' · ' . $cust : ''),
                'code' => orderDisplayCode($r),
                'stock' => 1,
                'price' => crmOrderPosAmount($r),
                'used' => false,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('pos_search: ' . $e->getMessage());
}

// Prázdný výsledek kvůli pobočce se musí vysvětlit: obsluha by jinak zírala na
// „nic nenalezeno" u zboží, které v systému vidí — jen leží na druhé pobočce.
$message = '';
if ($branchScoped && !$results && $qRaw !== '') {
    try {
        $elsewhere = 0;
        $st = $pdo->prepare("SELECT COUNT(*) FROM inventory
            WHERE quantity > 0 AND part_name LIKE ? AND COALESCE(NULLIF(branch_id, 0), ?) <> ?");
        $st->execute([$like, $defBranch, $myBranch]);
        $elsewhere += (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM products
            WHERE stock_qty > 0 AND (title LIKE ? OR model LIKE ?) AND COALESCE(NULLIF(branch_id, 0), ?) <> ?");
        $st->execute([$like, $like, $defBranch, $myBranch]);
        $elsewhere += (int)$st->fetchColumn();
        if ($elsewhere > 0) {
            $message = 'Skladem na pobočce ' . skladBranchLabel($myBranch) . ' nic takového není. '
                . 'Hledané zboží (' . $elsewhere . ' pol.) leží na druhé pobočce a odsud ho prodat nelze — '
                . 'musí ho prodat ona, nebo se nejdřív musí převést sem.';
        }
    } catch (Throwable $e) {
        error_log('pos_search (kontrola druhé pobočky): ' . $e->getMessage());
    }
}

echo json_encode(['success' => true, 'results' => $results, 'code_candidates' => $codeTerms, 'message' => $message]);
