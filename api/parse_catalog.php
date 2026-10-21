<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/catalog_sync.php';

if (!isset($_SESSION['user_id']) || !crmCanManageCatalogs()) {
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

// Import běží dlouho (crawl celého katalogu) — session UVOLNIT hned po ověření,
// jinak drží zámek a všechny další požadavky uživatele (filtry, jiné taby CRM)
// visí, dokud import neskončí. Do session už dál nezapisujeme.
session_write_close();

set_time_limit(300);

function redirectToInventory(array $params = []): void {
    // Catalog now lives on the Nákupy page — return there so the user sees the result and rows.
    $query = $params ? ('?' . http_build_query($params)) : '';
    header('Location: ../procurement.php' . $query);
    exit;
}

function redirectCatalogError(string $errorKey, string $detail = ''): void {
    $params = ['catalog_error' => $errorKey];
    if ($detail !== '') {
        $params['catalog_error_detail'] = $detail;
    }
    redirectToInventory($params);
}

// Jeden katalog může mít víc vstupních odkazů (jedna URL na řádek) —
// typicky MobileSentrix, kde je každá kategorie/model zvlášť.
$catalogUrls = [];
foreach (preg_split('/\s+/', trim((string)($_POST['catalog_url'] ?? ''))) ?: [] as $u) {
    $u = trim($u);
    if ($u !== '') { $catalogUrls[] = $u; }
}
$catalogUrls = array_values(array_unique($catalogUrls));
$catalogUrl = $catalogUrls[0] ?? '';

foreach ($catalogUrls as $u) {
    if (!isPublicCatalogUrl($u)) {
        redirectCatalogError('invalid_url', 'URL musí být veřejná a platná.');
    }
}
if ($catalogUrl === '') {
    redirectCatalogError('invalid_url', 'URL musí být veřejná a platná.');
}

$origin = getCatalogOrigin($catalogUrl);
if ($origin === '') {
    redirectCatalogError('invalid_url', 'Nepodařilo se určit origin katalogu.');
}
foreach ($catalogUrls as $u) {
    if (getCatalogOrigin($u) !== $origin) {
        redirectCatalogError('invalid_url', 'Všechny odkazy musí být ze stejného webu (jeden katalog = jeden dodavatel).');
    }
}

if (!ensureInventoryCatalogSchema()) {
    redirectCatalogError('processing_failed', 'Nepodařilo se připravit databázové sloupce pro katalog.');
}
set_setting('inventory_catalog_url', implode("\n", $catalogUrls));

// Stejné jádro jako denní synchronizace (includes/catalog_sync.php) — jen v requestu,
// proto s menším limitem stránek a kratší pauzou, ať se vejde do časového limitu.
try {
    $stats = crmCatalogSyncSupplier(supplierKeyFromUrl($catalogUrl), [
        'urls' => $catalogUrls, 'max_pages' => 200, 'max_products' => 5000, 'delay_ms' => 60,
    ]);
    if ($stats['error'] !== '' && ($stats['added'] + $stats['updated']) === 0) {
        redirectCatalogError('fetch_failed', $stats['error']);
    }
    if (($stats['added'] + $stats['updated']) === 0) {
        redirectCatalogError('no_products', 'Prohledáno stránek: ' . $stats['pages'] . ', nalezených produktů: ' . $stats['scanned'] . '.');
    }
    redirectToInventory([
        'catalog_imported' => 1,
        'catalog_added' => $stats['added'],
        'catalog_updated' => $stats['updated'],
    ]);
} catch (Throwable $e) {
    log_error('Catalog import failed', 'inventory_import', $e->getMessage());
    redirectCatalogError('processing_failed', $e->getMessage());
}
