<?php
/**
 * Rozcestník Skladu — výběr pobočky (dvě velké Liquid Glass karty).
 * Vkládá inventory.php, když není vybraná pobočka (?branch chybí/neplatné).
 * Vidí obě pobočky každý; kdo na pobočce nepracuje, má na kartě „jen prohlížení".
 */
if (!function_exists('skladBranchLabel')) { return; }
$__branches = getBranches(true);
$__counts = [];
foreach ($__branches as $__b) {
    $bid = (int)$__b['id'];
    try { $inv = (int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE branch_id = $bid AND " . inventoryStockedWhereSql())->fetchColumn(); }
    catch (Throwable $e) { $inv = 0; }
    try { $prod = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE branch_id = $bid")->fetchColumn(); }
    catch (Throwable $e) { $prod = 0; }
    $__counts[$bid] = ['inv' => $inv, 'prod' => $prod];
}
$__icons = ['karlin' => 'fa-warehouse', 'prikope' => 'fa-store'];
?>
<div class="mb-2">
    <h2 class="mb-0"><?php echo __('inventory'); ?></h2>
    <small class="text-muted">Vyber pobočku skladu — každá má vlastní zásoby</small>
</div>

<div class="sklad-branch-picker">
    <?php foreach ($__branches as $__b): $bid = (int)$__b['id']; $code = (string)$__b['code']; $c = $__counts[$bid]; ?>
    <a href="inventory.php?branch=<?php echo $bid; ?>" class="sklad-branch-card" data-afx-glass="panel">
        <span class="sklad-branch-ico"><i class="fas <?php echo $__icons[$code] ?? 'fa-boxes'; ?>"></i></span>
        <span class="sklad-branch-name"><?php echo e(skladBranchLabel($bid)); ?></span>
        <?php if (trim((string)($__b['address'] ?? '')) !== ''): ?>
            <span class="sklad-branch-addr"><?php echo e((string)$__b['address']); ?></span>
        <?php endif; ?>
        <span class="sklad-branch-stats"><?php echo $c['inv']; ?> dílů · <?php echo $c['prod']; ?> produktů</span>
        <?php if (!crmCanModifyBranchStock($bid)): ?>
            <span class="sklad-branch-ro"><i class="fas fa-eye me-1"></i>jen prohlížení</span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<style>
.sklad-branch-picker{display:flex;gap:30px;justify-content:center;flex-wrap:wrap;padding:46px 12px 64px;}
.sklad-branch-card{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;
    text-align:center;gap:9px;width:344px;max-width:44vw;min-height:288px;padding:46px 30px;border-radius:28px;
    text-decoration:none;color:#eaf2ff;overflow:hidden;
    background:rgba(255,255,255,.055);border:1px solid rgba(255,255,255,.14);
    backdrop-filter:blur(16px) saturate(1.35);-webkit-backdrop-filter:blur(16px) saturate(1.35);
    box-shadow:0 26px 64px rgba(0,0,0,.42);
    transition:transform .18s cubic-bezier(.34,1.56,.64,1),box-shadow .18s ease;}
.sklad-branch-card:hover{transform:translateY(-6px) scale(1.02);box-shadow:0 34px 80px rgba(0,0,0,.5);color:#fff;}
.sklad-branch-ico{font-size:56px;line-height:1;margin-bottom:8px;color:#55d4ff;filter:drop-shadow(0 6px 22px rgba(85,212,255,.55));}
.sklad-branch-name{font-size:23px;font-weight:700;letter-spacing:-.01em;}
.sklad-branch-addr{font-size:14px;opacity:.72;font-weight:500;}
.sklad-branch-stats{margin-top:6px;font-size:13.5px;font-weight:600;color:#3be8a8;}
.sklad-branch-ro{position:absolute;top:14px;right:16px;font-size:12px;opacity:.72;font-weight:500;}
html[data-lg-theme="light"] .sklad-branch-card{color:#0b1a2b;background:rgba(255,255,255,.62);border-color:rgba(0,0,0,.08);box-shadow:0 22px 54px rgba(0,40,90,.16);}
html[data-lg-theme="light"] .sklad-branch-card:hover{color:#000;}
html[data-lg-theme="light"] .sklad-branch-ico{color:#0a84ff;}
html[data-lg-theme="light"] .sklad-branch-stats{color:#0a7d55;}
@media(max-width:560px){.sklad-branch-card{width:100%;max-width:92vw;min-height:210px;padding:34px 24px;}}
</style>
