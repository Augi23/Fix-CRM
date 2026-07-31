<?php
/* Pokladna — hotovostní operace mimo prodej:
     action=move (výchozí) …… vklad (in) / výběr (out) s poznámkou; ke každému pohybu
                               se rovnou vystaví pokladní doklad PPD/VPD
     action=opening ………………… počáteční zůstatek pokladny (inventarizace) — jen vedení
     action=storno ……………………… storno pokladního dokladu (doklad se nikdy nemaže) — jen vedení
   Odpověď {ok, …}. Auditováno (kasa.cash_move / kasa.opening_balance / kasa.cash_doc*). */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cash_book.php';
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

$action = (string)($_POST['action'] ?? 'move');
$by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

// ── Počáteční zůstatek pokladny ─────────────────────────────────────────────
// Zásah do účetního základu (od téhle částky se počítá celý stav hotovosti) →
// jen vedení, stejná citlivost jako faktury.
if ($action === 'opening') {
    if (!crmCanManageInvoices()) { pcm_fail('Počáteční zůstatek smí nastavit jen vedení', 403); }
    $branch = (int)($_POST['branch_id'] ?? 0);
    // Kdo nevidí napříč pobočkami, smí nastavovat jen svou pokladnu — jinak by
    // si zaměstnanec jedné pobočky mohl přepsat stav hotovosti té druhé.
    if (!isBranchGlobalViewer() || $branch <= 0) { $branch = (int)getCurrentStaffBranchId(); }

    $balance = crmParseAmountCzk((string)($_POST['balance'] ?? ''));
    $date = trim((string)($_POST['opening_date'] ?? ''));
    if ($date === '') { $date = date('Y-m-d'); }
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);

    $res = afxCashSetOpeningBalance($branch, $balance, $date, $by, $note);
    if (!$res['ok']) { pcm_fail((string)$res['error']); }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Storno pokladního dokladu ───────────────────────────────────────────────
if ($action === 'storno') {
    if (!crmCanManageInvoices()) { pcm_fail('Storno dokladu smí provést jen vedení', 403); }
    $docId = (int)($_POST['doc_id'] ?? 0);
    if ($docId <= 0) { pcm_fail('Chybí doklad'); }
    $doc = afxCashDocGet($docId);
    if (!$doc) { pcm_fail('Doklad nenalezen', 404); }
    // Pobočková izolace: doklad cizí pobočky smí stornovat jen globální divák.
    if (!isBranchGlobalViewer() && (int)$doc['branch_id'] !== (int)getCurrentStaffBranchId()) {
        pcm_fail('Doklad patří jiné pobočce', 403);
    }
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 160);
    $res = afxCashDocStorno($docId, $reason, $by);
    if (!$res['ok']) { pcm_fail((string)$res['error']); }
    echo json_encode(['ok' => true, 'number' => $res['number']], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Vklad / výběr hotovosti ─────────────────────────────────────────────────
$direction = (string)($_POST['direction'] ?? '');
if (!in_array($direction, ['in', 'out'], true)) { pcm_fail('Chybný směr pohybu'); }

$amount = crmParseAmountCzk((string)($_POST['amount'] ?? ''));
if ($amount <= 0) { pcm_fail('Zadej platnou částku'); }
if ($amount > 500000) { pcm_fail('Částka je podezřele vysoká'); }

$note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);

ensurePosCashMovementsTable();
try {
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

    // Ke každému ručnímu pohybu hotovosti patří pokladní doklad (PPD/VPD) —
    // vazba ref_type='cash_movement' zajistí, že se doklad v pokladní knize
    // NEsčítá znovu; je to jen papír k pohybu, který v knize už je.
    // Selhání dokladu nesmí shodit samotný pohyb (peníze fyzicky prošly kasou).
    $doc = afxCashDocIssue([
        'branch_id' => (int)($branch ?? 0),
        'type' => $direction === 'in' ? 'income' : 'expense',
        'amount' => $amount,
        'date' => date('Y-m-d'),
        'purpose' => ($direction === 'in' ? 'Vklad do pokladny' : 'Výběr z pokladny') . ($note !== '' ? ' — ' . $note : ''),
        'issued_by' => $by,
        'ref_type' => 'cash_movement',
        'ref_id' => $id,
        'note' => $note,
    ]);

    echo json_encode([
        'ok' => true, 'id' => $id,
        'doc_number' => $doc['ok'] ? $doc['number'] : '',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('pos_cash_move: ' . $e->getMessage());
    pcm_fail('Chyba serveru', 500);
}
