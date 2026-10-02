<?php
/**
 * TŘÍDĚNÍ POŠTY — jeden běh přes všechny zapnuté schránky.
 *
 * Spouští se samo na pozadí z notify_poll (každé ~3 min, když je někdo v CRM
 * přihlášený). Aby se třídilo i v noci a o víkendu, přidej systémový cron:
 *     *\/5 * * * * php /cesta/k/crm/posta/cron.php > /dev/null 2>&1
 * (bez zpětného lomítka). Souběh dvou běhů hlídá zámek.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once __DIR__ . '/lib.php';

@set_time_limit(300);
$res = crmMailSortRunAll();
if (isset($res['skipped'])) { echo 'Přeskočeno: ' . $res['skipped'] . "\n"; exit(0); }
foreach ($res as $email => $r) {
    echo $email . ': ' . ($r['error'] !== null ? 'CHYBA ' . $r['error']
        : $r['processed'] . ' zpráv (zákazníci ' . $r['counts']['customer'] . ', nabídky ' . $r['counts']['offer'] . ', roboti ' . $r['counts']['robot'] . ', přesunuto ' . $r['moved'] . ')') . "\n";
}
if (!$res) { echo "Žádná zapnutá schránka.\n"; }
