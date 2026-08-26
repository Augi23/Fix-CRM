<?php
/**
 * KONTROLA IMEI U PČR — test (v3.61.0).
 *
 * Proč: Policie ČR 25. 8. 2026 přepsala aplikaci „Odcizené mobilní telefony"
 * (starý ASP.NET postback zmizel) a kontrola v CRM začala u všeho vracet
 * „unknown". Test hlídá obojí: že se živý web správně přečte, a hlavně že
 * vyhodnocení pozná NALEZENÝ záznam — ten na živém webu bez odcizeného IMEI
 * vyzkoušet nejde, proto se vyhodnocovací funkce testuje na vzorcích HTML.
 *
 * Nic se nikam nezapisuje. Spuštění z kořene CRM:  php scripts/pcr_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
require_once dirname(__DIR__) . '/includes/pcr.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

$msg = static fn(string $inner) => '<div class="gov-message__content">' . $inner . '</div>';

// ── 1) vyhodnocení odpovědi ──
head('Vyhodnocení odpovědi webu PČR');

$r = afxPcrClassifyHtml($msg('Na základě zadaných kritérií <strong>nebyl nalezen</strong> žádný záznam.'));
ok('„nebyl nalezen" = čistý', $r['status'] === 'clean', $r['status']);
ok('hláška se přenese celá', str_contains($r['text'], 'nebyl nalezen'), $r['text']);

foreach ([
    'Na základě zadaných kritérií <strong>byl nalezen</strong> 1 záznam.' => 'byl nalezen',
    'Na základě zadaných kritérií <strong>byly nalezeny</strong> 2 záznamy.' => 'byly nalezeny',
    'Telefon je evidován jako <strong>odcizený</strong>.' => 'odcizený',
    'IMEI je blokováno v síti operátorů.' => 'blokováno',
] as $html => $label) {
    $x = afxPcrClassifyHtml($msg($html));
    ok("„$label" . '" = ODCIZENO', $x['status'] === 'stolen', $x['status']);
}

// past, kvůli které se testuje negativ jako první
$x = afxPcrClassifyHtml($msg('nebyl nalezen'));
ok('podřetězec „byl nalezen" uvnitř „nebyl nalezen" neplete', $x['status'] === 'clean', $x['status']);

// stránka obsahuje i nesouvisející hlášky — rozhodovat musí ta o výsledku
$mix = $msg('Web používá cookies. Nalezeny nové funkce.')
     . $msg('Na základě zadaných kritérií <strong>nebyl nalezen</strong> žádný záznam.');
$x = afxPcrClassifyHtml($mix);
ok('cizí hláška se slovem „nalezeny" nezvrátí výsledek', $x['status'] === 'clean', $x['status'] . ' / ' . $x['text']);

// položka menu „nalezeny-predmet" mimo hlášku nesmí nic ovlivnit
$x = afxPcrClassifyHtml('<a href="/nalezeny-predmet">Nalezené předměty</a>'
    . $msg('Na základě zadaných kritérií <strong>nebyl nalezen</strong> žádný záznam.'));
ok('odkaz „nalezeny-predmet" v menu neplete', $x['status'] === 'clean', $x['status']);

ok('prázdná stránka = neurčito', afxPcrClassifyHtml('<html></html>')['status'] === 'unknown');
ok('cizí HTML bez hlášky = neurčito', afxPcrClassifyHtml('<div>nic</div>')['status'] === 'unknown');

// kdyby se PČR vrátila ke starému formuláři, ať to pořád funguje
$old = '<span id="ctl00_Application_Label1">Hledaný záznam nebyl nalezen.</span>';
ok('starý ASP.NET výpis se pořád přečte', afxPcrClassifyHtml($old)['status'] === 'clean');

// ── 2) vstupy ──
head('Kontrola vstupu');
foreach (['C02XY1234ABC', '12345', '', 'abc'] as $bad) {
    $b = afxPcrCheckImei($bad);
    ok('„' . $bad . '" = není IMEI (nekontroluje se)', $b['status'] === 'notimei', $b['status']);
}

// ── 3) živý web ──
head('Živý dotaz na policie.gov.cz');
$live = afxPcrCheckImei('356938035643809');
ok('web odpověděl a rozumíme mu', in_array($live['status'], ['clean', 'stolen'], true),
    $live['status'] . ' — ' . mb_substr((string)$live['text'], 0, 90));
ok('vrácené IMEI je 14 číslic', strlen((string)$live['imei']) === 14, (string)$live['imei']);
ok('hláška je od PČR, ne naše náhradní', !str_contains((string)$live['text'], 'nevrátila jednoznačnou'), (string)$live['text']);

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
