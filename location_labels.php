<?php
/**
 * Tisk štítků UMÍSTĚNÍ skladu (regály / police / krabičky).
 *   ?id=N              — jeden štítek
 *   ?all=1             — arch všech aktivních umístění
 *   ?type=krabicka     — arch jen daného typu (regal | police | krabicka)
 * QR kód vede na sklad.php?loc=<id> — mobil ukáže obsah umístění
 * (u krabičky seznam dílů; ťuknutím na díl rovnou naskladnění/výdej).
 * Kód umístění je TRVALÝ — štítek platí, i když se krabička přestěhuje.
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
        $stmt = $pdo->prepare($sqlBase . " WHERE l.is_active = 1 AND l.type = ? AND l.branch_id = ? ORDER BY l.code ASC");
        $stmt->execute([$type, $labelBranch]);
        $items = $stmt->fetchAll();
    } elseif (!empty($_GET['all'])) {
        $stmt = $pdo->prepare($sqlBase . " WHERE l.is_active = 1 AND l.branch_id = ? ORDER BY FIELD(l.type,'regal','police','krabicka'), l.code ASC");
        $stmt->execute([$labelBranch]);
        $items = $stmt->fetchAll();
    }
} catch (Throwable $e) { $items = []; }

$base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'admin.applefix.cloud') . dirname($_SERVER['PHP_SELF']);
$base = rtrim(str_replace('\\', '/', $base), '/');
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
    .label .cd { font-size: 19px; font-weight: 800; letter-spacing: .5px; line-height: 1.1; }
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
            <div class="cd"><?php echo e($it['code']); ?></div>
            <?php if (trim((string)$it['name']) !== ''): ?><div class="nm"><?php echo e($it['name']); ?></div><?php endif; ?>
            <div class="mt"><?php echo e(stockLocationTypeLabel((string)$it['type'])); ?><?php echo trim((string)($it['parent_code'] ?? '')) !== '' ? ' · na ' . e($it['parent_code']) : ''; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</body>
</html>
