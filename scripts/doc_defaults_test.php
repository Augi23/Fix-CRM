<?php
/**
 * DOKLADY — místo, datum a měna (test, v3.67.0).
 *
 * Hlídá dvě věci, které se dosud psaly ručně u každého výkupu:
 *  · místo a datum podpisu se předvyplní z provozovny a dnešního dne,
 *  · částka má vždy na konci měnu („5000" → „5 000 Kč").
 * A hlavně to, že se tím NEROZBIJE výplata z kasy — ta si částku z dokladu
 * čte a musí ji přečíst i s měnou (crmParseAmountCzk).
 *
 * Spuštění z kořene CRM:  php scripts/doc_defaults_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/documents.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

head('Částka s měnou');
$mena = trim((string)get_setting('currency', 'Kč')) ?: 'Kč';
echo "  (měna z nastavení: $mena)\n";

$f = static fn(string $x) => crmDocFormatAmount($x);
ok('„5000" dostane měnu', str_ends_with($f('5000'), $mena), $f('5000'));
ok('tisíce se oddělí mezerou', preg_replace('/\x{00a0}/u', ' ', $f('5000')) === '5 000 ' . $mena, $f('5000'));
ok('už zapsaná měna se nezdvojí', substr_count($f('5000 ' . $mena), $mena) === 1, $f('5000 ' . $mena));
ok('malé písmeno v měně se taky pozná', substr_count(mb_strtolower($f('5000 kč'), 'UTF-8'), 'kč') === 1, $f('5000 kč'));
ok('haléře zůstanou celé', str_contains($f('12490,50'), '12') && str_contains($f('12490,50'), ',50'), $f('12490,50'));
ok('text bez čísla se nechá být', $f('dohodou') === 'dohodou', $f('dohodou'));
ok('prázdno zůstane prázdné', $f('') === '');
ok('nula se needituje na měnu', $f('0') === '0', $f('0'));

head('Výplata z kasy částku pořád přečte');
foreach (['5000', '5 000 Kč', '12490,50', '12 490,50 Kč'] as $raw) {
    $formatted = $f($raw);
    $parsed = crmParseAmountCzk($formatted);
    $expect = crmParseAmountCzk($raw);
    ok("„$raw" . '" → „' . $formatted . '" se přečte jako ' . $expect,
        abs($parsed - $expect) < 0.005, (string)$parsed);
}
ok('„dohodou" dá nulu (kasa si vyžádá částku)', crmParseAmountCzk($f('dohodou')) === 0.0);

head('Místo a datum podpisu');
$def = crmDocDefaultValues('vykup');
ok('pole sign_place_date se předvyplní', !empty($def['sign_place_date']), json_encode($def));
$val = (string)($def['sign_place_date'] ?? '');
ok('obsahuje dnešní datum', str_contains($val, date('j. n. Y')), $val);
ok('u zástavy taky', !empty(crmDocDefaultValues('zastava')['sign_place_date']));
ok('nevyplňuje se nic jiného (cena ani věc)',
    !isset($def['item_price']) && !isset($def['item_description']), json_encode($def));

// místo se bere z provozovny přihlášeného; bez přihlášení zbude aspoň datum
$branch = '';
try {
    $bid = (int)getCurrentStaffBranchId();
    if ($bid > 0) {
        $st = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
        $st->execute([$bid]);
        $branch = trim((string)($st->fetchColumn() ?: ''));
    }
} catch (Throwable $e) {}
if ($branch !== '') {
    ok('obsahuje název provozovny', str_contains($val, $branch), $val . ' / ' . $branch);
} else {
    echo "  (bez přihlášené obsluhy se pobočka nezjistí — kontrola přeskočena)\n";
}

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
