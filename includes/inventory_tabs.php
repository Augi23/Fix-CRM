<?php
/**
 * Podzáložky sekce Sklad — pobočkově oddělené (v3.28.0).
 *   Nad záložkami: pruh s aktuální POBOČKOU + „Přepnout pobočku" (→ rozcestník).
 *   LEVÁ SKUPINA = kategorie: Servis · Produkty · Příslušenství
 *   PRAVÁ SKUPINA (ms-auto): Fotky modelů (JEN Karlín — spravuje e-shop) · Umístění · Nákupy
 * Všechny odkazy nesou ?branch=N. Práva: kategorie + Fotky/Umístění vidí jen manage_inventory;
 * Nákupy vidí každý. Vidět smí obě pobočky; MĚNIT jen zaměstnanec dané pobočky (řeší stránky).
 */
$__invTab = basename($_SERVER['PHP_SELF']);
$__invTab = $__invTab === 'products.php' ? 'products'
    : ($__invTab === 'procurement.php' ? 'procurement'
    : ($__invTab === 'model_photos.php' ? 'modelphotos'
    : ($__invTab === 'sklad_presuny.php' ? 'presuny'
    : ($__invTab === 'sklad_umisteni.php' ? 'locations' : 'service'))));
$__invCat = (string)($_GET['cat'] ?? '');
$__isAccessory = ($__invTab === 'products' && $__invCat === 'prislusenstvi');
$__isVykup     = ($__invTab === 'products' && $__invCat === 'vykupy');
$__isProducts  = ($__invTab === 'products' && !$__isAccessory && !$__isVykup);
$__skladBranch = function_exists('skladBranchOrOwn') ? skladBranchOrOwn() : (int)($_GET['branch'] ?? 0);
$__bq = '?branch=' . (int)$__skladBranch;
$__karlinId = function_exists('getDefaultBranchId') ? getDefaultBranchId() : 1;
$__isKarlin = ((int)$__skladBranch === (int)$__karlinId);
$__trDraft = 0;
try { $__trDraft = (int)$pdo->query("SELECT COUNT(*) FROM stock_transfer_items ti JOIN stock_transfers t ON t.id = ti.transfer_id WHERE t.from_branch_id = " . (int)$__skladBranch . " AND t.status = 'draft'")->fetchColumn(); } catch (Throwable $e) {}
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <span class="badge rounded-pill" style="background:rgba(85,212,255,.16);color:#7fe3ff;border:1px solid rgba(85,212,255,.35);font-size:13px;padding:.5em .95em;">
        <i class="fas <?php echo $__isKarlin ? 'fa-warehouse' : 'fa-store'; ?> me-1"></i><?php echo e(skladBranchLabel((int)$__skladBranch)); ?>
    </span>
    <a href="inventory.php" class="btn btn-sm btn-outline-secondary" title="Zpět na výběr pobočky skladu"><i class="fas fa-right-left me-1"></i>Přepnout pobočku</a>
    <?php /* Vysvětlivky k akčním ikonám na konci řádků — dle záložky (produkty vs. díly) */ ?>
    <?php if ($__invTab === 'products' || $__invTab === 'service'): ?>
    <span class="ms-auto afx-row-legend d-none d-lg-inline-flex align-items-center flex-wrap">
        <?php if ($__invTab === 'products'): ?>
            <span><i class="fas fa-tag text-info"></i>Cenový štítek</span>
            <span><i class="fas fa-hand-holding-heart" style="color:#8B5CF6"></i>Zapůjčení</span>
            <span><i class="fas fa-edit text-warning"></i>Upravit</span>
            <span><i class="fas fa-right-left text-info"></i>Přesun na pobočku</span>
            <span><i class="fas fa-trash text-danger"></i>Smazat</span>
        <?php else: ?>
            <span><i class="fas fa-truck-loading text-success"></i>Naskladnit</span>
            <span><i class="fas fa-qrcode text-info"></i>QR štítek</span>
            <span><i class="fas fa-edit text-warning"></i>Upravit</span>
            <span><i class="fas fa-link text-success"></i>Na zakázku</span>
            <span><i class="fas fa-right-left text-info"></i>Přesun na pobočku</span>
            <span><i class="fas fa-trash text-danger"></i>Smazat</span>
        <?php endif; ?>
    </span>
    <?php endif; ?>
</div>
<ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary">
    <?php if (hasPermission('manage_inventory')): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'service' ? 'active' : 'text-white-75'; ?>" href="inventory.php<?php echo $__bq; ?>"><i class="fas fa-tools me-2"></i>Servis — náhradní díly</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__isProducts ? 'active' : 'text-white-75'; ?>" href="products.php<?php echo $__bq; ?>"><i class="fas fa-mobile-alt me-2"></i>Produkty — e-shop</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__isAccessory ? 'active' : 'text-white-75'; ?>" href="products.php<?php echo $__bq; ?>&amp;cat=prislusenstvi"><i class="fas fa-plug me-2"></i>Příslušenství</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__isVykup ? 'active' : 'text-white-75'; ?>" href="products.php<?php echo $__bq; ?>&amp;cat=vykupy"><i class="fas fa-hand-holding-dollar me-2"></i>Výkupy</a>
    </li>
    <?php if ($__isKarlin): ?>
    <li class="nav-item ms-auto">
        <a class="nav-link <?php echo $__invTab === 'modelphotos' ? 'active' : 'text-white-75'; ?>" href="model_photos.php<?php echo $__bq; ?>"><i class="fas fa-images me-2"></i>Fotky modelů</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'locations' ? 'active' : 'text-white-75'; ?>" href="sklad_umisteni.php<?php echo $__bq; ?>"><i class="fas fa-map-location-dot me-2"></i>Umístění</a>
    </li>
    <?php else: ?>
    <li class="nav-item ms-auto">
        <a class="nav-link <?php echo $__invTab === 'locations' ? 'active' : 'text-white-75'; ?>" href="sklad_umisteni.php<?php echo $__bq; ?>"><i class="fas fa-map-location-dot me-2"></i>Umístění</a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'presuny' ? 'active' : 'text-white-75'; ?>" href="sklad_presuny.php<?php echo $__bq; ?>"><i class="fas fa-right-left me-2"></i>Přesuny<?php if (!empty($__trDraft)): ?> <span class="badge bg-warning text-dark ms-1"><?php echo (int)$__trDraft; ?></span><?php endif; ?></a>
    </li>
    <?php endif; ?>
    <li class="nav-item <?php echo hasPermission('manage_inventory') ? '' : 'ms-auto'; ?>">
        <a class="nav-link <?php echo $__invTab === 'procurement' ? 'active' : 'text-white-75'; ?>" href="procurement.php<?php echo $__bq; ?>"><i class="fas fa-truck-loading me-2"></i><?php echo __('procurement'); ?><?php if (!empty($procurementBadgeCount)): ?> <span class="badge bg-warning text-dark ms-1"><?php echo (int)$procurementBadgeCount; ?></span><?php endif; ?></a>
    </li>
</ul>
