<?php
/**
 * STAV 360° ZPRACOVÁNÍ (fáze 2) — odvozený z FILESYSTÉMU, bez DB stavu.
 * Video nahrané v Galerii leží v media/products/360/<safe>.<ext>; dispatcher (cron jako augi)
 * z něj vyrobí snímky do eshopu public/produkty-360/<kód>/frame-NNN.webp. Tenhle endpoint jen
 * porovná časy: snímky čerstvější než video = hotovo; video bez (čerstvých) snímků = zpracovává se.
 *
 * GET  ?code=<kód>                 → { status: none|processing|ready, frames, preview }
 * POST action=regen&code=<kód>     → touch videa (dispatcher ho pak přepracuje) — session+CSRF
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function s360_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function s360_fail(string $m, int $c = 400): void { http_response_code($c); s360_out(['success' => false, 'message' => $m]); }

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) { s360_fail('Nepřihlášeno.', 403); }
if (!crmCanManageProducts()) { s360_fail('Nedostatečná oprávnění.', 403); }

$code = trim((string)($_REQUEST['code'] ?? ''));
if ($code === '') { s360_fail('Chybí kód.'); }

// stejná sanitizace názvu jako v upload_product_video.php
$safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $code);
$safe = trim($safe, '._-');
if ($safe === '') { $safe = 'produkt-' . substr(md5($code), 0, 8); }

$videoDir = __DIR__ . '/../media/products/360';
$exts = ['mp4', 'mov', 'webm', 'm4v'];
$videoPath = ''; $videoMtime = 0;
foreach ($exts as $e) {
    $p = $videoDir . '/' . $safe . '.' . $e;
    if (is_file($p)) { $videoPath = $p; $videoMtime = (int)@filemtime($p); break; }
}

// složka snímků v eshopu (stejný server) — cestu lze přepsat settingem
$eshopPublic = rtrim((string)get_setting('eshop_public_dir', '/home/augi/AppleFix-eshop/public'), '/');
$eshopUrl    = rtrim((string)get_setting('eshop_public_url', 'https://applefix.click'), '/');
$framesDir   = $eshopPublic . '/produkty-360/' . $code;
$frame0      = $framesDir . '/frame-000.webp';

// ── POST regen: znovu-vyrobit (touch videa → dispatcher ho vezme) ──
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'regen') {
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) { s360_fail('Neplatný token — obnov stránku.', 403); }
    if ($videoPath === '') { s360_fail('K produktu není nahrané žádné 360° video.'); }
    @touch($videoPath);
    if (function_exists('crmAuditLog')) {
        crmAuditLog('product_360.regen', ['entity_type' => 'product', 'entity_label' => $code,
            'summary' => 'Znovu spuštěno 360° zpracování ' . $code]);
    }
    s360_out(['success' => true, 'status' => 'processing']);
}

// ── GET status ──
$frameCount = 0;
$frameMtime = 0;
if (is_dir($framesDir)) {
    $fs = glob($framesDir . '/frame-*.webp') ?: [];
    $frameCount = count($fs);
    if (is_file($frame0)) { $frameMtime = (int)@filemtime($frame0); }
}

if ($videoPath === '') {
    s360_out(['success' => true, 'status' => 'none', 'frames' => $frameCount]);
}
// snímky existují a jsou aspoň tak čerstvé jako video → hotovo
if ($frameCount > 0 && $frameMtime >= $videoMtime) {
    s360_out([
        'success' => true, 'status' => 'ready', 'frames' => $frameCount,
        'preview' => $eshopUrl . '/produkty-360/' . rawurlencode($code) . '/frame-000.webp?v=' . $frameMtime,
    ]);
}
// video je, snímky chybí nebo jsou starší → čeká/zpracovává se
s360_out(['success' => true, 'status' => 'processing', 'frames' => $frameCount]);
