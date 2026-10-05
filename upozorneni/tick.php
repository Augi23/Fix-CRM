<?php
/**
 * CHYTRÁ UPOZORNĚNÍ — tik pro externí cron (cron-job.org, UptimeRobot…).
 *   GET upozorneni/tick.php?key=<klíč z CRM → Upozornění>
 * Hodí se tam, kde hosting nemá systémový cron: služba zavolá adresu každou
 * minutu a upozornění chodí i ve chvíli, kdy nikdo nemá CRM otevřené.
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$key = (string)($_GET['key'] ?? ($_SERVER['HTTP_X_TICK_KEY'] ?? ''));
$expected = (string)(afxNotifyConfig()['global']['tick_token'] ?? '');
if (strlen($expected) < 24 || !hash_equals($expected, $key)) {
    usleep(300000);   // zpomalit hádání klíče
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
@set_time_limit(120);
set_setting('smart_notify_last_trigger', 'tick');
$res = afxNotifyRun();
echo json_encode(['ok' => true, 'sent' => (int)($res['sent'] ?? 0), 'skipped' => $res['skipped'] ?? null], JSON_UNESCAPED_UNICODE);
