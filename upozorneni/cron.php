<?php
/**
 * CHYTRÁ UPOZORNĚNÍ — jeden průchod všemi pravidly.
 *
 * Spouští se samo na pozadí (notify_poll, tiskový agent pobočky…), když je
 * CRM v provozu. Aby upozornění chodila spolehlivě i brzy ráno a v noci,
 * přidej systémový cron (bez zpětného lomítka):
 *     * * * * * php /cesta/k/crm/upozorneni/cron.php > /dev/null 2>&1
 * Bez přístupu ke cronu jde použít externí službu (např. cron-job.org) na
 * adresu upozorneni/tick.php?key=… — klíč je v CRM → Upozornění.
 *
 * Volby:  --dry   jen vypsat, co by odešlo (nic neposílá)
 *         --at="2026-10-06 07:45"   simulovat jiný čas (s --dry pro testy)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once __DIR__ . '/lib.php';

@set_time_limit(120);
$opts = getopt('', ['dry', 'at:']);
$dry = isset($opts['dry']);
$now = null;
if (!empty($opts['at'])) {
    try { $now = new DateTimeImmutable((string)$opts['at']); }
    catch (Throwable $e) { exit("Neplatný čas --at.\n"); }
}
if (!$dry) { set_setting('smart_notify_last_trigger', 'cli'); }

$res = afxNotifyRun($now, $dry);
if (isset($res['skipped'])) { echo 'Přeskočeno: ' . $res['skipped'] . "\n"; exit(0); }
echo ($dry ? '[NANEČISTO] ' : '') . 'Odesláno: ' . $res['sent'] . ' (' . $res['ms'] . " ms)\n";
foreach ($res['counts'] as $rule => $n) { if ($n > 0) { echo "  $rule: $n\n"; } }
foreach ($res['items'] as $it) {
    echo "\n— [{$it['rule']}] → {$it['to']} ({$it['role']}, {$it['level']})\n   {$it['title']}\n   " . str_replace("\n", "\n   ", $it['body']) . "\n";
}
