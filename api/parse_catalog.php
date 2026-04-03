<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    die(__('unauthorized'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die(__('csrf_token_invalid'));
}

set_time_limit(300);

function redirectToInventory(array $params = []): void {
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: ../inventory.php' . $query);
    exit;
}

function redirectCatalogError(string $errorKey, string $detail = ''): void {
    $params = ['catalog_error' => $errorKey];
    if ($detail !== '') {
        $params['catalog_error_detail'] = $detail;
    }
    redirectToInventory($params);
}

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

    if (!inventoryHasImagePathColumn()) {
        try {
            $pdo->exec("ALTER TABLE `inventory` ADD COLUMN `image_path` VARCHAR(255) DEFAULT NULL AFTER `min_stock`");
        } catch (Throwable $e) {
            log_error('Catalog import schema upgrade failed', 'inventory_import', 'image_path: ' . $e->getMessage());
            return false;
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

    return $categoryUrls;
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

function upsertInventoryItem(string $name, string $sku, float $price, string $imageUrl): string {
    global $pdo;

    $name = normalizeInventoryText($name, 255);
    $sku = normalizeInventoryText($sku, 50);
    $imageUrl = normalizeInventoryText($imageUrl, 255);

    if ($name === '') {
        return 'skipped';
    }

    $hasImagePath = inventoryHasImagePathColumn();
    $imageSelect = $hasImagePath ? ', image_path' : '';
    $imageSet = $hasImagePath ? ', image_path = ?' : '';
    $imageInsertColumn = $hasImagePath ? ', image_path' : '';
    $imageInsertValue = $hasImagePath ? ', ?' : '';

    $lookupBySku = $sku !== '';
    if ($lookupBySku) {
        $stmt = $pdo->prepare("SELECT id, sale_price{$imageSelect} FROM inventory WHERE sku = ? LIMIT 1");
        $stmt->execute([$sku]);
    } else {
        $stmt = $pdo->prepare("SELECT id, sale_price{$imageSelect} FROM inventory WHERE part_name = ? AND (sku IS NULL OR sku = '') LIMIT 1");
        $stmt->execute([$name]);
    }

    $existing = $stmt->fetch();

    if ($existing) {
        $newPrice = $price > 0 ? $price : (float)$existing['sale_price'];
        $params = [$newPrice];
        if ($hasImagePath) {
            $params[] = $imageUrl !== '' ? $imageUrl : (string)($existing['image_path'] ?? '');
        }
        $params[] = $existing['id'];
        $update = $pdo->prepare("UPDATE inventory SET sale_price = ?{$imageSet} WHERE id = ?");
        $update->execute($params);
        return 'updated';
    }

    $params = [$name, $lookupBySku ? $sku : null, $price, $price > 0 ? $price * 0.7 : 0];
    if ($hasImagePath) {
        $params[] = $imageUrl;
    }
    $insert = $pdo->prepare("INSERT INTO inventory (part_name, sku, sale_price, cost_price, quantity, min_stock{$imageInsertColumn}) VALUES (?, ?, ?, ?, 0, 5{$imageInsertValue})");
    $insert->execute($params);
    return 'added';
}

$catalogUrl = trim((string)($_POST['catalog_url'] ?? ''));
if (!isPublicCatalogUrl($catalogUrl)) {
    redirectCatalogError('invalid_url', 'URL musí být veřejná a platná.');
}

$origin = getCatalogOrigin($catalogUrl);
if ($origin === '') {
    redirectCatalogError('invalid_url', 'Nepodařilo se určit origin katalogu.');
}

if (!ensureInventoryCatalogSchema()) {
    redirectCatalogError('processing_failed', 'Nepodařilo se připravit databázové sloupce pro katalog.');
}

set_setting('inventory_catalog_url', $catalogUrl);

try {
    $startHtml = fetchHtml($catalogUrl);
    if ($startHtml === '') {
        redirectCatalogError('fetch_failed', $catalogUrl);
    }

    $startXPath = createXPathFromHtml($startHtml);
    if (!$startXPath instanceof DOMXPath) {
        redirectCatalogError('processing_failed', 'DOM parser nedokázal zpracovat úvodní stránku.');
    }

    $categoryUrls = collectCategoryUrls($startXPath, $catalogUrl, $origin);
    if (empty($categoryUrls)) {
        $categoryUrls = [$catalogUrl => ''];
    }

    $addedCount = 0;
    $updatedCount = 0;
    $processedPages = [];
    $maxPages = 120;
    $maxProducts = 1500;
    $scannedProducts = 0;

    foreach (array_keys($categoryUrls) as $pageUrl) {
        if (isset($processedPages[$pageUrl])) {
            continue;
        }
        $processedPages[$pageUrl] = true;

        if (count($processedPages) > $maxPages) {
            break;
        }

        $pageHtml = $pageUrl === $catalogUrl ? $startHtml : fetchHtml($pageUrl);
        if ($pageHtml === '') {
            continue;
        }

        $pageXPath = createXPathFromHtml($pageHtml);
        if (!$pageXPath instanceof DOMXPath) {
            continue;
        }

        $products = $pageXPath->query("//div[contains(@class, 'product') and (.//*[@data-micro='name'] or .//span[@data-micro='name'])]");
        if (!$products || $products->length === 0) {
            continue;
        }

        foreach ($products as $product) {
            $scannedProducts++;

            $name = queryFirstValue($pageXPath, $product, [
                ".//span[@data-micro='name']",
                ".//*[contains(@class, 'product-name')]//a",
                ".//*[contains(@class, 'name')]//a",
            ]);
            if ($name === '') {
                continue;
            }

            $sku = queryFirstValue($pageXPath, $product, [
                ".//span[@data-micro='sku']",
                ".//*[contains(@class, 'sku')]",
            ]);

            $priceRaw = queryFirstValue($pageXPath, $product, [
                ".//div[@data-micro='offer']/@data-micro-price",
                ".//*[@data-micro='price']/@content",
                ".//*[@itemprop='price']/@content",
                ".//*[contains(@class, 'price-final')]",
            ]);
            $price = parseMoneyValue($priceRaw);

            $imageUrl = queryFirstValue($pageXPath, $product, [
                ".//img/@data-micro-image",
                ".//img/@data-src",
                ".//img/@data-lazy-src",
                ".//img/@data-original",
                ".//img/@data-srcset",
                ".//img/@srcset",
                ".//img/@src",
                ".//source/@data-srcset",
                ".//source/@srcset",
            ]);
            if (strpos($imageUrl, 'data:image') === 0) {
                $imageUrl = '';
            } elseif (str_contains($imageUrl, ',') || str_contains($imageUrl, ' ')) {
                $imageUrl = extractFirstSrcsetUrl($imageUrl);
            }
            if ($imageUrl !== '') {
                $imageUrl = resolveCatalogUrl($origin, $pageUrl, $imageUrl);
            }

            $result = upsertInventoryItem($name, $sku, $price, $imageUrl);
            if ($result === 'added') {
                $addedCount++;
            } elseif ($result === 'updated') {
                $updatedCount++;
            }

            if (($addedCount + $updatedCount) >= $maxProducts) {
                break 2;
            }
        }
    }

    if (($addedCount + $updatedCount) === 0) {
        redirectCatalogError('no_products', 'Prohledáno stránek: ' . count($processedPages) . ', nalezených produktů: ' . $scannedProducts . '.');
    }

    redirectToInventory([
        'catalog_imported' => 1,
        'catalog_added' => $addedCount,
        'catalog_updated' => $updatedCount,
    ]);
} catch (Throwable $e) {
    log_error('Catalog import failed', 'inventory_import', $e->getMessage());
    redirectCatalogError('processing_failed', $e->getMessage());
}
