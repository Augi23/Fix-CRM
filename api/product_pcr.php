<?php
/**
 * Živá PČR kontrola pro naskladňovací formulář (badge u pole SN/IMEI).
 * Autoritativní kontrola běží stejně znovu v product_create — tohle je jen
 * okamžitá zpětná vazba pro obsluhu.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/pcr.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanManageProducts()) {   // dotazy na PČR jen pro ty, kdo naskladňují
    echo json_encode(['success' => false, 'message' => 'Jen pro vedení.']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$res = afxPcrCheckImei((string)($_POST['imei'] ?? ''));
echo json_encode(['success' => true, 'status' => $res['status'], 'text' => $res['text']], JSON_UNESCAPED_UNICODE);
