<?php
/* Uložení pole „Řešení / závěr technika" z detailu reklamace.
   Neprázdný text nastaví resolved_at + resolved_by (jméno natvrdo — přežije
   smazání účtu); smazání textu závěr zruší. Smí každý přihlášený zaměstnanec. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function cr_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) cr_fail(__('unauthorized'), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') cr_fail(__('cl_err_invalid_request'), 405);
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) cr_fail(__('csrf_token_invalid'), 419);

$id = (int)($_POST['id'] ?? 0);
$text = trim((string)($_POST['resolution_text'] ?? ''));
if ($id <= 0) cr_fail(__('missing_id'));

ensureComplaintsClientColumns($pdo);
ensureComplaintsWorkflowColumns($pdo);

$st = $pdo->prepare("SELECT id, complaint_code FROM complaints WHERE id = ? LIMIT 1");
$st->execute([$id]);
$complaint = $st->fetch();
if (!$complaint) cr_fail(__('cmpl_not_found'), 404);

try {
    if ($text !== '') {
        $by = mb_substr(crmStaffDisplayName(), 0, 100);
        $up = $pdo->prepare("UPDATE complaints
                             SET resolution_text = ?, resolved_at = NOW(), resolved_by = ?,
                                 staff_ack_at = COALESCE(staff_ack_at, NOW())
                             WHERE id = ?");
        $up->execute([$text, $by, $id]);
        $resolvedAtH = date('d.m.Y H:i');
    } else {
        $by = null;
        $up = $pdo->prepare("UPDATE complaints
                             SET resolution_text = NULL, resolved_at = NULL, resolved_by = NULL
                             WHERE id = ?");
        $up->execute([$id]);
        $resolvedAtH = '';
    }

    crmAuditLog('complaint.resolution', [
        'entity_type' => 'complaint', 'entity_id' => $id,
        'entity_label' => (string)$complaint['complaint_code'],
        'summary' => 'Reklamace ' . $complaint['complaint_code'] . ' — '
            . ($text !== '' ? 'zapsáno řešení (' . mb_strlen($text) . ' znaků)' : 'řešení smazáno'),
    ]);

    echo json_encode(['ok' => true, 'resolved_at_h' => $resolvedAtH, 'resolved_by' => $by], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('save_complaint_resolution: ' . $e->getMessage());
    cr_fail(__('cmpl_save_failed'), 500);
}
