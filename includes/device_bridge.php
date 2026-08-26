<?php
/**
 * MŮSTEK PRO ČTENÍ ZAŘÍZENÍ (v3.62.0)
 *
 * Na Macu u pultu běží malá služba (device-bridge/), která přes USB přečte
 * připojený iPhone/iPad a POŠLE údaje sem. Naskladňovací formulář si je pak
 * jen vyzvedne — nic se neplatí a navíc se dozvíme kondici baterie, kterou
 * žádná IMEI služba neřekne.
 *
 * Proč tudy a ne z prohlížeče přímo na 127.0.0.1: stránka jede přes HTTPS
 * a Safari i appka „Designed for iPad" volání na localhost blokují (stejná
 * zkušenost jako u tisku štítků).
 */

/** Stanice a jejich poslední hlášení. */
function afxEnsureDeviceBridgeTable(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    if ($pdo->inTransaction()) { return; }   // DDL v transakci = implicitní commit
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_bridge_stations (
            station VARCHAR(120) NOT NULL,
            device_json MEDIUMTEXT NULL,
            has_device TINYINT(1) NOT NULL DEFAULT 0,
            remote_ip VARCHAR(45) NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (station)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) { error_log('afxEnsureDeviceBridgeTable: ' . $e->getMessage()); }
}

/**
 * Token, kterým se můstek hlásí. Vyrobí se sám při prvním zobrazení návodu,
 * takže si instalaci může spustit kdokoli ze zaměstnanců bez čekání na admina.
 */
function afxDeviceBridgeToken(bool $regenerate = false): string {
    global $pdo;
    static $memo = null;
    if (!$regenerate && $memo !== null) { return $memo; }
    // POZOR: get_setting() si výsledek cachuje V RÁMCI POŽADAVKU včetně toho,
    // že klíč neexistuje — po zápisu by tedy dál vracel prázdno a token by se
    // generoval při každém volání znovu. To by po každém otevření Nastavení
    // odstřihlo všechny už nainstalované můstky. Proto čteme přímo z databáze.
    $token = '';
    try {
        $st = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'device_bridge_token'");
        $st->execute();
        $token = trim((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) { error_log('afxDeviceBridgeToken: ' . $e->getMessage()); }

    if ($regenerate || strlen($token) < 20) {
        $token = bin2hex(random_bytes(16));
        set_setting('device_bridge_token', $token);
    }
    $memo = $token;
    return $memo;
}

/** Příkaz k instalaci — přesně to, co se v Nastavení nabídne ke zkopírování. */
function afxDeviceBridgeInstallCommand(?string $token = null): string {
    $token = $token ?? afxDeviceBridgeToken();
    $base = afxDeviceBridgeServerUrl();
    return 'curl -fsSL ' . $base . '/device-bridge/install.sh | bash -s -- ' . $token;
}

/** Adresa CRM, na kterou se má můstek hlásit (bez lomítka na konci). */
function afxDeviceBridgeServerUrl(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'admin.applefix.cloud');
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $host) ?: 'admin.applefix.cloud';
    return 'https://' . $host;
}

/** Zápis hlášení od můstku. Vrací true, když se uložilo. */
function afxDeviceBridgeStore(string $station, ?array $device, string $ip): bool {
    global $pdo;
    afxEnsureDeviceBridgeTable();
    $station = trim($station) !== '' ? mb_substr(trim($station), 0, 120) : 'neznámá stanice';
    $has = $device && !empty($device['serial'] ?? '') ? 1 : 0;
    try {
        $st = $pdo->prepare("INSERT INTO device_bridge_stations (station, device_json, has_device, remote_ip)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE device_json = VALUES(device_json), has_device = VALUES(has_device),
                remote_ip = VALUES(remote_ip), updated_at = NOW()");
        $st->execute([$station, $has ? json_encode($device, JSON_UNESCAPED_UNICODE) : null,
            $has, mb_substr($ip, 0, 45)]);
        return true;
    } catch (Throwable $e) {
        error_log('afxDeviceBridgeStore: ' . $e->getMessage());
        return false;
    }
}

/**
 * Naposledy hlášené zařízení. Bere se nejčerstvější stanice, která něco má
 * a hlásila se v posledních $maxAge sekundách — po odpojení telefonu (nebo
 * vypnutí Macu) se tedy do formuláře nic starého nevloží.
 */
function afxDeviceBridgeLatest(int $maxAge = 90): ?array {
    global $pdo;
    afxEnsureDeviceBridgeTable();
    try {
        $st = $pdo->prepare("SELECT station, device_json, updated_at,
                TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age
            FROM device_bridge_stations
            WHERE has_device = 1 AND updated_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ORDER BY updated_at DESC LIMIT 1");
        $st->execute([$maxAge]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        $device = json_decode((string)$row['device_json'], true);
        if (!is_array($device)) { return null; }
        return ['station' => (string)$row['station'], 'age' => (int)$row['age'],
                'updated_at' => (string)$row['updated_at'], 'device' => $device];
    } catch (Throwable $e) {
        error_log('afxDeviceBridgeLatest: ' . $e->getMessage());
        return null;
    }
}

/** Přehled stanic pro Nastavení. */
function afxDeviceBridgeStations(): array {
    global $pdo;
    afxEnsureDeviceBridgeTable();
    try {
        $q = $pdo->query("SELECT station, has_device, device_json, remote_ip, updated_at,
                TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age
            FROM device_bridge_stations ORDER BY updated_at DESC LIMIT 20");
        $out = [];
        foreach ($q as $r) {
            $dev = $r['device_json'] ? json_decode((string)$r['device_json'], true) : null;
            $out[] = [
                'station' => (string)$r['station'],
                'online' => (int)$r['age'] < 90,
                'age' => (int)$r['age'],
                'updated_at' => (string)$r['updated_at'],
                'remote_ip' => (string)$r['remote_ip'],
                'device' => is_array($dev) ? $dev : null,
            ];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Údaje z telefonu → políčka naskladňovacího formuláře.
 * Model/kapacita/barva se prověří proti katalogu úplně stejně jako u doplnění
 * z IMEI, takže se nikdy nevyplní hodnota, kterou katalog nezná.
 */
function afxDeviceBridgeToForm(array $d): array {
    require_once __DIR__ . '/imei_info.php';
    $model = trim((string)($d['model'] ?? ''));
    $type = $model !== '' ? afxImeiDeviceType($model) : '';
    if ($type === '') {
        $cls = strtolower(trim((string)($d['device_class'] ?? '')));
        $type = $cls === 'ipad' ? 'iPad' : ($cls === 'iphone' ? 'iPhone' : '');
    }
    $models = [];
    foreach (afxProductTypes() as $t) {
        if (($t['id'] ?? '') === $type && ($t['manuf'] ?? '') === 'Apple') { $models = $t['models'] ?? []; break; }
    }
    $capacity = trim((string)($d['capacity'] ?? ''));
    $color = trim((string)($d['color'] ?? ''));
    $health = $d['battery_health'] ?? null;

    return [
        'manufacturer'   => 'Apple',
        'device_type'    => $type,
        'model'          => $model,
        'model_known'    => $models ? afxImeiCatalogValue($models, $model) !== '' : false,
        'capacity'       => $capacity,
        'capacity_known' => $capacity !== '' && afxImeiCatalogValue(AFX_CAPS, $capacity) !== '',
        'color'          => $color,
        'color_known'    => $color !== '' && afxImeiColorFromText($color)['known'],
        'serial'         => trim((string)($d['serial'] ?? '')),
        'imei'           => trim((string)($d['imei'] ?? '')),
        'battery'        => is_numeric($health) ? (int)$health : null,
        'battery_cycles' => isset($d['battery_cycles']) && is_numeric($d['battery_cycles']) ? (int)$d['battery_cycles'] : null,
        'ios'            => trim((string)($d['ios'] ?? '')),
        'product_type'   => trim((string)($d['product_type'] ?? '')),
        'model_number'   => trim((string)($d['model_number'] ?? '')),
        'activation'     => trim((string)($d['activation'] ?? '')),
        'device_name'    => trim((string)($d['device_name'] ?? '')),
    ];
}

/**
 * Údaje z připojeného telefonu → pole VÝKUPNÍHO LISTU / zástavního formuláře.
 * Vyplňuje jen popis věci — cenu ani stav dohody nikdy (to je věc jednání).
 * Do „stavu" jde kondice baterie a verze systému: přesně to, co obsluha
 * dosud odhadovala a co se u výkupu nejvíc hádá.
 */
function afxDeviceBridgeToDocFields(array $d): array {
    $model = trim((string)($d['model'] ?? '')) ?: trim((string)($d['product_type'] ?? ''));
    $cap = trim((string)($d['capacity'] ?? ''));
    $color = trim((string)($d['color'] ?? ''));
    $desc = trim(implode(' ', array_filter([$model, $cap, $color], static fn($x) => $x !== '')));

    $stav = [];
    $h = $d['battery_health'] ?? null;
    if (is_numeric($h)) {
        $cyc = isset($d['battery_cycles']) && is_numeric($d['battery_cycles']) ? (int)$d['battery_cycles'] : null;
        $stav[] = 'Kondice baterie ' . (int)$h . ' %' . ($cyc !== null ? ' (' . $cyc . ' cyklů)' : '');
    }
    if (!empty($d['ios'])) { $stav[] = 'iOS ' . trim((string)$d['ios']); }
    $act = trim((string)($d['activation'] ?? ''));
    if ($act !== '' && $act !== 'Activated') { $stav[] = 'Stav aktivace: ' . $act; }

    return [
        'item_description' => $desc,
        'item_model'       => $model,
        'item_serial'      => trim((string)($d['imei'] ?? '')) ?: trim((string)($d['serial'] ?? '')),
        'item_state'       => implode(' · ', $stav),
        // jen pro hlášku v UI, do dokladu se nepíše
        'serial_number'    => trim((string)($d['serial'] ?? '')),
        'imei'             => trim((string)($d['imei'] ?? '')),
    ];
}
