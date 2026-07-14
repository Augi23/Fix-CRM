<?php
/* Uvolnění zakázky dalšímu technikovi: technik dokončil SVOU specializovanou
   část → zakázka přejde do „Čeká na technika" bez přiřazení. Jeho pracovní
   i přiřazovací čas se pozastaví; dalšímu technikovi se čas rozběhne, až si
   zakázku převezme a dá ji „V opravě". */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Neplatný token']); exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) { echo json_encode(['ok' => false, 'error' => 'Chybí zakázka']); exit; }

$st = $pdo->prepare("SELECT id, status, technician_id, branch_id FROM orders WHERE id = ? LIMIT 1");
$st->execute([$orderId]);
$order = $st->fetch();
if (!$order) { echo json_encode(['ok' => false, 'error' => 'Zakázka nenalezena']); exit; }
if (!canAccessOrderBranch($order)) { echo json_encode(['ok' => false, 'error' => 'Bez oprávnění']); exit; }

$curTech = (int)($order['technician_id'] ?? 0);
if ($curTech === 0) { echo json_encode(['ok' => false, 'error' => 'Zakázka nemá přiřazeného technika']); exit; }
if (!isOrderStatusIn((string)$order['status'], 'active')) {
    echo json_encode(['ok' => false, 'error' => 'Uvolnit lze jen rozpracovanou zakázku']); exit;
}

// smí: přiřazený technik sám, nebo kdokoliv s právem úprav
$isOwnTech = !empty($_SESSION['tech_id']) && (int)$_SESSION['tech_id'] === $curTech;
if (!$isOwnTech && !hasPermission('edit_orders') && !hasPermission('admin_access')) {
    echo json_encode(['ok' => false, 'error' => 'Zakázku může uvolnit jen její technik nebo vedoucí']); exit;
}

try {
    ensureHandoffOrderStatus();
    ensureOrderWorkLogSchema();
    $oldStatus = (string)$order['status'];
    $newStatus = 'Čeká na technika';

    $pdo->beginTransaction();
    workSegmentClose($orderId);                                   // pauza pracovního času
    $pdo->prepare("UPDATE orders SET technician_id = NULL, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$newStatus, $orderId]);
    $pdo->commit();

    assignmentSegmentSync($orderId, null, $newStatus);            // pauza přiřazovacího času
    if (function_exists('logOrderStatusChange')) { try { logOrderStatusChange($orderId, $oldStatus, $newStatus); } catch (Throwable $e) {} }
    if (function_exists('crmNotifyOrderLifecycleEvent')) {
        try {
            crmNotifyOrderLifecycleEvent([
                'type' => 'order_status_changed',
                'order_id' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'previous_technician_id' => $curTech,
            ]);
        } catch (Throwable $e) {}
    }

    crmAuditLog('order.status_change', [
        'entity_type' => 'order', 'entity_id' => (int)$orderId,
        'summary' => 'Zakázka #' . (int)$orderId . ' uvolněna dalšímu technikovi (stav: ' . $oldStatus . ' → ' . $newStatus . ')',
    ]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['ok' => false, 'error' => 'Chyba serveru']);
}
