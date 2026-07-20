<?php
/**
 * Podzáložky sekce Přehledy (od v2.9.0 — Historie sloučena pod Přehledy):
 *   Statistiky     — reports.php (výkony, finance, zaměstnanci)
 *   Historie úprav — history.php (kdo co v systému změnil) — jen crmCanViewHistory()
 * Vkládá se hned pod hlavičku obou stránek.
 */
$__repTab = basename($_SERVER['PHP_SELF']) === 'history.php' ? 'history' : 'stats';
?>
<ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary">
    <li class="nav-item">
        <a class="nav-link <?php echo $__repTab === 'stats' ? 'active' : 'text-white-75'; ?>" href="reports.php"><i class="fas fa-chart-line me-2"></i>Statistiky</a>
    </li>
    <?php if (crmCanViewHistory()): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $__repTab === 'history' ? 'active' : 'text-white-75'; ?>" href="history.php"><i class="fas fa-clock-rotate-left me-2"></i>Historie úprav</a>
    </li>
    <?php endif; ?>
</ul>
