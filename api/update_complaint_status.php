<?php
/* Změna stavu reklamace ze seznamu Reklamace (staff).
   První reakce servisu nastaví staff_ack_at → reklamace přestane být
   „připíchnutá" nahoře a řadí se dál klasicky. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$status = trim((string)($_POST['status'] ?? ''));
if ($id <= 0 || $status === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing']);
    exit;
}

$allowed = ['Přijato', 'V řešení', 'Čeká na zákazníka', 'Vyřízeno', 'Zamítnuto'];
if (!in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_status']);
    exit;
}

try {
    ensureComplaintsClientColumns($pdo);
    $stmt = $pdo->prepare("UPDATE complaints
                           SET complaint_status = ?, staff_ack_at = COALESCE(staff_ack_at, NOW())
                           WHERE id = ?");
    $stmt->execute([$status, $id]);
    crmAuditLog('complaint.status_change', [
        'entity_type' => 'complaint', 'entity_id' => (int)$id,
        'summary' => 'Reklamace #' . (int)$id . ' — stav: ' . $status,
    ]);
    echo json_encode(['ok' => true, 'status' => $status], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
