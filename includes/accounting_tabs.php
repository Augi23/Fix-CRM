<?php
/**
 * Podzáložky sekce Účetnictví:
 *   Faktury  — vystavené faktury a dobropisy (accounting.php)
 *   Prodej   — jednotlivé prodeje z kasy (ucetni_prodej.php)
 *   Banka    — napojený účet KB, pohyby a párování plateb (banka.php)
 *   Podklady — tiskové sestavy pro účetní za období (ucetni_sestavy.php)
 * Vkládá se hned pod hlavičku všech tří stránek (stejný vzor jako Sklad).
 *
 * Viditelnost: crmCanAccountingRead() (vedení + role účetní), ne
 * crmCanManageInvoices() — ta je jen pro vedení a účetní by záložky Banka
 * a Podklady vůbec neviděla, přestože párování plateb a sestavy jsou přesně
 * její práce (includes/accounting_role.php).
 */
$__accTab = 'faktury';
switch (basename($_SERVER['PHP_SELF'])) {
    case 'ucetni_prodej.php':  $__accTab = 'prodej';  break;
    case 'banka.php':          $__accTab = 'banka';   break;
    case 'ucetni_sestavy.php': $__accTab = 'sestavy'; break;
}
?>
<ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary">
    <li class="nav-item">
        <a class="nav-link <?php echo $__accTab === 'faktury' ? 'active' : 'text-white-75'; ?>" href="accounting.php"><i class="fas fa-file-invoice-dollar me-2"></i>Faktury</a>
    </li>
    <?php if (crmCanAccountingRead()): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $__accTab === 'prodej' ? 'active' : 'text-white-75'; ?>" href="ucetni_prodej.php"><i class="fas fa-cash-register me-2"></i>Prodej</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__accTab === 'banka' ? 'active' : 'text-white-75'; ?>" href="banka.php"><i class="fas fa-building-columns me-2"></i>Banka</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $__accTab === 'sestavy' ? 'active' : 'text-white-75'; ?>" href="ucetni_sestavy.php"><i class="fas fa-file-lines me-2"></i>Podklady pro účetní</a>
    </li>
    <?php endif; ?>
</ul>
