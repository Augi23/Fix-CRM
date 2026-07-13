<?php
/* Přidání katalogu dodavatele (tlačítko „Přidat katalog" na Nákupech).
   Host i klíč se odvodí z URL; generický parser katalogu si s běžnými
   e-shopy poradí bez dalších úprav. */
require_once '../includes/config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    die(__('unauthorized'));
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405); die('Method not allowed');
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403); die(__('csrf_token_invalid'));
}

function backToProcurement(array $params): void {
    header('Location: ../procurement.php?' . http_build_query($params));
    exit;
}

$name = trim((string)($_POST['catalog_name'] ?? ''));
$url  = trim((string)($_POST['catalog_url'] ?? ''));

$parts = parse_url($url);
$scheme = strtolower((string)($parts['scheme'] ?? ''));
$host = strtolower((string)($parts['host'] ?? ''));
$host = preg_replace('/^www\./', '', $host);

if ($name === '' || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
    backToProcurement(['catalog_source_error' => 'invalid']);
}

try {
    ensureSupplierCatalogsTable();

    // duplicitní host (i mezi výchozími katalogy)
    foreach (getSupplierCatalogs() as $c) {
        $h = strtolower((string)($c['host'] ?? ''));
        if ($h !== '' && ($host === $h || str_ends_with($host, '.' . $h))) {
            backToProcurement(['catalog_source_error' => 'exists']);
        }
    }

    $skey = trim(preg_replace('/[^a-z0-9]+/', '-', $host), '-');
    $skey = mb_substr($skey, 0, 40);
    $chk = $pdo->prepare("SELECT COUNT(*) FROM supplier_catalogs WHERE skey = ?");
    $chk->execute([$skey]);
    if ((int)$chk->fetchColumn() > 0) {
        backToProcurement(['catalog_source_error' => 'exists']);
    }

    $pdo->prepare("INSERT INTO supplier_catalogs (skey, name, host, default_url) VALUES (?, ?, ?, ?)")
        ->execute([$skey, mb_substr($name, 0, 80), mb_substr($host, 0, 120), mb_substr($url, 0, 255)]);

    backToProcurement(['catalog_source_added' => '1']);
} catch (Throwable $e) {
    backToProcurement(['catalog_source_error' => 'server']);
}
