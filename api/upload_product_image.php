<?php
/**
 * UPLOAD FOTKY PRODUKTU (z naskladňovací Mac appky).
 * Appka nemá přihlašovací session → autorizace přes sdílený token
 * (setting `product_image_token`, appka ho posílá v poli `token`).
 * Fotka se uloží veřejně do /media/products/<kód>.jpg a vrátí se URL.
 * Tu appka zapíše do sloupce [IMAGES] → Upgates i Meta katalog ji použijí.
 *
 * POST multipart: image (soubor), code (kód produktu), token
 * Odpověď JSON: { success, url } | { success:false, message }
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

function afx_img_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    afx_img_fail('Jen POST.', 405);
}

// ── Autorizace: přihlášená session (naskladnění v CRM) NEBO token (Mac appka) ──
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id']))
    && validateCsrfToken((string)($_POST['csrf_token'] ?? ''))
    && crmCanManageProducts();
if (!$sessionOk) {
    // přihlášený uživatel s vypršelým CSRF nesmí dostat matoucí „Neplatný token" (to je hláška appky)
    if ((!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && isset($_POST['csrf_token'])) {
        afx_img_fail('Přihlášení vypršelo — obnov stránku (⌘R) a zkus fotku znovu.', 403);
    }
    // původní tokenová větev pro appku — BEZE ZMĚNY
    $token = (string)($_POST['token'] ?? ($_SERVER['HTTP_X_AFX_TOKEN'] ?? ''));
    $expected = (string)get_setting('product_image_token', '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        afx_img_fail('Neplatný token.', 403);
    }
}

$code = trim((string)($_POST['code'] ?? ''));
if ($code === '') { afx_img_fail('Chybí kód produktu.'); }
// bezpečný název souboru z kódu (serial / AFX-…)
$safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $code);
$safe = trim($safe, '._-');
if ($safe === '') { $safe = 'produkt-' . substr(md5($code), 0, 8); }
// varianta = přípona názvu (studio / g0..g9) → víc fotek jednoho produktu bez přepisu hlavní fotky
$variant = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_POST['variant'] ?? '')));
if ($variant !== '') { $safe .= '-' . $variant; }
// keep_alpha = zachovat průhlednost (PNG) — pro studiovou fotku bez pozadí; jinak JPEG na bílé
$keepAlpha = !empty($_POST['keep_alpha']);
$ext = $keepAlpha ? 'png' : 'jpg';

if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
    afx_img_fail('Chybí soubor s obrázkem.');
}
if (($_FILES['image']['size'] ?? 0) > 15 * 1024 * 1024) {
    afx_img_fail('Obrázek je příliš velký (max 15 MB).');
}

$tmp = $_FILES['image']['tmp_name'];
$info = @getimagesize($tmp);
if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
    afx_img_fail('Nepodporovaný formát (povoleno JPG, PNG, WEBP).');
}

// ── Cílová složka (veřejná) ──
$dir = __DIR__ . '/../media/products';
if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
$destJpg = $dir . '/' . $safe . '.' . $ext;

// ── Zmenšení + převod na JPEG přes GD (Meta i Upgates mají rády ~1600 px, <8 MB) ──
$saved = false;
if (function_exists('imagecreatetruecolor')) {
    try {
        switch ($info[2]) {
            case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($tmp); break;
            case IMAGETYPE_PNG:  $src = @imagecreatefrompng($tmp); break;
            case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : null; break;
            case IMAGETYPE_GIF:  $src = @imagecreatefromgif($tmp); break;
            default: $src = null;
        }
        if ($src) {
            $w = imagesx($src); $h = imagesy($src);
            $max = 1600;
            $scale = min(1.0, $max / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($keepAlpha) {
                // studiová fotka bez pozadí → zachovat průhlednost (PNG)
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $bg = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefilledrectangle($dst, 0, 0, $nw, $nh, $bg);
            } else {
                // bílé pozadí (JPEG nemá průhlednost) — hodí se pro produktové fotky
                $white = imagecolorallocate($dst, 255, 255, 255);
                imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $saved = $keepAlpha ? imagepng($dst, $destJpg, 6) : imagejpeg($dst, $destJpg, 88);
            imagedestroy($src); imagedestroy($dst);
        }
    } catch (Throwable $e) { $saved = false; }
}
// fallback: uložit originál, když GD selže
if (!$saved) {
    if (!@move_uploaded_file($tmp, $destJpg)) { afx_img_fail('Uložení fotky selhalo.', 500); }
}
@chmod($destJpg, 0644);

$host = $_SERVER['HTTP_HOST'] ?? 'admin.applefix.cloud';
$url = 'https://' . $host . '/media/products/' . rawurlencode($safe) . '.' . $ext . '?v=' . (int)@filemtime($destJpg);

crmAuditLog('product_image.upload', [
    'entity_type' => 'product', 'entity_label' => $code,
    'summary' => 'Nahrána fotka produktu ' . $code,
]);

echo json_encode(['success' => true, 'url' => $url, 'code' => $code], JSON_UNESCAPED_UNICODE);
