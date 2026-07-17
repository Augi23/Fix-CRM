<?php
/**
 * Export skladu Produktů ve formátu souboru naskladňovací appky (27 sloupců,
 * středník, UTF-8 s BOM) — ke stažení pro ruční import do Upgates, později
 * jako URL pro pravidelný import (auth tokenem v settingu products_export_token).
 * Základ řádku = raw_csv (kompletní historická data), přes něj se přepíší
 * ŽIVÉ hodnoty ze strukturovaných sloupců — vyhrají úpravy v CRM i prodeje
 * na kase (stock_qty 0 → Vyprodáno se tak poprvé propíše do Upgates).
 * Exportuje se VŠE včetně vyprodaných (Upgates je potřebuje vynulovat).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/product_catalog.php';
ob_clean();

// ── auth: přihlášené vedení NEBO export token (pro budoucí URL import Upgates) ──
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id']))
    && crmCanManageProducts();
if (!$sessionOk) {
    $token = (string)($_GET['token'] ?? '');
    $expected = (string)get_setting('products_export_token', '');
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bez oprávnění.';
        exit;
    }
}

ensureProductsTable();
ensureProductsPosColumn();
ensureProductsCrmColumns();

$header = afxProductCsvHeader();

$rows = $pdo->query("SELECT * FROM products ORDER BY added_at IS NULL, added_at, id")->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="AppleFix-produkty-CRM-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM — appka čte utf-8-sig, Upgates i Excel ho snesou
fputcsv($out, $header, ';', '"', '');

foreach ($rows as $p) {
    $raw = json_decode((string)($p['raw_csv'] ?? ''), true);
    $assoc = is_array($raw) ? $raw : [];

    // živé hodnoty ze sloupců VŽDY vyhrávají nad historickým raw_csv
    $stockQty = (int)$p['stock_qty'];
    $stockVal = $stockQty > 0 ? '1' : '0';
    $priceF = (float)$p['price'];
    $priceStr = $priceF == (int)$priceF ? (string)(int)$priceF : rtrim(rtrim(number_format($priceF, 2, '.', ''), '0'), '.');
    $stockKey = (string)($p['stock_key'] ?? '');
    $bat = trim((string)($p['battery'] ?? ''));

    $live = [
        '[PRODUCT_CODE]' => (string)$p['product_code'],
        '[TITLE]' => (string)$p['title'],
        '[AVAILABILITY]' => $stockQty > 0 ? 'Skladem' : 'Vyprodáno',
        '[STOCK]' => $stockVal,
        '[PRICE_ORIGINAL "Výchozí"]' => $priceStr,
        '[PARAMETER "Model"]' => (string)($p['model'] ?? ''),
        '[PARAMETER "Kapacita"]' => (string)($p['capacity'] ?? ''),
        '[PARAMETER "Barva"]' => (string)($p['color'] ?? ''),
        '[PARAMETER "Stav"]' => (string)($p['grade'] ?? ''),
        '[PARAMETER "Baterie"]' => $bat,
        '[STOCK_STOCK "karlin"]' => $stockKey === 'karlin' ? $stockVal : '',
        '[STOCK_STOCK "vaclavak"]' => $stockKey === 'vaclavak' ? $stockVal : '',
        '[IMAGES]' => (string)($p['image_url'] ?? ''),
    ];
    $assoc = array_merge($assoc, $live);

    // řádek bez raw_csv (nemělo by nastat) → syntetizovat zbytek ze sloupců
    if (!is_array($raw)) {
        $assoc += [
            '[ACTIVE_YN]' => '1',
            '[MANUFACTURER]' => (string)($p['manufacturer'] ?? ''),
            '[CATEGORIES]' => (string)($p['category_code'] ?? ''),
            '[IS_PRICES_WITH_VAT_YN]' => '1',
            '[VAT]' => '0',
            '[SHORT_DESCRIPTION]' => implode(' | ', array_filter([
                $p['grade'] ? 'Stav: ' . $p['grade'] : '',
                $bat !== '' ? 'Kondice baterie: ' . $bat : '',
                $p['capacity'] ? 'Úložiště: ' . $p['capacity'] : '',
                $p['color'] ? 'Barva: ' . $p['color'] : '',
            ])) . ' | Zvláštní režim DPH §90 (použité zboží)',
            'PRIDANO' => !empty($p['added_at']) ? date('Y-m-d H:i', strtotime((string)$p['added_at'])) : '',
            'PCR_VYSLEDEK' => (string)($p['pcr_result'] ?? ''),
        ];
    }

    $line = [];
    foreach ($header as $h) { $line[] = (string)($assoc[$h] ?? ''); }
    fputcsv($out, $line, ';', '"', '');
}
fclose($out);
