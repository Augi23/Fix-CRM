<?php
/**
 * MOST „SKEN Z TELEFONU → RECEPCE" (MacBook/PC v režimu recepce).
 *
 * POST {code}                → uloží sken pro POBOČKU přihlášeného (z mobilní appky;
 *                              volá se fetchem z WebView, takže jede na session cookie).
 * GET  ?after=<id>           → vrátí NEVYZVEDNUTÉ skeny své pobočky s id > after
 *                              (posledních 5 minut) a rovnou je CLAIMNE (delivered_at),
 *                              aby je otevřelo jen jedno zařízení recepce.
 * GET  ?after=0&baseline=1   → jen aktuální max(id) — startovní bod pollingu.
 *
 * Auth: přihlášený zaměstnanec (users i technicians session).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function stationFail(int $http, string $error): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) stationFail(401, 'unauthorized');

ensureStationScansTable($pdo);
$branch = (int)getCurrentStaffBranchId();
if ($branch <= 0) stationFail(400, 'no_branch');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $raw = (string)file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    $code = trim((string)(is_array($body) ? ($body['code'] ?? '') : ($_POST['code'] ?? '')));
    // stejné čištění jako u hardware čtečky v main.js (čísla/písmena/pomlčka)
    $code = preg_replace('/[^0-9A-Za-z\-]/', '', $code);
    if ($code === '' || mb_strlen($code) > 120) stationFail(400, 'invalid_code');
    $by = function_exists('crmStaffDisplayName') ? mb_substr(crmStaffDisplayName(), 0, 100) : null;
    $ins = $pdo->prepare("INSERT INTO station_scans (branch_id, code, sent_by) VALUES (?, ?, ?)");
    $ins->execute([$branch, $code, $by]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    exit;
}

// GET — polling recepce
$after = isset($_GET['after']) ? max(0, (int)$_GET['after']) : 0;
if (!empty($_GET['baseline'])) {
    $max = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM station_scans")->fetchColumn();
    echo json_encode(['ok' => true, 'baseline' => $max], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo->beginTransaction();
    // FOR UPDATE: dvě zařízení recepce si stejný sken nerozeberou dvakrát
    $sel = $pdo->prepare("SELECT id, code, sent_by FROM station_scans
                          WHERE branch_id = ? AND id > ? AND delivered_at IS NULL
                            AND created_at >= (NOW() - INTERVAL 5 MINUTE)
                          ORDER BY id ASC LIMIT 10 FOR UPDATE");
    $sel->execute([$branch, $after]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE station_scans SET delivered_at = NOW() WHERE id IN ($ph)")->execute($ids);
    }
    $pdo->commit();
    echo json_encode(['ok' => true, 'scans' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'code' => (string)$r['code'], 'sent_by' => (string)($r['sent_by'] ?? ''),
    ], $rows)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('station_scan: ' . $e->getMessage());
    stationFail(500, 'internal_error');
}
