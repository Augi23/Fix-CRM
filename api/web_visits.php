<?php
/**
 * Návštěvnost webů pro dlaždice a přehled na Nástěnce (čtecí, pro vedení).
 * GET ?days=14 → { ok, sites: { cz: {label, today:{…}, yesterday:{…}, days:[…] }, click: {…} } }
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!crmCanManageProducts()) {   // stejné publikum jako ostatní přehledy vedení
    http_response_code(403);
    echo json_encode(['ok' => false, 'sites' => []]); exit;
}

$days = max(2, min(60, (int)($_GET['days'] ?? 14)));
$stats = afxWebVisitStats($days);
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$out = [];
foreach (AFX_WEB_SITES as $key => $label) {
    $rows = $stats[$key] ?? [];
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $series[] = [
            'date' => $d,
            'label' => date('j.n.', strtotime($d)),
            'hits' => (int)($rows[$d]['hits'] ?? 0),
            'visitors' => (int)($rows[$d]['visitors'] ?? 0),
        ];
    }
    $sum = array_sum(array_column($series, 'visitors'));
    $out[$key] = [
        'label' => $label,
        'today' => ['hits' => (int)($rows[$today]['hits'] ?? 0), 'visitors' => (int)($rows[$today]['visitors'] ?? 0)],
        'yesterday' => ['hits' => (int)($rows[$yesterday]['hits'] ?? 0), 'visitors' => (int)($rows[$yesterday]['visitors'] ?? 0)],
        'sum_visitors' => $sum,
        'days' => $series,
    ];
}
echo json_encode(['ok' => true, 'days' => $days, 'sites' => $out], JSON_UNESCAPED_UNICODE);
