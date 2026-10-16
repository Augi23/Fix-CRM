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

$id = $_POST['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'No ID provided']);
    exit;
}

try {
    // Get file info and order info to check permissions
    ensureOrderAttachmentUploader();
    $stmt = $pdo->prepare("SELECT a.order_id, a.file_path, a.file_name, a.uploaded_by_tech, a.uploaded_by_user, o.technician_id, o.branch_id
                           FROM order_attachments a
                           JOIN orders o ON a.order_id = o.id
                           WHERE a.id = ?");
    $stmt->execute([$id]);
    $data = $stmt->fetch();

    if ($data) {
        // Vlastní fotky smaže každý, cizí (i staré bez známého autora) jen admin a Boss
        // (majitel 16.10.2026). Dřív šlo přes API smazat cokoli komukoli s edit_orders.
        if (!crmIsAdminLevel() && !canAccessOrderBranch($data)) {
            throw new Exception(__('access_denied_msg'));
        }
        if (!crmCanDeleteAttachment($data)) {
            throw new Exception('Smazat můžeš jen fotky, které jsi nahrál(a) ty. Cizí fotky maže jen admin nebo Boss.');
        }

        $full_path = '../' . $data['file_path'];
        if ($data['file_path'] && file_exists($full_path)) {
            unlink($full_path);
        }

        $del = $pdo->prepare("DELETE FROM order_attachments WHERE id = ?");
        $del->execute([$id]);
        crmAuditLog('order.media_delete', [
            'entity_type' => 'order', 'entity_id' => (int)$data['order_id'],
            'summary' => 'Smazána příloha zakázky: ' . (string)($data['file_name'] ?: basename((string)$data['file_path'])),
            'branch_id' => (int)($data['branch_id'] ?? 0),
        ]);
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('File not found in database');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
