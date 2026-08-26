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
