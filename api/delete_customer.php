<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

// Mazání klienta je nevratné → jen admin a Boss (úpravu kontaktu smí každý,
// ale smazat záznam ne). Sladěno s mazáním zakázek (rozhodnutí majitele 18.7.2026).
if (!isset($_SESSION['user_id']) || !crmCanDeleteOrders()) {
    echo json_encode(['success' => false, 'message' => __('no_delete_permission')]);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]);
    exit;
}

$id = $_POST['id'] ?? $_GET['id'] ?? null;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID не указан']);
    exit;
}

try {
    // Jméno pro historii zjistíme JEŠTĚ před smazáním.
    $__cn = '';
    try { $ns = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) FROM customers WHERE id = ?"); $ns->execute([$id]); $__cn = (string)$ns->fetchColumn(); } catch (Throwable $e) {}

    // Check if customer has orders
    $check = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        throw new Exception('Nelze smazat klienta, který má zakázky. Nejdřív smažte zakázky.');
    }

    $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
    $stmt->execute([$id]);

    crmAuditLog('customer.delete', [
        'entity_type' => 'customer', 'entity_id' => (int)$id, 'entity_label' => $__cn,
        'summary' => 'Smazán klient ' . ($__cn !== '' ? $__cn : ('#' . $id)),
    ]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
