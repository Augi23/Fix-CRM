<?php
/**
 * Nahrání bankovního výpisu GPC/ABO (v3.93.0) — Účetnictví → Banka.
 * Pohyby se uloží (zdroj 'gpc') a hned spárují s fakturami a e-shop objednávkami.
 * Smí vedení (admin, Boss) a účetní s právem úprav.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/bank_gpc.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Nepřihlášen.']); exit; }
if (!(crmCanManageInvoices() || (function_exists('crmCanAccountingEdit') && crmCanAccountingEdit()))) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Nahrávat výpisy smí vedení nebo účetní.'], JSON_UNESCAPED_UNICODE); exit;
}
if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')]); exit; }
if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { echo json_encode(['ok' => false, 'error' => 'Vyber soubor s výpisem.'], JSON_UNESCAPED_UNICODE); exit; }
if ((int)$_FILES['file']['size'] > 5 * 1024 * 1024) { echo json_encode(['ok' => false, 'error' => 'Soubor je moc velký (max. 5 MB).'], JSON_UNESCAPED_UNICODE); exit; }

$raw = (string)file_get_contents($_FILES['file']['tmp_name']);
echo json_encode(afxImportGpc($raw), JSON_UNESCAPED_UNICODE);
