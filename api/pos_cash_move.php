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

// ÚČETNÍ do pokladny NESMÍ zapisovat (jen číst knihu): vklad/výběr i doklady
// jsou provozní operace s fyzickou hotovostí, kterou účetní v ruce nemá.
if (function_exists('crmIsAccountant') && crmIsAccountant()) {
    pcm_fail('Role účetní hotovostí nehýbe — pokladní kniha je pro ni jen ke čtení.', 403);
}

// Neznámá/prázdná akce = 'move' — větev vklad/výběr níže je fall-through, takže
// bez normalizace by `action=cokoliv` přeskočil kontrolu uzávěrky (nález prověrky)
$action = (string)($_POST['action'] ?? 'move');
if (!in_array($action, ['move', 'opening', 'storno'], true)) { $action = 'move'; }
$by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

// ── Počáteční zůstatek pokladny ─────────────────────────────────────────────
// Zásah do účetního základu (od téhle částky se počítá celý stav hotovosti) →
// jen vedení, stejná citlivost jako faktury.
// pohyb hotovosti se zapisuje k dnešku — v uzamčeném měsíci nesmí vzniknout
if ($action === 'move' && function_exists('afxAccountingClosedError')) {
    $err = afxAccountingClosedError(date('Y-m-d'), 'pohyb hotovosti');
    if ($err !== null) { pcm_fail($err, 423); }
}

if ($action === 'opening') {
    if (!crmCanManageInvoices()) { pcm_fail('Počáteční zůstatek smí nastavit jen vedení', 403); }
    $branch = (int)($_POST['branch_id'] ?? 0);
    // Kdo nevidí napříč pobočkami, smí nastavovat jen svou pokladnu — jinak by
    // si zaměstnanec jedné pobočky mohl přepsat stav hotovosti té druhé.
    if (!isBranchGlobalViewer() || $branch <= 0) { $branch = (int)getCurrentStaffBranchId(); }

    // crmParseAmountCzk je shovívavý: pro „nevím" vrátí 0,00 a z „cca 15 tis."
    // vytáhne 15 — obojí by se TIŠE stalo základem celé pokladní knihy. Vstup
    // proto musí být čitelné číslo (mezery/nbsp a „Kč"/„,-" jsou tolerované).
    $balRaw = trim((string)($_POST['balance'] ?? ''));
    $balNorm = str_ireplace([' ', "\u{00A0}", 'Kč'], '', $balRaw);
    if (substr($balNorm, -2) === ',-') { $balNorm = substr($balNorm, 0, -2); }
    if (!preg_match('/^\d+([.,]\d{1,2})?$/', $balNorm)) {
        pcm_fail('Zadej zůstatek číslem, např. 15000 nebo 15 000,50');
    }
    $balance = crmParseAmountCzk($balRaw);
    $date = trim((string)($_POST['opening_date'] ?? ''));
    if ($date === '') { $date = date('Y-m-d'); }
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);

    try {
        $res = afxCashSetOpeningBalance($branch, $balance, $date, $by, $note);
    } catch (AfxAccountingClosedException $e) {
        // uzávěrka = srozumitelná hláška, ne 500 (typicky historické datum inventury)
        pcm_fail($e->getMessage(), 423);
    }
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
    try {
        $res = afxCashDocStorno($docId, $reason, $by);
    } catch (AfxAccountingClosedException $e) {
        pcm_fail($e->getMessage(), 423);
    }
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

// Zákonný limit hotovosti (zák. 254/2004 Sb.) platí pro přijatou i POSKYTNUTOU
// platbu. Vklad/výběr nad limit nezakazujeme (může jít o svoz vlastní tržby do
// banky, což limitu nepodléhá), ale obsluha musí varování dostat hned.
$limitWarning = '';
if ($amount > AFX_CASH_LIMIT_CZK) {
    $limitWarning = 'Pozor: částka přesahuje zákonný limit hotovostní platby '
        . formatMoney(AFX_CASH_LIMIT_CZK) . ' (zák. 254/2004 Sb.). Pokud jde o platbu jedné protistraně, musí proběhnout převodem.';
}

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
        'warning' => $limitWarning,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('pos_cash_move: ' . $e->getMessage());
    pcm_fail('Chyba serveru', 500);
}
