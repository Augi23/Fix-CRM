<?php
/**
 * Podzáložky sekce Sklad:
 *   Servis   — náhradní díly na opravy (inventory.php; občas prodej dílu koncovému zákazníkovi)
 *   Produkty — bazarová elektronika + příslušenství pro e-shop (products.php)
 *   Nákupy   — objednávání dílů a příjem dodávek (procurement.php) — pod Skladem od v2.9.0
 * Vkládá se hned pod hlavičku všech tří stránek.
 * Práva: Servis/Produkty vidí jen manage_inventory (stejně jako dřív buňku Sklad);
 * Nákupy vidí KAŽDÝ (bez gate — historicky, např. brigádník objednává díly).
 */
$__invTab = basename($_SERVER['PHP_SELF']);
$__invTab = $__invTab === 'products.php' ? 'products'
    : ($__invTab === 'procurement.php' ? 'procurement'
    : ($__invTab === 'model_photos.php' ? 'modelphotos' : 'service'));
?>
<ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary">
    <?php if (hasPermission('manage_inventory')): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'service' ? 'active' : 'text-white-75'; ?>" href="inventory.php"><i class="fas fa-tools me-2"></i>Servis — náhradní díly</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'products' ? 'active' : 'text-white-75'; ?>" href="products.php"><i class="fas fa-mobile-alt me-2"></i>Produkty — e-shop</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'modelphotos' ? 'active' : 'text-white-75'; ?>" href="model_photos.php"><i class="fas fa-images me-2"></i>Fotky modelů</a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'procurement' ? 'active' : 'text-white-75'; ?>" href="procurement.php"><i class="fas fa-truck-loading me-2"></i><?php echo __('procurement'); ?><?php if (!empty($procurementBadgeCount)): ?> <span class="badge bg-warning text-dark ms-1"><?php echo (int)$procurementBadgeCount; ?></span><?php endif; ?></a>
    </li>
</ul>
