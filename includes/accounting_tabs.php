<?php
/**
 * Podzáložky sekce Účetnictví:
 *   Faktury — vystavené faktury a dobropisy (accounting.php)
 *   Banka   — napojený účet KB, pohyby a párování plateb (banka.php)
 * Vkládá se hned pod hlavičku obou stránek (stejný vzor jako Sklad).
 */
$__accTab = basename($_SERVER['PHP_SELF']) === 'banka.php' ? 'banka' : 'faktury';
?>
<ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary">
    <li class="nav-item">
        <a class="nav-link <?php echo $__accTab === 'faktury' ? 'active' : 'text-white-75'; ?>" href="accounting.php"><i class="fas fa-file-invoice-dollar me-2"></i>Faktury</a>
    </li>
    <?php if (crmCanManageInvoices()): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $__accTab === 'banka' ? 'active' : 'text-white-75'; ?>" href="banka.php"><i class="fas fa-building-columns me-2"></i>Banka</a>
    </li>
    <?php endif; ?>
</ul>
