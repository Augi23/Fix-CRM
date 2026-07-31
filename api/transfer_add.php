<?php
/** Přidání položky (díl/produkt) do rozpracovaného přesunu na druhou pobočku. */
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
ensureSkladBranchSchema();

$from     = (int)($_POST['from_branch'] ?? 0) ?: getCurrentStaffBranchId();
$type     = (string)($_POST['type'] ?? 'inventory');
$sourceId = (int)($_POST['source_id'] ?? 0);
$qty      = max(1, (int)($_POST['qty'] ?? 1));
if ($sourceId <= 0) { echo json_encode(['success' => false, 'message' => 'Chybí položka.'], JSON_UNESCAPED_UNICODE); exit; }

$res = afxTransferAddItem($from, $type, $sourceId, $qty);
echo json_encode(['success' => $res['ok'], 'message' => $res['message'], 'count' => $res['count'] ?? 0], JSON_UNESCAPED_UNICODE);
