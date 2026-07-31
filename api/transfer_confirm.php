<?php
/** Potvrzení a PROVEDENÍ přesunu (jen zaměstnanec ZDROJOVÉ pobočky). */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/stock_transfers.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !hasPermission('manage_inventory')) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')], JSON_UNESCAPED_UNICODE); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')], JSON_UNESCAPED_UNICODE); exit;
}

$transferId = (int)($_POST['transfer_id'] ?? 0);
if ($transferId <= 0) { echo json_encode(['success' => false, 'message' => 'Chybí přesun.'], JSON_UNESCAPED_UNICODE); exit; }
$res = afxTransferConfirm($transferId);
echo json_encode(['success' => $res['ok'], 'message' => $res['message']], JSON_UNESCAPED_UNICODE);
