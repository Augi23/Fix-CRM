<?php
/**
 * 3D MAPA SKLADU — vizualizace rozložení skladu (regály/police/krabičky)
 * vyrobená v Claude Design a exportovaná jako samostatné HTML.
 *   sklad_mapa.php            — stránka v CRM (chrome + iframe s mapou)
 *   sklad_mapa.php?raw=1      — samotné HTML mapy (servíruje se ze
 *                               secure/warehouse3d.html, mimo web root přístup)
 * Mapa si data tahá živě z api/warehouse_map_data.php (same-origin fetch,
 * session cookie platí). Nová verze mapy = přepsat secure/warehouse3d.html
 * (commit → Nastavení → Aktualizace), stránka se nemění.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

$mapFile = __DIR__ . '/secure/warehouse3d.html';

// ── ?raw=1: vlastní HTML mapy (jen po přihlášení; secure/ je z webu blokované) ──
if (!empty($_GET['raw'])) {
    if (!isset($_SESSION['user_id'])) { http_response_code(401); die('Nepřihlášeno.'); }
    if (!hasPermission('manage_inventory')) { http_response_code(403); die('Bez oprávnění.'); }
    header('Content-Type: text/html; charset=utf-8');
    if (is_file($mapFile)) {
        // mapa je mimo CRM šablonu → CSRF token pro ukládání rozmístění se vstřikuje sem
        echo str_replace('__CSRF_TOKEN__', e((string)($_SESSION['csrf_token'] ?? '')), (string)file_get_contents($mapFile));
    } else {
        echo '<!doctype html><html lang="cs"><head><meta charset="utf-8"><title>3D mapa skladu</title></head>'
           . '<body style="margin:0;display:flex;align-items:center;justify-content:center;height:100vh;'
           . 'background:#0B0B0D;color:#98989F;font-family:-apple-system,sans-serif;text-align:center;">'
           . '<div><div style="font-size:42px;margin-bottom:12px;">🗺️</div>'
           . '<div style="color:#fff;font-weight:600;margin-bottom:6px;">Mapa zatím není nahraná</div>'
           . '<div style="font-size:14px;">Exportuj ji z Claude Design a ulož jako <code>secure/warehouse3d.html</code>.</div></div></body></html>';
    }
    exit;
}

// ── stránka v CRM ──
require_once 'includes/header.php';
ensureStockLocationsSchema();
ensureSkladBranchSchema();

$branchId = (int)skladBranchOrOwn();
$hasMap = is_file($mapFile);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="mb-0">3D mapa skladu <span class="fs-6 text-white-50"><?php echo e(skladBranchLabel($branchId)); ?></span></h2>
        <small class="text-muted">Rozložení regálů, polic a krabiček — data živě ze skladu</small>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <a href="sklad_umisteni.php?branch=<?php echo (int)$branchId; ?>" class="btn btn-outline-info"><i class="fas fa-map-location-dot me-2"></i> Umístění</a>
        <?php if ($hasMap): ?>
            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('mapFrame').src = document.getElementById('mapFrame').src;"><i class="fas fa-rotate-right me-2"></i> Obnovit</button>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/inventory_tabs.php'; ?>

<?php if (!$hasMap): ?>
    <div class="glass-panel p-5 border-secondary text-center text-white-75">
        <i class="fas fa-cube fa-3x mb-3 d-block opacity-25"></i>
        <div class="text-white fw-semibold mb-2">Mapa zatím není nahraná</div>
        <div class="mb-1">1. V <b>Claude Design</b> exportuj vizualizaci jako <b>samostatný HTML soubor</b>.</div>
        <div class="mb-1">2. Ulož ho do repa jako <code>secure/warehouse3d.html</code> a pushni.</div>
        <div>3. V CRM klikni na <b>Nastavení → Aktualizace</b> — mapa se tu objeví sama.</div>
        <div class="small text-white-50 mt-3">Datový endpoint <code>api/warehouse_map_data.php</code> už běží — mapa si stav skladu stáhne živě.</div>
    </div>
<?php else: ?>
    <div class="glass-panel p-2 border-secondary">
        <iframe id="mapFrame" src="sklad_mapa.php?raw=1&amp;branch=<?php echo (int)$branchId; ?>"
                style="width:100%; height:78vh; border:0; border-radius:12px; display:block; background:#0B0B0D;"
                title="3D mapa skladu"></iframe>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
