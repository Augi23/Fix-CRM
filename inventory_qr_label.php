<?php
/**
 * Tisk QR štítků na regály skladu.
 *   ?id=N   — jeden štítek konkrétního dílu
 *   ?all=1  — arch se štítky všech naskladněných dílů (tisk na A4 / řezané štítky)
 * QR kód vede na sklad.php?qr=<id> (naskladnění / výdej mobilem).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$one = (int)($_GET['id'] ?? 0);
$items = [];
if ($one > 0) {
    $stmt = $pdo->prepare("SELECT id, part_name, sku, sale_price FROM inventory WHERE id = ?");
    $stmt->execute([$one]);
    $items = $stmt->fetchAll();
} elseif (!empty($_GET['all'])) {
    $items = $pdo->query("SELECT id, part_name, sku, sale_price FROM inventory WHERE " . inventoryStockedWhereSql() . " ORDER BY part_name ASC")->fetchAll();
}
$base = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'admin.applefix.cloud') . dirname($_SERVER['PHP_SELF']);
$base = rtrim(str_replace('\\', '/', $base), '/');
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>QR štítky skladu</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, 'SF Pro Text', 'Segoe UI', sans-serif; background: #fff; color: #000; padding: 8mm; }
    .toolbar { margin-bottom: 8mm; display: flex; gap: 8px; }
    .toolbar button, .toolbar a { padding: 8px 18px; border: 1px solid #999; border-radius: 8px; background: #f2f2f2; color: #000; text-decoration: none; font-size: 14px; cursor: pointer; }
    .sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; }
    .label {
        border: 1.2px dashed #888; border-radius: 3mm; padding: 3mm;
        display: flex; align-items: center; gap: 3mm;
        break-inside: avoid; page-break-inside: avoid; min-height: 30mm;
    }
    .label img { width: 24mm; height: 24mm; flex: 0 0 auto; }
    .label .nm { font-size: 11.5px; font-weight: 700; line-height: 1.25; word-break: break-word; }
    .label .mt { font-size: 10px; color: #333; margin-top: 1.5mm; }
    @media print { .toolbar { display: none; } body { padding: 4mm; } .label { border-color: #bbb; } }
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">🖨 Tisknout</button>
    <a href="inventory.php">← Zpět na sklad</a>
    <?php if ($one): ?><a href="inventory_qr_label.php?all=1">Arch všech dílů</a><?php endif; ?>
</div>
<?php if (!$items): ?>
    <p>Žádné díly k tisku. <a href="inventory.php">Zpět na sklad</a></p>
<?php else: ?>
<div class="sheet">
    <?php foreach ($items as $it):
        $url = $base . '/sklad.php?qr=' . (int)$it['id']; ?>
    <div class="label">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&margin=0&data=<?php echo urlencode($url); ?>" alt="QR">
        <div>
            <div class="nm"><?php echo e($it['part_name']); ?></div>
            <div class="mt"><?php echo $it['sku'] ? 'SKU: ' . e($it['sku']) : '&nbsp;'; ?></div>
            <div class="mt"><?php echo number_format((float)$it['sale_price'], 0, ',', ' '); ?> Kč · díl #<?php echo (int)$it['id']; ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</body>
</html>
