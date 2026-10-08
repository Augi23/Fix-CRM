<?php
/* Globální vyhledávání pro „Spotlight" v horní liště (assets/js/global-search.js).
   GET q=<dotaz>, scope=all|accounting → JSON viz gsRun() v includes/global_search.php. */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/global_search.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nepřihlášeno']); exit;
}
session_write_close();   // hledání nesmí blokovat souběžné požadavky téhož přihlášení

$scope = ((string)($_GET['scope'] ?? '') === 'accounting') ? 'accounting' : 'all';
if (function_exists('crmIsAccountant') && crmIsAccountant()) { $scope = 'accounting'; }   // účetní jinak CRM neotevře

$res = gsRun($pdo, (string)($_GET['q'] ?? ''), $scope, 5);
foreach ($res['groups'] as &$g) { unset($g['best']); foreach ($g['items'] as &$it) { unset($it['gs_score']); } unset($it); }
unset($g);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
