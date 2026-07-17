<?php
/**
 * Katalog naskladňování produktů — JEDINÝ zdroj pravdy pro výčty a skládání
 * názvu/popisu. Výčty i algoritmy jsou 1:1 port z naskladňovací Mac appky
 * (macapp/app.py) — TITLE se musí generovat IDENTICKY, jinak by další import
 * z appky produkt „přejmenoval" (Upgates páruje kódem, název přepisuje).
 * Používá: products.php (JS dostane json_encode), api/product_create.php
 * (server znovu počítá a validuje), api/export_products_csv.php.
 */

const AFX_APPLE_COLORS = ['Black', 'White', 'Space Black', 'Space Gray', 'Silver', 'Gold', 'Graphite', 'Sierra Blue', 'Pacific Blue', 'Blue', 'Green', 'Alpine Green', 'Pink', 'Purple', 'Deep Purple', 'Red', 'Midnight', 'Starlight', 'Yellow', 'Coral', 'Natural Titanium', 'Blue Titanium', 'White Titanium', 'Black Titanium', 'Desert Titanium', 'Ultramarine', 'Teal', 'Cosmic Orange', 'Mist Blue'];
const AFX_ANDROID_COLORS = ['Black', 'White', 'Blue', 'Green', 'Grey', 'Silver', 'Gold', 'Purple', 'Red'];
const AFX_CAPS = ['16 GB', '32 GB', '64 GB', '128 GB', '256 GB', '512 GB', '1 TB', '2 TB'];
const AFX_RAMS = ['8 GB', '16 GB', '18 GB', '24 GB', '32 GB', '36 GB', '48 GB', '64 GB', '96 GB', '128 GB'];
const AFX_CPU_CORES = ['8', '10', '11', '12', '14', '16', '20', '24', '28', '32'];
const AFX_GPU_CORES = ['8', '10', '14', '16', '18', '19', '20', '30', '32', '38', '40', '60', '76', '80'];
const AFX_GRADE_LABELS = ['Nový', 'Zánovní', 'A – jako nové', 'B – mírné stopy', 'C – viditelné opotřebení', 'D – silné opotřebení'];
const AFX_PRODEJNY = [['Karlín', 'karlin'], ['Černá Růže', 'vaclavak']];

const AFX_IPHONES = ['iPhone 6s', 'iPhone 7', 'iPhone 7 Plus', 'iPhone 8', 'iPhone 8 Plus', 'iPhone X', 'iPhone XR', 'iPhone XS', 'iPhone XS Max', 'iPhone 11', 'iPhone 11 Pro', 'iPhone 11 Pro Max', 'iPhone 12 mini', 'iPhone 12', 'iPhone 12 Pro', 'iPhone 12 Pro Max', 'iPhone 13 mini', 'iPhone 13', 'iPhone 13 Pro', 'iPhone 13 Pro Max', 'iPhone 14', 'iPhone 14 Plus', 'iPhone 14 Pro', 'iPhone 14 Pro Max', 'iPhone 15', 'iPhone 15 Plus', 'iPhone 15 Pro', 'iPhone 15 Pro Max', 'iPhone 16', 'iPhone 16 Plus', 'iPhone 16 Pro', 'iPhone 16 Pro Max', 'iPhone 17', 'iPhone 17 Pro', 'iPhone 17 Pro Max', 'iPhone SE 2020', 'iPhone SE 2022'];
const AFX_IPADS = ['iPad 9', 'iPad 10', 'iPad 11', 'iPad mini 6', 'iPad mini 7', 'iPad Air 4', 'iPad Air 5', 'iPad Air 11″', 'iPad Air 13″', 'iPad Pro 11″', 'iPad Pro 12,9″', 'iPad Pro 13″'];
const AFX_MACBOOKS_AIR = [
    'MacBook Air 13″ (2017) Intel', 'MacBook Air 13″ (2018) Intel', 'MacBook Air 13″ (2019) Intel', 'MacBook Air 13″ (2020) Intel',
    'MacBook Air 13″ M1 (2020)', 'MacBook Air 13″ M2 (2022)', 'MacBook Air 15″ M2 (2023)',
    'MacBook Air 13″ M3 (2024)', 'MacBook Air 15″ M3 (2024)', 'MacBook Air 13″ M4 (2025)', 'MacBook Air 15″ M4 (2025)',
];
const AFX_MACBOOKS_PRO = [
    'MacBook Pro 13″ (2015) Intel', 'MacBook Pro 13″ (2016) Intel', 'MacBook Pro 13″ (2017) Intel', 'MacBook Pro 13″ (2018) Intel', 'MacBook Pro 13″ (2019) Intel', 'MacBook Pro 13″ (2020) Intel',
    'MacBook Pro 15″ (2015) Intel', 'MacBook Pro 15″ (2016) Intel', 'MacBook Pro 15″ (2017) Intel', 'MacBook Pro 15″ (2018) Intel', 'MacBook Pro 15″ (2019) Intel', 'MacBook Pro 16″ (2019) Intel',
    'MacBook Pro 13″ M1 (2020)', 'MacBook Pro 13″ M2 (2022)',
    'MacBook Pro 14″ M1 Pro (2021)', 'MacBook Pro 14″ M1 Max (2021)', 'MacBook Pro 16″ M1 Pro (2021)', 'MacBook Pro 16″ M1 Max (2021)',
    'MacBook Pro 14″ M2 Pro (2023)', 'MacBook Pro 14″ M2 Max (2023)', 'MacBook Pro 16″ M2 Pro (2023)', 'MacBook Pro 16″ M2 Max (2023)',
    'MacBook Pro 14″ M3 (2023)', 'MacBook Pro 14″ M3 Pro (2023)', 'MacBook Pro 14″ M3 Max (2023)', 'MacBook Pro 16″ M3 Pro (2023)', 'MacBook Pro 16″ M3 Max (2023)',
    'MacBook Pro 14″ M4 (2024)', 'MacBook Pro 14″ M4 Pro (2024)', 'MacBook Pro 14″ M4 Max (2024)', 'MacBook Pro 16″ M4 Pro (2024)', 'MacBook Pro 16″ M4 Max (2024)',
    'MacBook Pro 14″ M5 (2025)',
];

/** Typy zařízení: id → [výrobce, K-kód kategorie, cap?, ram?, gen?, barvy, modely] */
function afxProductTypes(): array {
    static $types = null;
    if ($types === null) {
        $types = [
            ['id' => 'iPhone', 'manuf' => 'Apple', 'k' => 'K00039', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_APPLE_COLORS, 'models' => AFX_IPHONES],
            ['id' => 'iPad', 'manuf' => 'Apple', 'k' => 'K00041', 'cap' => true, 'ram' => false, 'gen' => true, 'colors' => AFX_APPLE_COLORS, 'models' => AFX_IPADS],
            ['id' => 'MacBook Pro', 'manuf' => 'Apple', 'k' => 'K00143', 'cap' => true, 'ram' => true, 'gen' => false, 'colors' => ['Space Gray', 'Silver', 'Space Black'], 'models' => AFX_MACBOOKS_PRO],
            ['id' => 'MacBook Air', 'manuf' => 'Apple', 'k' => 'K00144', 'cap' => true, 'ram' => true, 'gen' => false, 'colors' => ['Space Gray', 'Silver', 'Starlight', 'Midnight', 'Sky Blue'], 'models' => AFX_MACBOOKS_AIR],
            ['id' => 'Samsung', 'manuf' => 'Samsung', 'k' => 'K00135', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Xiaomi', 'manuf' => 'Xiaomi', 'k' => 'K00136', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Asus', 'manuf' => 'Asus', 'k' => 'K00137', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Doogee', 'manuf' => 'Doogee', 'k' => 'K00138', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Honor', 'manuf' => 'Honor', 'k' => 'K00139', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Huawei', 'manuf' => 'Huawei', 'k' => 'K00140', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Motorola', 'manuf' => 'Motorola', 'k' => 'K00141', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
            ['id' => 'Nokia', 'manuf' => 'Nokia', 'k' => 'K00142', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => AFX_ANDROID_COLORS, 'models' => []],
        ];
    }
    return $types;
}

function afxProductTypeById(string $id): array {
    foreach (afxProductTypes() as $t) {
        if ($t['id'] === $id) return $t;
    }
    // vlastní (neznámý) typ — jako GENERIC_TYPE v appce
    return ['id' => $id, 'manuf' => '', 'k' => '', 'cap' => true, 'ram' => false, 'gen' => false, 'colors' => [], 'models' => []];
}

/**
 * Složení kompletního „řádku" produktu z polí formuláře — port form_row()+build_title().
 * $in: typ, model, cap, color, grade (celý label nebo token), battery, price, serial,
 *      ram, cpu, gpu, rocnik, generace, sold(bool), stock_key, image_url,
 *      pcr_status, pcr_text, code (hotový product_code), added (Y-m-d H:i)
 * Vrací: ['title', 'short_desc', 'assoc' => 27 klíčů raw_csv, 'manuf', 'k', 'grade_token', 'display_model']
 */
function afxProductAssemble(array $in): array {
    $t = afxProductTypeById(trim((string)($in['typ'] ?? '')));
    $model = trim((string)($in['model'] ?? ''));
    $cap = $t['cap'] ? trim((string)($in['cap'] ?? '')) : '';
    $color = trim((string)($in['color'] ?? ''));
    $ram = $t['ram'] ? trim((string)($in['ram'] ?? '')) : '';
    $cpu = $t['ram'] ? trim((string)($in['cpu'] ?? '')) : '';
    $gpu = $t['ram'] ? trim((string)($in['gpu'] ?? '')) : '';
    $bat = trim((string)($in['battery'] ?? ''));
    $bat = rtrim(str_replace('%', '', $bat));   // ukládá se bez %, do CSV s " %"
    $bat = trim($bat);
    $rocnik = trim((string)($in['rocnik'] ?? ''));
    $generace = $t['gen'] ? trim((string)($in['generace'] ?? '')) : '';
    $sold = !empty($in['sold']);
    $priceStr = trim((string)($in['price'] ?? ''));

    // grade: „A – jako nové" → „A"; prázdné → „A" (přesně grade_val() z appky)
    $gradeRaw = trim((string)($in['grade'] ?? ''));
    $gradeToken = $gradeRaw !== '' ? trim(explode(' ', $gradeRaw)[0]) : 'A';

    // display_model(): model doplněný o typ, pokud ho už neobsahuje
    $typeId = trim($t['id']);
    $displayModel = $model;
    if ($model !== '' && $typeId !== '' && !str_starts_with(mb_strtolower($model), mb_strtolower($typeId))) {
        $displayModel = $typeId . ' ' . $model;
    }

    // build_title()
    if ($ram !== '' && $cap !== '') { $mem = $ram . '/' . $cap . ' SSD'; }
    elseif ($ram !== '') { $mem = $ram . ' RAM'; }
    else { $mem = $cap; }
    $coresParts = [];
    if ($cpu !== '') $coresParts[] = $cpu . ' CPU';
    if ($gpu !== '') $coresParts[] = $gpu . ' GPU';
    $cores = implode(' ', $coresParts);
    if ($cores !== '' && $mem !== '') { $spec = $cores . ', ' . $mem; }
    else { $spec = $cores !== '' ? $cores : $mem; }
    $titleParts = array_filter([$displayModel, $spec, $color, $gradeToken], static fn($p) => $p !== '');
    $title = trim(implode(' ', $titleParts));

    // SHORT_DESCRIPTION — pořadí přesně dle form_row()
    $sd = [];
    if ($gradeToken !== '') $sd[] = 'Stav: ' . $gradeToken;
    if ($bat !== '') $sd[] = 'Kondice baterie: ' . $bat . ' %';
    if ($cpu !== '') $sd[] = 'Jader CPU: ' . $cpu;
    if ($gpu !== '') $sd[] = 'Jader GPU: ' . $gpu;
    if ($ram !== '') $sd[] = 'RAM: ' . $ram;
    if ($cap !== '') $sd[] = 'Úložiště: ' . $cap;
    if ($color !== '') $sd[] = 'Barva: ' . $color;
    if ($rocnik !== '') $sd[] = 'Ročník: ' . $rocnik;
    if ($generace !== '') $sd[] = 'Generace: ' . $generace;
    $sd[] = 'Zvláštní režim DPH §90 (použité zboží)';
    $shortDesc = implode(' | ', $sd);

    $stockVal = $sold ? '0' : '1';
    $stockKey = (string)($in['stock_key'] ?? '');
    $code = (string)($in['code'] ?? '');
    $added = (string)($in['added'] ?? date('Y-m-d H:i'));
    $pcrStatus = (string)($in['pcr_status'] ?? '');
    $pcrText = (string)($in['pcr_text'] ?? '');

    $assoc = [
        '[PRODUCT_CODE]' => $code,
        '[ACTIVE_YN]' => '1',
        '[TITLE]' => $title,
        '[MANUFACTURER]' => $t['manuf'],
        '[CATEGORIES]' => $t['k'],
        '[AVAILABILITY]' => $sold ? 'Vyprodáno' : 'Skladem',
        '[STOCK]' => $stockVal,
        '[PRICE_ORIGINAL "Výchozí"]' => $priceStr,
        '[IS_PRICES_WITH_VAT_YN]' => '1',
        '[VAT]' => '0',
        '[SHORT_DESCRIPTION]' => $shortDesc,
        '[PARAMETER "Model"]' => $displayModel,
        '[PARAMETER "Kapacita"]' => $cap,
        '[PARAMETER "Barva"]' => $color,
        '[PARAMETER "Stav"]' => $gradeToken,
        '[PARAMETER "Baterie"]' => $bat !== '' ? $bat . ' %' : '',
        '[STOCK_STOCK "karlin"]' => $stockKey === 'karlin' ? $stockVal : '',
        '[STOCK_STOCK "vaclavak"]' => $stockKey === 'vaclavak' ? $stockVal : '',
        '[PARAMETER "RAM"]' => $ram,
        'PRIDANO' => $added,
        'CPU_JADRA' => $cpu,
        'GPU_JADRA' => $gpu,
        '[PARAMETER "Ročník"]' => $rocnik,
        '[PARAMETER "Generace"]' => $generace,
        'PCR_DATUM' => ($pcrStatus !== '' && $pcrStatus !== 'notimei') ? $added : '',
        'PCR_VYSLEDEK' => $pcrText,
        '[IMAGES]' => (string)($in['image_url'] ?? ''),
    ];

    return [
        'title' => $title,
        'short_desc' => $shortDesc,
        'assoc' => $assoc,
        'manuf' => $t['manuf'],
        'k' => $t['k'],
        'grade_token' => $gradeToken,
        'display_model' => $displayModel,
        'battery_csv' => $bat !== '' ? $bat . ' %' : '',
    ];
}

/** Kanonická 27sloupcová hlavička souboru appky — pro export CSV. */
function afxProductCsvHeader(): array {
    return ['[PRODUCT_CODE]', '[ACTIVE_YN]', '[TITLE]', '[MANUFACTURER]', '[CATEGORIES]', '[AVAILABILITY]',
        '[STOCK]', '[PRICE_ORIGINAL "Výchozí"]', '[IS_PRICES_WITH_VAT_YN]', '[VAT]', '[SHORT_DESCRIPTION]',
        '[PARAMETER "Model"]', '[PARAMETER "Kapacita"]', '[PARAMETER "Barva"]', '[PARAMETER "Stav"]',
        '[PARAMETER "Baterie"]', '[STOCK_STOCK "karlin"]', '[STOCK_STOCK "vaclavak"]', '[PARAMETER "RAM"]',
        'PRIDANO', 'CPU_JADRA', 'GPU_JADRA', '[PARAMETER "Ročník"]', '[PARAMETER "Generace"]',
        'PCR_DATUM', 'PCR_VYSLEDEK', '[IMAGES]'];
}
