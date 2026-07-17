<?php
/**
 * Podzáložky sekce Sklad:
 *   Servis   — náhradní díly na opravy (inventory.php; občas prodej dílu koncovému zákazníkovi)
 *   Produkty — bazarová elektronika + příslušenství pro e-shop (products.php)
 * Vkládá se hned pod hlavičku obou stránek.
 */
$__invTab = basename($_SERVER['PHP_SELF']) === 'products.php' ? 'products' : 'service';
?>
<ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary">
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'service' ? 'active' : 'text-white-75'; ?>" href="inventory.php"><i class="fas fa-tools me-2"></i>Servis — náhradní díly</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__invTab === 'products' ? 'active' : 'text-white-75'; ?>" href="products.php"><i class="fas fa-mobile-alt me-2"></i>Produkty — e-shop</a>
    </li>
</ul>
