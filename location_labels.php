<?php
/**
 * Tisk štítků UMÍSTĚNÍ skladu (regály / police / krabičky).
 *   ?id=N              — jeden štítek
 *   ?all=1             — arch všech aktivních umístění
 *   ?type=krabicka     — arch jen daného typu (regal | police | krabicka)
 * QR kód vede na sklad.php?loc=<id> — mobil ukáže obsah umístění
 * (u krabičky seznam dílů; ťuknutím na díl rovnou naskladnění/výdej).
 * Kód KRABIČKY je TRVALÝ — štítek platí, i když se krabička přestěhuje.
 * Kód POLICE obsahuje regál (RegK1-P2), takže po přesunu police na jiný regál
 * se přečísluje a štítek se tiskne znovu.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
// rozvržení skladu (kde co leží) je provozní informace — stejné právo jako sklad
if (!hasPermission('manage_inventory')) { header('Location: index.php'); exit; }
ensureStockLocationsSchema();

$one = (int)($_GET['id'] ?? 0);
$type = (string)($_GET['type'] ?? '');
// arch štítků je POBOČKOVÝ — jinak by z Karlína vyjely i štítky regálů Na Příkopě
ensureSkladBranchSchema();
$labelBranch = (int)skladBranchOrOwn();
$items = [];
try {
    $sqlBase = "SELECT l.*, p.code AS parent_code FROM stock_locations l LEFT JOIN stock_locations p ON p.id = l.parent_id";
    if ($one > 0) {
        $stmt = $pdo->prepare($sqlBase . " WHERE l.id = ?");
        $stmt->execute([$one]);
        $items = $stmt->fetchAll();
    } elseif (in_array($type, ['regal', 'police', 'krabicka'], true)) {
        $stmt = $pdo->prepare($sqlBase . " WHERE l.is_active = 1 AND l.type = ? AND l.branch_id = ? ORDER BY LENGTH(l.code) ASC, l.code ASC");
        $stmt->execute([$type, $labelBranch]);
        $items = $stmt->fetchAll();
    } elseif (!empty($_GET['all'])) {
        $stmt = $pdo->prepare($sqlBase . " WHERE l.is_active = 1 AND l.branch_id = ? ORDER BY FIELD(l.type,'regal','police','krabicka'), LENGTH(l.code) ASC, l.code ASC");
        $stmt->execute([$labelBranch]);
        $items = $stmt->fetchAll();
    }
} catch (Throwable $e) { $items = []; }

// na štítku je VELKÉ značení R-P-B (jak ho vidí obsluha u dílů) + malá neměnná
// identita (KrK028) — QR míří na id, takže platí i po přestěhování krabičky
$labelPos = [];
try { $labelPos = stockLocationPosCodes($pdo, array_column($items, 'id')); } catch (Throwable $e) {}

$base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'admin.applefix.cloud') . dirname($_SERVER['PHP_SELF']);
$base = rtrim(str_replace('\\', '/', $base), '/');

/* ── Rozvržení PRUHY: 4 štítky přes celou šířku A4 (na výšku). ──────────────────
 *  ?layout=strip[&type=regal|police]  — REGÁLY (1 regál = 1 pruh) a/nebo POLICE
 *  (1 pruh = VŠECHNY police jednoho regálu). QR na každém štítku → sklad.php?loc=id
 *  ukáže obsah. Bez type se tisknou nejdřív regály, pak police (každé na svém archu). */
if (($_GET['layout'] ?? '') === 'strip') {
    $stype = in_array($type, ['regal', 'police'], true) ? $type : 'all';

    $rk = $pdo->prepare("SELECT * FROM stock_locations WHERE type='regal' AND is_active=1 AND branch_id=? ORDER BY LENGTH(code), code");
    $rk->execute([$labelBranch]);
    $racks = $rk->fetchAll();

    $shByRack = [];
    $sh = $pdo->prepare("SELECT * FROM stock_locations WHERE type='police' AND is_active=1 AND branch_id=? ORDER BY parent_id, LENGTH(code), code");
    $sh->execute([$labelBranch]);
    foreach ($sh->fetchAll() as $s) { $shByRack[(int)$s['parent_id']][] = $s; }

    $ids = array_column($racks, 'id');
    foreach ($shByRack as $arr) { foreach ($arr as $s) { $ids[] = (int)$s['id']; } }
    $pos = [];
    try { $pos = stockLocationPosCodes($pdo, $ids); } catch (Throwable $e) {}
    $posOf = function ($it) use ($pos) { return $pos[(int)$it['id']] ?? (string)$it['code']; };
    $qr = function ($id, $px = 220) use ($base) {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . (int)$px . 'x' . (int)$px . '&margin=0&data='
             . urlencode($base . '/sklad.php?loc=' . (int)$id);
    };

    // jeden pruh REGÁLU: jen velké značení (bez QR) — čistá identifikace regálu
    $rackStrip = function ($r) use ($posOf) {
        ob_start(); ?>
        <div class="strip strip-rack">
            <div class="s-big"><?php echo e($posOf($r)); ?></div>
            <div class="s-sub"><?php echo e($r['code']); ?><?php if (trim((string)$r['name']) !== ''): ?> · <?php echo e($r['name']); ?><?php endif; ?></div>
        </div>
    <?php return ob_get_clean(); };

    // jeden pruh POLIC: záhlaví regálu + všechny police (kód + malý QR na obsah police)
    $shelfStrip = function ($r, $shelves) use ($posOf, $qr) {
        ob_start(); ?>
        <div class="strip strip-shelf">
            <div class="s-head">
                <div class="s-big s-big-sm"><?php echo e($posOf($r)); ?></div>
                <div class="s-sub"><?php echo e($r['code']); ?><?php if (trim((string)$r['name']) !== ''): ?> · <?php echo e($r['name']); ?><?php endif; ?><span class="s-kind s-kind-inline">POLICE</span></div>
            </div>
            <div class="s-cells">
                <?php foreach ($shelves as $sh2): ?>
                    <div class="cell">
                        <img src="<?php echo e($qr($sh2['id'], 150)); ?>" alt="QR">
                        <div class="cell-cd"><?php echo e($posOf($sh2)); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php return ob_get_clean(); };

    // pomocník: pole HTML pruhů → stránky po 4
    $paginate = function (array $strips) {
        $out = '';
        $chunks = array_chunk($strips, 4);
        foreach ($chunks as $ch) {
            $out .= '<div class="page">';
            foreach ($ch as $s) { $out .= $s; }
            // doplnit prázdné pruhy, ať jsou vždy 4 stejné výšky
            for ($i = count($ch); $i < 4; $i++) { $out .= '<div class="strip strip-empty"></div>'; }
            $out .= '</div>';
        }
        return $out;
    };

    $rackStrips = [];
    foreach ($racks as $r) { $rackStrips[] = $rackStrip($r); }
    $shelfStrips = [];
    foreach ($racks as $r) {
        $shelves = $shByRack[(int)$r['id']] ?? [];
        if ($shelves) { $shelfStrips[] = $shelfStrip($r, $shelves); }
    }
    ?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>Štítky regálů a polic (arch A4)</title>
<style>
    @page { size: A4 portrait; margin: 0; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, 'SF Pro Text', 'Segoe UI', Arial, sans-serif; background: #e9e9ec; color: #000; }
    .toolbar { display: flex; gap: 8px; flex-wrap: wrap; padding: 8mm; }
    .toolbar button, .toolbar a { padding: 8px 18px; border: 1px solid #999; border-radius: 8px; background: #f7f7f7; color: #000; text-decoration: none; font-size: 14px; cursor: pointer; }
    .toolbar a.active { background: #0a84ff; color: #fff; border-color: #0a84ff; }
    .page {
        width: 210mm; height: 297mm; padding: 6mm; margin: 0 auto 6mm; background: #fff;
        display: flex; flex-direction: column; gap: 4mm; page-break-after: always; box-shadow: 0 2px 12px rgba(0,0,0,.18);
    }
    .strip {
        flex: 1 1 0; min-height: 0; border: 1.4px dashed #9aa0a6; border-radius: 4mm; padding: 5mm 7mm;
        display: flex; align-items: center; gap: 6mm; break-inside: avoid; overflow: hidden;
    }
    .strip-empty { border-color: #e6e6e6; }
    /* REGÁL — jen velký kód, vycentrovaný přes celý pruh */
    .strip-rack { flex-direction: column; justify-content: center; align-items: center; text-align: center; gap: 1mm; }
    .s-big { font-size: 92pt; font-weight: 800; line-height: .92; letter-spacing: 1px; }
    .s-sub { font-size: 17pt; font-weight: 600; color: #444; }
    .s-kind { font-size: 11pt; font-weight: 700; letter-spacing: 3px; color: #6b7178; margin-top: 2mm; }
    /* POLICE */
    .strip-shelf { flex-direction: column; align-items: stretch; gap: 3mm; }
    .strip-shelf .s-head { display: flex; align-items: baseline; gap: 5mm; }
    .s-big-sm { font-size: 30pt; }
    .s-kind-inline { display: inline; margin-left: 4mm; letter-spacing: 2px; }
    .s-cells { display: flex; gap: 5mm; flex-wrap: nowrap; align-items: flex-start; }
    .strip-shelf .cell { text-align: center; flex: 1 1 0; min-width: 0; }
    .strip-shelf .cell img { width: 20mm; height: 20mm; max-width: 100%; }
    .cell-cd { font-size: 12pt; font-weight: 800; margin-top: 1mm; word-break: break-word; }
    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .page { margin: 0; box-shadow: none; width: auto; height: 100vh; }
        .strip { border-color: #c4c4c4; }
    }
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">🖨 Tisknout</button>
    <a href="sklad_umisteni.php">← Zpět na umístění</a>
    <a href="location_labels.php?layout=strip&amp;type=regal&amp;branch=<?php echo (int)$labelBranch; ?>"<?php echo $stype==='regal'?' class="active"':''; ?>>Jen regály</a>
    <a href="location_labels.php?layout=strip&amp;type=police&amp;branch=<?php echo (int)$labelBranch; ?>"<?php echo $stype==='police'?' class="active"':''; ?>>Jen police</a>
    <a href="location_labels.php?layout=strip&amp;branch=<?php echo (int)$labelBranch; ?>"<?php echo $stype==='all'?' class="active"':''; ?>>Regály + police</a>
</div>
<?php
    if (!$racks) { echo '<p style="padding:8mm">Žádné regály k tisku. <a href="sklad_umisteni.php">Zpět na umístění</a></p>'; }
    if ($stype === 'regal' || $stype === 'all') { echo $paginate($rackStrips); }
    if ($stype === 'police' || $stype === 'all') { echo $paginate($shelfStrips); }
?>
</body>
</html>
<?php
    exit;
}
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>Štítky umístění skladu</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, 'SF Pro Text', 'Segoe UI', sans-serif; background: #fff; color: #000; padding: 8mm; }
    .toolbar { margin-bottom: 8mm; display: flex; gap: 8px; flex-wrap: wrap; }
    .toolbar button, .toolbar a { padding: 8px 18px; border: 1px solid #999; border-radius: 8px; background: #f2f2f2; color: #000; text-decoration: none; font-size: 14px; cursor: pointer; }
    .sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; }
    .label {
        border: 1.2px dashed #888; border-radius: 3mm; padding: 3mm;
        display: flex; align-items: center; gap: 3mm;
        break-inside: avoid; page-break-inside: avoid; min-height: 30mm;
    }
    .label img { width: 24mm; height: 24mm; flex: 0 0 auto; }
    .label .cd { font-size: 19px; font-weight: 800; letter-spacing: .5px; line-height: 1.1;  word-break: break-word; overflow-wrap: anywhere; }
    .label .nm { font-size: 11.5px; font-weight: 700; line-height: 1.25; word-break: break-word; margin-top: 1mm; }
    .label .mt { font-size: 10px; color: #333; margin-top: 1mm; }
    @media print { .toolbar { display: none; } body { padding: 4mm; } .label { border-color: #bbb; } }
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">🖨 Tisknout</button>
    <a href="sklad_umisteni.php">← Zpět na umístění</a>
    <a href="location_labels.php?type=krabicka&amp;branch=<?php echo (int)$labelBranch; ?>">Jen krabičky</a>
    <a href="location_labels.php?type=police&amp;branch=<?php echo (int)$labelBranch; ?>">Jen police</a>
    <a href="location_labels.php?type=regal&amp;branch=<?php echo (int)$labelBranch; ?>">Jen regály</a>
    <a href="location_labels.php?all=1&amp;branch=<?php echo (int)$labelBranch; ?>">Vše</a>
</div>
<?php if (!$items): ?>
    <p>Žádná umístění k tisku. <a href="sklad_umisteni.php">Zpět na umístění</a></p>
<?php else: ?>
<div class="sheet">
    <?php foreach ($items as $it):
        $url = $base . '/sklad.php?loc=' . (int)$it['id']; ?>
    <div class="label">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=0&data=<?php echo urlencode($url); ?>" alt="QR">
        <div>
            <?php $__lp = $labelPos[(int)$it['id']] ?? (string)$it['code']; ?>
            <div class="cd"><?php echo e($__lp); ?></div>
            <?php if (trim((string)$it['name']) !== ''): ?><div class="nm"><?php echo e($it['name']); ?></div><?php endif; ?>
            <div class="mt"><?php echo e(stockLocationTypeLabel((string)$it['type'])); ?><?php echo $__lp !== (string)$it['code'] ? ' · ' . e($it['code']) : ''; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</body>
</html>
