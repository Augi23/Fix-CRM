<?php
/**
 * DOPLNĚNÍ ÚDAJŮ Z IMEI — test (v3.61.0).
 *
 * Rozebrání popisu od Apple se testuje na vzorcích (žádný kredit se neutratí),
 * cache se ověří v transakci s rollbackem. Živý dotaz na iFreeiCloud se pustí
 * JEN s parametrem --live a jen na IMEI, které už v cache je, takže ani ten
 * nic nestojí.
 *
 * Spuštění z kořene CRM:  php scripts/imei_info_test.php [--live]
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/imei_info.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

// ── 1) rozebrání popisu zařízení ──
head('Rozebrání popisu od Apple');

$p = afxImeiParseAppleModel('iPhone 12 64GB White [A2403] [iPhone13,2]', 'iPhone 12');
ok('model', $p['model'] === 'iPhone 12', $p['model']);
ok('kapacita s mezerou', $p['capacity'] === '64 GB', $p['capacity']);
ok('barva', $p['color'] === 'White', $p['color']);
ok('objednací číslo', $p['model_number'] === 'A2403', $p['model_number']);
ok('identifikátor', $p['identifier'] === 'iPhone13,2', $p['identifier']);

$p = afxImeiParseAppleModel('iPhone 15 Pro Max 1TB Natural Titanium [A3106] [iPhone16,2]');
ok('1TB → „1 TB"', $p['capacity'] === '1 TB', $p['capacity']);
ok('dvouslovná barva celá', $p['color'] === 'Natural Titanium', $p['color']);
ok('model bez kapacity a barvy', $p['model'] === 'iPhone 15 Pro Max', $p['model']);

$p = afxImeiParseAppleModel('iPad Pro 11-inch (3rd generation) 256GB Space Gray [A2377] [iPad13,4]');
ok('iPad: kapacita', $p['capacity'] === '256 GB', $p['capacity']);
ok('iPad: barva „Space Gray" (ne „Gray")', $p['color'] === 'Space Gray', $p['color']);

$p = afxImeiParseAppleModel('Apple Watch Series 7 GPS 45mm Midnight Aluminum [A2474]');
ok('hodinky nemají kapacitu', $p['capacity'] === '', $p['capacity']);
// Vědomé rozhodnutí po prověrce: barva uprostřed názvu se NEHÁDÁ. Dřív se
// vyřízla z modelu („…45mm Jet Aluminum") a do skladu šel zmršený název —
// lepší barvu nedoplnit a nechat ji na obsluze.
ok('hodinky: barva se z názvu nehádá', $p['color'] === '', $p['color']);
ok('hodinky: model zůstane celý', $p['model'] === 'Apple Watch Series 7 GPS 45mm Midnight Aluminum', $p['model']);
$p = afxImeiParseAppleModel('Apple Watch Series 9 41mm Midnight');
ok('hodinky: barva NA KONCI se pozná', $p['color'] === 'Midnight', $p['color']);

$p = afxImeiParseAppleModel('AirPods Pro (2nd generation) [A2698]');
ok('AirPods: model zůstane celý', str_contains($p['model'], 'AirPods Pro'), $p['model']);
ok('AirPods: prázdná kapacita i barva', $p['capacity'] === '' && $p['color'] === '');

ok('prázdný vstup nespadne', afxImeiParseAppleModel('')['model'] === '');
ok('nesmyslný vstup nespadne', is_array(afxImeiParseAppleModel('???')));

// ── 1b) pasti prokázané prověrkou 26. 8. 2026 ──
head('Pasti z prověrky (chybná kapacita a barva)');

$p = afxImeiParseAppleModel('iPod classic 320GB Silver');
ok('„320GB" NENÍ „32 GB"', $p['capacity'] === '320 GB', $p['capacity']);
$p = afxImeiParseAppleModel('iPhone X 160GB Space Gray');
ok('„160GB" NENÍ „16 GB"', $p['capacity'] === '160 GB', $p['capacity']);
$p = afxImeiParseAppleModel('MacBook Pro 16-inch 16GB 1TB Space Gray');
ok('u Macu je úložiště POSLEDNÍ číslo, ne RAM', $p['capacity'] === '1 TB', $p['capacity']);
ok('u Macu se barva bere až za úložištěm', $p['color'] === 'Space Gray', $p['color']);
ok('model Macu končí před RAM', $p['model'] === 'MacBook Pro 16-inch', $p['model']);

$p = afxImeiParseAppleModel('iPhone 11 Pro 64GB Midnight Green');
ok('„Midnight Green" se nezkrátí na „Midnight"', $p['color'] === 'Midnight Green', $p['color']);
ok('neznámá barva je označená jako neznámá', $p['color_known'] === false);

$p = afxImeiParseAppleModel('IPAD PRO 11 CELL 256GB SPACE GRAY-ITP');
ok('ocásek „-ITP" se ořízne', $p['color'] === 'Space Gray', $p['color']);

$p = afxImeiParseAppleModel('Apple Watch Series 10 46mm Jet Black Aluminum');
ok('barva se NEvyřízne z názvu hodinek', str_contains($p['model'], 'Jet Black Aluminum') || $p['model'] === 'Apple Watch Series 10 46mm Jet Black Aluminum', $p['model']);

$p = afxImeiParseAppleModel('iPhone 13 mini 128GB Pink');
ok('„13 mini" nevyrobí kapacitu z čísla modelu', $p['capacity'] === '128 GB', $p['capacity']);
ok('model zůstane „iPhone 13 mini"', $p['model'] === 'iPhone 13 mini', $p['model']);

// ── 2) barvy proti katalogu ──
head('Barvy');
ok('„Space Black" se nespáruje na „Black"', afxImeiMatchColor('Space Black') === 'Space Black');
ok('„Black" zůstane „Black"', afxImeiMatchColor('Black') === 'Black');
ok('neznámá barva se vrátí celá, ne první slovo', afxImeiMatchColor('Fialová mlha') === 'Fialová mlha');
ok('neznámá barva má known = false', afxImeiColorFromText('Fialová mlha')['known'] === false);
ok('známá barva má known = true', afxImeiColorFromText('Space Gray')['known'] === true);

// ── 3) typ zařízení ──
head('Typ zařízení');
foreach ([
    'iPhone 12' => 'iPhone', 'iPad Air' => 'iPad', 'MacBook Pro 14' => 'MacBook',
    'Apple Watch Series 7' => 'Apple Watch', 'AirPods Pro' => 'AirPods',
    'Mac mini' => 'Mac mini', 'iMac 24' => 'iMac', 'HomePod mini' => 'HomePod',
    'Nokia 3310' => '',
] as $model => $exp) {
    ok("„$model" . '" → ' . ($exp ?: 'neznámý typ'), afxImeiDeviceType($model) === $exp, afxImeiDeviceType($model));
}

// ── 4) celá odpověď API → formulář ──
head('Převod odpovědi API na políčka formuláře');
$obj = [
    'model' => 'iPhone 12 64GB White [A2403] [iPhone13,2]',
    'apple/modelName' => 'iPhone 12',
    'serial' => 'FFXGT3HX0F0Q', 'imei' => '353036118781852', 'imei2' => '353036118927992',
    'thumbnail' => 'https://appleid.cdn-apple.com/x.png',
    'warrantyStatus' => 'Out Of Warranty', 'estPurchaseDate' => '3 Jan 2022',
    'fmiOn' => false, 'lostMode' => false, 'simLock' => false, 'replaced' => false, 'isAppleDevice' => true,
];
$i = afxImeiInfoFromApiObject($obj);
ok('výrobce Apple', $i['manufacturer'] === 'Apple');
ok('typ iPhone', $i['device_type'] === 'iPhone', $i['device_type']);
ok('model z katalogu', $i['model'] === 'iPhone 12' && $i['model_known'] === true, $i['model'] . ' known=' . var_export($i['model_known'], true));
ok('kapacita „64 GB" (jako v katalogu)', $i['capacity'] === '64 GB', $i['capacity']);
ok('barva White', $i['color'] === 'White');
ok('sériové číslo', $i['serial'] === 'FFXGT3HX0F0Q');
ok('Find My vypnuté', $i['find_my'] === false);
ok('SIM-lock odemčeno', $i['sim_lock'] === false);
ok('záruka i datum nákupu', $i['warranty'] !== '' && $i['purchase_date'] !== '');

$i2 = afxImeiInfoFromApiObject(['model' => 'iPhone 99 Ultra 4TB Fialová mlha', 'apple/modelName' => 'iPhone 99 Ultra']);
ok('neznámý model se pozná (model_known = false)', $i2['model_known'] === false);
ok('neznámá kapacita projde dál', $i2['capacity'] === '4 TB', $i2['capacity']);

// ── 5) značka z odmítnutí ──
head('Ne-Apple zařízení');
ok('HONOR z hlášky', afxImeiBrandFromError('Only Apple devices supported. This device is a HONOR.') === 'Honor',
    afxImeiBrandFromError('Only Apple devices supported. This device is a HONOR.'));
ok('SAMSUNG z hlášky', afxImeiBrandFromError('Only Apple devices supported. This device is a SAMSUNG.') === 'Samsung');
ok('víceslovný text dá JEN značku',
    afxImeiBrandFromError('Only Apple devices supported. This device is a XIAOMI REDMI NOTE 12.') === 'Xiaomi',
    afxImeiBrandFromError('Only Apple devices supported. This device is a XIAOMI REDMI NOTE 12.'));
ok('cizí text nic nevymyslí', afxImeiBrandFromError('Invalid key') === '');

// ── 6) co se na API vůbec nesmí zeptat (každý dotaz stojí kredit) ──
head('Pojistky proti zbytečnému kreditu');
foreach (['', '12345', 'C02XY1234ABC', '3530361187818521234'] as $bad) {
    $r = afxImeiInfoLookup($bad);
    ok('„' . mb_substr($bad, 0, 22) . '" se neptá API', $r['ok'] === false && $r['source'] === 'none', $r['source']);
}
// překlep v jinak správně dlouhém IMEI musí zachytit kontrolní číslice
$r = afxImeiInfoLookup('353036118781858');
ok('IMEI s překlepem se neptá API (Luhn)', $r['source'] === 'none' && str_contains($r['error'], 'kontrolou'), $r['error']);
ok('správné IMEI Luhnem projde', afxImeiLuhnValid('353036118781852'));
ok('14 číslic se dopočítá na 15', afxImeiNormalize15('35303611878185') === '353036118781852',
    afxImeiNormalize15('35303611878185'));

// ── 7) cache ──
head('Cache odpovědí');
afxEnsureImeiLookupTable();
$tbl = $pdo->query("SHOW TABLES LIKE 'imei_lookups'")->fetch();
ok('tabulka imei_lookups existuje', (bool)$tbl);

$testImei = afxImeiNormalize15('99999999999999');   // platná kontrolní číslice, jinak ho Luhn odmítne dřív než cache
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO imei_lookups (imei, ok, state, brand, note, payload) VALUES (?, 1, 'ok', 'Apple', '', ?)")
        ->execute([$testImei, json_encode(['model' => 'TEST iPhone', 'device_type' => 'iPhone'], JSON_UNESCAPED_UNICODE)]);
    $c = afxImeiInfoLookup($testImei);
    ok('odpověď se vezme z cache (bez dotazu na API)', $c['source'] === 'cache', $c['source']);
    ok('data z cache dorazí', ($c['info']['model'] ?? '') === 'TEST iPhone');
    ok('cache hlásí, kdy se ptalo', ($c['checked_at'] ?? '') !== '');

    // výpadek sítě NESMÍ zablokovat IMEI natrvalo — po 15 minutách se zkusí znovu
    $pdo->prepare("UPDATE imei_lookups SET ok = 0, state = 'error', note = 'timeout',
        payload = NULL, created_at = DATE_SUB(NOW(), INTERVAL 30 MINUTE) WHERE imei = ?")->execute([$testImei]);
    $stale = $pdo->prepare("SELECT TIMESTAMPDIFF(MINUTE, created_at, NOW()) FROM imei_lookups WHERE imei = ?");
    $stale->execute([$testImei]);
    ok('starý neúspěch je v cache označený jako „error"', (int)$stale->fetchColumn() >= 15);

    // „není Apple" naopak platí navždy (značka se nezmění)
    $pdo->prepare("UPDATE imei_lookups SET state = 'not_apple', brand = 'Honor', note = 'jde o Honor' WHERE imei = ?")->execute([$testImei]);
    $na = afxImeiInfoLookup($testImei);
    ok('„není Apple" se drží z cache', $na['source'] === 'cache' && $na['brand'] === 'Honor', $na['source'] . '/' . $na['brand']);
} finally {
    $pdo->rollBack();
}
ok('testovací záznam v databázi nezůstal',
    (int)$pdo->query("SELECT COUNT(*) FROM imei_lookups WHERE imei = '$testImei'")->fetchColumn() === 0);

// ── 8) volitelně živý dotaz (jen na IMEI, které už je v cache) ──
if (in_array('--live', $argv, true)) {
    head('Živý dotaz (z cache, bez kreditu)');
    $known = (string)$pdo->query("SELECT imei FROM imei_lookups WHERE ok = 1 ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    if ($known !== '') {
        $l = afxImeiInfoLookup($known);
        ok('cache vrací hotové údaje', $l['ok'] && !empty($l['info']['model']), (string)($l['info']['model'] ?? ''));
    } else {
        echo "  (v cache zatím není žádné Apple IMEI — přeskočeno)\n";
    }
}

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
