<?php
/**
 * Sloučení duplicitních klientů (v3.93.0) — POST keep_id, drop_id, csrf_token.
 * Vše z drop_id přejde na keep_id (crmMergeCustomers). Jen admin a Boss.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Nepřihlášen.']); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method_not_allowed']); exit; }
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit; }
if (!crmIsAdminLevel()) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Slučovat klienty smí jen admin nebo Boss.'], JSON_UNESCAPED_UNICODE); exit; }

$res = crmMergeCustomers((int)($_POST['keep_id'] ?? 0), (int)($_POST['drop_id'] ?? 0));
echo json_encode($res, JSON_UNESCAPED_UNICODE);
