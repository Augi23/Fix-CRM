<?php
/**
 * Katalogy dodavatelů: stažení a parsování e-shopů dodavatelů, přepočet ceny
 * a denní synchronizace do inventory. Používá api/parse_catalog.php (ruční import),
 * scripts/sync_supplier_catalogs.php (denní běh na pozadí) a Nákupy.
 */

/* ── Pomocné funkce parseru (přesunuto z api/parse_catalog.php) ── */
function isPublicCatalogUrl(string $url): bool {
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return false;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || strpos($host, '.') === false) {
        return false;
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    if ($port !== null && !in_array($port, [80, 443], true)) {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    $resolvedIps = [];
    $ipv4Records = @gethostbynamel($host);
    if (is_array($ipv4Records)) {
        $resolvedIps = array_merge($resolvedIps, $ipv4Records);
    }

    if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
        $ipv6Records = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                if (!empty($record['ipv6'])) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        }
    }

    foreach (array_unique($resolvedIps) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
    }

    return true;
}

function getCatalogOrigin(string $url): string {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $origin = strtolower($parts['scheme']) . '://' . $parts['host'];
    if (isset($parts['port'])) {
        $origin .= ':' . (int)$parts['port'];
    }

    return $origin;
}

function inventoryHasImagePathColumn(): bool {
    static $hasColumn = null;

    if ($hasColumn !== null) {
        return $hasColumn;
    }

    global $pdo;

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'image_path'"
        );
        $stmt->execute();
        $hasColumn = ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        $hasColumn = false;
    }

    return $hasColumn;
}

function ensureInventoryCatalogSchema(): bool {
    global $pdo;

    try {
        $pdo->exec("ALTER TABLE `inventory` MODIFY COLUMN `part_name` VARCHAR(255) NOT NULL");
    } catch (Throwable $e) {
        log_error('Catalog import schema upgrade failed', 'inventory_import', 'part_name: ' . $e->getMessage());
        return false;
    }

    foreach ([
        "ALTER TABLE `inventory` ADD COLUMN `image_path` VARCHAR(255) DEFAULT NULL AFTER `min_stock`",
        "ALTER TABLE `inventory` ADD COLUMN `source_supplier` VARCHAR(50) DEFAULT NULL AFTER `image_path`",
        "ALTER TABLE `inventory` ADD COLUMN `source_url` VARCHAR(255) DEFAULT NULL AFTER `source_supplier`",
        "ALTER TABLE `inventory` ADD COLUMN `supplier_availability` VARCHAR(120) DEFAULT NULL AFTER `source_url`",
        "ALTER TABLE `inventory` ADD COLUMN `supplier_stock_qty` INT NULL AFTER `supplier_availability`",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Ignore duplicate-column errors so older installs can be upgraded in place.
        }
    }

    return true;
}

function normalizePath(string $path): string {
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    return '/' . implode('/', $segments);
}

function extractFirstSrcsetUrl(string $srcset): string {
    $srcset = trim($srcset);
    if ($srcset === '') {
        return '';
    }

    $parts = preg_split('/\s*,\s*/', $srcset);
    if (!is_array($parts) || empty($parts)) {
        return '';
    }

    $first = trim((string)$parts[0]);
    if ($first === '') {
        return '';
    }

    $spacePos = strpos($first, ' ');
    return $spacePos === false ? $first : trim(substr($first, 0, $spacePos));
}

function resolveCatalogUrl(string $origin, string $currentUrl, string $candidate): string {
    $candidate = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($candidate === '' || str_starts_with($candidate, '#') || preg_match('/^(javascript|mailto|tel):/i', $candidate)) {
        return '';
    }

    if (preg_match('#^https?://#i', $candidate)) {
        return preg_replace('/#.*$/', '', $candidate);
    }

    $currentParts = parse_url($currentUrl);
    $scheme = (string)($currentParts['scheme'] ?? 'https');

    if (str_starts_with($candidate, '//')) {
        return $scheme . ':' . $candidate;
    }

    $relativeParts = parse_url($candidate);
    $relativePath = (string)($relativeParts['path'] ?? '');
    $relativeQuery = isset($relativeParts['query']) ? ('?' . $relativeParts['query']) : '';

    if (str_starts_with($candidate, '/')) {
        return rtrim($origin, '/') . normalizePath($relativePath) . $relativeQuery;
    }

    $currentPath = (string)($currentParts['path'] ?? '/');
    $currentDir = preg_replace('#/[^/]*$#', '/', $currentPath);
    $fullPath = normalizePath($currentDir . $relativePath);

    return rtrim($origin, '/') . $fullPath . $relativeQuery;
}

function fetchHtml(string $url): string {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $html = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if (!is_string($html) || $html === '' || $httpCode < 200 || $httpCode >= 400) {
        return '';
    }

    return $html;
}

function createXPathFromHtml(string $html): ?DOMXPath {
    if ($html === '') {
        return null;
    }

    $previousErrors = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = @$doc->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);

    if (!$loaded) {
        return null;
    }

    return new DOMXPath($doc);
}

/**
 * Denní kurz cizí měny → CZK podle ČNB (kurzovní lístek, cache v settings na 24 h).
 * Fallback při nedostupnosti ČNB: poslední známý kurz, jinak konzervativní konstanta.
 */
function afxCzkRate(string $currency): float {
    $currency = strtoupper($currency);
    if ($currency === 'CZK') { return 1.0; }
    $fallback = ['EUR' => 25.0, 'USD' => 23.0][$currency] ?? 1.0;
    $cacheKey = 'fx_' . strtolower($currency) . '_czk';
    $cached = (string)get_setting($cacheKey, '');
    if ($cached !== '') {
        [$den, $kurz] = array_pad(explode('|', $cached, 2), 2, '');
        if ($den === date('Y-m-d') && (float)$kurz > 0) { return (float)$kurz; }
    }
    try {
        $txt = @file_get_contents('https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt',
            false, stream_context_create(['http' => ['timeout' => 6]]));
        if ($txt) {
            foreach (explode("\n", $txt) as $line) {
                $c = explode('|', trim($line));
                // země|měna|množství|kód|kurz
                if (count($c) === 5 && $c[3] === $currency && (float)$c[2] > 0) {
                    $kurz = ((float)str_replace(',', '.', $c[4])) / (float)$c[2];
                    if ($kurz > 0) {
                        set_setting($cacheKey, date('Y-m-d') . '|' . $kurz);
                        return $kurz;
                    }
                }
            }
        }
    } catch (Throwable $e) { error_log('afxCzkRate: ' . $e->getMessage()); }
    // ČNB nedostupná → poslední známý kurz (i starší), jinak fallback konstanta
    if ($cached !== '') {
        $kurz = (float)(explode('|', $cached, 2)[1] ?? 0);
        if ($kurz > 0) { return $kurz; }
    }
    return $fallback;
}

function isMobileSentrixUrl(string $url): bool {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return $host !== '' && (str_contains($host, 'mobilesentrix'));
}

function collectCategoryUrls(DOMXPath $xpath, string $currentUrl, string $origin): array {
    $links = $xpath->query("//ul[contains(@class, 'category-list')]//a[@href] | //div[contains(@class, 'categories')]//a[@href]");
    $categoryUrls = [];
    $originHost = (string)parse_url($origin, PHP_URL_HOST);

    foreach ($links as $link) {
        $resolvedUrl = resolveCatalogUrl($origin, $currentUrl, $link->getAttribute('href'));
        if ($resolvedUrl === '') {
            continue;
        }

        $host = (string)parse_url($resolvedUrl, PHP_URL_HOST);
        if ($host === '' || strcasecmp($host, $originHost) !== 0) {
            continue;
        }

        $categoryUrls[$resolvedUrl] = trim($link->nodeValue);
    }

    // MobileSentrix (Magento): strom kategorií nemá category-list třídy — podkategorie
    // poznáme podle CESTY: jsou to odkazy, jejichž path začíná cestou aktuální stránky
    // (např. /replacement-parts/apple pod /replacement-parts). Produkty žijí v kořeni
    // (/nazev-produktu), takže prefix filtr je nikdy nezachytí. Sestup je rekurzivní —
    // každá podkategorie objeví zase své děti.
    if (isMobileSentrixUrl($currentUrl)) {
        $currentPath = rtrim((string)parse_url($currentUrl, PHP_URL_PATH), '/');
        if ($currentPath !== '' && $currentPath !== '/') {
            foreach ($xpath->query('//a[@href]') as $link) {
                $resolvedUrl = resolveCatalogUrl($origin, $currentUrl, $link->getAttribute('href'));
                if ($resolvedUrl === '' || str_contains($resolvedUrl, '?')) {
                    continue;
                }
                $host = (string)parse_url($resolvedUrl, PHP_URL_HOST);
                if ($host === '' || strcasecmp($host, $originHost) !== 0) {
                    continue;
                }
                $path = rtrim((string)parse_url($resolvedUrl, PHP_URL_PATH), '/');
                if ($path !== $currentPath && str_starts_with($path, $currentPath . '/')) {
                    $categoryUrls[$resolvedUrl] = trim($link->nodeValue);
                }
            }
        }
    }

    return $categoryUrls;
}

function collectPaginationUrls(DOMXPath $xpath, string $currentUrl, string $origin): array {
    $nodes = $xpath->query("//*[@data-target-url]");
    $pageUrls = [];
    $originHost = (string)parse_url($origin, PHP_URL_HOST);

    foreach ($nodes as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }

        $targetUrl = trim($node->getAttribute('data-target-url'));
        // „načíst další" s rozsahem stránek (fixshop ?p=2-3) — jen zdvojuje obsah a z každé
        // takové stránky vzniká další rozsah → nekonečné bobtnání fronty
        if ($targetUrl === '' || preg_match('/[?&]p=\d+-\d+/', $targetUrl)) {
            continue;
        }

        $resolvedUrl = resolveCatalogUrl($origin, $currentUrl, $targetUrl);
        if ($resolvedUrl === '') {
            continue;
        }

        $host = (string)parse_url($resolvedUrl, PHP_URL_HOST);
        if ($host === '' || strcasecmp($host, $originHost) !== 0) {
            continue;
        }

        $pageUrls[$resolvedUrl] = trim((string)$node->textContent);
    }

    // Obecné stránkování odkazy ?p=N / ?page=N / ?strana=N na TÉŽE stránce (fixshop, Shoptet…)
    // — z odkazů se vezme nejvyšší číslo a vygenerují se všechny stránky (odkazy ukazují jen
    // pár čísel kolem aktuální). Jen z první stránky výpisu, ať se to negeneruje znovu a znovu.
    if (!isMobileSentrixUrl($currentUrl) && !preg_match('/[?&](?:p|page|strana)=\d+/', $currentUrl)) {
        $curPath = rtrim((string)parse_url($currentUrl, PHP_URL_PATH), '/');
        $maxN = 0; $param = '';
        foreach ($xpath->query('//a[@href]') as $a) {
            $u = resolveCatalogUrl($origin, $currentUrl, $a->getAttribute('href'));
            if ($u === '' || rtrim((string)parse_url($u, PHP_URL_PATH), '/') !== $curPath) { continue; }
            if (preg_match('/[?&](p|page|strana)=(\d+)/', html_entity_decode($u), $m) && (int)$m[2] > $maxN) {
                $maxN = (int)$m[2]; $param = $m[1];
            }
        }
        if ($maxN > 1) {
            $base = strtok($currentUrl, '#');
            $sep = str_contains($base, '?') ? '&' : '?';
            for ($n = 2; $n <= min($maxN, 600); $n++) { $pageUrls[$base . $sep . $param . '=' . $n] = 'strana ' . $n; }
        }
    }

    // MobileSentrix: počet stránek nese skryté pole .total_pages, listuje se ?p=N
    if (isMobileSentrixUrl($currentUrl) && strpos($currentUrl, '?p=') === false) {
        $totalNode = $xpath->query("//input[contains(concat(' ', normalize-space(@class), ' '), ' total_pages ')]/@value")->item(0);
        $totalPages = $totalNode ? (int)trim((string)$totalNode->nodeValue) : 0;
        if ($totalPages > 1) {
            $baseUrl = preg_replace('/[?&]p=\d+/', '', $currentUrl);
            $sep = str_contains($baseUrl, '?') ? '&' : '?';
            // v4.0.0: celý výpis (dřív max. 50 stránek → MobileSentrix jen zčásti); strop jen pojistka
            for ($p = 2; $p <= min($totalPages, 600); $p++) {
                $pageUrls[$baseUrl . $sep . 'p=' . $p] = 'strana ' . $p;
            }
        }
    }

    return $pageUrls;
}

function queryFirstValue(DOMXPath $xpath, DOMNode $contextNode, array $queries): string {
    foreach ($queries as $query) {
        $node = $xpath->query($query, $contextNode)->item(0);
        if ($node instanceof DOMNode) {
            $value = trim((string)$node->nodeValue);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function parseMoneyValue(string $rawValue): float {
    $value = html_entity_decode(strip_tags($rawValue), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/[^\d,.\-]/u', '', $value);
    $value = is_string($value) ? trim($value) : '';

    if ($value === '' || $value === '-') {
        return 0.0;
    }

    $lastComma = strrpos($value, ',');
    $lastDot = strrpos($value, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($lastComma !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function normalizeInventoryText(string $value, int $maxLength): string {
    $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function extractAvailabilityInfo(DOMXPath $xpath, DOMNode $contextNode): array {
    $availability = queryFirstValue($xpath, $contextNode, [
        ".//*[@data-micro='availability']",
        ".//*[@itemprop='availability']",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' stock-info ')]",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' availability ')]",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' availability-info ')]",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' stock ')]",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' available ')]",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' availability-label ')]",
    ]);

    if ($availability === '') {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($contextNode->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($text !== '') {
            $lines = preg_split('/\R/u', $text) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/(skladem|na dotaz|vyprodáno|na objednání|na objednavku|dostupnost|dostupné|není skladem|na skladě)/iu', $line)) {
                    $availability = $line;
                    break;
                }
            }
        }
    }

    $availability = normalizeInventoryText($availability, 120);
    $stockQty = null;
    if ($availability !== '' && preg_match('/(?:\(|\b)(\d+)\s*ks\b/iu', $availability, $match)) {
        $stockQty = (int)$match[1];
    }

    return [$availability, $stockQty];
}

/* ═══════════════════════════════════════════════════════════════════════════
   DENNÍ SYNCHRONIZACE KATALOGŮ DODAVATELŮ (v4.0.0)
   Dřív byl katalog jednorázový snímek z ručního importu: cena v Kč zamrzla
   v den importu (kurz ČNB / 24,21 Kč/EUR z 1. 8.), DPH se nerozlišovala
   (MobileSentrix ukazuje ceny bez DPH, české e-shopy s DPH), dodavatelé si
   přes shodné SKU přepisovali položky a zmizelé díly se nijak neoznačily.
   Teď se každý den katalog projde znovu a do inventory se ukládá:
     supplier_price / supplier_currency  — cena přesně jak je na webu
     supplier_price_czk                  — co reálně zaplatíme: kurz ČNB dne,
                                           + DPH dle nastavení, − naše sleva
     supplier_seen_at / price_synced_at  — kdy jsme díl u dodavatele viděli
     supplier_gone_at                    — dodavatel díl přestal nabízet
   Čistě katalogové položky (nic skladem) přebírají supplier_price_czk i do
   sale_price/cost_price — kromě ručně upravených cen (price_manual = 1).
   ═══════════════════════════════════════════════════════════════════════════ */

/** Sloupce pro synchronizaci (idempotentní, bez migrace). */
function ensureSupplierSyncSchema(): void {
    global $pdo;
    static $done = false;
    if ($done) { return; }
    $done = true;
    ensureSupplierCatalogsTable();
    ensureInventoryCatalogSchema();
    try {
        $sc = $pdo->query("SHOW COLUMNS FROM supplier_catalogs")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('price_vat_add', $sc, true)) {
            $pdo->exec("ALTER TABLE supplier_catalogs
                ADD COLUMN price_vat_add DECIMAL(5,2) NOT NULL DEFAULT 0,
                ADD COLUMN discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
                ADD COLUMN sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
                ADD COLUMN last_sync_at DATETIME NULL,
                ADD COLUMN last_sync_info VARCHAR(500) NULL");
            // MobileSentrix a refurb.zone ukazují ceny BEZ DPH (ověřeno 21. 10. 2026) — výchozí +21 % (lze změnit v Nákupech)
            $pdo->exec("UPDATE supplier_catalogs SET price_vat_add = 21 WHERE host LIKE '%mobilesentrix%' OR host = 'refurb.zone'");
            // …a jako vstup měl jen úvodní stránku webu, odkud parser výpis dílů nenajde →
            // výpis všech Apple dílů (stránkovaný ?p=N, ~15 000 položek)
            $pdo->exec("UPDATE supplier_catalogs SET default_url = 'https://www.mobilesentrix.eu/replacement-parts/apple'
                WHERE host = 'mobilesentrix.eu' AND TRIM(TRAILING '/' FROM TRIM(default_url)) = 'https://www.mobilesentrix.eu'");
            // fixshop.cz: úvodní stránka má jen 50 dílů — výpis Apple dílů má ~140 stránek (?p=N)
            $pdo->exec("UPDATE supplier_catalogs SET default_url = 'https://fixshop.cz/nahradni-dily-apple/'
                WHERE host = 'fixshop.cz' AND TRIM(TRAILING '/' FROM TRIM(default_url)) = 'https://fixshop.cz'");
        }
    } catch (Throwable $e) { error_log('ensureSupplierSyncSchema supplier_catalogs: ' . $e->getMessage()); }
    try {
        $ic = $pdo->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('supplier_checked_at', $ic, true)) {
            // kdy se naposledy ověřila stránka dílu, který výpis kategorie nepotkal
            $pdo->exec("ALTER TABLE inventory ADD COLUMN supplier_checked_at DATETIME NULL");
        }
        if (!in_array('supplier_price_czk', $ic, true)) {
            $pdo->exec("ALTER TABLE inventory
                ADD COLUMN supplier_price DECIMAL(12,2) NULL,
                ADD COLUMN supplier_currency CHAR(3) NULL,
                ADD COLUMN supplier_price_czk DECIMAL(10,2) NULL,
                ADD COLUMN supplier_seen_at DATETIME NULL,
                ADD COLUMN price_synced_at DATETIME NULL,
                ADD COLUMN supplier_gone_at DATETIME NULL,
                ADD COLUMN price_manual TINYINT(1) NOT NULL DEFAULT 0,
                ADD INDEX idx_inv_supplier_sku (source_supplier, sku)");
        }
    } catch (Throwable $e) { error_log('ensureSupplierSyncSchema inventory: ' . $e->getMessage()); }
}

/** Nastavení dodavatele pro přepočet ceny. */
function crmSupplierConfig(string $skey): array {
    global $pdo;
    ensureSupplierSyncSchema();
    $st = $pdo->prepare("SELECT * FROM supplier_catalogs WHERE skey = ? LIMIT 1");
    $st->execute([$skey]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'skey' => $skey,
        'name' => (string)($r['name'] ?? $skey),
        'urls' => supplierCatalogUrls((string)($r['default_url'] ?? '')),
        'vat' => (float)($r['price_vat_add'] ?? 0),
        'discount' => (float)($r['discount_pct'] ?? 0),
        'enabled' => (int)($r['sync_enabled'] ?? 1) === 1,
    ];
}

/** Cena z webu dodavatele → co reálně zaplatíme v Kč. */
function crmSupplierPriceCzk(float $price, string $currency, array $cfg): float {
    if ($price <= 0) { return 0.0; }
    $czk = $price * afxCzkRate($currency);
    $czk *= 1 + max(0.0, $cfg['vat']) / 100;
    $czk *= 1 - min(90.0, max(0.0, $cfg['discount'])) / 100;
    return round($czk, 2);
}

/**
 * Projde katalog dodavatele (kategorie + stránkování) a každý nalezený produkt
 * předá do $onProduct jako pole (name, sku, price, currency, image, url,
 * availability, stock_qty). Bez zápisu do DB — ten řeší volající.
 * @return array{pages:int, scanned:int, complete:bool, error:string}
 */
function crmCatalogCrawl(array $catalogUrls, callable $onProduct, array $opt = []): array {
    $maxPages = (int)($opt['max_pages'] ?? 900);
    $maxProducts = (int)($opt['max_products'] ?? 30000);
    $delayMs = (int)($opt['delay_ms'] ?? 250);   // šetrně k webu dodavatele
    $catalogUrl = $catalogUrls[0] ?? '';
    $out = ['pages' => 0, 'scanned' => 0, 'complete' => false, 'error' => ''];
    $origin = getCatalogOrigin($catalogUrl);
    if ($origin === '') { $out['error'] = 'neplatná adresa katalogu'; return $out; }

    $startHtml = fetchHtml($catalogUrl);
    if ($startHtml === '') { $out['error'] = 'web dodavatele neodpověděl: ' . $catalogUrl; return $out; }
    $startXPath = createXPathFromHtml($startHtml);
    if (!$startXPath instanceof DOMXPath) { $out['error'] = 'úvodní stránku nešlo přečíst'; return $out; }

    // úvodní stránka katalogu PRVNÍ (dřív až za všemi kategoriemi → při limitu se na ni nedostalo)
    $queued = [$catalogUrl => true];
    foreach (array_slice($catalogUrls, 1) as $u) { $queued[$u] = true; }
    $processed = [];
    $seenProducts = [];   // stejná karta bývá na stránce dvakrát (Shoptet: data-micro + .product)
    $hitLimit = false;
    // MobileSentrix: výpis kategorie se celý prolistuje přes ?p=N, podkategorie by
    // jen zdvojily tytéž produkty (a stovky stránek navíc)
    $flat = isMobileSentrixUrl($catalogUrl)
        // vstupní výpis má vlastní stránkování přes všechny díly (fixshop ?p=1…140) →
        // podkategorie by jen opakovaly tytéž produkty (a stovky stránek navíc)
        || count(collectPaginationUrls($startXPath, $catalogUrl, $origin)) > 3;

    while ($queued) {
        $pageUrl = array_key_first($queued);
        unset($queued[$pageUrl]);
        if (isset($processed[$pageUrl])) { continue; }
        if (count($processed) >= $maxPages) { $hitLimit = true; break; }
        $processed[$pageUrl] = true;

        if ($pageUrl !== $catalogUrl && $delayMs > 0) { usleep($delayMs * 1000); }
        $html = $pageUrl === $catalogUrl ? $startHtml : fetchHtml($pageUrl);
        if ($html === '') { continue; }
        $xp = createXPathFromHtml($html);
        if (!$xp instanceof DOMXPath) { continue; }

        $found = $flat ? collectPaginationUrls($xp, $pageUrl, $origin)
                       : array_merge(collectCategoryUrls($xp, $pageUrl, $origin), collectPaginationUrls($xp, $pageUrl, $origin));
        foreach (array_keys($found) as $u) {
            if (!isset($processed[$u])) { $queued[$u] = true; }
        }

        $products = $xp->query(
            "//div[contains(concat(' ', normalize-space(@class), ' '), ' product-card ')] |
             //div[contains(concat(' ', normalize-space(@class), ' '), ' product ')] |
             //article[contains(concat(' ', normalize-space(@class), ' '), ' product ')] |
             //*[@data-testid='productItem'] |
             //*[@data-micro='product'] |
             //li[contains(concat(' ', normalize-space(@class), ' '), ' item ')][.//*[contains(concat(' ', normalize-space(@class), ' '), ' product-name ')]]"
        );
        if (!$products || $products->length === 0) { continue; }

        foreach ($products as $product) {
            $p = crmCatalogParseProduct($xp, $product, $pageUrl, $origin);
            if ($p === null) { continue; }
            $dupKey = $p['url'] !== '' ? $p['url'] : $p['sku'] . '|' . $p['name'];
            if (isset($seenProducts[$dupKey])) { continue; }
            $seenProducts[$dupKey] = true;
            $out['scanned']++;
            $onProduct($p);
            if ($out['scanned'] >= $maxProducts) { $hitLimit = true; break 2; }
        }
    }
    $out['pages'] = count($processed);
    $out['complete'] = !$hitLimit;
    return $out;
}

/** Jedna karta produktu z výpisu kategorie → pole s daty (nebo null). */
function crmCatalogParseProduct(DOMXPath $xp, DOMNode $product, string $pageUrl, string $origin): ?array {
    $name = queryFirstValue($xp, $product, [
        ".//span[@data-micro='name']",
        // product-name PŘED img/@alt — u MobileSentrix je první <img> odznak
        // („Soldering Required" apod.), jeho alt by přepsal skutečný název
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-name ')]//a",
        ".//h2[contains(concat(' ', normalize-space(@class), ' '), ' product-name ')]",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-name ')]",
        ".//img/@alt",
        ".//a[@title]/@title",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' name ')]//a",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' line-clamp-3 ')]",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' font-bold ')]",
    ]);
    if ($name === '') { return null; }
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($product->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    $isMs = isMobileSentrixUrl($pageUrl);

    $sku = queryFirstValue($xp, $product, [
        ".//span[@data-micro='sku']",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' sku ')]",
    ]);
    if ($sku === '' && preg_match('/(?:^|\b)Kód:\s*(\S+)/iu', $text, $m)) { $sku = trim((string)$m[1]); }
    if ($sku === '' && $isMs) {
        // stabilní ID produktu z quickorder inputu → SKU „MS-<id>" (klíč pro synchronizaci)
        $msId = queryFirstValue($xp, $product, [".//input[@name='products[]']/@value"]);
        if ($msId !== '' && ctype_digit($msId)) { $sku = 'MS-' . $msId; }
    }

    $priceRaw = queryFirstValue($xp, $product, [
        ".//div[@data-micro='offer']/@data-micro-price",
        ".//*[@data-micro='price']/@content",
        ".//*[@itemprop='price']/@content",
        ".//span[contains(concat(' ', normalize-space(@class), ' '), ' price-info-span ')]/@data-base-price",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' price-final ')]",
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' product-card-list__price ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' font-bold ')]",
        // tučný text s měnou (fixshop: <p class="font-bold">63 Kč</p>) — dřív se bral JAKÝKOLI
        // tučný text a z „…3349mAh, A3018 … 317 Kč" vznikla cena 3 018 317 Kč
        ".//*[contains(concat(' ', normalize-space(@class), ' '), ' font-bold ')][contains(., 'Kč') or contains(., '€')]",
    ]);
    // cena musí VYPADAT jako částka (číslice, mezery, oddělovače, měna) — jinak ji vzít z textu karty
    if ($priceRaw !== '' && !preg_match('/^\s*(?:€|EUR|CZK)?\s*\d[\d\s\x{00A0}.,]{0,14}\s*(?:Kč|€|EUR|CZK|,-)?\s*$/u', $priceRaw)) {
        $priceRaw = '';
    }
    if ($priceRaw === '') {
        if (preg_match('/(\d[\d\s]*[.,]?\d*)\s*(?:Kč|€|EUR)/iu', $text, $m)) { $priceRaw = trim((string)$m[1]); }
        elseif (preg_match('/[€]\s*(\d[\d\s]*[.,]?\d*)/u', $text, $m)) { $priceRaw = trim((string)$m[1]); }
    }
    $price = parseMoneyValue($priceRaw);

    // MĚNA: mobilesentrix.eu = EUR (mobilesentrix.com = USD); jinde rozhodne symbol u ceny
    $currency = 'CZK';
    if ($isMs) {
        $currency = str_contains(strtolower((string)parse_url($pageUrl, PHP_URL_HOST)), 'mobilesentrix.com') ? 'USD' : 'EUR';
    } elseif (preg_match('/€|\bEUR\b/iu', $text) && !preg_match('/Kč/u', $text)) {
        $currency = 'EUR';
    }

    $image = queryFirstValue($xp, $product, [
        ".//img/@data-micro-image", ".//img/@data-src", ".//img/@data-lazy-src", ".//img/@data-original",
        ".//img/@data-srcset", ".//img/@srcset", ".//img/@src", ".//source/@data-srcset", ".//source/@srcset",
    ]);
    if (strpos($image, 'data:image') === 0) { $image = ''; }
    elseif (str_contains($image, ',') || str_contains($image, ' ')) { $image = extractFirstSrcsetUrl($image); }
    if ($image !== '') { $image = resolveCatalogUrl($origin, $pageUrl, $image); }

    $url = queryFirstValue($xp, $product, [".//a[@data-instant]/@href", ".//a[@href]/@href"]);
    if ($url !== '') { $url = resolveCatalogUrl($origin, $pageUrl, $url); }

    [$availability, $stockQty] = extractAvailabilityInfo($xp, $product);
    if ($availability === '' && $isMs) {
        // skladovou zásobu dodavatele nese hidden input .productqty (max. objednatelné množství)
        $msQty = queryFirstValue($xp, $product, [".//input[contains(concat(' ', normalize-space(@class), ' '), ' productqty ')]/@value"]);
        if ($msQty !== '' && ctype_digit($msQty)) {
            $stockQty = (int)$msQty;
            $availability = $stockQty > 0 ? ('Skladem u dodavatele (' . $stockQty . ' ks)') : 'Vyprodáno u dodavatele';
        }
    }

    return [
        'name' => $name, 'sku' => $sku, 'price' => $price, 'currency' => $currency,
        'image' => $image, 'url' => $url, 'availability' => $availability, 'stock_qty' => $stockQty,
    ];
}

/**
 * Zápis jednoho produktu dodavatele do inventory.
 * Párování JEN v rámci dodavatele (SKU → odkaz → název bez SKU); cizí dodavatel
 * se shodným kódem se už nepřepisuje. Ručně zadaný díl (bez dodavatele) se
 * stejným SKU se k dodavateli připojí.
 * @return string added|updated|skipped  (+ $priceChanged = cena se změnila o víc než 1 Kč)
 */
function crmCatalogUpsert(array $p, array $cfg, string $runTs, ?bool &$priceChanged = null): string {
    global $pdo;
    $priceChanged = false;
    $skey = $cfg['skey'];
    $name = normalizeInventoryText((string)$p['name'], 255);
    $sku = normalizeInventoryText((string)$p['sku'], 50);
    $url = normalizeInventoryText((string)$p['url'], 255);
    $image = normalizeInventoryText((string)$p['image'], 255);
    $avail = normalizeInventoryText((string)$p['availability'], 120);
    $stockQty = $p['stock_qty'];
    if ($name === '') { return 'skipped'; }
    $price = (float)$p['price'];
    $currency = (string)$p['currency'];
    $czk = crmSupplierPriceCzk($price, $currency, $cfg);
    // pojistka: nesmyslná cena (parser chytil číslo modelu / kód místo ceny) = cena neznámá
    if ($czk > 300000) { $price = 0.0; $czk = 0.0; }

    $cols = "id, quantity, COALESCE(is_stocked, 0) AS is_stocked, price_manual, supplier_price_czk, sale_price, supplier_availability, image_path";
    $row = null;
    $try = [];
    if ($sku !== '') { $try[] = ["SELECT $cols FROM inventory WHERE source_supplier = ? AND sku = ? LIMIT 1", [$skey, $sku]]; }
    if ($url !== '') { $try[] = ["SELECT $cols FROM inventory WHERE source_supplier = ? AND source_url = ? LIMIT 1", [$skey, $url]]; }
    $try[] = ["SELECT $cols FROM inventory WHERE source_supplier = ? AND part_name = ? LIMIT 1", [$skey, $name]];
    if ($sku !== '') { $try[] = ["SELECT $cols FROM inventory WHERE (source_supplier IS NULL OR source_supplier = '') AND sku = ? LIMIT 1", [$sku]]; }
    foreach ($try as [$sql, $args]) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) { break; }
    }

    if ($row) {
        $set = ["source_supplier = ?", "supplier_seen_at = ?", "supplier_gone_at = NULL"];
        $args = [$skey, $runTs];
        if ($sku !== '') { $set[] = "sku = ?"; $args[] = $sku; }
        if ($url !== '') { $set[] = "source_url = ?"; $args[] = $url; }
        if ($avail !== '') { $set[] = "supplier_availability = ?"; $args[] = $avail; }
        elseif ((string)$row['supplier_availability'] === 'Dodavatel už nenabízí') { $set[] = "supplier_availability = NULL"; }   // díl se u dodavatele vrátil
        if ($stockQty !== null) { $set[] = "supplier_stock_qty = ?"; $args[] = (int)$stockQty; }
        if ($image !== '' && (string)($row['image_path'] ?? '') === '') { $set[] = "image_path = ?"; $args[] = $image; }
        if ($czk <= 0 && ((float)$row['supplier_price_czk'] > 300000 || ((int)$row['quantity'] <= 0 && (float)$row['sale_price'] > 300000))) {
            // dřívější nesmyslná cena (chybně přečtené číslo) — raději „neověřeno" než miliony
            $set[] = "supplier_price_czk = NULL"; $set[] = "price_synced_at = NULL";
            if ((int)$row['quantity'] <= 0 && (int)$row['price_manual'] === 0) { $set[] = "sale_price = 0"; $set[] = "cost_price = 0"; }
        }
        if ($czk > 0) {
            $priceChanged = abs((float)$row['supplier_price_czk'] - $czk) >= 1;
            array_push($set, "supplier_price = ?", "supplier_currency = ?", "supplier_price_czk = ?", "price_synced_at = ?");
            array_push($args, $price, $currency, $czk, $runTs);
            // čistě katalogová položka (nic skladem) bez ruční ceny → aktuální cena dodavatele
            if ((int)$row['quantity'] <= 0 && (int)$row['is_stocked'] === 0 && (int)$row['price_manual'] === 0) {
                array_push($set, "sale_price = ?", "cost_price = ?");
                array_push($args, $czk, $czk);
            }
        }
        $args[] = (int)$row['id'];
        $pdo->prepare("UPDATE inventory SET " . implode(', ', $set) . " WHERE id = ?")->execute($args);
        return 'updated';
    }

    $pdo->prepare("INSERT INTO inventory (part_name, sku, sale_price, cost_price, quantity, min_stock, image_path, source_supplier,
            source_url, supplier_availability, supplier_stock_qty, supplier_price, supplier_currency, supplier_price_czk,
            supplier_seen_at, price_synced_at)
        VALUES (?, ?, ?, ?, 0, 5, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$name, $sku !== '' ? $sku : null, $czk, $czk, $image, $skey, $url, $avail, $stockQty,
                   $price > 0 ? $price : null, $currency, $czk > 0 ? $czk : null, $runTs, $czk > 0 ? $runTs : null]);
    return 'added';
}

/** HTTP stav stránky (0 = nedostupné). */
function crmCatalogUrlStatus(string $url): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_RANGE => '0-2047',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36',
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return $code;
}

/** Díly dodavatele, které dnešní výpis nepotkal: přímo zkontrolovat jejich stránku.
 *  404/410 → dodavatel díl zrušil (supplier_gone_at); stránka existuje → jen „viděno".
 *  Cena se tu nemění — z detailu produktu ji nejde spolehlivě poznat (s/bez DPH). */
function crmCatalogCheckUnseen(string $skey, string $runTs, int $limit): int {
    global $pdo;
    if ($limit <= 0) { return 0; }
    $st = $pdo->prepare("SELECT id, source_url FROM inventory WHERE source_supplier = ? AND supplier_gone_at IS NULL
        AND (supplier_seen_at IS NULL OR supplier_seen_at < ?) AND source_url LIKE 'http%'
        AND (supplier_checked_at IS NULL OR supplier_checked_at < ? - INTERVAL 7 DAY)
        ORDER BY supplier_checked_at IS NOT NULL, supplier_checked_at ASC LIMIT " . (int)$limit);
    $st->execute([$skey, $runTs, $runTs]);
    $gone = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        usleep(250000);
        $code = crmCatalogUrlStatus((string)$r['source_url']);
        if ($code === 404 || $code === 410) {
            $pdo->prepare("UPDATE inventory SET supplier_gone_at = ?, supplier_checked_at = ?, supplier_availability = 'Dodavatel už nenabízí' WHERE id = ?")
                ->execute([$runTs, $runTs, (int)$r['id']]);
            $gone++;
        } elseif ($code >= 200 && $code < 400) {
            // jen „ověřeno, že existuje" — NE supplier_seen_at (to je výpis; jinak by díl mimo
            // výpis druhý den vypadal jako zmizelý)
            $pdo->prepare("UPDATE inventory SET supplier_checked_at = ? WHERE id = ?")->execute([$runTs, (int)$r['id']]);
        }
    }
    return $gone;
}

/**
 * Synchronizace jednoho dodavatele (denní běh i tlačítko „Synchronizovat teď").
 * Když průchod katalogem doběhne celý, položky, které dodavatel už nenabízí,
 * se označí (supplier_gone_at) — nic se nemaže.
 */
function crmCatalogSyncSupplier(string $skey, array $opt = []): array {
    global $pdo;
    ensureSupplierSyncSchema();
    $cfg = crmSupplierConfig($skey);
    if (!empty($opt['urls'])) { $cfg['urls'] = array_values($opt['urls']); }   // ruční import s vlastními odkazy
    $runTs = date('Y-m-d H:i:s');
    $stats = ['supplier' => $skey, 'added' => 0, 'updated' => 0, 'price_changes' => 0, 'gone' => 0, 'pages' => 0, 'scanned' => 0, 'complete' => false, 'error' => ''];
    if (!$cfg['urls']) { $stats['error'] = 'chybí adresa katalogu'; return $stats; }

    $crawl = crmCatalogCrawl($cfg['urls'], function (array $p) use ($cfg, $runTs, &$stats) {
        $changed = false;
        $r = crmCatalogUpsert($p, $cfg, $runTs, $changed);
        if ($r === 'added') { $stats['added']++; }
        elseif ($r === 'updated') { $stats['updated']++; }
        if ($changed) { $stats['price_changes']++; }
    }, $opt);
    $stats['pages'] = $crawl['pages'];
    $stats['scanned'] = $crawl['scanned'];
    $stats['complete'] = $crawl['complete'];
    $stats['error'] = $crawl['error'];

    // ZMIZELÉ DÍLY — opatrně, ať se neoznačí díl, který jen leží mimo procházený výpis
    // (MobileSentrix nemá ve výpisu Apple dílů úplně všechno, starší importy pokrývaly i jiné kategorie):
    //  a) díl, který synchronizace DŘÍV viděla a teď ve stejně úplném průchodu chybí;
    //  b) díl, jehož stránka u dodavatele vrací 404/410 (ověří se přímo, viz níže).
    if ($crawl['complete'] && $crawl['error'] === '' && ($stats['added'] + $stats['updated']) > 0) {
        $prevSeen = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE source_supplier = " . $pdo->quote($skey)
            . " AND supplier_seen_at IS NOT NULL AND supplier_seen_at < " . $pdo->quote($runTs) . " AND supplier_gone_at IS NULL")->fetchColumn();
        $nowSeen = $stats['added'] + $stats['updated'];
        // výpis se dnes vrátil výrazně menší (výpadek, změna webu) → nic neoznačovat
        if ($prevSeen === 0 || $nowSeen >= 0.8 * ($nowSeen + $prevSeen) || $prevSeen < 50) {
            $st = $pdo->prepare("UPDATE inventory SET supplier_gone_at = ?, supplier_availability = 'Dodavatel už nenabízí'
                WHERE source_supplier = ? AND supplier_gone_at IS NULL AND supplier_seen_at IS NOT NULL AND supplier_seen_at < ?");
            $st->execute([$runTs, $skey, $runTs]);
            $stats['gone'] += $st->rowCount();
        }
    }
    // díly mimo výpis: ověřit přímo stránku produktu (denně nejvýš N nejdéle neověřených)
    $stats['gone'] += crmCatalogCheckUnseen($skey, $runTs, (int)($opt['check_unseen'] ?? 150));

    $info = $stats['error'] !== ''
        ? 'Chyba: ' . $stats['error']
        : sprintf('%d položek, %d nových, %d změn ceny%s%s', $stats['added'] + $stats['updated'], $stats['added'], $stats['price_changes'],
            $stats['gone'] ? ', ' . $stats['gone'] . ' už nenabízí' : '',
            $crawl['complete'] ? '' : ' (průchod nedokončen — limit stránek)');
    try {
        $pdo->prepare("UPDATE supplier_catalogs SET last_sync_at = ?, last_sync_info = ? WHERE skey = ?")
            ->execute([$runTs, mb_substr($info, 0, 500), $skey]);
    } catch (Throwable $e) {}
    return $stats;
}
