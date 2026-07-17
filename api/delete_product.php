<?php
/**
 * Smazání jednoho produktu (e-shop) — pojistka na omylem naimportovaný řádek.
 * Pozor: pokud kus zůstává v souboru appky, další import ho vrátí.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanManageProducts()) {
    echo json_encode(['success' => false, 'message' => 'Mazání produktů smí jen vedení (admin, Boss, manažer).']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Chybí ID produktu.']); exit;
}

ensureProductsTable();

try {
    $st = $pdo->prepare("SELECT id, product_code, title FROM products WHERE id = ?");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Produkt nenalezen.']); exit;
    }
    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
    crmAuditLog('products.delete', [
        'entity_type' => 'products', 'entity_id' => (int)$p['id'], 'entity_label' => (string)$p['title'],
        'summary' => 'Smazán produkt „' . $p['title'] . '" (' . $p['product_code'] . ') ze skladu e-shopu',
    ]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('delete_product: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Smazání selhalo.']);
}
