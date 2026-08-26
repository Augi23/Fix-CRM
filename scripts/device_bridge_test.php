<?php
/**
 * MŮSTEK PRO ČTENÍ ZAŘÍZENÍ — test (v3.62.0).
 *
 * Testuje serverovou část: token, uložení hlášení, výdej do formuláře
 * a hlavně to, že se do skladu nedostane nic, co katalog nezná.
 * Vše v transakci s ROLLBACKem, nic se nikam neposílá.
 *
 * Spuštění z kořene CRM:  php scripts/device_bridge_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/device_bridge.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

// ── 1) token a příkaz k instalaci ──
head('Token a návod k instalaci');
$t1 = afxDeviceBridgeToken();
ok('token se vyrobí sám', strlen($t1) >= 20, $t1);
ok('podruhé vrátí tentýž (můstky nepřestanou fungovat)', afxDeviceBridgeToken() === $t1);
$cmd = afxDeviceBridgeInstallCommand($t1);
ok('příkaz obsahuje token', str_contains($cmd, $t1));
ok('příkaz stahuje z našeho serveru', str_contains($cmd, '/device-bridge/install.sh'), $cmd);
ok('příkaz jede přes HTTPS', str_starts_with($cmd, 'curl -fsSL https://'), $cmd);

// ── 2) převod údajů z telefonu na políčka formuláře ──
head('Údaje z telefonu → formulář');
$iphone12 = [
    'imei' => '353036118781852', 'serial' => 'FFXGT3HX0F0Q', 'device_class' => 'iPhone',
    'product_type' => 'iPhone13,2', 'model' => 'iPhone 12', 'model_number' => 'MGJ83',
    'capacity' => '64 GB', 'color' => 'White', 'ios' => '17.6.1', 'activation' => 'Activated',
    'battery_health' => 87, 'battery_cycles' => 412,
];
$f = afxDeviceBridgeToForm($iphone12);
ok('výrobce Apple', $f['manufacturer'] === 'Apple');
ok('typ iPhone', $f['device_type'] === 'iPhone', $f['device_type']);
ok('model zná katalog', $f['model'] === 'iPhone 12' && $f['model_known'] === true);
ok('kapacita zná katalog', $f['capacity'] === '64 GB' && $f['capacity_known'] === true);
ok('barva zná katalog', $f['color'] === 'White' && $f['color_known'] === true);
ok('kondice baterie projde jako číslo', $f['battery'] === 87, var_export($f['battery'], true));
ok('cykly baterie projdou', $f['battery_cycles'] === 412);
ok('IMEI i sériové číslo', $f['imei'] === '353036118781852' && $f['serial'] === 'FFXGT3HX0F0Q');

// neznámý model se NESMÍ tvářit jako známý (jinak by se zapsal do číselníku)
$f2 = afxDeviceBridgeToForm(['device_class' => 'iPhone', 'product_type' => 'iPhone99,9',
    'model' => '', 'capacity' => '', 'color' => '', 'serial' => 'X']);
ok('neznámý model se nevyplní', $f2['model'] === '' && $f2['model_known'] === false);
ok('typ se pozná i bez modelu (z DeviceClass)', $f2['device_type'] === 'iPhone', $f2['device_type']);
$f3 = afxDeviceBridgeToForm(['device_class' => 'iPad', 'model' => 'iPad Pro 11-inch (3rd generation)',
    'capacity' => '256 GB', 'color' => 'Fialová mlha', 'serial' => 'Y']);
ok('iPad se pozná jako iPad', $f3['device_type'] === 'iPad', $f3['device_type']);
ok('model mimo katalog je označený', $f3['model_known'] === false);
ok('barva mimo katalog je označená', $f3['color_known'] === false);
ok('prázdný vstup nespadne', is_array(afxDeviceBridgeToForm([])));

// ── 2b) údaje pro VÝKUPNÍ LIST ──
head('Údaje z telefonu → výkupní list');
$doc = afxDeviceBridgeToDocFields($iphone12);
ok('popis věci se složí z modelu, kapacity a barvy — s pomlčkami (v3.66.0)',
    $doc['item_description'] === 'iPhone 12 – 64 GB – White', $doc['item_description']);
ok('model zvlášť', $doc['item_model'] === 'iPhone 12', $doc['item_model']);
ok('do pole SN/IMEI jde IMEI (ne sériové číslo)', $doc['item_serial'] === '353036118781852', $doc['item_serial']);
ok('stav nese kondici baterie i cykly',
    str_contains($doc['item_state'], '87 %') && str_contains($doc['item_state'], '412 cyklů'), $doc['item_state']);
ok('stav nese verzi systému', str_contains($doc['item_state'], 'iOS 17.6.1'), $doc['item_state']);
ok('u aktivovaného kusu se stav aktivace nepřipomíná',
    !str_contains($doc['item_state'], 'aktivace'), $doc['item_state']);
ok('cena se z telefonu NIKDY nebere', !isset($doc['item_price']) && !isset($doc['item_estimate']));

$neaktiv = afxDeviceBridgeToDocFields(['model' => 'iPhone 12', 'imei' => '1', 'activation' => 'Unactivated']);
ok('neaktivovaný kus se ve stavu zvýrazní',
    str_contains($neaktiv['item_state'], 'Unactivated'), $neaktiv['item_state']);
$bezImei = afxDeviceBridgeToDocFields(['model' => 'iPad Air', 'serial' => 'DMPX123', 'capacity' => '64 GB']);
ok('bez IMEI (iPad Wi-Fi) se použije sériové číslo', $bezImei['item_serial'] === 'DMPX123', $bezImei['item_serial']);
ok('prázdný vstup nespadne', is_array(afxDeviceBridgeToDocFields([])));

// ── 2c) překlad identifikátoru a pomlčky (v3.66.0) ──
head('Označení modelu a pomlčky');
ok('iPhone18,5 → iPhone 17e', afxAppleModelName('iPhone18,5') === 'iPhone 17e', afxAppleModelName('iPhone18,5'));
ok('iPhone13,2 → iPhone 12', afxAppleModelName('iPhone13,2') === 'iPhone 12');
ok('iPhone17,5 → iPhone 16e', afxAppleModelName('iPhone17,5') === 'iPhone 16e');
ok('neznámý identifikátor se nepřekládá', afxAppleModelName('iPhone99,9') === '');
ok('identifikátor se pozná', afxIsDeviceIdentifier('iPhone18,5') && afxIsDeviceIdentifier('iPad13,4'));
ok('obchodní název se za identifikátor nepovažuje', !afxIsDeviceIdentifier('iPhone 17e'));

ok('server přebije název z můstku', afxDeviceDisplayModel(['model' => 'iPhone18,5', 'product_type' => 'iPhone18,5']) === 'iPhone 17e');
ok('obchodní název z můstku projde', afxDeviceDisplayModel(['model' => 'iPhone 12', 'product_type' => 'iPhone13,2']) === 'iPhone 12');
ok('neznámý identifikátor NEskončí v poli', afxDeviceDisplayModel(['model' => 'iPhone99,9', 'product_type' => 'iPhone99,9']) === '');

ok('pomlčky mezi modelem, kapacitou a barvou',
    afxDeviceDescription('iPhone 17e', '256 GB', 'Black') === 'iPhone 17e – 256 GB – Black',
    afxDeviceDescription('iPhone 17e', '256 GB', 'Black'));
ok('chybějící barva neudělá dvojitou pomlčku',
    afxDeviceDescription('iPhone 12', '64 GB', '') === 'iPhone 12 – 64 GB',
    afxDeviceDescription('iPhone 12', '64 GB', ''));
ok('samotný model zůstane bez pomlčky', afxDeviceDescription('iPad Air', '', '') === 'iPad Air');

$novy = afxDeviceBridgeToDocFields(['product_type' => 'iPhone18,5', 'model' => 'iPhone18,5',
    'capacity' => '256 GB', 'color' => 'Black', 'imei' => '353036118781852']);
ok('výkupní list: identifikátor se přepsal a pomlčky sedí',
    $novy['item_description'] === 'iPhone 17e – 256 GB – Black', $novy['item_description']);
ok('výkupní list: model je obchodní název', $novy['item_model'] === 'iPhone 17e', $novy['item_model']);
$nezn = afxDeviceBridgeToDocFields(['product_type' => 'iPhone99,9', 'model' => 'iPhone99,9', 'imei' => '1']);
ok('neznámý model se v dokladu označí', $nezn['model_unknown'] === true && $nezn['item_model'] === '');

// ── 3) uložení hlášení a jeho čtení ──
head('Hlášení od můstku');
afxEnsureDeviceBridgeTable();
ok('tabulka stanic existuje', (bool)$pdo->query("SHOW TABLES LIKE 'device_bridge_stations'")->fetch());

$station = 'TEST Mac ' . bin2hex(random_bytes(3));
$pdo->beginTransaction();
try {
    ok('hlášení se uloží', afxDeviceBridgeStore($station, $iphone12, '192.168.1.50'));
    $latest = afxDeviceBridgeLatest();
    ok('formulář si zařízení vyzvedne', $latest !== null && ($latest['device']['serial'] ?? '') === 'FFXGT3HX0F0Q',
        $latest ? (string)($latest['station'] ?? '') : 'nic');

    // odpojený telefon nesmí zůstat viset ve formuláři
    afxDeviceBridgeStore($station, null, '192.168.1.50');
    $after = afxDeviceBridgeLatest();
    $stillMine = $after !== null && ($after['station'] ?? '') === $station;
    ok('po odpojení se zařízení už nenabízí', !$stillMine, $stillMine ? 'pořád se nabízí' : '');

    // staré hlášení (vypnutý Mac) se taky nesmí použít
    afxDeviceBridgeStore($station, $iphone12, '192.168.1.50');
    $pdo->prepare("UPDATE device_bridge_stations SET updated_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE station = ?")
        ->execute([$station]);
    $stale = afxDeviceBridgeLatest();
    $staleMine = $stale !== null && ($stale['station'] ?? '') === $station;
    ok('starší hlášení než 90 s se ignoruje', !$staleMine);

    $list = afxDeviceBridgeStations();
    $found = false;
    foreach ($list as $row) { if ($row['station'] === $station) { $found = true; ok('v Nastavení je stanice vidět i offline', $row['online'] === false); } }
    ok('stanice je v přehledu', $found);
} finally {
    $pdo->rollBack();
}
ok('testovací stanice v databázi nezůstala',
    (int)$pdo->query("SELECT COUNT(*) FROM device_bridge_stations WHERE station LIKE 'TEST Mac %'")->fetchColumn() === 0);

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
