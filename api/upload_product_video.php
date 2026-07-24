<?php
/**
 * UPLOAD 360° VIDEA PRODUKTU (sekce Galerie v naskladnění/úpravě).
 * Video (dvě celé otočky) se uloží veřejně do /media/products/360/<kód>.<ext> a vrátí se URL.
 * FÁZE 2: z tohoto videa eshop po naskladnění vyrobí 36 snímků = jedno 360 otočení,
 * odstraní pozadí a složí 360 produkt bez pozadí.
 *
 * Nejde přes upload_product_image.php — ten přes getimagesize()+GD očekává obrázek.
 * POST multipart: video (soubor), code (kód produktu), token | csrf_token
 * Odpověď JSON: { success, url } | { success:false, message }
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

function afx_vid_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { afx_vid_fail('Jen POST.', 405); }

// ── Autorizace: stejná jako upload_product_image.php (session+CSRF NEBO sdílený token appky) ──
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id']))
    && validateCsrfToken((string)($_POST['csrf_token'] ?? ''))
    && crmCanManageProducts();
if (!$sessionOk) {
    if ((!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && isset($_POST['csrf_token'])) {
        afx_vid_fail('Přihlášení vypršelo — obnov stránku (⌘R) a zkus video znovu.', 403);
    }
    $token = (string)($_POST['token'] ?? ($_SERVER['HTTP_X_AFX_TOKEN'] ?? ''));
    $expected = (string)get_setting('product_image_token', '');
    if ($expected === '' || !hash_equals($expected, $token)) { afx_vid_fail('Neplatný token.', 403); }
}

$code = trim((string)($_POST['code'] ?? ''));
if ($code === '') { afx_vid_fail('Chybí kód produktu.'); }
$safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $code);
$safe = trim($safe, '._-');
if ($safe === '') { $safe = 'produkt-' . substr(md5($code), 0, 8); }

if (empty($_FILES['video']['tmp_name']) || !is_uploaded_file($_FILES['video']['tmp_name'])) {
    afx_vid_fail('Chybí video soubor.');
}
if (($_FILES['video']['size'] ?? 0) > 300 * 1024 * 1024) {
    afx_vid_fail('Video je příliš velké (max 300 MB).');
}

// MIME jen přes finfo (nikdy $_FILES['type'] — je od klienta)
$tmp = $_FILES['video']['tmp_name'];
$mime = '';
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string)@finfo_file($fi, $tmp);
    finfo_close($fi);
}
$allowed = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-m4v' => 'mp4'];
if (!isset($allowed[$mime])) { afx_vid_fail('Nepodporovaný formát videa (povoleno MP4, MOV, WEBM).'); }
$ext = $allowed[$mime];

// ── Cílová složka (veřejná, aby eshop mohl video stáhnout ke zpracování) ──
$dir = __DIR__ . '/../media/products/360';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$dest = $dir . '/' . $safe . '.' . $ext;
foreach (['mp4', 'mov', 'webm'] as $e) { if ($e !== $ext) { @unlink($dir . '/' . $safe . '.' . $e); } } // starou příponu pryč
if (!@move_uploaded_file($tmp, $dest)) { afx_vid_fail('Uložení videa selhalo.', 500); }
@chmod($dest, 0644);

// Sidecar s PŘESNÝM kódem produktu — dispatcher (fáze 2) podle něj pojmenuje složku snímků
// public/produkty-360/<kód>/ (název videa je sanitizovaný $safe, kód může mít jiné znaky).
// Zápis do vlastní složky CRM (žádný cross-user problém); dispatcher ho jen ČTE.
@file_put_contents($dir . '/' . $safe . '.code', $code);
@chmod($dir . '/' . $safe . '.code', 0644);

$host = $_SERVER['HTTP_HOST'] ?? 'admin.applefix.cloud';
$url = 'https://' . $host . '/media/products/360/' . rawurlencode($safe) . '.' . $ext . '?v=' . (int)@filemtime($dest);

crmAuditLog('product_video.upload', [
    'entity_type' => 'product', 'entity_label' => $code,
    'summary' => 'Nahráno 360° video produktu ' . $code,
]);

echo json_encode(['success' => true, 'url' => $url, 'code' => $code], JSON_UNESCAPED_UNICODE);
