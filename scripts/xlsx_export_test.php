<?php
/**
 * EXPORT PODKLADŮ DO EXCELU — test (v3.68.0).
 *
 * Kontroluje, že sešit opravdu vznikne, je to platný XLSX (ZIP se všemi
 * povinnými díly) a hlavně že ČÁSTKY JSOU ČÍSLA, ne text — jinak by si
 * účetní v Excelu nic nesečetla a celý export by neměl smysl.
 * Data se čtou z ostré databáze jen ke čtení, nic se nikam nezapisuje.
 *
 * Spuštění z kořene CRM:  php scripts/xlsx_export_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/ucetni_reports.php';
require_once $root . '/includes/xlsx_writer.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

/** Sestaví sešit do souboru (send() posílá do prohlížeče, tady chceme soubor). */
function buildToFile(AfxXlsx $x, string $path): void {
    ob_start();
    $x->send(basename($path));
    $bin = (string)ob_get_clean();
    file_put_contents($path, $bin);
}

head('Základní sešit');
$tmp = sys_get_temp_dir() . '/afx_xlsx_test_' . bin2hex(random_bytes(4)) . '.xlsx';
$x = new AfxXlsx('Kniha faktur');
$x->row(['Kniha vydaných faktur'], ['text']);
$x->blank();
$x->header(['Číslo', 'Vystaveno', 'Odběratel', 'Částka']);
$x->row(['20260001', '2026-08-01', 'ACME s.r.o.', 12490.50], ['text', 'date', 'text', 'money']);
$x->row(['20260002', '2026-08-15', 'Novák & syn', 5000], ['text', 'date', 'text', 'money']);
buildToFile($x, $tmp);

ok('soubor vznikl a není prázdný', is_file($tmp) && filesize($tmp) > 500, (string)@filesize($tmp));
$zip = new ZipArchive();
ok('je to platný ZIP (XLSX je zip)', $zip->open($tmp) === true);
foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $part) {
    ok("obsahuje $part", $zip->locateName($part) !== false);
}
$sheet = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
$book = (string)$zip->getFromName('xl/workbook.xml');
$zip->close();

head('Obsah listu');
ok('název listu je v sešitu', str_contains($book, 'Kniha faktur'), '');
ok('text se zapsal jako inlineStr', str_contains($sheet, '<is><t xml:space="preserve">ACME s.r.o.'), '');
ok('ampersand v názvu je zaescapovaný (jinak nevalidní XML)',
    str_contains($sheet, 'Nov') && str_contains($sheet, '&amp;') && !preg_match('/&(?!amp;|lt;|gt;|quot;|#)/', $sheet));
ok('list je validní XML', @simplexml_load_string($sheet) !== false);

// TOHLE je smysl celého exportu: částka musí být číslo, ne text
ok('částka 12490,50 je ČÍSLO, ne text',
    (bool)preg_match('/<c r="D4"[^>]*><v>12490\.5<\/v><\/c>/', $sheet),
    (string)(preg_match('/<c r="D4".*?<\/c>/', $sheet, $m) ? $m[0] : 'nenalezeno'));
ok('celá částka 5000 je taky číslo',
    (bool)preg_match('/<c r="D5"[^>]*><v>5000<\/v><\/c>/', $sheet));
ok('datum je číslo (sériové datum Excelu), ne text',
    (bool)preg_match('/<c r="B4"[^>]*><v>(\d{5})<\/v><\/c>/', $sheet, $ds),
    (string)(preg_match('/<c r="B4".*?<\/c>/', $sheet, $m) ? $m[0] : 'nenalezeno'));
// PAST: převod přes timestamp posouval každé datum o den zpět (půlnoc v Praze
// je v UTC ještě předchozí den). U data vystavení a splatnosti nepřijatelné.
$zpet = isset($ds[1])
    ? (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)$ds[1] . ' days')->format('Y-m-d')
    : '';
ok('datum se v Excelu přečte jako 1. 8. 2026 (žádný posun o den)', $zpet === '2026-08-01', $zpet);
$serial = new ReflectionMethod('AfxXlsx', 'dateSerial'); $serial->setAccessible(true);
foreach (['2026-01-15', '2026-08-31', '2026-08-04 23:45:00', '2000-02-29'] as $d) {
    $sv = $serial->invoke(null, $d);
    $back = (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)$sv . ' days')->format('Y-m-d');
    ok("datum $d se nepošoupne", $back === substr($d, 0, 10), $back);
}
ok('nesmyslné datum se uloží jako text, ne jako den 0', $serial->invoke(null, '0000-00-00') === null);
ok('hlavička má vlastní styl (tučně)', str_contains($sheet, 's="1"'));
ok('hlavička je zmrazená', str_contains($sheet, 'state="frozen"'));
ok('nastavený filtr nad tabulkou', str_contains($sheet, '<autoFilter'));
@unlink($tmp);

head('Zvláštní vstupy');
$tmp2 = sys_get_temp_dir() . '/afx_xlsx_test2_' . bin2hex(random_bytes(4)) . '.xlsx';
$y = new AfxXlsx('Nevhodný/název:listu*který je moc dlouhý na Excel');
$y->header(['A', 'B']);
$y->row(['', null], ['text', 'money']);
$y->row(["řádek\r\ns koncem řádku", '1 234,50'], ['text', 'money']);
$y->row(['<script>', '—'], ['text', 'money']);
buildToFile($y, $tmp2);
$z2 = new ZipArchive();
$z2->open($tmp2);
$sheet2 = (string)$z2->getFromName('xl/worksheets/sheet1.xml');
$book2 = (string)$z2->getFromName('xl/workbook.xml');
$z2->close();
// POZOR: délku počítat ve ZNACÍCH, ne v bajtech — česká diakritika má
// v UTF-8 dva bajty a limit Excelu je 31 znaků
preg_match('/<sheet name="([^"]*)"/u', $book2, $m);
$sheetName = $m[1] ?? '';
ok('název listu se ořeže na 31 znaků a bez zakázaných znaků',
    $sheetName !== '' && mb_strlen(html_entity_decode($sheetName, ENT_QUOTES | ENT_XML1, 'UTF-8')) <= 31
    && !preg_match('#[:\\\\/?*\[\]]#u', $sheetName),
    $sheetName . ' (' . mb_strlen($sheetName) . ' znaků)');
ok('prázdné buňky nespadnou', str_contains($sheet2, '<c r="A2"'));
ok('částka „1 234,50" se přečte jako číslo', str_contains($sheet2, '<v>1234.5</v>'));
ok('pomlčka místo částky zůstane textem', str_contains($sheet2, '—'));
ok('HTML v datech se zaescapuje', str_contains($sheet2, '&lt;script&gt;'));
ok('list je pořád validní XML', @simplexml_load_string($sheet2) !== false);
@unlink($tmp2);

head('Sloupce sestav');
$period = afxUcetniResolvePeriod([]);
foreach (['kniha', 'uhrady', 'pohledavky', 'dobropisy', 'kasa', 'zalohy', 'banka'] as $key) {
    $data = match ($key) {
        'kniha'      => afxUcetniDataKniha($period['from'], $period['to'], 0),
        'uhrady'     => afxUcetniDataUhrady($period['from'], $period['to'], 0),
        'pohledavky' => afxUcetniDataPohledavky($period['to'], 0),
        'dobropisy'  => afxUcetniDataDobropisy($period['from'], $period['to'], 0),
        'kasa'       => afxUcetniDataKasa($period['from'], $period['to'], 0),
        'zalohy'     => afxUcetniDataZalohy($period['from'], $period['to'], 0),
        'banka'      => afxUcetniDataBanka($period['from'], $period['to'], 0),
    };
    $spec = afxUcetniExportSpec($key, $data);
    $okCols = !empty($spec['sloupce']);
    // každý sloupec musí jít spočítat na KAŽDÉM řádku (překlep v názvu pole
    // by se jinak projevil až u účetní v prázdném sloupci)
    $err = '';
    foreach (array_slice($spec['rows'], 0, 30) as $r) {
        foreach ($spec['sloupce'] as $c) {
            try { ($c[1])($r); } catch (Throwable $e) { $err = $c[0] . ': ' . $e->getMessage(); break 2; }
        }
    }
    ok("sestava „$key" . '" má sloupce a projdou na datech (' . count($spec['rows']) . ' řádků)',
        $okCols && $err === '', $err);
}

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
