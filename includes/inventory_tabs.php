<?php
/**
 * Podzáložky sekce Sklad:
 *   LEVÁ SKUPINA = kategorie skladu:
 *     Servis        — náhradní díly na opravy (inventory.php; občas prodej dílu zákazníkovi)
 *     Produkty      — bazarová elektronika pro e-shop (products.php)
 *     Příslušenství — doplňky (kabely, kryty, boxy, adaptéry…) = products.php?cat=prislusenstvi
 *   PRAVÁ SKUPINA (odsazená přes ms-auto) = nástroje nad skladem:
 *     Fotky modelů (model_photos.php) · Umístění (sklad_umisteni.php) · Nákupy (procurement.php)
 * Vkládá se hned pod hlavičku všech stránek.
 * Práva: kategorie + Fotky/Umístění vidí jen manage_inventory (jako dřív buňka Sklad);
 * Nákupy vidí KAŽDÝ (bez gate — historicky, např. brigádník objednává díly).
 */
$__invTab = basename($_SERVER['PHP_SELF']);
$__invTab = $__invTab === 'products.php' ? 'products'
    : ($__invTab === 'procurement.php' ? 'procurement'
    : ($__invTab === 'model_photos.php' ? 'modelphotos'
    : ($__invTab === 'sklad_umisteni.php' ? 'locations' : 'service')));
$__invCat = (string)($_GET['cat'] ?? '');
$__isAccessory = ($__invTab === 'products' && $__invCat === 'prislusenstvi');
$__isProducts  = ($__invTab === 'products' && !$__isAccessory);
?>
<ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary">
    <?php if (hasPermission('manage_inventory')): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'service' ? 'active' : 'text-white-75'; ?>" href="inventory.php"><i class="fas fa-tools me-2"></i>Servis — náhradní díly</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__isProducts ? 'active' : 'text-white-75'; ?>" href="products.php"><i class="fas fa-mobile-alt me-2"></i>Produkty — e-shop</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__isAccessory ? 'active' : 'text-white-75'; ?>" href="products.php?cat=prislusenstvi"><i class="fas fa-plug me-2"></i>Příslušenství</a>
    </li>
    <li class="nav-item ms-auto">
        <a class="nav-link <?php echo $__invTab === 'modelphotos' ? 'active' : 'text-white-75'; ?>" href="model_photos.php"><i class="fas fa-images me-2"></i>Fotky modelů</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'locations' ? 'active' : 'text-white-75'; ?>" href="sklad_umisteni.php"><i class="fas fa-map-location-dot me-2"></i>Umístění</a>
    </li>
    <?php endif; ?>
    <li class="nav-item <?php echo hasPermission('manage_inventory') ? '' : 'ms-auto'; ?>">
        <a class="nav-link <?php echo $__invTab === 'procurement' ? 'active' : 'text-white-75'; ?>" href="procurement.php"><i class="fas fa-truck-loading me-2"></i><?php echo __('procurement'); ?><?php if (!empty($procurementBadgeCount)): ?> <span class="badge bg-warning text-dark ms-1"><?php echo (int)$procurementBadgeCount; ?></span><?php endif; ?></a>
    </li>
</ul>
