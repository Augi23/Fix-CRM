<?php
/* Pokladna — hotovostní operace mimo prodej:
     action=move (výchozí) …… vklad (in) / výběr (out) s poznámkou; ke každému pohybu
                               se rovnou vystaví pokladní doklad PPD/VPD
     action=opening ………………… počáteční zůstatek pokladny (inventarizace) — jen vedení
     action=storno ……………………… storno pokladního dokladu (doklad se nikdy nemaže) — jen vedení
     action=transfer ………………… přesun hotovosti mezi pokladnami poboček („z trezoru do trezoru“,
                               v4.2.0) — jen admin / Boss / manažer; výdej u zdrojové + příjem
                               u cílové pokladny v jedné transakci, ke každému VPD/PPD
   Odpověď {ok, …}. Auditováno (kasa.cash_move / kasa.cash_transfer / kasa.opening_balance / kasa.cash_doc*). */
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
if (!in_array($action, ['move', 'opening', 'storno', 'transfer'], true)) { $action = 'move'; }
$by = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));

// ── Počáteční zůstatek pokladny ─────────────────────────────────────────────
// Zásah do účetního základu (od téhle částky se počítá celý stav hotovosti) →
// jen vedení, stejná citlivost jako faktury.
// pohyb hotovosti se zapisuje k dnešku — v uzamčeném měsíci nesmí vzniknout
if (in_array($action, ['move', 'transfer'], true) && function_exists('afxAccountingClosedError')) {
    $err = afxAccountingClosedError(date('Y-m-d'), 'pohyb hotovosti');
    if ($err !== null) { pcm_fail($err, 423); }
}

// ── Přesun hotovosti mezi pokladnami („z trezoru do trezoru“) ──────────────
// Jen vedení (admin, Boss, manažer — crmCanTransferCash). Manažer bez pohledu
// napříč pobočkami přesouvá jen ZE své pokladny. Výdej a příjem vznikají v jedné
// transakci a odkazují na sebe (ref_type 'cash_transfer', ref_id = protipohyb),
// takže kniha obou poboček sedí a nic nejde „přesunout“ jen napůl.
if ($action === 'transfer') {
    if (!crmCanTransferCash()) { pcm_fail('Přesun hotovosti mezi pokladnami smí jen vedení (admin, Boss, manažer).', 403); }
    $from = (int)($_POST['from_branch_id'] ?? 0);
    $to = (int)($_POST['to_branch_id'] ?? 0);
    if (!isBranchGlobalViewer() || $from <= 0) { $from = (int)getCurrentStaffBranchId(); }
    $branches = [];
    foreach (getBranches(true) as $b) { $branches[(int)$b['id']] = (string)$b['name']; }
    if ($from <= 0 || !isset($branches[$from])) { pcm_fail('Zdrojová pokladna není platná pobočka.'); }
    if ($to <= 0 || !isset($branches[$to])) { pcm_fail('Vyber cílovou pokladnu (pobočku).'); }
    if ($from === $to) { pcm_fail('Zdrojová a cílová pokladna jsou stejné — přesun nemá smysl.'); }

    $amount = crmParseAmountCzk((string)($_POST['amount'] ?? ''));
    if ($amount <= 0) { pcm_fail('Zadej platnou částku'); }
    if ($amount > 500000) { pcm_fail('Částka je podezřele vysoká'); }
    // víc, než v pokladně podle CRM je, přesunout nejde — kniha by šla do mínusu
    $avail = afxCashBalanceAt($from, date('Y-m-d'));
    if ($avail + 0.005 < $amount) {
        pcm_fail('V pokladně ' . $branches[$from] . ' je podle CRM jen ' . formatMoney($avail)
            . ' — víc přesunout nejde. Nesedí-li to se zásuvkou, nejdřív srovnej stav (převzetí pokladny → NE, přepočítám).');
    }
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);

    ensurePosCashMovementsTable();
    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO pos_cash_movements (branch_id, direction, amount, purpose, ref_type, ref_label, note, created_by)
            VALUES (?, ?, ?, ?, 'cash_transfer', ?, ?, ?)");
        $byDb = $by !== '' ? mb_substr($by, 0, 100) : null;
        $ins->execute([$from, 'out', $amount, 'presun_out', mb_substr('→ ' . $branches[$to], 0, 40), $note !== '' ? $note : null, $byDb]);
        $outId = (int)$pdo->lastInsertId();
        $ins->execute([$to, 'in', $amount, 'presun_in', mb_substr('← ' . $branches[$from], 0, 40), $note !== '' ? $note : null, $byDb]);
        $inId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE pos_cash_movements SET ref_id = ? WHERE id = ?")->execute([$inId, $outId]);
        $pdo->prepare("UPDATE pos_cash_movements SET ref_id = ? WHERE id = ?")->execute([$outId, $inId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('pos_cash_move transfer: ' . $e->getMessage());
        pcm_fail('Chyba serveru — přesun neproběhl.', 500);
    }

    crmAuditLog('kasa.cash_transfer', [
        'entity_type' => 'pos_cash_movement', 'entity_id' => $outId,
        'summary' => 'Přesun hotovosti ' . formatMoney($amount) . ': ' . $branches[$from] . ' → ' . $branches[$to]
            . ($note !== '' ? ' (' . $note . ')' : ''),
        'branch_id' => $from,
    ]);

    // doklady k oběma pohybům (VPD u zdroje, PPD u cíle) — jen papír, v knize
    // už pohyby jsou (ref_type 'cash_movement' se nesčítá znovu); selhání
    // dokladu nesmí shodit přesun, peníze už fyzicky putují
    $docOut = afxCashDocIssue([
        'branch_id' => $from, 'type' => 'expense', 'amount' => $amount, 'date' => date('Y-m-d'),
        'purpose' => 'Přesun hotovosti do pokladny ' . $branches[$to] . ($note !== '' ? ' — ' . $note : ''),
        'counterparty' => 'Pokladna ' . $branches[$to],
        'issued_by' => $by, 'ref_type' => 'cash_movement', 'ref_id' => $outId, 'note' => $note,
    ]);
    $docIn = afxCashDocIssue([
        'branch_id' => $to, 'type' => 'income', 'amount' => $amount, 'date' => date('Y-m-d'),
        'purpose' => 'Přesun hotovosti z pokladny ' . $branches[$from] . ($note !== '' ? ' — ' . $note : ''),
        'counterparty' => 'Pokladna ' . $branches[$from],
        'issued_by' => $by, 'ref_type' => 'cash_movement', 'ref_id' => $inId, 'note' => $note,
    ]);
    echo json_encode([
        'ok' => true, 'out_id' => $outId, 'in_id' => $inId,
        'doc_out' => $docOut['ok'] ? $docOut['number'] : '',
        'doc_in' => $docIn['ok'] ? $docIn['number'] : '',
        'from' => $branches[$from], 'to' => $branches[$to], 'amount' => $amount,
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
