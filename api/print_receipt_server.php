<?php
/**
 * Serverový tisk účtenky na pokladní termotiskárnu (Xprinter XP58-IIN v USB serveru).
 * Prohlížeč → HTTPS → server → /dev/usb/lp0. Funguje i ze Safari/iPadu, bez dialogu.
 *
 * POST JSON { csrf_token, sale_id, drawer?: 1 }   → vytiskne doklad (+ otevře zásuvku)
 * POST JSON { csrf_token, test: 1 }               → zkušební účtenka
 * POST JSON { csrf_token, job_status: id }        → vzal si agent úlohu z fronty? (kasa čeká na tisk)
 * POST JSON { csrf_token, mark_printed: sale_id, via } → účtenka šla na papír můstkem / dialogem prohlížeče
 * POST JSON { csrf_token, skip: sale_id, reason? } → obsluha pokračuje BEZ účtenky (audit)
 * GET  ?preview=1[&id=N]                          → PNG náhled rastru (diagnostika, netiskne)
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/receipt58.php';
require_once '../includes/receipt_escpos.php';
require_once '../includes/pos_shift.php';
ob_clean();

if (!crmCanUsePos()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => __('unauthorized')]); exit;
}

// Data účtenky (afxReceiptDataForSale) jsou od v4.2.0 v includes/receipt58.php — sdílí je
// kasa (automatický tisk hned po prodeji), tohle API i dotisk.

/** Zkušební účtenka — hlavička s adresou POBOČKY, na kterou se tiskne (ať je hned
 *  vidět, jestli má pobočka v Nastavení → Firma vyplněnou správnou adresu). */
function afxReceiptTestData(int $branchId = 0): array {
    $bc = crmOrderBranchContact($branchId > 0 ? $branchId : (int)getCurrentStaffBranchId());
    return crmBuildPosReceipt58(
        ['sale_number' => 'TEST', 'created_at' => date('Y-m-d H:i:s'),
         'seller_name' => trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? '')),
         'payment_method' => 'cash', 'total' => 123, 'vat_rate' => 0, 'is_vat_payer' => 0,
         'cash_received' => 200, 'cash_change' => 77],
        [['item_name' => 'Zkušební tisk účtenky — ěščřžýáíéúů ĚŠČŘŽ', 'item_code' => 'TEST-58',
          'quantity' => 1, 'unit_price' => 123, 'is_used_goods' => 0]],
        ['name' => get_setting('company_name', 'AppleFix s.r.o.'), 'address' => trim((string)$bc['address']),
         'ico' => trim((string)get_setting('company_ico', '')), 'dic' => '',
         'phone' => (string)$bc['phone'], 'web' => 'www.applefix.cz']
    );
}

// ── GET náhled (PNG rastru přesně tak, jak půjde na hlavu) ──
if (($_GET['preview'] ?? '') === '1') {
    $data = isset($_GET['id']) ? afxReceiptDataForSale((int)$_GET['id']) : afxReceiptTestData();
    if (!$data) { http_response_code(404); die('Doklad nenalezen.'); }
    $im = crmReceiptRaster($data);
    header('Content-Type: image/png');
    imagepng($im); exit;
}

// ── POST tisk ──
header('Content-Type: application/json; charset=utf-8');
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit;
}

try {
    // ── stav tiskové úlohy (kasa po prodeji čeká, až si ji agent vezme) ──
    if (isset($in['job_status'])) {
        $js = afxPrintJobStatus((int)$in['job_status']);
        echo json_encode(['ok' => true] + $js); exit;
    }
    // ── účtenka šla na papír jinudy než frontou (můstek / tiskový dialog) ──
    if (isset($in['mark_printed'])) {
        $via = in_array((string)($in['via'] ?? ''), ['bridge', 'browser'], true) ? (string)$in['via'] : 'bridge';
        $sid = (int)$in['mark_printed'];
        if ($sid > 0 && afxReceiptDataForSale($sid) !== null) {
            afxPosSaleMarkReceiptPrinted($sid, $via);
            // čekající úloha ve frontě by jinak za chvíli vyjela podruhé
            if ($via === 'bridge') {
                try { $pdo->prepare("UPDATE pos_print_jobs SET taken_at = NOW() WHERE sale_id = ? AND taken_at IS NULL")->execute([$sid]); }
                catch (Throwable $e) {}
            }
        }
        echo json_encode(['ok' => true]); exit;
    }
    // ── prodej bez účtenky: obsluha to musí výslovně odkývat, zapisuje se do historie ──
    if (isset($in['skip'])) {
        $sid = (int)$in['skip'];
        $sb = 0;
        $data = $sid > 0 ? afxReceiptDataForSale($sid, $sb) : null;
        if ($data) {
            $num = (string)($data['sale_number'] ?? ('#' . $sid));
            try {
                $pdo->prepare("UPDATE pos_sales SET receipt_print_via = 'skipped' WHERE id = ? AND receipt_printed_at IS NULL")->execute([$sid]);
            } catch (Throwable $e) {}
            crmAuditLog('kasa.receipt_skipped', [
                'entity_type' => 'pos_sale', 'entity_id' => $sid, 'entity_label' => $num,
                'summary' => 'Doklad ' . $num . ' — účtenka se NEVYTISKLA, obsluha pokračovala bez ní'
                    . (trim((string)($in['reason'] ?? '')) !== '' ? ' (' . mb_substr(trim((string)$in['reason']), 0, 160) . ')' : ''),
                'branch_id' => $sb ?: (int)getCurrentStaffBranchId(),
            ]);
        }
        echo json_encode(['ok' => true]); exit;
    }

    // lístek směny (převzetí/uzávěrka) — velkým písmem kolik má být v kase
    $slip = (string)($in['slip'] ?? '');
    if ($slip === 'shift_open' || $slip === 'shift_close') {
        $branch = (int)getCurrentStaffBranchId();
        $shift = $slip === 'shift_open' ? afxPosShiftCurrent($branch) : afxPosShiftLastClosed($branch);
        if (!$shift) { echo json_encode(['ok' => false, 'error' => 'Směna nenalezena.']); exit; }
        // u převzetí patří na lístek i předchozí držitel kasy (poslední uzavřená směna)
        $prev = $slip === 'shift_open' ? afxPosShiftLastClosed($branch) : null;
        // uzávěrka: výpis pohybů za dobu držení kasy (jen pro info) — stejná
        // pravidla jako pokladní kniha, oříznuté na okno směny
        $moves = null;
        if ($slip === 'shift_close') {
            try {
                require_once '../includes/cash_book.php';
                $fromTs = strtotime((string)$shift['opened_at']);
                $toTs = strtotime((string)($shift['closed_at'] ?: date('Y-m-d H:i:s')));
                if ($fromTs && $toTs) {
                    $rows = afxCashBookRows($branch, date('Y-m-d', $fromTs), date('Y-m-d', $toTs));
                    $moves = array_values(array_filter($rows, static function ($r) use ($fromTs, $toTs) {
                        if (empty($r['counts'])) { return false; }
                        $ts = strtotime(trim((string)($r['date'] ?? '') . ' ' . (string)($r['time'] ?? '')));
                        return $ts !== false && $ts >= $fromTs && $ts <= $toTs + 59;
                    }));
                }
            } catch (Throwable $e) { error_log('slip shift_close pohyby: ' . $e->getMessage()); $moves = null; }
        }
        $bytes = crmEscposPreamble() . crmEscposReceipt(crmShiftSlipRaster($shift, $slip === 'shift_open' ? 'open' : 'close', $prev, $moves));
        // enqueue = tisková fronta (appka z TestFlightu / Safari nedosáhnou na můstek)
        if (!empty($in['enqueue'])) {
            $qid = afxPrintJobEnqueue($branch, $bytes, 0, $slip);
            echo json_encode(['ok' => $qid > 0, 'queued' => $qid > 0 ? 1 : 0, 'job_id' => $qid,
                'error' => $qid > 0 ? null : 'Frontu tisku se nepodařilo naplnit.']); exit;
        }
        echo json_encode(['ok' => true, 'b64' => base64_encode($bytes)]); exit;
    }

    $saleBranch = 0;
    // zkušební účtenka: pobočka zvolená adminem v Nastavení, jinak pobočka obsluhy
    $testBranch = (!empty($in['test']) && (int)($in['branch_id'] ?? 0) > 0 && hasPermission('admin_access'))
        ? (int)$in['branch_id'] : 0;
    $saleId = (int)($in['sale_id'] ?? 0);
    if (!empty($in['test'])) {
        $bytes = crmEscposPreamble();
        if (!empty($in['drawer'])) { $bytes .= crmEscposDrawerPulse(); }
        $bytes .= crmEscposReceipt(crmReceiptRaster(afxReceiptTestData($testBranch)));
    } else {
        $bytes = afxReceiptBytesForSale($saleId, !empty($in['drawer']), true, $saleBranch);
        if ($bytes === null) { echo json_encode(['ok' => false, 'error' => 'Doklad nenalezen.']); exit; }
    }

    // TISKOVÁ FRONTA (enqueue): kasa v appce z TestFlightu (WKWebView) ani Safari
    // nedosáhnou z HTTPS na místní můstek — úloha se uloží na server a poller na
    // pokladním Macu ji do ~2 s stáhne a vytiskne. Tiskne pořád jen ten počítač.
    if (!empty($in['enqueue'])) {
        // fronta pobočky DOKLADU (u testu pobočka obsluhy) — účtenka jede na kasu,
        // kde prodej proběhl, ne kde zrovna sedí vedení
        $qBranch = !empty($in['test']) ? (int)getCurrentStaffBranchId() : $saleBranch;
        // zkušební účtenku smí vedení poslat na KONKRÉTNÍ pobočku (nastavení tiskáren
        // druhé pobočky se jinak nedá odsud vyzkoušet)
        $reqBranch = (int)($in['branch_id'] ?? 0);
        if (!empty($in['test']) && $reqBranch > 0 && hasPermission('admin_access')) { $qBranch = $reqBranch; }
        if ($qBranch <= 0) { $qBranch = (int)getCurrentStaffBranchId(); }
        // v4.2.0: fronta pobočky OBSLUHY má přednost — zákazník stojí u kasy, kde se
        // markuje (doklad k zakázce nese pobočku zakázky, ne kasy). Pobočka dokladu
        // zůstává zálohou pro obsluhu bez pobočky.
        if (empty($in['test']) && (int)getCurrentStaffBranchId() > 0) { $qBranch = (int)getCurrentStaffBranchId(); }
        $qid = afxPrintJobEnqueue($qBranch, $bytes, !empty($in['test']) ? 0 : $saleId, !empty($in['test']) ? 'test' : 'receipt');
        echo json_encode(['ok' => $qid > 0, 'queued' => $qid > 0 ? 1 : 0, 'job_id' => $qid,
            'agent_alive' => afxPrintAgentAlive($qBranch),
            'error' => $qid > 0 ? null : 'Frontu tisku se nepodařilo naplnit.']); exit;
    }

    // VÝCHOZÍ REŽIM: server NIC netiskne — vrátí hotové bajty a odešle je až
    // prohlížeč POKLADNÍHO počítače na svůj lokální můstek (127.0.0.1:9101).
    // Tiskne tedy vždy jen ten počítač, který má tiskárnu v USB (přání majitele).
    if (!empty($in['bytes'])) {
        echo json_encode(['ok' => true, 'b64' => base64_encode($bytes)]); exit;
    }

    // cíl tisku podle pobočky DOKLADU (u testu pobočka obsluhy / zvolená adminem)
    $res = crmReceiptSendBytes($bytes, null, $saleBranch ?: (int)getCurrentStaffBranchId());
    if (!$res['ok']) { error_log('print_receipt_server: ' . $res['error']); }
    echo json_encode($res);
} catch (Throwable $e) {
    error_log('print_receipt_server: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Tisk se nepodařil: ' . $e->getMessage()]);
}
