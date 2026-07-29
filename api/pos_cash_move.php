<?php
/* Ruční pohyb hotovosti v kase: vklad (in) / výběr (out) s poznámkou.
   POST direction=in|out, amount, note → {ok, id}. Auditováno (kasa.cash_move). */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function pcm_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { pcm_fail('Nepřihlášeno', 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { pcm_fail('Chybná metoda', 405); }
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { pcm_fail('Neplatný token', 419); }

$direction = (string)($_POST['direction'] ?? '');
if (!in_array($direction, ['in', 'out'], true)) { pcm_fail('Chybný směr pohybu'); }

$amount = crmParseAmountCzk((string)($_POST['amount'] ?? ''));
if ($amount <= 0) { pcm_fail('Zadej platnou částku'); }
if ($amount > 500000) { pcm_fail('Částka je podezřele vysoká'); }

$note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);

ensurePosCashMovementsTable();
try {
    $by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
    $branch = (int)getCurrentStaffBranchId() ?: null;
    $purpose = $direction === 'in' ? 'vklad' : 'vyber';
    $pdo->prepare("INSERT INTO pos_cash_movements (branch_id, direction, amount, purpose, note, created_by) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$branch, $direction, $amount, $purpose, $note !== '' ? $note : null, $by !== '' ? mb_substr($by, 0, 100) : null]);
    $id = (int)$pdo->lastInsertId();

    crmAuditLog('kasa.cash_move', [
        'entity_type' => 'pos_cash_movement', 'entity_id' => $id,
        'summary' => ($direction === 'in' ? 'Vklad do kasy ' : 'Výběr z kasy ') . formatMoney($amount) . ($note !== '' ? ' (' . $note . ')' : ''),
        'branch_id' => $branch,
    ]);
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('pos_cash_move: ' . $e->getMessage());
    pcm_fail('Chyba serveru', 500);
}
