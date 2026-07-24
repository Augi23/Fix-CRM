<?php
/**
 * KNIHOVNA STUDIOVÝCH FOTEK MODELŮ (model_photos) — API.
 * Studiová fotka se váže na RODINU modelu + barvu (productModelKey), ne na jednotlivý kus.
 * Produkt bez vlastní studiovky ji ve feedu zdědí → jedna fotka pokryje všechny kusy modelu
 * (i budoucí naskladněné). Naplňuje se z UI (model_photos.php) i z párovacího seedu.
 *
 * Autorizace (stačí jedno):
 *   - přihlášené vedení (crmCanManageProducts) + platný CSRF  → pro UI,
 *   - sdílený token (setting product_image_token)            → pro seed z Macu.
 *
 * Akce (GET/POST ?action=):
 *   groups           → seznam skupin model_key nad `products` (počet, vzorky, aktuální fotka) + coverage
 *   list             → syrové řádky knihovny model_photos
 *   set   {model_key, studio_url}  → upsert fotky do knihovny (studio_url musí projít whitelistem)
 *   clear {model_key}              → smazání fotky modelu
 * Odpověď: JSON.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mp_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function mp_ok(array $data = []): void {
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string)($_REQUEST['action'] ?? 'groups');
$isWrite = in_array($action, ['set', 'clear'], true);

// ── Autorizace: session (UI) NEBO token (seed) ──
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && crmCanManageProducts();
if ($isWrite && $sessionOk) {
    // zápis z UI vyžaduje i platný CSRF
    $sessionOk = validateCsrfToken((string)($_POST['csrf_token'] ?? ''));
    if (!$sessionOk) mp_fail('Přihlášení vypršelo — obnov stránku (⌘R) a zkus to znovu.', 403);
}
if (!$sessionOk) {
    $token = (string)($_REQUEST['token'] ?? ($_SERVER['HTTP_X_AFX_TOKEN'] ?? ''));
    $expected = (string)get_setting('product_image_token', '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        mp_fail('Nedostatečná oprávnění.', 403);
    }
}

ensureProductsTable();
if (function_exists('ensureProductsCrmColumns')) { try { ensureProductsCrmColumns(); } catch (Throwable $e) {} }
ensureModelPhotosTable();

/** Načte knihovnu jako mapu model_key → řádek. */
function mp_library(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT model_key, studio_image_url, label, updated_at FROM model_photos")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['model_key']] = $r;
    }
    return $out;
}

try {
    if ($action === 'list') {
        mp_ok(['rows' => array_values(mp_library($pdo))]);
    }

    if ($action === 'set') {
        $key = trim((string)($_POST['model_key'] ?? ''));
        // z Galerie u produktu přijde rovnou model+barva → klíč spočítáme tady (shodně s feedem)
        if ($key === '' && trim((string)($_POST['model'] ?? '')) !== '') {
            $key = productModelKey((string)$_POST['model'], (string)($_POST['color'] ?? ''));
        }
        $url = trim((string)($_POST['studio_url'] ?? ''));
        if ($key === '') mp_fail('Chybí model_key nebo model.');
        if (mb_strlen($key) > 160) mp_fail('model_key je příliš dlouhý.');
        $clean = productImageDisplayUrl($url);
        if ($clean === '') mp_fail('Neplatná nebo nepovolená URL fotky.');
        $label = productModelKeyLabel($key);
        $who = trim((string)($_SESSION['user_name'] ?? $_SESSION['tech_name'] ?? 'seed'));
        $st = $pdo->prepare("INSERT INTO model_photos (model_key, studio_image_url, label, updated_by, updated_at)
                             VALUES (?,?,?,?,NOW())
                             ON DUPLICATE KEY UPDATE studio_image_url=VALUES(studio_image_url),
                                 label=VALUES(label), updated_by=VALUES(updated_by), updated_at=NOW()");
        $st->execute([$key, $clean, $label, $who]);
        if (function_exists('crmAuditLog')) {
            crmAuditLog('model_photo.set', ['entity_type' => 'model_photo', 'entity_label' => $key,
                'summary' => 'Studiová fotka modelu: ' . $label]);
        }
        mp_ok(['model_key' => $key, 'label' => $label, 'studio_url' => $clean]);
    }

    if ($action === 'clear') {
        $key = trim((string)($_POST['model_key'] ?? ''));
        if ($key === '') mp_fail('Chybí model_key.');
        $pdo->prepare("DELETE FROM model_photos WHERE model_key = ?")->execute([$key]);
        if (function_exists('crmAuditLog')) {
            crmAuditLog('model_photo.clear', ['entity_type' => 'model_photo', 'entity_label' => $key,
                'summary' => 'Smazána studiová fotka modelu: ' . $key]);
        }
        mp_ok(['model_key' => $key]);
    }

    // ── action = groups (default) ──
    $lib = mp_library($pdo);
    $rows = $pdo->query("SELECT product_code, title, model, color, stock_qty, studio_image_url
                         FROM products")->fetchAll(PDO::FETCH_ASSOC);
    $groups = [];             // model_key → agregace
    $noKey = 0;               // produkty, u nichž nelze určit klíč (chybí model)
    $ownStudio = 0;           // produkty s vlastní per-produkt studiovkou
    foreach ($rows as $r) {
        $hasOwn = trim((string)($r['studio_image_url'] ?? '')) !== '';
        if ($hasOwn) { $ownStudio++; }
        $key = productModelKey($r['model'] ?? '', $r['color'] ?? '');
        if ($key === '') { $noKey++; continue; }
        if (!isset($groups[$key])) {
            $groups[$key] = ['model_key' => $key, 'label' => productModelKeyLabel($key),
                'count' => 0, 'in_stock' => 0, 'no_own' => 0, 'samples' => [],
                'has_color' => (mb_strpos($key, '|') !== false && substr($key, mb_strpos($key, '|') + 1) !== '')];
        }
        $groups[$key]['count']++;
        if (!$hasOwn) $groups[$key]['no_own']++;   // jen kusy BEZ vlastní studiovky knihovnu skutečně zdědí
        if ((int)$r['stock_qty'] > 0) $groups[$key]['in_stock']++;
        if (count($groups[$key]['samples']) < 4) $groups[$key]['samples'][] = (string)$r['title'];
    }
    // přilep aktuální fotku z knihovny + spočítej pokrytí
    $covered = 0; $groupsCovered = 0;
    foreach ($groups as $k => &$g) {
        $g['current_url'] = isset($lib[$k]) ? (string)$lib[$k]['studio_image_url'] : '';
        $g['updated_at']  = isset($lib[$k]) ? (string)$lib[$k]['updated_at'] : null;
        // pokrytí = jen kusy bez vlastní studiovky (ownStudio se přičte zvlášť → žádné dvojí počítání)
        if ($g['current_url'] !== '') { $groupsCovered++; $covered += $g['no_own']; }
    }
    unset($g);
    // řazení: nejdřív skupiny bez fotky s nejvíc kusy, pak s fotkou
    $arr = array_values($groups);
    usort($arr, function ($a, $b) {
        $ac = $a['current_url'] === '' ? 0 : 1;
        $bc = $b['current_url'] === '' ? 0 : 1;
        if ($ac !== $bc) return $ac - $bc;              // bez fotky nahoru
        return $b['count'] <=> $a['count'];             // víc kusů nahoru
    });

    mp_ok([
        'groups' => $arr,
        'stats' => [
            'products_total'   => count($rows),
            'products_no_key'  => $noKey,
            'products_own'     => $ownStudio,
            'groups_total'     => count($arr),
            'groups_covered'   => $groupsCovered,
            'products_covered' => $covered + $ownStudio,
        ],
    ]);
} catch (Throwable $e) {
    mp_fail('Chyba: ' . $e->getMessage(), 500);
}
