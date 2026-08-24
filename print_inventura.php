<?php
/**
 * Inventurní soupis produktů skladem — tisková stránka A4 (schválený vzhled 24. 8. 2026).
 * print_inventura.php[?prodejna=karlin|vaclavak][&auto=1 → rovnou tiskový dialog]
 *
 * Vypisuje VŠECHNY kusy se stock_qty > 0 (tj. to, co nabízí e-shop; kusy skryté
 * z e-shopu jsou v regálu taky, jen nesou poznámku „není na e-shopu"). Na konci
 * každého řádku je prázdný čtvereček na odškrtnutí propiskou. Čistě ČTECÍ stránka.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!crmCanUsePos() && !(function_exists('crmCanAccountingRead') && crmCanAccountingRead())) { die(__('unauthorized')); }
// deaktivovaný zaměstnanec s ještě živou relací nemá co tisknout ceník skladu
if (!empty($_SESSION['_staff_revoked']) && function_exists('crmKickRevokedStaff')) { crmKickRevokedStaff(); }

ensureProductsTable();
ensureProductsHideEshopColumn();
ensureProductsLoanColumns();

$prodejny = ['karlin' => 'Karlín', 'vaclavak' => 'Černá Růže'];
$filtr = is_string($_GET['prodejna'] ?? null) ? (string)$_GET['prodejna'] : '';
if (!isset($prodejny[$filtr])) { $filtr = ''; }

// Zapůjčené/komisní kusy mají stock_qty > 0, ale fyzicky v prodejně NEJSOU
// (e-shop je taky nenabízí) — v soupisu zůstávají s poznámkou „zapůjčeno",
// ať je inventura nehlásí jako ztracené a součet je poctivý.
$sql = "SELECT title, product_code, price, stock_qty, stock_key, hide_eshop, loan_at
        FROM products WHERE stock_qty > 0";
$par = [];
if ($filtr !== '') { $sql .= " AND stock_key = ?"; $par[] = $filtr; }
$sql .= " ORDER BY stock_key, title, id";
$st = $pdo->prepare($sql);
$st->execute($par);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$items = count($rows);
$kusy = 0;
$loanKusy = 0;
foreach ($rows as $r) {
    $kusy += (int)$r['stock_qty'];
    if (!empty($r['loan_at'])) { $loanKusy += (int)$r['stock_qty']; }
}

// celé Kč; netypické haléře se ukážou, ať soupis sedí na korunu (vzor účtenky)
$money = static function ($v): string {
    $v = (float)$v;
    return (abs($v - round($v)) < 0.005)
        ? number_format($v, 0, ',', ' ') . ' Kč'
        : number_format($v, 2, ',', ' ') . ' Kč';
};
?><!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<title>Inventurní soupis — produkty skladem</title>
<style>
@page { size: A4 portrait; margin: 11mm 10mm 13mm 10mm; }
* { box-sizing: border-box; }
body { font: 9px/1.32 -apple-system, "Helvetica Neue", Arial, sans-serif; color: #000; margin: 0; background: #fff; }
.head { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2.2px solid #000; padding-bottom: 6px; margin-bottom: 6px; }
.head h1 { font-size: 15px; margin: 0; letter-spacing: .02em; }
.head .sub { font-size: 9.5px; color: #333; margin-top: 2px; }
.head .meta { text-align: right; font-size: 9.5px; line-height: 1.45; }
table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
th { text-align: left; font-size: 8px; text-transform: uppercase; letter-spacing: .05em; color: #444;
     border-bottom: 1.4px solid #000; padding: 2px 4px; }
td { border-bottom: .6px solid #bbb; padding: 2.6px 4px; vertical-align: middle; }
tr { page-break-inside: avoid; }
.grp td { background: #000; color: #fff; font-weight: 700; font-size: 9.5px; padding: 3.4px 6px; letter-spacing: .04em; border: 0;
          -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.num { width: 26px; color: #666; text-align: right; }
.name { font-weight: 600; }
.note { font-weight: 400; color: #777; font-style: italic; }
.code { width: 108px; font-family: "SF Mono", Menlo, monospace; font-size: 8px; color: #333; }
.price { width: 68px; text-align: right; white-space: nowrap; }
.qty { width: 30px; text-align: center; font-weight: 700; }
.chk { width: 34px; text-align: center; }
.box { display: inline-block; width: 4.2mm; height: 4.2mm; border: 1.3px solid #000; border-radius: 1px; vertical-align: middle; }
.foot { margin-top: 9mm; display: flex; gap: 12mm; page-break-inside: avoid; font-size: 10px; }
.foot .line { flex: 1; border-top: 1px solid #000; padding-top: 3px; color: #333; }
.empty { padding: 14mm 0; text-align: center; color: #555; font-size: 12px; }
/* lišta jen na obrazovce — do tisku nejde */
.toolbar { display: flex; gap: 8px; align-items: center; padding: 10px 12px; background: #f2f2f5; border-bottom: 1px solid #ccc;
           margin: 0 0 12px; font-size: 13px; position: sticky; top: 0; }
.toolbar a, .toolbar button { font: inherit; text-decoration: none; border: 1px solid #999; background: #fff; color: #111;
           padding: 5px 12px; border-radius: 7px; cursor: pointer; }
.toolbar a.on { background: #111; color: #fff; border-color: #111; }
.toolbar .p { margin-left: auto; font-weight: 700; }
@media print { .toolbar { display: none; } }
@media screen { body { padding: 0 16px 24px; max-width: 900px; margin: 0 auto; } }
</style>
</head>
<body>
<div class="toolbar">
    <span>Prodejna:</span>
    <a href="print_inventura.php" class="<?php echo $filtr === '' ? 'on' : ''; ?>">Vše</a>
    <?php foreach ($prodejny as $pk => $pn): ?>
    <a href="print_inventura.php?prodejna=<?php echo e($pk); ?>" class="<?php echo $filtr === $pk ? 'on' : ''; ?>"><?php echo e($pn); ?></a>
    <?php endforeach; ?>
    <button type="button" class="p" onclick="window.print()">🖨️ Vytisknout</button>
</div>

<div class="head">
    <div>
        <h1>INVENTURNÍ SOUPIS — produkty skladem</h1>
        <div class="sub"><?php echo e((string)get_setting('company_name', 'AppleFix s.r.o.')); ?> ·
            kusy nabízené na e-shopu i skladem na prodejně · odškrtni zkontrolované<?php
            echo $filtr !== '' ? ' · jen prodejna ' . e($prodejny[$filtr]) : ''; ?></div>
    </div>
    <div class="meta"><b><?php echo date('j. n. Y'); ?></b><br><?php echo $items; ?> položek · <?php echo $kusy; ?> ks<?php
        if ($loanKusy > 0): ?><br><span style="font-weight:400;color:#555;">z toho zapůjčeno <?php echo $loanKusy; ?> ks</span><?php endif; ?></div>
</div>

<?php if (!$rows): ?>
<div class="empty">Žádné produkty skladem<?php echo $filtr !== '' ? ' na prodejně ' . e($prodejny[$filtr]) : ''; ?>.</div>
<?php else: ?>
<table>
<thead><tr>
    <th class="num">Č.</th><th>Produkt</th><th>Kód / SN</th>
    <th class="price" style="text-align:right">Cena</th>
    <th class="qty" style="text-align:center">Ks</th>
    <th class="chk" style="text-align:center">✓</th>
</tr></thead>
<tbody>
<?php
$curKey = null;
$n = 0;
foreach ($rows as $r):
    if ($r['stock_key'] !== $curKey):
        $curKey = $r['stock_key'];
        ?><tr class="grp"><td colspan="6">Prodejna <?php echo e($prodejny[$curKey] ?? ((string)$curKey !== '' ? (string)$curKey : '—')); ?></td></tr><?php
    endif;
    $n++;
    ?><tr>
        <td class="num"><?php echo $n; ?></td>
        <td class="name"><?php echo e((string)$r['title']); ?><?php
            if (!empty($r['loan_at'])): ?> <span class="note">· zapůjčeno — fyzicky mimo prodejnu</span><?php endif; ?><?php
            if ((int)($r['hide_eshop'] ?? 0) === 1): ?> <span class="note">· není na e-shopu</span><?php endif; ?></td>
        <td class="code"><?php echo e((string)($r['product_code'] ?? '')); ?></td>
        <td class="price"><?php echo e($money($r['price'])); ?></td>
        <td class="qty"><?php echo (int)$r['stock_qty']; ?></td>
        <td class="chk"><span class="box"></span></td>
    </tr><?php
endforeach; ?>
</tbody>
</table>

<div class="foot">
    <div class="line">Zkontroloval(a)</div>
    <div class="line">Podpis</div>
    <div class="line">Datum kontroly</div>
</div>
<?php endif; ?>

<?php if ((string)($_GET['auto'] ?? '') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
<?php endif; ?>
</body>
</html>
