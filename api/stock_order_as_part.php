<?php
/**
 * Naskladnit zařízení ze zakázky jako DÍL na sklad dílů (inventory).
 * Použití: zařízení, které se nevrací klientovi (neopravitelné, odkoupené, výkup)
 * se z detailu zakázky přidá na sklad dílů jako 1 ks. Smí admin a Boss (Boss=admin).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !hasPermission('admin_access')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('unauthorized')], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')], JSON_UNESCAPED_UNICODE);
    exit;
}

$order_id = (int)($_POST['order_id'] ?? 0);
if ($order_id <= 0) {
    echo json_encode(['success' => false, 'message' => __('missing_id')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $st = $pdo->prepare("SELECT order_code, device_brand, device_model, serial_number, final_cost, estimated_cost, technician_notes FROM orders WHERE id = ?");
    $st->execute([$order_id]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        echo json_encode(['success' => false, 'message' => __('order_not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Název dílu = značka + model zařízení; SKU = sériové číslo (pokud je).
    $partName = trim((string)($order['device_brand'] ?? '') . ' ' . (string)($order['device_model'] ?? ''));
    $partName = preg_replace('/\s+/', ' ', $partName);
    if ($partName === '') {
        $partName = 'Díl ze zakázky ' . (string)($order['order_code'] ?? ('#' . $order_id));
    }
    $sku = trim((string)($order['serial_number'] ?? ''));
    $sku = $sku !== '' ? $sku : null;

    // Nákupní cena = hodnota zakázky (orientační, dá se upravit ve Skladu). Prodejní necháme prázdnou.
    $cost = null;
    if ($order['final_cost'] !== null && $order['final_cost'] !== '') $cost = (float)$order['final_cost'];
    elseif ($order['estimated_cost'] !== null && $order['estimated_cost'] !== '') $cost = (float)$order['estimated_cost'];

    ensureInventoryStockedSchema();
    // 1 ks, min_stock 0 (jednorázový použitý díl → nehlásit „dochází"), viditelný ve Skladu.
    $ins = $pdo->prepare("INSERT INTO inventory (part_name, sku, quantity, cost_price, sale_price, min_stock, is_stocked) VALUES (?, ?, 1, ?, NULL, 0, 1)");
    $ins->execute([$partName, $sku, $cost]);
    $invId = (int)$pdo->lastInsertId();

    // Stopa do zakázky (dohledatelnost) + audit.
    $stamp = date('j.n.Y H:i');
    $note = trim((string)($order['technician_notes'] ?? ''));
    $note = ($note !== '' ? $note . "\n" : '') . '📦 Naskladněno jako díl na sklad dílů (' . $stamp . ', „' . $partName . '", ID dílu ' . $invId . ').';
    $pdo->prepare("UPDATE orders SET technician_notes = ? WHERE id = ?")->execute([$note, $order_id]);

    crmAuditLog('inventory.create', [
        'entity_type' => 'inventory', 'entity_id' => $invId, 'entity_label' => $partName,
        'summary' => 'Zakázka ' . (string)($order['order_code'] ?? ('#' . $order_id)) . ' naskladněna jako díl „' . $partName . '"',
    ]);

    echo json_encode(['success' => true, 'inventory_id' => $invId, 'part_name' => $partName], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
