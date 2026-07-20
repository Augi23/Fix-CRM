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

    // Hodnoty z okna (modalu). Když nepřijdou, odvodíme z zařízení/zakázky.
    $partName = trim((string)($_POST['part_name'] ?? ''));
    if ($partName === '') {
        $partName = preg_replace('/\s+/', ' ', trim((string)($order['device_brand'] ?? '') . ' ' . (string)($order['device_model'] ?? '')));
    }
    if ($partName === '') {
        $partName = 'Díl ze zakázky ' . (string)($order['order_code'] ?? ('#' . $order_id));
    }
    $partName = mb_substr($partName, 0, 100);

    $sku = trim((string)($order['serial_number'] ?? ''));
    $sku = $sku !== '' ? mb_substr($sku, 0, 50) : null;

    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    $notesIn = trim((string)($_POST['notes'] ?? ''));

    // Nákupní cena: z okna, jinak hodnota zakázky (orientační, upravitelné ve Skladu).
    $cost = null;
    if (($_POST['cost_price'] ?? '') !== '') {
        $cost = (float)str_replace(',', '.', (string)$_POST['cost_price']);
    } elseif ($order['final_cost'] !== null && $order['final_cost'] !== '') {
        $cost = (float)$order['final_cost'];
    } elseif ($order['estimated_cost'] !== null && $order['estimated_cost'] !== '') {
        $cost = (float)$order['estimated_cost'];
    }

    ensureInventoryStockedSchema();
    // min_stock 0 (jednorázový použitý díl → nehlásit „dochází"), viditelný ve Skladu.
    $ins = $pdo->prepare("INSERT INTO inventory (part_name, sku, quantity, cost_price, sale_price, min_stock, is_stocked) VALUES (?, ?, ?, ?, NULL, 0, 1)");
    $ins->execute([$partName, $sku, $qty, $cost]);
    $invId = (int)$pdo->lastInsertId();

    // Naskladnění zapíšeme i do pohybů skladu (s poznámkou/rozpisem z okna).
    if (function_exists('crmLogInventoryMove')) {
        try { crmLogInventoryMove($invId, $qty, 'stock_from_order', $order_id, $notesIn); } catch (Throwable $e) {}
    }

    // Stopa do zakázky (dohledatelnost) + audit.
    $stamp = date('j.n.Y H:i');
    $trace = '📦 Naskladněno jako díl na sklad dílů (' . $stamp . ', „' . $partName . '", ' . $qty . ' ks, ID dílu ' . $invId . ').';
    if ($notesIn !== '') $trace .= ' Poznámky: ' . $notesIn;
    $note = trim((string)($order['technician_notes'] ?? ''));
    $note = ($note !== '' ? $note . "\n" : '') . $trace;
    $pdo->prepare("UPDATE orders SET technician_notes = ? WHERE id = ?")->execute([$note, $order_id]);

    crmAuditLog('inventory.create', [
        'entity_type' => 'inventory', 'entity_id' => $invId, 'entity_label' => $partName,
        'summary' => 'Zakázka ' . (string)($order['order_code'] ?? ('#' . $order_id)) . ' naskladněna jako díl „' . $partName . '" (' . $qty . ' ks)' . ($notesIn !== '' ? ' — ' . mb_substr($notesIn, 0, 120) : ''),
    ]);

    echo json_encode(['success' => true, 'inventory_id' => $invId, 'part_name' => $partName], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
