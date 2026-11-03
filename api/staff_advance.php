<?php
/* Zálohy na výplaty zaměstnanců (v4.3.0)
     POST action=pay     technician_id, amount, note   → výdej z kasy + záznam + VPD (vedení)
     POST action=settle  id, note                       → vyúčtováno (strženo z výplaty) — admin/Boss
     POST action=storno  id, reason                     → storno (peníze zpět do kasy) — admin/Boss
     GET  action=list    technician_id?, status?, from?, to?  → JSON seznam (pro okno kasy / reporty)
   Odpověď {ok, …}. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cash_book.php';
require_once __DIR__ . '/../includes/staff_advances.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sa_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit;
}
if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { sa_fail('Nepřihlášeno', 401); }

$action = (string)($_REQUEST['action'] ?? 'list');
$by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

if ($action === 'list') {
    if (!crmCanPayStaffAdvance() && !crmCanSettleStaffAdvance()) { sa_fail('Bez oprávnění', 403); }
    $f = ['technician_id' => (int)($_GET['technician_id'] ?? 0), 'status' => (string)($_GET['status'] ?? ''),
          'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'limit' => 200];
    if (!in_array($f['status'], ['', 'open', 'settled', 'cancelled'], true)) { $f['status'] = ''; }
    $rows = [];
    foreach (afxStaffAdvancesList($f) as $r) {
        // manažer vidí jen zálohy své pobočky
        if (!isBranchGlobalViewer() && (int)($r['branch_id'] ?? 0) !== (int)getCurrentStaffBranchId()) { continue; }
        $rows[] = [
            'id' => (int)$r['id'], 'technician_id' => (int)$r['technician_id'], 'tech_name' => (string)($r['tech_name'] ?? ''),
            'amount' => (float)$r['amount'], 'note' => (string)($r['note'] ?? ''), 'status' => (string)$r['status'],
            'status_label' => afxStaffAdvanceStatusLabel((string)$r['status']),
            'paid_at' => date('j.n.Y H:i', strtotime((string)$r['paid_at'])), 'paid_by' => (string)($r['paid_by'] ?? ''),
            'doc_number' => (string)($r['doc_number'] ?? ''), 'settled_at' => $r['settled_at'] ? date('j.n.Y', strtotime((string)$r['settled_at'])) : '',
        ];
    }
    $open = 0.0;
    foreach ($rows as $r) { if ($r['status'] === 'open') { $open += $r['amount']; } }
    echo json_encode(['ok' => true, 'rows' => $rows, 'open_total' => round($open, 2)], JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sa_fail('Chybná metoda', 405); }
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { sa_fail('Neplatný token', 419); }

if ($action === 'pay') {
    if (!crmCanPayStaffAdvance()) { sa_fail('Zálohu na výplatu smí vyplatit jen vedení (admin, Boss, manažer).', 403); }
    $techId = (int)($_POST['technician_id'] ?? 0);
    if ($techId <= 0) { sa_fail('Vyber zaměstnance.'); }
    $amount = crmParseAmountCzk((string)($_POST['amount'] ?? ''));
    $res = afxStaffAdvanceCreate($techId, (float)$amount, (string)($_POST['note'] ?? ''), $by);
    if (!$res['ok']) { sa_fail((string)$res['error']); }
    echo json_encode($res, JSON_UNESCAPED_UNICODE); exit;
}

if ($action === 'settle') {
    if (!crmCanSettleStaffAdvance()) { sa_fail('Vyúčtovat zálohu smí jen admin nebo Boss.', 403); }
    $res = afxStaffAdvanceSettle((int)($_POST['id'] ?? 0), (string)($_POST['note'] ?? ''), $by);
    if (!$res['ok']) { sa_fail((string)$res['error']); }
    echo json_encode(['ok' => true]); exit;
}

if ($action === 'storno') {
    if (!crmCanSettleStaffAdvance()) { sa_fail('Stornovat zálohu smí jen admin nebo Boss.', 403); }
    $res = afxStaffAdvanceStorno((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''), $by);
    if (!$res['ok']) { sa_fail((string)$res['error']); }
    echo json_encode(['ok' => true]); exit;
}

sa_fail('Neznámá akce.');
