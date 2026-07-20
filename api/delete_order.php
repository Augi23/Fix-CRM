<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

// Trvalé smazání zakázky je nevratné → jen administrátor a Boss.
if (!crmCanDeleteOrders()) {
    echo json_encode(['success' => false, 'message' => __('no_delete_permission')]);
    exit;
}

$id = $_POST['id'] ?? $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => __('id_missing')]);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, order_code, status FROM orders WHERE id = ?");
    $stmt->execute([$id]);
    $__ord = $stmt->fetch();
    if (!$__ord) {
        echo json_encode(['success' => false, 'message' => __('order_not_found')]);
        exit;
    }

    // Ochrana citlivých návazností: fakturu ani reklamaci nesmí smazání zakázky
    // tiše zničit — admin je musí nejdřív vyřešit zvlášť.
    $invCount = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE order_id = ?");
    $invCount->execute([$id]);
    if ((int)$invCount->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Zakázku nelze smazat — má vystavenou fakturu. Nejdřív fakturu vyřešte v účetnictví.']);
        exit;
    }
    try {
        $cmpCount = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE order_id = ?");
        $cmpCount->execute([$id]);
        if ((int)$cmpCount->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Zakázku nelze smazat — je k ní navázaná reklamace. Nejdřív vyřešte reklamaci.']);
            exit;
        }
    } catch (Throwable $e) { /* tabulka complaints nemusí existovat na všech instancích */ }

    $pdo->beginTransaction();

    // Smazat fyzické soubory příloh (řádky padnou níže s ostatními child tabulkami)
    $stmt_files = $pdo->prepare("SELECT file_path FROM order_attachments WHERE order_id = ?");
    $stmt_files->execute([$id]);
    foreach ($stmt_files->fetchAll() as $f) {
        $full_path = '../' . ($f['file_path'] ?? '');
        if (!empty($f['file_path']) && file_exists($full_path)) {
            @unlink($full_path);
        }
    }

    // Odvázat webovou rezervaci (záznam rezervace ponecháme kvůli historii)
    $pdo->prepare("UPDATE web_bookings SET order_id = NULL WHERE order_id = ?")->execute([$id]);

    // Vrátit na sklad kusy, jejichž odečet je už promítnutý: QR-vydané položky
    // (stock_deducted=1) vždy; klasické položky jen u dokončené zakázky (stejné
    // pravidlo jako delete_order_item.php). Jinak by smazání zakázky kusy „ztratilo".
    try {
        $__isDone = in_array((string)($__ord['status'] ?? ''), getOrderStatusList('done'), true);
        $itq = $pdo->prepare("SELECT inventory_id, quantity, COALESCE(stock_deducted,0) sd FROM order_items WHERE order_id = ? AND inventory_id IS NOT NULL");
        $itq->execute([$id]);
        foreach ($itq->fetchAll() as $oi) {
            if ((int)$oi['sd'] === 1 || $__isDone) {
                changeInventoryQuantity($oi['inventory_id'], (int)$oi['quantity']);
                if (function_exists('crmLogInventoryMove')) {
                    crmLogInventoryMove((int)$oi['inventory_id'], (int)$oi['quantity'], 'return', (int)$id, 'Vráceno — zakázka smazána');
                }
            }
        }
    } catch (Throwable $e) { /* sloupec nemusí na staré instanci existovat */ }

    // Smazat všechny podřízené záznamy zakázky. order_items/order_attachments mají FK
    // na orders → MUSÍ padnout před samotnou zakázkou (jinak FK chyba 1451/1452).
    $childTables = [
        'order_items', 'order_attachments', 'order_status_log', 'order_assignment_log',
        'order_work_log', 'order_price_lines', 'order_signatures', 'signature_requests',
        'tech_assignment_popups', 'purchase_requests',
    ];
    foreach ($childTables as $tbl) {
        try {
            $pdo->prepare("DELETE FROM `$tbl` WHERE order_id = ?")->execute([$id]);
        } catch (Throwable $e) { /* tabulka na této instanci nemusí existovat */ }
    }

    // Nakonec samotná zakázka
    $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$id]);

    $pdo->commit();
    $__oc = trim((string)($__ord['order_code'] ?? '')) !== '' ? (string)$__ord['order_code'] : ('#' . (int)$id);
    crmAuditLog('order.delete', [
        'entity_type' => 'order', 'entity_id' => (int)$id, 'entity_label' => $__oc,
        'summary' => 'Trvale smazána zakázka ' . $__oc,
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
