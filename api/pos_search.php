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

$qRaw = trim((string)($_GET['q'] ?? ''));
$like = '%' . $qRaw . '%';
$results = [];

try {
    // ── servisní díly ──
    if ($qRaw === '') {
        $st = $pdo->query("SELECT id, part_name, sku, quantity, sale_price FROM inventory
            WHERE quantity > 0 ORDER BY part_name ASC LIMIT 8");
    } else {
        $st = $pdo->prepare("SELECT id, part_name, sku, quantity, sale_price FROM inventory
            WHERE quantity > 0 AND (part_name LIKE ? OR sku LIKE ?) ORDER BY part_name ASC LIMIT 15");
        $st->execute([$like, $like]);
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
        $st = $pdo->query("SELECT id, title, product_code, stock_qty, price, grade FROM products
            WHERE stock_qty > 0 ORDER BY added_at DESC, id DESC LIMIT 8");
    } else {
        $st = $pdo->prepare("SELECT id, title, product_code, stock_qty, price, grade FROM products
            WHERE stock_qty > 0 AND (title LIKE ? OR product_code LIKE ? OR model LIKE ?) ORDER BY title ASC LIMIT 15");
        $st->execute([$like, $like, $like]);
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
} catch (Throwable $e) {
    error_log('pos_search: ' . $e->getMessage());
}

echo json_encode(['success' => true, 'results' => $results]);
