<?php
/**
 * Výdej skenu dokladu totožnosti — JEN PRO VEDENÍ.
 *
 * Skeny leží mimo webový kořen (crmIdScanRoot), takže se k nim nedá dostat odkazem.
 * Tenhle endpoint je jediná cesta ven: ověří přihlášení a roli, teprve pak soubor pošle.
 * Každé zobrazení se zapisuje do historie změn — u citlivých osobních údajů má být
 * dohledatelné, kdo se na ně díval.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/documents.php';

if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !crmCanManageInvoices()) {
    http_response_code(403);
    exit('Přístup jen pro vedení.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Chybí id.'); }

ensureDocumentMediaTable();
$st = $pdo->prepare("SELECT id, document_id, file_path, file_type, file_name, kind
    FROM document_media WHERE id = ? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || !str_starts_with(ltrim((string)$row['file_path'], '/'), 'id_scans/')) {
    http_response_code(404); exit('Nenalezeno.');
}

$root = realpath(crmIdScanRoot());
$full = realpath(dirname(crmIdScanRoot()) . '/' . ltrim((string)$row['file_path'], '/'));
if ($root === false || $full === false || !str_starts_with($full, $root . DIRECTORY_SEPARATOR) || !is_file($full)) {
    http_response_code(404); exit('Soubor chybí.');
}

// nahlédnutí do dokladu totožnosti se zaznamenává (GDPR — přístup k citlivým údajům)
crmAuditLog('document.id_scan_view', [
    'entity_type' => 'document', 'entity_id' => (int)$row['document_id'],
    'summary' => 'Zobrazen sken dokladu totožnosti (' . ((string)$row['kind'] === 'id_front' ? 'přední' : 'zadní')
        . ' strana) u dokumentu #' . (int)$row['document_id'],
]);

$mime = (string)($row['file_type'] ?? 'application/octet-stream');
if (!str_starts_with($mime, 'image/')) { $mime = 'application/octet-stream'; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($full));
header('Content-Disposition: inline; filename="doklad-' . (int)$row['id'] . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
readfile($full);
