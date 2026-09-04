<?php
/**
 * Příloha reklamace pro PŘIHLÁŠENÉHO KLIENTA (fotka, PDF doklad…).
 * klient/api/complaint_file.php?id=<media_id>&src=media|attachment
 *
 * Proč endpoint a ne přímý odkaz do /uploads: k reklamaci se nahrávají i osobní
 * doklady (účtenka, faktura). Přímá adresa je sice s náhodným názvem, ale platí
 * navždy a komukoli — tudy se soubor vydá jen vlastníkovi reklamace a bez cache.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!clientIsLoggedIn()) { http_response_code(403); exit; }
$customerId = (int)($_SESSION['client_customer_id'] ?? 0);
if ($customerId <= 0) { http_response_code(403); exit; }

$id  = (int)($_GET['id'] ?? 0);
$src = ($_GET['src'] ?? 'media') === 'attachment' ? 'complaint_attachments' : 'complaint_media';
if ($id <= 0) { http_response_code(404); exit; }

try {
    // stejné vlastnictví jako výpis na nástěnce (duplicitní záznamy zákazníka,
    // reklamace k jeho zakázce, import bez zákazníka) — viz clientComplaintOwnerSql
    $own = clientComplaintOwnerSql($pdo, $customerId, 'c');
    $st = $pdo->prepare("SELECT m.file_path, m.file_name, m.file_type
        FROM `$src` m JOIN complaints c ON c.id = m.complaint_id
        WHERE m.id = ? AND " . $own['sql'] . " LIMIT 1");
    $st->execute(array_merge([$id], $own['params']));
    $row = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('klient complaint_file: ' . $e->getMessage());
    http_response_code(500); exit;
}
if (!$row) { http_response_code(404); exit; }   // cizí i neexistující = stejná odpověď

// Cesta musí zůstat uvnitř uploads/ (žádné ../ ven z úložiště)
$base = realpath(__DIR__ . '/../../uploads');
$file = realpath(__DIR__ . '/../../' . ltrim((string)$row['file_path'], '/'));
if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    http_response_code(404); exit;
}

$type = (string)($row['file_type'] ?? '');
if ($type === '' || !preg_match('#^[\w.+-]+/[\w.+-]+$#', $type)) { $type = 'application/octet-stream'; }
$name = preg_replace('/[^\w.\- ]+/u', '_', (string)($row['file_name'] ?? basename($file))) ?: basename($file);

header('Content-Type: ' . $type);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
readfile($file);
