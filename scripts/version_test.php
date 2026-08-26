<?php
/**
 * VERZE CRM — test (v3.63.1).
 *
 * Proč: 26. 8. 2026 hlásilo CRM po aktualizaci „máte nejnovější verzi 3.60.2"
 * a zároveň nabízelo 3.63.0, která už nainstalovaná byla — nabídka nešla
 * odklikat. Příčina: nainstalovaná verze se brala jako PRVNÍ záznam po
 * seřazení podle času, dostupná jako PRVNÍ výskyt v souboru. Když na CRM
 * pracují dvě relace naráz a časy zápisů se prostřídají, jsou to různé
 * záznamy. Test hlídá, že se obě čísla počítají stejně a podle VÝŠE VERZE.
 *
 * Spuštění z kořene CRM:  php scripts/version_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

head('Verze ze zdrojáku changelogu');

// přesně situace, která to rozbila: novější ČAS u nižší verze
$mixed = <<<'PHP'
<?php
$entries = [
    ['version' => '3.60.2', 'date' => '2026-08-26', 'time' => '22:05', 'title' => 'kolega'],
    ['version' => '3.63.0', 'date' => '2026-08-26', 'time' => '03:05', 'title' => 'já'],
];
PHP;
ok('bere se NEJVYŠŠÍ verze, ne první v souboru',
    crmVersionFromChangelogSource($mixed) === '3.63.0', crmVersionFromChangelogSource($mixed));

$reversed = <<<'PHP'
<?php
$entries = [
    ['version' => '3.63.0', 'date' => '2026-08-26', 'time' => '03:05'],
    ['version' => '3.60.2', 'date' => '2026-08-26', 'time' => '22:05'],
];
PHP;
ok('na pořadí v souboru nezáleží', crmVersionFromChangelogSource($reversed) === '3.63.0');

ok('desítky se porovnávají číselně, ne abecedně',
    crmVersionFromChangelogSource("'version' => '3.9.0' 'version' => '3.10.0'") === '3.10.0',
    crmVersionFromChangelogSource("'version' => '3.9.0' 'version' => '3.10.0'"));
ok('velké číslo vyhrává', crmVersionFromChangelogSource("'version' => '4.0.0' 'version' => '3.99.99'") === '4.0.0');
ok('prázdný zdroj nic nevymyslí', crmVersionFromChangelogSource('') === '');
ok('text bez verzí nic nevymyslí', crmVersionFromChangelogSource('nic tady není') === '');

head('Verze aplikace');
$v = crmAppVersion();
ok('má tvar x.y.z', (bool)preg_match('/^\d+\.\d+\.\d+\z/', $v), $v);
ok('není nouzová 1.0.0', $v !== '1.0.0', $v);

// musí odpovídat nejvyšší verzi v changelogu
$cl = include $root . '/includes/changelog.php';
$max = '';
foreach ($cl as $e) {
    $ev = (string)($e['version'] ?? '');
    if (preg_match('/^\d+\.\d+\.\d+\z/', $ev) && ($max === '' || version_compare($ev, $max, '>'))) { $max = $ev; }
}
ok('shoduje se s nejvyšší verzí v historii úprav', $v === $max, "app=$v max=$max");

// a hlavně: musí souhlasit s tím, co se počítá pro VZDÁLENOU verzi
$fromSrc = crmVersionFromChangelogSource((string)file_get_contents($root . '/includes/changelog.php'));
ok('nainstalovaná a dostupná verze se počítají stejně', $v === $fromSrc, "app=$v src=$fromSrc");

head('Záložní soubor VERSION');
$file = trim((string)@file_get_contents($root . '/VERSION'));
ok('VERSION má platný tvar', (bool)preg_match('/^\d+\.\d+\.\d+\z/', $file), $file);
ok('VERSION není vyšší než changelog (jinak by lhal)',
    version_compare($file, $max, '<=') , "VERSION=$file max=$max");

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
