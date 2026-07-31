<?php
/**
 * Převzetí / uzávěrka pokladny (pos_shifts).
 * POST JSON { csrf_token, action: open|close, ... }
 *   open:  { match: 1|0, counted?: number }   — ANO/NE na stav hotovosti
 *   close: { counted: number, note?: string, force?: 1 }  — uzávěrka (force jen vedení)
 * GET ?action=status → aktuální směna + stav hotovosti podle systému.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/pos_shift.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!crmCanUsePos()) {
    echo json_encode(['ok' => false, 'error' => __('unauthorized')]); exit;
}
afxEnsurePosShiftTable();
$branchId = (int)getCurrentStaffBranchId();

if (($_GET['action'] ?? '') === 'status') {
    $shift = afxPosShiftCurrent($branchId);
    echo json_encode([
        'ok' => true,
        'shift' => $shift,
        'mine' => afxPosShiftIsMine($shift),
        'expected' => afxCashBalanceAt($branchId, date('Y-m-d')),
    ]); exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in) || !validateCsrfToken((string)($in['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit;
}

$action = (string)($in['action'] ?? '');
try {
    if ($action === 'open') {
        $match = (int)($in['match'] ?? 0) === 1;
        $counted = isset($in['counted']) && $in['counted'] !== '' && $in['counted'] !== null
            ? (float)str_replace(',', '.', (string)$in['counted']) : null;
        $res = afxPosShiftOpen($branchId, $match, $counted);
        echo json_encode($res); exit;
    }

    if ($action === 'close') {
        $shift = afxPosShiftCurrent($branchId);
        if (!$shift) { echo json_encode(['ok' => false, 'error' => 'Žádná otevřená směna.']); exit; }
        $force = (int)($in['force'] ?? 0) === 1;
        if ($force && !(function_exists('crmCanDeleteOrders') && crmCanDeleteOrders())) {
            echo json_encode(['ok' => false, 'error' => 'Uzavřít cizí směnu smí jen vedení.']); exit;
        }
        $counted = (float)str_replace(',', '.', (string)($in['counted'] ?? ''));
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255);
        $res = afxPosShiftClose((int)$shift['id'], $counted, $note, $force);
        echo json_encode($res); exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Neznámá akce.']);
} catch (Throwable $e) {
    error_log('pos_shift: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Operace se nepodařila — zkus to znovu.']);
}
