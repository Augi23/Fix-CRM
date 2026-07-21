<?php
/**
 * ČTECÍ JSON FEED PRODUKTŮ PRO VLASTNÍ E-SHOP.
 * Zdroj = tabulka `products` (Sklad → Produkty). Vlastní e-shop (běží na STEJNÉM
 * serveru jako CRM) si sem chodí pro aktuální stav produktů — polling nebo po importu.
 *
 * Auth (stačí jedno):
 *   - požadavek ze stejného serveru (127.0.0.1/::1) → BEZ tokenu (e-shop běží lokálně),
 *   - přihlášené vedení (crmCanManageProducts) — pro ruční náhled feedu,
 *   - token: ?token=<t> nebo hlavička X-Feed-Token proti settingu `eshop_feed_token`
 *     (vygeneruje se automaticky, viz crmEshopFeedToken()) — pro vzdálený přístup.
 *
 * Parametry (GET):
 *   token=<t>            token (jen vzdáleně)
 *   code=<kód>           jeden produkt podle product_code
 *   in_stock=1           jen skladem (stock_qty > 0)
 *   updated_since=<ISO>  jen produkty změněné od data (inkrementální sync, sloupec updated_at)
 *   limit=<n>            max 2000 (default 500), offset=<n>
 *
 * Odpověď: { ok, generated_at, count, total, limit, offset, products:[ … ] }
 * Ceny jsou S DPH. Použité zboží běží ve zvláštním režimu §90 (vat_margin_scheme_90=true) —
 * e-shop NESMÍ z prodejní ceny vyčíslovat DPH.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ensureProductsTable();
if (function_exists('ensureProductsPosColumn')) { try { ensureProductsPosColumn(); } catch (Throwable $e) {} }
if (function_exists('ensureProductsCrmColumns')) { try { ensureProductsCrmColumns(); } catch (Throwable $e) {} }

// ── auth ─────────────────────────────────────────────────────────────────────
$remote   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal  = in_array($remote, ['127.0.0.1', '::1', ''], true);
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && crmCanManageProducts();
$expected  = crmEshopFeedToken();
$provided  = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_FEED_TOKEN'] ?? ''));
$tokenOk   = ($expected !== '' && $provided !== '' && hash_equals($expected, $provided));
if (!$isLocal && !$sessionOk && !$tokenOk) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── parametry ────────────────────────────────────────────────────────────────
$code         = trim((string)($_GET['code'] ?? ''));
$inStockOnly  = ($_GET['in_stock'] ?? '') === '1';
$updatedSince = trim((string)($_GET['updated_since'] ?? ''));
$limit        = max(1, min(2000, (int)($_GET['limit'] ?? 500)));
$offset       = max(0, (int)($_GET['offset'] ?? 0));

$where = []; $params = [];
if ($code !== '')       { $where[] = 'product_code = ?'; $params[] = $code; }
if ($inStockOnly)       { $where[] = 'stock_qty > 0'; }
if ($updatedSince !== '') {
    $tsN = strtotime($updatedSince);
    if ($tsN) { $where[] = 'updated_at >= ?'; $params[] = date('Y-m-d H:i:s', $tsN); }
}
$wsql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

try {
    $cst = $pdo->prepare("SELECT COUNT(*) FROM products$wsql");
    $cst->execute($params);
    $total = (int)$cst->fetchColumn();

    $st = $pdo->prepare("SELECT * FROM products$wsql ORDER BY updated_at DESC, id DESC LIMIT $limit OFFSET $offset");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $products = [];
    foreach ($rows as $p) {
        $raw = json_decode((string)($p['raw_csv'] ?? ''), true);
        if (!is_array($raw)) $raw = [];

        // §90 (zvláštní režim použitého zboží): [VAT]=0 nebo věta „§90" v krátkém popisu.
        $shortDesc = (string)($raw['[SHORT_DESCRIPTION]'] ?? $raw['SHORT_DESCRIPTION'] ?? '');
        $vatVal    = trim((string)($raw['[VAT]'] ?? $raw['VAT'] ?? ''));
        $margin90  = ($vatVal === '0' || $vatVal === '0.0' || $vatVal === '0.00')
                     || (mb_stripos($shortDesc, '§90') !== false)
                     || (mb_stripos($shortDesc, 'zvláštní režim') !== false);

        // Fotka jen z whitelistovaného hostu (admin.applefix.cloud / media/products).
        $img = productImageDisplayUrl((string)($p['image_url'] ?? ''));
        if ($img !== '' && str_starts_with($img, 'media/products/')) {
            $img = 'https://admin.applefix.cloud/' . ltrim($img, '/');
        }

        $products[] = [
            'code'                 => (string)$p['product_code'],
            'title'                => (string)$p['title'],
            'manufacturer'         => $p['manufacturer'] !== null ? (string)$p['manufacturer'] : null,
            'category_code'        => $p['category_code'] !== null ? (string)$p['category_code'] : null,
            'model'                => $p['model'] !== null ? (string)$p['model'] : null,
            'capacity'             => $p['capacity'] !== null ? (string)$p['capacity'] : null,
            'color'                => $p['color'] !== null ? (string)$p['color'] : null,
            'grade'                => $p['grade'] !== null ? (string)$p['grade'] : null,
            'battery'              => $p['battery'] !== null ? (string)$p['battery'] : null,
            'price'                => (float)$p['price'],
            'currency'             => 'CZK',
            'prices_include_vat'   => true,
            'vat_margin_scheme_90' => (bool)$margin90,
            'in_stock'             => ((int)$p['stock_qty']) > 0,
            'stock_qty'            => (int)$p['stock_qty'],
            'stock_location'       => (string)($p['stock_key'] ?? ''),
            'short_description'    => $shortDesc,
            'image'                => $img !== '' ? $img : null,
            'pcr_result'           => $p['pcr_result'] !== null ? (string)$p['pcr_result'] : null,
            'added_at'             => $p['added_at'] !== null ? (string)$p['added_at'] : null,
            'updated_at'           => (string)$p['updated_at'],
            'last_seen_at'         => (string)$p['last_seen_at'],
            // Kompletní zdrojová data (SHORT_DESCRIPTION, RAM, ročník, generace, CPU/GPU…).
            // Pozn.: [IMAGES] z tohoto pole jsou cizí URL — pro obrázek použij pole `image`.
            'attributes'           => $raw,
        ];
    }

    echo json_encode([
        'ok'           => true,
        'generated_at' => date('c'),
        'count'        => count($products),
        'total'        => $total,
        'limit'        => $limit,
        'offset'       => $offset,
        'products'     => $products,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
