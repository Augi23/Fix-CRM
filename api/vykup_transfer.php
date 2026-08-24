<?php
/**
 * Převod vykoupeného kusu (products.is_vykup=1, záložka Výkupy) dál:
 *   op=to_parts — na sklad NÁHRADNÍCH DÍLŮ (inventory): vznikne karta dílu
 *                 (název kusu, SKU = kód/sériovko, nákupka = výkupní cena,
 *                 model, kusy, pobočka kusu). Kus ve Výkupech se vynuluje a
 *                 prováže (products.moved_to_inventory_id) — podruhé to nejde.
 *   op=to_sale  — do PRODEJE (záložka Produkty): nastaví prodejní cenu,
 *                 shodí is_vykup a volitelně odkryje na e-shopu (hide_eshop=0).
 * Práva: manage_inventory + sklad pobočky kusu (crmCanModifyBranchStock).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || !hasPermission('manage_inventory')) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

ensureProductsTable();
ensureProductsVykupColumns();
ensureInventoryStockedSchema();
ensureSkladBranchSchema();

$op = (string)($_POST['op'] ?? '');
$pid = (int)($_POST['product_id'] ?? 0);

try {
    if ($pid <= 0) { throw new Exception('Chybí kus.'); }
    // Kus držený objednávkou z e-shopu se nesmí převést na díl — zákazník by přišel
    // k prázdnému regálu a objednávku by nešlo vyřídit.
    if (function_exists('afxProductReservationBlock')) {
        $resBlock = afxProductReservationBlock($pid);
        if ($resBlock !== '') { echo json_encode(['success' => false, 'message' => $resBlock], JSON_UNESCAPED_UNICODE); exit; }
    }
    $st = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $st->execute([$pid]);
    $p = $st->fetch();
    if (!$p) { throw new Exception('Kus nenalezen.'); }
    if (!(int)($p['is_vykup'] ?? 0)) { throw new Exception('Tenhle kus není ve Výkupech.'); }
    $branch = (int)($p['branch_id'] ?? 0) ?: getDefaultBranchId();
    if (!crmCanModifyBranchStock($branch)) { throw new Exception('Kus patří skladu jiné pobočky — převádět ho smí její zaměstnanci.'); }
    if (!empty($p['loan_at'])) { throw new Exception('Kus je zapůjčený / v komisi — nejdřív ho vrať do skladu.'); }
    if ((int)($p['moved_to_inventory_id'] ?? 0) > 0) { throw new Exception('Kus už je převedený na díly (karta dílu #' . (int)$p['moved_to_inventory_id'] . ').'); }

    $title = (string)$p['title'];

    if ($op === 'to_parts') {
        // dárce na díly: karta dílu se vším, co o kusu víme (sériovko, výkupní
        // cena jako nákupní, model) — prodejní cenu a umístění doplní obsluha
        $qty = max(1, (int)($p['stock_qty'] ?? 1));
        $cost = ($p['purchase_price'] !== null && $p['purchase_price'] !== '') ? (float)$p['purchase_price'] : null;
        $model = trim((string)($p['model'] ?? ''));
        $sku = trim((string)($p['product_code'] ?? ''));
        $ins = $pdo->prepare("INSERT INTO inventory (part_name, sku, quantity, cost_price, sale_price, min_stock, is_stocked, device_model, branch_id) VALUES (?, ?, ?, ?, NULL, 0, 1, ?, ?)");
        $ins->execute([mb_substr($title, 0, 255), $sku !== '' ? mb_substr($sku, 0, 50) : null,
            $qty, $cost, $model !== '' ? mb_substr($model, 0, 64) : null, $branch]);
        $invId = (int)$pdo->lastInsertId();
        try { crmLogInventoryMove($invId, $qty, 'vykup_to_part', null, 'Z výkupu: ' . $title); } catch (Throwable $e) {}
        $pdo->prepare("UPDATE products SET stock_qty = 0, moved_to_inventory_id = ? WHERE id = ?")->execute([$invId, $pid]);
        crmAuditLog('inventory.create', [
            'entity_type' => 'inventory', 'entity_id' => $invId, 'entity_label' => $title,
            'summary' => 'Výkup „' . $title . '" převeden na sklad náhradních dílů (' . $qty . ' ks, karta #' . $invId . ')',
        ]);
        echo json_encode(['success' => true, 'inventory_id' => $invId,
            'message' => 'Převedeno na díly — vznikla karta „' . $title . '" (' . $qty . ' ks). Najdeš ji v Servis — náhradní díly.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($op === 'to_sale') {
        $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
        if ($price <= 0) { throw new Exception('Zadej prodejní cenu.'); }
        if ((int)($p['stock_qty'] ?? 0) < 1) { throw new Exception('Kus nemá nic skladem — do prodeje nejde zařadit.'); }
        $hideEshop = !empty($_POST['show_eshop']) ? 0 : 1;
        $pdo->prepare("UPDATE products SET is_vykup = 0, price = ?, hide_eshop = ? WHERE id = ?")
            ->execute([$price, $hideEshop, $pid]);
        crmAuditLog('products.update', [
            'entity_type' => 'product', 'entity_id' => $pid, 'entity_label' => $title,
            'summary' => 'Výkup „' . $title . '" zařazen do prodeje za ' . number_format($price, 0, ',', ' ') . ' Kč' . ($hideEshop ? ' (na e-shopu skrytý)' : ' (viditelný na e-shopu)'),
        ]);
        echo json_encode(['success' => true,
            'message' => 'Zařazeno do prodeje za ' . number_format($price, 0, ',', ' ') . ' Kč — kus je teď v záložce Produkty' . ($hideEshop ? '.' : ' a pojede na e-shop.')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new Exception('Neznámá operace.');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
