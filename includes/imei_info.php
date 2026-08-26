<?php
/**
 * DOPLNĚNÍ ÚDAJŮ Z IMEI (v3.61.0)
 *
 * Při naskladnění stačí naskenovat IMEI a formulář si sám doplní výrobce, typ,
 * model, úložiště a barvu. Data dává iFreeiCloud (stejný účet, který se už
 * používá pro ověření IMEI na Nástěnce) — vrací mimo jiné řetězec
 *   „iPhone 12 64GB White [A2403] [iPhone13,2]"
 * a k tomu Find My, SIM-lock a záruku, což je při výkupu použitého kusu
 * důležitější než cokoli jiného.
 *
 * ⚠️ Dotaz je PLACENÝ (kredit za kus), proto se každé IMEI ptáme JEN JEDNOU
 * a odpověď se ukládá do `imei_lookups`. Opakované otevření formuláře,
 * překlep v jiném poli ani znovunaskladnění téhož kusu už kredit nestojí.
 *
 * Podporovaná jsou jen zařízení Apple. U ostatních značek API odmítne dotaz
 * hláškou „Only Apple devices supported. This device is a HONOR." — i z toho
 * se dá vytěžit alespoň výrobce, takže se to taky uloží a použije.
 */

require_once __DIR__ . '/product_catalog.php';

/** Tabulka s odpověďmi (kredit se platí jen za první dotaz na dané IMEI). */
function afxEnsureImeiLookupTable(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    if ($pdo->inTransaction()) { return; }   // DDL v transakci = implicitní commit
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS imei_lookups (
            imei VARCHAR(20) NOT NULL,
            ok TINYINT(1) NOT NULL DEFAULT 0,
            state VARCHAR(12) NOT NULL DEFAULT 'ok',
            brand VARCHAR(60) NOT NULL DEFAULT '',
            note VARCHAR(255) NOT NULL DEFAULT '',
            payload MEDIUMTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (imei)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // starší instalace (tabulka bez state/note)
        try { $pdo->exec("ALTER TABLE imei_lookups ADD COLUMN state VARCHAR(12) NOT NULL DEFAULT 'ok'"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE imei_lookups ADD COLUMN note VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Throwable $e) {}
        $done = true;
    } catch (Throwable $e) { error_log('afxEnsureImeiLookupTable: ' . $e->getMessage()); }
}

/**
 * Rozebrání popisu zařízení od Apple na jednotlivé údaje.
 * Vstup:  „iPhone 12 64GB White [A2403] [iPhone13,2]"
 * Výstup: model, capacity, color (+ *_known = zná to náš katalog?), model_number, identifier.
 *
 * PASTI, na které přišla prověrka 26. 8. 2026 (a mají vlastní testy):
 *  · „320GB" se NESMÍ oříznout na „32 GB" (ořez nul jen u desetinných čísel),
 *  · u Maců je v popisu PRVNÍ číslo RAM („16GB 1TB Space Gray") → úložiště je
 *    to POSLEDNÍ, jinak by se do skladu uložila RAM jako kapacita,
 *  · barva se nikdy nevyřezává z názvu modelu (z „Jet Black Aluminum"
 *    vznikl model „Apple Watch … Jet Aluminum"),
 *  · dvouslovná barva se nesmí zkrátit na první slovo („Midnight Green"),
 *  · ocásky typu „SPACE GRAY-ITP" se ořežou před porovnáním s katalogem.
 */
function afxImeiParseAppleModel(string $raw, string $modelNameHint = ''): array {
    $out = ['model' => '', 'capacity' => '', 'color' => '', 'model_number' => '', 'identifier' => '',
            'capacity_known' => false, 'color_known' => false];
    $s = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');
    if ($s === '') { return $out; }

    // hranaté závorky: [A2403] = objednací číslo, [iPhone13,2] = identifikátor
    if (preg_match_all('/\[([^\]]+)\]/u', $s, $mm)) {
        foreach ($mm[1] as $tag) {
            $tag = trim($tag);
            if (preg_match('/^[A-Z]{1,2}\d{3,4}$/', $tag)) { $out['model_number'] = $tag; }
            elseif (preg_match('/^[A-Za-z]+\d+,\d+$/', $tag)) { $out['identifier'] = $tag; }
        }
        $s = trim(preg_replace('/\s*\[[^\]]*\]/u', '', $s) ?? $s);
    }

    // všechny kapacity v popisu; úložiště = POSLEDNÍ (u Maců je první RAM)
    $caps = [];
    if (preg_match_all('/(?<![\w.])(\d+(?:[.,]\d+)?)\s*(GB|TB)\b/iu', $s, $cm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($cm as $c) { $caps[] = ['num' => $c[1][0], 'unit' => strtoupper($c[2][0]), 'at' => $c[0][1], 'len' => strlen($c[0][0])]; }
    }
    $model = $s;
    $tail = '';
    if ($caps) {
        $last = $caps[count($caps) - 1];
        $num = str_replace(',', '.', $last['num']);
        // POZOR: nuly ořezávat JEN u desetinných čísel — „320" není „32"
        if (str_contains($num, '.')) { $num = rtrim(rtrim($num, '0'), '.'); }
        $out['capacity'] = $num . ' ' . $last['unit'];
        $out['capacity_known'] = afxImeiCatalogValue(AFX_CAPS, $out['capacity']) !== '';
        $model = trim(substr($s, 0, $caps[0]['at']));                     // model = před PRVNÍ kapacitou
        $tail  = trim(substr($s, $last['at'] + $last['len']));            // barva = za POSLEDNÍ kapacitou
    }

    // barva: nejdřív za kapacitou, jinak na konci názvu (hodinky, AirPods)
    $color = afxImeiColorFromText($tail);
    if ($color['value'] === '' && $tail === '') { $color = afxImeiColorFromText($s, true); }
    // barva z modelu se NEVYŘEZÁVÁ — jen se přečte (viz past s „Jet Black Aluminum")
    $out['color'] = $color['value'];
    $out['color_known'] = $color['known'];

    $hint = trim($modelNameHint);
    if ($hint !== '') { $model = $hint; }
    $out['model'] = trim(preg_replace('/\s{2,}/u', ' ', $model) ?? $model);
    return $out;
}

/**
 * Barva z útržku textu. Vrací ['value' => barva, 'known' => je v katalogu?].
 * Hledá se NEJDELŠÍ shoda (aby „Space Black" nespadlo na „Black") a jen na
 * ZAČÁTKU útržku, případně (u $atEnd) na jeho konci — nikdy uprostřed názvu.
 */
function afxImeiColorFromText(string $text, bool $atEnd = false): array {
    $t = trim($text);
    if ($t === '') { return ['value' => '', 'known' => false]; }
    // „SPACE GRAY-ITP", „Blue/Silver", „Midnight, 45mm" → useknout ocásek
    $clean = trim(preg_replace('/\s*[\-\/,;].*$/u', '', $t) ?? $t);
    $all = array_unique(array_merge(AFX_APPLE_COLORS, AFX_COMPUTER_COLORS, AFX_ANDROID_COLORS, AFX_ACCESSORY_COLORS));
    usort($all, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $low = mb_strtolower($clean, 'UTF-8');
    $lowFull = mb_strtolower($t, 'UTF-8');
    foreach ($all as $c) {
        $cl = mb_strtolower($c, 'UTF-8');
        if ($low === $cl) { return ['value' => $c, 'known' => true]; }
        if ($atEnd && (str_ends_with($lowFull, ' ' . $cl) || $lowFull === $cl)) { return ['value' => $c, 'known' => true]; }
    }
    // dvouslovné barvy typu „Midnight Green" katalog nemusí znát — vrátit CELÝ
    // útržek (ne první slovo!) a označit jako neznámý, ať se nevyplní naslepo
    if ($atEnd) { return ['value' => '', 'known' => false]; }
    // z útržku vzít jen tu část, která vypadá jako barva (max 3 slova, bez čísel)
    $words = preg_split('/\s+/u', $clean) ?: [];
    $words = array_slice(array_filter($words, static fn($w) => $w !== '' && !preg_match('/\d/', $w)), 0, 3);
    $guess = trim(implode(' ', $words));
    return ['value' => $guess, 'known' => false];
}

/** Zpětná kompatibilita se starším voláním (vrací jen text barvy). */
function afxImeiMatchColor(string $text, bool $atEnd = false): string {
    return afxImeiColorFromText($text, $atEnd)['value'];
}

/** Typ zařízení podle názvu modelu — musí odpovídat id v afxProductTypes(). */
function afxImeiDeviceType(string $model): string {
    $m = mb_strtolower($model, 'UTF-8');
    foreach ([
        'iPhone' => ['iphone'],
        'iPad' => ['ipad'],
        'Apple Watch' => ['watch'],
        'AirPods' => ['airpods'],
        'MacBook' => ['macbook'],
        'iMac' => ['imac'],
        'Mac mini' => ['mac mini'],
        'Mac Studio' => ['mac studio'],
        'Mac Pro' => ['mac pro'],
        'Apple TV' => ['apple tv'],
        'HomePod' => ['homepod'],
    ] as $type => $needles) {
        foreach ($needles as $n) { if (str_contains($m, $n)) { return $type; } }
    }
    return '';
}

/** Zná katalog tuhle hodnotu? (case-insensitive) Vrací hodnotu z katalogu, nebo ''. */
function afxImeiCatalogValue(array $list, string $value): string {
    $v = mb_strtolower(trim($value), 'UTF-8');
    if ($v === '') { return ''; }
    foreach ($list as $item) {
        if (mb_strtolower((string)$item, 'UTF-8') === $v) { return (string)$item; }
    }
    return '';
}

/** Odpověď API → pole pro formulář naskladnění. */
function afxImeiInfoFromApiObject(array $obj): array {
    $parsed = afxImeiParseAppleModel((string)($obj['model'] ?? ''), (string)($obj['apple/modelName'] ?? ''));
    $type = afxImeiDeviceType($parsed['model'] !== '' ? $parsed['model'] : (string)($obj['model'] ?? ''));

    // hodnoty prověřit proti katalogu — co v něm není, pošleme jako „vlastní"
    $models = [];
    foreach (afxProductTypes() as $t) {
        if (($t['id'] ?? '') === $type && ($t['manuf'] ?? '') === 'Apple') { $models = $t['models'] ?? []; break; }
    }
    $bool = static fn($v) => is_bool($v) ? $v : null;

    return [
        'manufacturer'  => 'Apple',
        'device_type'   => $type,
        'model'         => $parsed['model'],
        'model_known'   => $models ? afxImeiCatalogValue($models, $parsed['model']) !== '' : false,
        'capacity'      => afxImeiCatalogValue(AFX_CAPS, $parsed['capacity']) ?: $parsed['capacity'],
        'capacity_known' => (bool)$parsed['capacity_known'],
        'color'         => $parsed['color'],
        'color_known'   => (bool)$parsed['color_known'],
        'model_number'  => $parsed['model_number'],
        'identifier'    => $parsed['identifier'],
        'serial'        => trim((string)($obj['serial'] ?? '')),
        'imei2'         => trim((string)($obj['imei2'] ?? '')),
        'thumbnail'     => trim((string)($obj['thumbnail'] ?? '')),
        'find_my'       => $bool($obj['fmiOn'] ?? null),
        'lost_mode'     => $bool($obj['lostMode'] ?? null),
        'sim_lock'      => $bool($obj['simLock'] ?? null),
        'replaced'      => $bool($obj['replaced'] ?? null),
        'warranty'      => trim((string)($obj['warrantyStatus'] ?? '')),
        'purchase_date' => trim((string)($obj['estPurchaseDate'] ?? '')),
        'raw_model'     => trim((string)($obj['model'] ?? '')),
    ];
}

/** Výrobce z odmítavé hlášky: „Only Apple devices supported. This device is a HONOR." */
function afxImeiBrandFromError(string $error): string {
    if (preg_match('/this device is (?:a|an)\s+([A-Za-z0-9.\-]+)/i', $error, $m)) {
        $brand = trim($m[1], " .\t\n");   // JEN první slovo — „XIAOMI REDMI NOTE 12" je pořád Xiaomi
        // katalog má značky s velkým prvním písmenem („Honor", „Samsung")
        foreach (afxProductTypes() as $t) {
            $mf = (string)($t['manuf'] ?? '');
            if ($mf !== '' && mb_strtolower($mf, 'UTF-8') === mb_strtolower($brand, 'UTF-8')) { return $mf; }
        }
        return mb_convert_case(mb_strtolower($brand, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
    return '';
}

/**
 * Zjištění údajů k IMEI. Nejdřív se kouká do cache (kredit se platí jednou),
 * pak teprve na API. Vrací ['ok', 'source' => cache|api, 'info' => [...],
 * 'brand', 'error'].
 */
function afxImeiInfoLookup(string $rawImei, bool $useCache = true): array {
    global $pdo;
    $digits = preg_replace('/\D+/', '', $rawImei) ?? '';
    // POZOR na kredit: pole musí obsahovat PŘESNĚ 14 nebo 15 číslic. Čtečka,
    // která načte i objednací číslo („A2403 3569…"), by jinak vyrobila
    // nesmyslné IMEI a zaplacený dotaz na neexistující zařízení.
    if (!in_array(strlen($digits), [14, 15], true)) {
        return ['ok' => false, 'source' => 'none', 'info' => null, 'brand' => '', 'checked_at' => '',
            'error' => 'IMEI musí mít přesně 14 nebo 15 číslic.'];
    }
    $imei = afxImeiNormalize15($digits);
    if (!afxImeiLuhnValid($imei)) {
        return ['ok' => false, 'source' => 'none', 'info' => null, 'brand' => '', 'checked_at' => '',
            'error' => 'IMEI neprošlo kontrolou číslic (překlep?) — dotaz se neposílal.'];
    }
    afxEnsureImeiLookupTable();

    // ── cache ────────────────────────────────────────────────────────────
    // „není Apple" platí navždy, technický výpadek jen 15 minut (jinak by
    // jeden timeout zablokoval zařízení natrvalo). Když cache NEJDE číst,
    // radši se neptáme vůbec — placené API bez ochrany je horší než chyba.
    $cacheOk = true;
    if ($useCache) {
        try {
            $st = $pdo->prepare("SELECT ok, state, brand, note, payload, created_at,
                    TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min FROM imei_lookups WHERE imei = ? LIMIT 1");
            $st->execute([$imei]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $state = (string)($row['state'] ?? 'ok');
                $fresh = $state !== 'error' || (int)$row['age_min'] < 15;
                if ($fresh) {
                    $info = $row['payload'] ? json_decode((string)$row['payload'], true) : null;
                    return [
                        'ok' => (int)$row['ok'] === 1,
                        'source' => 'cache',
                        'info' => is_array($info) ? $info : null,
                        'brand' => (string)$row['brand'],
                        'checked_at' => (string)$row['created_at'],
                        'error' => (int)$row['ok'] === 1 ? '' : ((string)$row['note'] ?: 'Údaje se nepodařilo zjistit.'),
                    ];
                }
            }
        } catch (Throwable $e) {
            $cacheOk = false;
            error_log('afxImeiInfoLookup cache: ' . $e->getMessage());
        }
    }
    if (!$cacheOk) {
        return ['ok' => false, 'source' => 'none', 'info' => null, 'brand' => '', 'checked_at' => '',
            'error' => 'Paměť dotazů není dostupná — dotaz se neposlal, aby se zbytečně neutrácel kredit.'];
    }

    // klíč i službu brát stejně jako api/check_imei.php (nastavení → konstanta → ENV)
    $key = function_exists('get_setting_with_fallback')
        ? trim((string)get_setting_with_fallback('ifreeicloud_api_key', defined('IFREEICLOUD_API_KEY_FALLBACK') ? IFREEICLOUD_API_KEY_FALLBACK : '', 'IFREEICLOUD_API_KEY'))
        : trim((string)get_setting('ifreeicloud_api_key', ''));
    $svcRaw = function_exists('get_setting_with_fallback')
        ? get_setting_with_fallback('ifreeicloud_service_id', defined('IFREEICLOUD_SERVICE_ID_FALLBACK') ? (string)IFREEICLOUD_SERVICE_ID_FALLBACK : '205', 'IFREEICLOUD_SERVICE_ID')
        : get_setting('ifreeicloud_service_id', '205');
    $svc = (int)$svcRaw ?: 205;   // prázdné nastavení nesmí dát službu 0
    if ($key === '') {
        return ['ok' => false, 'source' => 'none', 'info' => null, 'brand' => '', 'checked_at' => '',
            'error' => 'Není vyplněný klíč iFreeiCloud (Nastavení → Systém → Integrace).'];
    }

    try {
        $ch = curl_init('https://api.ifreeicloud.co.uk');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query(['service' => $svc, 'imei' => $imei, 'key' => $key]),
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        unset($ch);
        if ($body === false) { throw new RuntimeException($err ?: 'požadavek selhal'); }
        $json = json_decode((string)$body, true);
        if (!is_array($json)) { throw new RuntimeException('neočekávaná odpověď API'); }
    } catch (Throwable $e) {
        // technické selhání se ukládá TAKY — kredit už mohl padnout a bez
        // záznamu by se na stejné IMEI klikalo (a platilo) pořád dokola
        afxImeiCacheStore($imei, false, 'error', '', 'Dotaz na iFreeiCloud selhal: ' . $e->getMessage(), null);
        return ['ok' => false, 'source' => 'api', 'info' => null, 'brand' => '', 'checked_at' => '',
            'error' => 'Dotaz na iFreeiCloud selhal: ' . $e->getMessage()];
    }

    if (!empty($json['success']) && !empty($json['object']) && is_array($json['object'])) {
        $info = afxImeiInfoFromApiObject($json['object']);
        afxImeiCacheStore($imei, true, 'ok', 'Apple', '', $info);
        return ['ok' => true, 'source' => 'api', 'info' => $info, 'brand' => 'Apple',
            'checked_at' => date('Y-m-d H:i:s'), 'error' => ''];
    }

    $error = trim((string)($json['error'] ?? $json['message'] ?? 'Údaje se nepodařilo zjistit.'));
    $brand = afxImeiBrandFromError($error);
    if ($brand !== '') {
        // skutečné odmítnutí „není Apple" — tohle se nezmění, platí navždy
        $msg = 'iFreeiCloud umí jen Apple. Podle IMEI jde o značku ' . $brand . ' — doplní se aspoň výrobce.';
        afxImeiCacheStore($imei, false, 'not_apple', $brand, $msg, null);
    } else {
        // došel kredit, špatný klíč, zařízení zatím není v databázi… → zkusit
        // později; NEUKLÁDAT natrvalo, jinak by IMEI zůstalo „mrtvé"
        $msg = $error;
        afxImeiCacheStore($imei, false, 'error', '', $msg, null);
    }
    return ['ok' => false, 'source' => 'api', 'info' => null, 'brand' => $brand,
        'checked_at' => date('Y-m-d H:i:s'), 'error' => $msg];
}

/** Uložení odpovědi do paměti dotazů. */
function afxImeiCacheStore(string $imei, bool $ok, string $state, string $brand, string $note, ?array $info): void {
    global $pdo;
    try {
        $ins = $pdo->prepare("INSERT INTO imei_lookups (imei, ok, state, brand, note, payload)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ok = VALUES(ok), state = VALUES(state), brand = VALUES(brand),
                note = VALUES(note), payload = VALUES(payload), created_at = NOW()");
        $ins->execute([$imei, $ok ? 1 : 0, $state, mb_substr($brand, 0, 60), mb_substr($note, 0, 255),
            $info ? json_encode($info, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Throwable $e) { error_log('afxImeiCacheStore: ' . $e->getMessage()); }
}

/** 14 číslic (bez kontrolní) → plných 15. Jinak se stejný kus platí dvakrát. */
function afxImeiNormalize15(string $digits): string {
    if (strlen($digits) === 15) { return $digits; }
    if (strlen($digits) !== 14) { return $digits; }
    $sum = 0;
    for ($i = 0; $i < 14; $i++) {
        $d = (int)$digits[13 - $i];
        if ($i % 2 === 0) { $d *= 2; if ($d > 9) { $d -= 9; } }
        $sum += $d;
    }
    return $digits . (string)((10 - ($sum % 10)) % 10);
}

/** Kontrolní číslice IMEI (Luhn) — překlep se nemá platit. */
function afxImeiLuhnValid(string $imei): bool {
    if (!preg_match('/^\d{15}$/', $imei)) { return false; }
    $sum = 0;
    for ($i = 0; $i < 15; $i++) {
        $d = (int)$imei[14 - $i];
        if ($i % 2 === 1) { $d *= 2; if ($d > 9) { $d -= 9; } }
        $sum += $d;
    }
    return $sum % 10 === 0;
}
