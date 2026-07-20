<?php
/* Převzetí / přiřazení reklamace technikovi (z detailu reklamace).
   - action=claim  : přihlášený TECHNIK si převezme NEPŘIŘAZENOU reklamaci (jako u zakázek)
   - action=assign : vedení (admin/Boss/manažer) přeřadí na libovolného technika (0 = bez technika)
   První reakce servisu zároveň nastaví staff_ack_at (uvolní „pin" nahoře v seznamu). */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function ca_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) ca_fail(__('unauthorized'), 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ca_fail(__('cl_err_invalid_request'), 405);
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) ca_fail(__('csrf_token_invalid'), 419);

$id = (int)($_POST['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? 'claim'));
if ($id <= 0) ca_fail(__('missing_id'));

ensureComplaintsClientColumns($pdo);
ensureComplaintsWorkflowColumns($pdo);

$st = $pdo->prepare("SELECT id, complaint_code, technician_id FROM complaints WHERE id = ? LIMIT 1");
$st->execute([$id]);
$complaint = $st->fetch();
if (!$complaint) ca_fail(__('cmpl_not_found'), 404);

$newTechId = null;

if ($action === 'claim') {
    // Převzít smí jen zaměstnanec s technickým účtem (technicians) — admin bez
    // technického účtu přiřazuje přes action=assign.
    $meTech = (int)($_SESSION['tech_id'] ?? 0);
    if ($meTech <= 0) ca_fail(__('cmpl_claim_requires_tech'), 403);
    $curTech = (int)($complaint['technician_id'] ?? 0);
    if ($curTech > 0 && $curTech !== $meTech && !crmComplaintCanManage()) {
        ca_fail(__('cmpl_already_assigned'), 409);
    }
    $newTechId = $meTech;
} elseif ($action === 'assign') {
    // Přeřazení / odebrání technika = jen vedení.
    if (!crmComplaintCanManage()) ca_fail(__('access_denied_msg'), 403);
    $reqTech = (int)($_POST['technician_id'] ?? 0);
    if ($reqTech > 0) {
        $tq = $pdo->prepare("SELECT id FROM technicians WHERE id = ? AND is_active = 1 LIMIT 1");
        $tq->execute([$reqTech]);
        if (!$tq->fetchColumn()) ca_fail(__('cmpl_unknown_technician'));
        $newTechId = $reqTech;
    } else {
        $newTechId = null; // bez technika
    }
} else {
    ca_fail(__('cl_err_invalid_request'));
}

try {
    $up = $pdo->prepare("UPDATE complaints
                         SET technician_id = ?, staff_ack_at = COALESCE(staff_ack_at, NOW())
                         WHERE id = ?");
    $up->execute([$newTechId, $id]);

    $techName = '';
    if ($newTechId !== null) {
        $tn = $pdo->prepare("SELECT name FROM technicians WHERE id = ? LIMIT 1");
        $tn->execute([$newTechId]);
        $techName = (string)($tn->fetchColumn() ?: '');
    }

    crmAuditLog('complaint.assign', [
        'entity_type' => 'complaint', 'entity_id' => $id,
        'entity_label' => (string)$complaint['complaint_code'],
        'summary' => 'Reklamace ' . $complaint['complaint_code'] . ' — '
            . ($newTechId !== null
                ? ($action === 'claim' ? 'převzal technik ' : 'přiřazen technik ') . ($techName !== '' ? $techName : ('#' . $newTechId))
                : 'odebrán technik'),
    ]);

    echo json_encode(['ok' => true, 'technician_id' => $newTechId, 'tech_name' => $techName], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('complaint_assign: ' . $e->getMessage());
    ca_fail(__('cmpl_save_failed'), 500);
}
