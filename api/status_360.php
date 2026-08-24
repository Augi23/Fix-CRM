<?php
/**
 * STAV 360° ZPRACOVÁNÍ (fáze 2) — odvozený z FILESYSTÉMU, bez DB stavu.
 * Zdroj nahraný v Galerii leží v media/products/360/ — FOTKY z točny ve složce
 * <safe>-photos/ (preferované), starší cesta = video <safe>.<ext>. Dispatcher (cron jako augi)
 * z nich vyrobí snímky do eshopu public/produkty-360/<kód>/frame-NNN.webp. Tenhle endpoint jen
 * porovná časy: snímky čerstvější než zdroj = hotovo; zdroj bez (čerstvých) snímků = zpracovává se.
 *
 * GET  ?code=<kód>                 → { status: none|processing|ready|failed|manual, frames, preview }
 * ('failed' = dispatcher zapsal marker <kód>.failed a zdroj se od selhání nezměnil;
 *  'manual' = v eshopu leží sada *.webp BEZ zdroje v CRM — ručně nahraná/osiřelá, nelze přegenerovat)
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
// $code se používá RAW v cestě k eshop snímkům (produkty-360/<kód>) — stejný whitelist
// jako v uploadu, jinak ?code=../.. čte mimo strom. Legální kódy (sériáky) projdou.
if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,63}$/', $code)) { s360_fail('Neplatný kód.'); }

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
// FOTKY z točny (upload_product_360_photos.php) — mají před videem přednost i v dispatcheru.
// Zdrojové mtime = NEJNOVĚJŠÍ soubor složky (vč. .code, který touchne „Přegenerovat").
$photosDir = $videoDir . '/' . $safe . '-photos';
$photosCount = 0; $photosMtime = 0;
if (is_dir($photosDir)) {
    foreach ((array)scandir($photosDir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $fp = $photosDir . '/' . $f;
        if (!is_file($fp)) { continue; }
        if ($f !== '.code') { $photosCount++; }
        $photosMtime = max($photosMtime, (int)@filemtime($fp));
    }
}
$hasSource   = ($videoPath !== '') || ($photosCount > 0);
// Priorita ZRCADLÍ dispatcher: existují-li fotky, video se IGNORUJE (nesmí vstoupit
// do max() — novější video by tu drželo věčné 'processing', které dispatcher nikdy neukončí).
$sourceMtime = $photosCount > 0 ? $photosMtime : $videoMtime;

// složka snímků v eshopu (stejný server) — cestu lze přepsat settingem
$eshopPublic = rtrim((string)get_setting('eshop_public_dir', '/home/augi/AppleFix-eshop/public'), '/');
$eshopUrl    = rtrim((string)get_setting('eshop_public_url', 'https://applefix.click'), '/');
$framesDir   = $eshopPublic . '/produkty-360/' . $code;
$frame0      = $framesDir . '/frame-000.webp';
$failedMark  = $framesDir . '.failed';   // dispatcher: zpracování trvale selhalo (obsah = mtime zdroje při selhání)

// ── POST regen: znovu-vyrobit (touch zdroje → dispatcher ho vezme; fotky mají přednost) ──
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'regen') {
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) { s360_fail('Neplatný token — obnov stránku.', 403); }
    if (!$hasSource) { s360_fail('K produktu nejsou nahrané žádné 360° fotky ani video.'); }
    if ($photosCount > 0) { @touch($photosDir . '/.code'); }
    else { @touch($videoPath); }
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

if (!$hasSource) {
    // Bez zdroje v CRM může v eshopu přesto ležet sada snímků (ručně nahraná, např. rsyncem
    // s názvy 01.webp…, nebo osiřelá po smazaném zdroji). Eshop čte VŠECHNA *.webp, tak je
    // ukázat i operátorovi — jinak CRM hlásí „nic", zatímco eshop 360° zobrazuje.
    $manual = is_dir($framesDir) ? (glob($framesDir . '/*.webp') ?: []) : [];
    if ($manual) {
        sort($manual, SORT_STRING);   // stejné pořadí jako eshop (lexikografický sort)
        s360_out([
            'success' => true, 'status' => 'manual', 'frames' => count($manual),
            'preview' => $eshopUrl . '/produkty-360/' . rawurlencode($code) . '/'
                       . rawurlencode(basename($manual[0])) . '?v=' . (int)@filemtime($manual[0]),
        ]);
    }
    s360_out(['success' => true, 'status' => 'none', 'frames' => 0]);
}
// snímky existují a jsou aspoň tak čerstvé jako zdroj (fotky/video) → hotovo
if ($frameCount > 0 && $frameMtime >= $sourceMtime) {
    s360_out([
        'success' => true, 'status' => 'ready', 'frames' => $frameCount,
        'preview' => $eshopUrl . '/produkty-360/' . rawurlencode($code) . '/frame-000.webp?v=' . $frameMtime,
    ]);
}
// dispatcher označil zdroj jako selhaný a zdroj se OD TÉ DOBY nezměnil → failed
// („Přegenerovat" touchne zdroj → sourceMtime > marker → zase 'processing' a dispatcher to zkusí)
$failedFor = is_file($failedMark) ? (float)trim((string)@file_get_contents($failedMark)) : 0.0;
if ($failedFor > 0 && $failedFor >= $sourceMtime) {
    s360_out(['success' => true, 'status' => 'failed', 'frames' => $frameCount]);
}
// zdroj je, snímky chybí nebo jsou starší → čeká/zpracovává se
s360_out(['success' => true, 'status' => 'processing', 'frames' => $frameCount]);
