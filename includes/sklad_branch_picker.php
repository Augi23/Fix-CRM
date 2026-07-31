<?php
/**
 * Rozcestník Skladu — výběr pobočky (dvě velké Liquid Glass karty).
 * Vkládá inventory.php, když není vybraná pobočka (?branch chybí/neplatné).
 * Karty nesou sklo SAMY (světlý gradient + vnitřní lesk) — sedí na PŮVODNÍM pozadí CRM,
 * bez vlastního pódia. Vidí obě pobočky každý; kdo na pobočce nepracuje, má „jen prohlížení".
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
    try {
        $__ac = afxProductAccessoryCond();
        $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE branch_id = ? AND " . $__ac['sql']);
        $st->execute(array_merge([$bid], $__ac['params']));
        $acc = (int)$st->fetchColumn();
    } catch (Throwable $e) { $acc = 0; }
    $__counts[$bid] = ['inv' => $inv, 'prod' => $prod, 'acc' => $acc];
}
// Ikony 1:1 z návrhu (vlastní SVG obrys, ne FontAwesome) — sklad (Karlín) / obchod (Černá Růže).
$__svg = [
    'karlin'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 21V9l9-5 9 5v12"/><path d="M3 21h18"/><path d="M7 21v-6h10v6"/><path d="M7 12h10"/></svg>',
    'prikope' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9v11h18V9"/><path d="M3 9h18"/><path d="M9 20v-6h6v6"/></svg>',
];
$__streets = ['karlin' => 'Křižíkova 177/29, Praha 8', 'prikope' => 'Na Příkopě 853, Praha 1'];
$__pl = function (int $n, string $one, string $few, string $many): string {
    if ($n === 1) return $one; if ($n >= 2 && $n <= 4) return $few; return $many;
};
?>
<div class="mb-2">
    <h2 class="mb-0"><?php echo __('inventory'); ?></h2>
    <small class="text-muted">Vyber pobočku skladu — každá má vlastní zásoby</small>
</div>

<div class="sklad-branch-picker">
    <?php foreach ($__branches as $__b): $bid = (int)$__b['id']; $code = (string)$__b['code']; $c = $__counts[$bid]; ?>
    <a href="inventory.php?branch=<?php echo $bid; ?>" class="sklad-branch-card">
        <span class="sklad-branch-ico"><?php echo $__svg[$code] ?? $__svg['karlin']; ?></span>
        <span class="sklad-branch-name"><?php echo e(skladBranchLabel($bid)); ?></span>
        <span class="sklad-branch-addr"><?php echo e($__streets[$code] ?? (string)$__b['address']); ?></span>
        <span class="sklad-branch-stats"><?php echo $c['inv'] . ' ' . $__pl($c['inv'], 'díl', 'díly', 'dílů'); ?> · <?php echo $c['prod'] . ' ' . $__pl($c['prod'], 'produkt', 'produkty', 'produktů'); ?> · <?php echo $c['acc']; ?> příslušenství</span>
        <?php if (!crmCanModifyBranchStock($bid)): ?>
            <span class="sklad-branch-ro"><i class="fas fa-eye me-1"></i>jen prohlížení</span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<style>
.sklad-branch-picker{display:flex;gap:30px;justify-content:center;flex-wrap:wrap;padding:46px 12px 40px;}
.sklad-branch-card{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;
    text-align:center;gap:10px;width:346px;max-width:44vw;min-height:290px;padding:46px 30px;border-radius:28px;
    text-decoration:none;color:#eaf2ff;overflow:hidden;
    /* sklo NESE KARTA sama: světlý gradient shora dolů = lesk i na tmavém pozadí CRM */
    background:linear-gradient(180deg,rgba(255,255,255,.14) 0%,rgba(255,255,255,.045) 34%,rgba(255,255,255,.03) 100%);
    border:1px solid rgba(255,255,255,.18);
    backdrop-filter:blur(18px) saturate(1.4);-webkit-backdrop-filter:blur(18px) saturate(1.4);
    box-shadow:0 28px 66px rgba(0,0,0,.46), inset 0 1px 0 rgba(255,255,255,.38), inset 0 -34px 60px -44px rgba(0,0,0,.55);
    transition:transform .18s cubic-bezier(.34,1.56,.64,1),box-shadow .18s ease,border-color .18s ease;}
.sklad-branch-card:hover{transform:translateY(-6px) scale(1.02);color:#fff;border-color:rgba(85,212,255,.45);
    box-shadow:0 40px 90px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.5), inset 0 -34px 60px -44px rgba(0,0,0,.5);}
.sklad-branch-ico{display:inline-flex;line-height:0;margin-bottom:8px;}
.sklad-branch-ico svg{width:62px;height:62px;stroke:#5cd6ff;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 6px 26px rgba(85,212,255,.6));}
.sklad-branch-name{font-size:23px;font-weight:700;letter-spacing:-.01em;}
.sklad-branch-addr{font-size:14px;opacity:.72;font-weight:500;}
.sklad-branch-stats{margin-top:6px;font-size:13.5px;font-weight:600;color:#3be8a8;}
.sklad-branch-ro{position:absolute;top:14px;right:16px;font-size:12px;opacity:.75;font-weight:500;}
html[data-lg-theme="light"] .sklad-branch-card{color:#0b1a2b;
    background:linear-gradient(180deg,rgba(255,255,255,.9),rgba(255,255,255,.66) 40%,rgba(255,255,255,.6) 100%);
    border-color:rgba(0,0,0,.08);box-shadow:0 22px 54px rgba(0,40,90,.16),inset 0 1px 0 rgba(255,255,255,.9);}
html[data-lg-theme="light"] .sklad-branch-card:hover{color:#000;}
html[data-lg-theme="light"] .sklad-branch-ico svg{stroke:#0a84ff;}
html[data-lg-theme="light"] .sklad-branch-stats{color:#0a7d55;}
@media(max-width:560px){.sklad-branch-card{width:100%;max-width:92vw;min-height:210px;padding:34px 24px;}}
</style>
