<?php
/**
 * SKLAD → PRODUKTY — bazarová elektronika a příslušenství pro e-shop.
 * Plní se importem souboru z naskladňovací Mac appky (~/Desktop/AppleFix-produkty.csv,
 * formát Upgates CSV). Import = upsert podle kódu produktu (sériové číslo / AFX-…),
 * takže opakované nahrání stejného souboru nic nerozbije.
 * Servisní náhradní díly zůstávají v záložce Servis (inventory.php) — oddělené tabulky.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/product_catalog.php';
require_once 'includes/header.php';
ensureProductsTable();
ensureProductsPosColumn();
ensureProductsCrmColumns();

$canManage = crmCanManageProducts();

// výchozí prodejna dle pobočky přihlášeného (Na Příkopě = Černá Růže = sklad vaclavak)
$__myBranch = null;
try {
    $__bs = $pdo->prepare("SELECT code FROM branches WHERE id = ?");
    $__bs->execute([(int)getCurrentStaffBranchId()]);
    $__myBranch = (string)$__bs->fetchColumn();
} catch (Throwable $e) {}
$defaultStockKey = $__myBranch === 'prikope' ? 'vaclavak' : 'karlin';

$limit = 50;
$page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = trim((string)($_GET['search'] ?? ''));
$avail = (string)($_GET['avail'] ?? '');   // '' = vše, 'in' = skladem, 'out' = vyprodáno, 'loan' = zapůjčené
ensureProductsLoanColumns();
ensureSkladBranchSchema();
ensureProductsHideEshopColumn();
ensureEshopReservationSchema();   // reserved_qty — badge „rezervováno pro e-shop"
// Pobočka skladu (vybraná ?branch, jinak vlastní). Vidí se obě; MĚNIT jen zaměstnanec pobočky.
$skladBranch = skladBranchOrOwn();
$canModifyStock = crmCanModifyBranchStock($skladBranch);
$canManageBranch = $canManage && $canModifyStock;
// Prodejna (stock_key) i nová položka jdou do PRÁVĚ zobrazené pobočky (ne dle přihlášeného).
$skladBranchCode = skladBranchCode($skladBranch);
$defaultStockKey = $skladBranchCode === 'prikope' ? 'vaclavak' : 'karlin';

$where_clauses = ['branch_id = ?'];
$params = [$skladBranch];
if ($search !== '') {
    $where_clauses[] = "(title LIKE ? OR product_code LIKE ? OR model LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($avail === 'in')   { $where_clauses[] = "stock_qty > 0 AND loan_at IS NULL"; }
if ($avail === 'out')  { $where_clauses[] = "stock_qty <= 0"; }
if ($avail === 'loan') { $where_clauses[] = "loan_at IS NOT NULL"; }

// Kategorie: '' = Produkty (vše) · 'prislusenstvi' = jen doplňky (kabely, kryty, boxy, adaptéry…).
// Doplněk = má accessory-slovo v názvu, NEBO název/model neodpovídá žádnému zařízení/značce.
// (Zrcadlí kategorii „Příslušenství" na e-shopu; „kryt na iPhone" tak spadne mezi doplňky, ne mezi telefony.)
$cat = (string)($_GET['cat'] ?? '');
$isAccessoryTab = ($cat === 'prislusenstvi');
$isVykupTab = ($cat === 'vykupy');
ensureProductsVykupColumns();
$catWhere = ''; $catParams = [];
if ($isVykupTab) {
    // Výkupy = kusy naskladněné z výkupních listů (products.is_vykup; mají
    // vlastní záložku a do Produktů/Příslušenství se nemíchají)
    $where_clauses[] = "COALESCE(is_vykup, 0) = 1";
} elseif ($isAccessoryTab) {
    $__ac = afxProductAccessoryCond();   // jediný zdroj pravdy (functions.php)
    $catWhere = $__ac['sql'];
    $where_clauses[] = $catWhere;
    foreach ($__ac['params'] as $__p) { $params[] = $__p; $catParams[] = $__p; }
    $where_clauses[] = "COALESCE(is_vykup, 0) = 0";
} else {
    $where_clauses[] = "COALESCE(is_vykup, 0) = 0";
}
$where_sql = " WHERE " . implode(" AND ", $where_clauses);

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM products" . $where_sql);
$total_stmt->execute($params);
$total_count = (int)$total_stmt->fetchColumn();
$total_pages = (int)ceil($total_count / $limit);

$stmt = $pdo->prepare("SELECT * FROM products" . $where_sql . " ORDER BY added_at DESC, id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$products = $stmt->fetchAll();

$statStmt = $pdo->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN stock_qty > 0 AND loan_at IS NULL THEN stock_qty ELSE 0 END) AS in_stock,
        SUM(CASE WHEN stock_qty > 0 AND loan_at IS NULL THEN price * stock_qty ELSE 0 END) AS stock_value,
        SUM(CASE WHEN loan_at IS NOT NULL THEN 1 ELSE 0 END) AS loaned
    FROM products WHERE branch_id = ?" . ($catWhere ? " AND $catWhere" : ""));
$statStmt->execute(array_merge([$skladBranch], $catParams));
$stats = $statStmt->fetch();

// Import z appky ODSTRANĚN (1.8.2026) — produkty se naskladňují výhradně v CRM.
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><?php echo __('inventory'); ?></h2>
        <small class="text-muted"><?php echo $cat === 'prislusenstvi' ? 'Příslušenství' : 'Produkty pro e-shop'; ?>: <?php echo (int)($stats['total'] ?? 0); ?> ·
            skladem <?php echo (int)($stats['in_stock'] ?? 0); ?> ks ·
            hodnota <?php echo formatMoney((float)($stats['stock_value'] ?? 0)); ?><?php
            if ((int)($stats['loaned'] ?? 0) > 0): ?> · <a href="products.php?avail=loan" style="color:#A78BFA">zapůjčeno <?php echo (int)$stats['loaned']; ?></a><?php endif; ?></small>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <button class="btn btn-outline-info" data-bs-toggle="collapse" data-bs-target="#filterPanel">
            <i class="fas fa-filter me-2"></i> <?php echo __('filters'); ?>
        </button>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#stockHistoryModal" title="Posledních 20 naskladněných produktů — kdo je naskladnil a kdy">
            <i class="fas fa-clock-rotate-left me-2"></i> Historie naskladnění
        </button>
        <?php
        // předvolená prodejna tisku = právě zobrazená pobočka skladu (na stránce
        // tisku jde jedním klikem přepnout na Vše nebo druhou prodejnu)
        $__invSk = 'karlin';
        try {
            $__ib = $pdo->prepare("SELECT code FROM branches WHERE id = ?");
            $__ib->execute([(int)$skladBranch]);
            if ((string)$__ib->fetchColumn() === 'prikope') { $__invSk = 'vaclavak'; }
        } catch (Throwable $e) {}
        ?>
        <a class="btn btn-outline-secondary" href="print_inventura.php?prodejna=<?php echo e($__invSk); ?>&amp;auto=1" target="_blank"
           title="Inventurní soupis všech kusů skladem (= nabídka e-shopu) na papír A4 — s odškrtávacím polem na konci každého řádku">
            <i class="fas fa-print me-2"></i> Inventura A4
        </a>
        <?php if (!$canModifyStock): ?>
        <span class="badge bg-secondary align-self-center" title="Do skladu jiné pobočky můžeš jen nahlížet"><i class="fas fa-eye me-1"></i>jen prohlížení</span>
        <?php endif; ?>
        <?php if ($canManageBranch): ?>
        <button class="btn btn-success" id="productCreateOpen" data-bs-toggle="modal" data-bs-target="#productCreateModal">
            <i class="fas fa-box-open me-2"></i> <?php echo $isAccessoryTab ? 'Naskladnit příslušenství' : 'Naskladnit produkt'; ?>
        </button>
        <a class="btn btn-outline-secondary" href="api/export_products_csv.php" title="Kompletní sklad ve formátu souboru appky — pro ruční import do Upgates">
            <i class="fas fa-file-csv me-2"></i> CSV pro Upgates
        </a>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/inventory_tabs.php'; ?>

<?php
// Historie naskladnění — posledních 20 produktů (obdoba panelu „Naposledy přidané"
// v Mac appce): kdo kus naskladnil (created_by dle přihlášení) a kdy (added_at).
$__histRows = [];
try {
    $__histRows = $pdo->query(
        "SELECT title, product_code, price, source, created_by,
                COALESCE(added_at, first_seen_at) AS stocked_at
         FROM products
         ORDER BY COALESCE(added_at, first_seen_at) DESC, id DESC
         LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $__histRows = []; }
?>

<!-- Zapůjčeno / komisní prodej: kus fyzicky není u nás, ale ve skladu zůstává -->
<div class="modal fade" id="productLoanModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0">
        <h5 class="modal-title"><i class="fas fa-hand-holding-heart me-2" style="color:#8B5CF6"></i>Zapůjčeno / komisní prodej</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="small text-white-75 mb-3" id="loanProductTitle"></div>
        <input type="hidden" id="loanProductId">
        <div class="mb-3">
          <label class="form-label small">Komu <span class="text-danger">*</span></label>
          <input type="text" class="form-control" id="loanTo" placeholder="např. Štěpán Říčan" maxlength="120">
        </div>
        <div class="mb-2">
          <label class="form-label small">Poznámka</label>
          <input type="text" class="form-control" id="loanNote" placeholder="kontakt, do kdy, dohoda…" maxlength="255">
        </div>
        <div class="small text-white-50">Kus zůstane ve skladu i v přehledech, ale na e-shop se posílat nebude.</div>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zrušit</button>
        <button type="button" class="btn text-white" style="background:#8B5CF6" id="loanSaveBtn">Označit jako zapůjčené</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="stockHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-secondary text-white">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-clock-rotate-left me-2 text-info"></i>Historie naskladnění — posledních 20 produktů</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0">
                        <thead><tr><th>Produkt</th><th class="text-end">Cena</th><th>Naskladnil</th><th>Kdy</th></tr></thead>
                        <tbody>
                        <?php if (empty($__histRows)): ?>
                            <tr><td colspan="4" class="text-center text-white-50 py-4">Zatím žádné naskladněné produkty.</td></tr>
                        <?php else: foreach ($__histRows as $hr):
                            $__hrTs = $hr['stocked_at'] ? strtotime((string)$hr['stocked_at']) : false;
                            // čas 00:00 = appka poslala jen datum → hodiny nezobrazovat
                            $__hrWhen = $__hrTs ? date(date('H:i', $__hrTs) === '00:00' ? 'j.n.Y' : 'j.n.Y H:i', $__hrTs) : '—';
                            $__hrWho = trim((string)($hr['created_by'] ?? ''));
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?php echo e($hr['title']); ?></div>
                                    <div class="small text-white-50 font-monospace"><?php echo e($hr['product_code']); ?></div>
                                </td>
                                <td class="text-end fw-bold" style="white-space:nowrap;"><?php echo number_format((float)$hr['price'], 0, ',', ' '); ?> Kč</td>
                                <td>
                                    <?php echo $__hrWho !== '' ? e($__hrWho) : '<span class="text-white-50">appka</span>'; ?>
                                    <?php if (($hr['source'] ?? 'app') !== 'crm'): ?><div class="small text-white-50">import z appky</div><?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;"><?php echo e($__hrWhen); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="collapse mb-4 <?php echo ($search !== '' || $avail !== '') ? 'show' : ''; ?>" id="filterPanel">
    <div class="card card-body shadow-sm">
        <form action="products.php" method="GET" class="row g-3">
            <input type="hidden" name="branch" value="<?php echo (int)$skladBranch; ?>">
            <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?php echo e($cat); ?>"><?php endif; ?>
            <div class="col-md-6">
                <label class="form-label small">Hledat (název, kód, model)</label>
                <input type="text" name="search" class="form-control form-control-sm" value="<?php echo e($search); ?>" placeholder="např. iPhone 13, F2LLD…">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Dostupnost</label>
                <select name="avail" class="form-select form-select-sm">
                    <option value="" <?php echo $avail === '' ? 'selected' : ''; ?>>Vše</option>
                    <option value="in" <?php echo $avail === 'in' ? 'selected' : ''; ?>>Skladem</option>
                    <option value="out" <?php echo $avail === 'out' ? 'selected' : ''; ?>>Vyprodáno</option>
                    <option value="loan" <?php echo $avail === 'loan' ? 'selected' : ''; ?>>Zapůjčeno/Komisní prod.</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><?php echo __('apply_btn'); ?></button>
                <a href="products.php?branch=<?php echo (int)$skladBranch; ?><?php echo $cat !== '' ? '&cat=' . e($cat) : ''; ?>" class="btn btn-sm btn-outline-secondary"><?php echo __('reset_btn'); ?></a>
            </div>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="ps-4"><?php echo __('photo_col'); ?></th>
                                <th>Produkt</th>
                                <th>Kód</th>
                                <th>Stav</th>
                                <th>Baterie</th>
                                <th>Cena</th>
                                <th>Dostupnost</th>
                                <th>Naskladněno</th>
                                <th class="text-end pe-4"><?php echo __('action'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="fas fa-mobile-alt fa-3x mb-3 d-block opacity-25"></i>
                                        Zatím žádné produkty.<?php echo $canManage ? ' Naskladni první kus tlačítkem „Naskladnit produkt" vpravo nahoře.' : ''; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): ?>
                                <?php $img = productImageDisplayUrl($p['image_url'] ?? ''); ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php if ($img !== ''): ?>
                                            <a href="<?php echo e($img); ?>" data-fancybox="products">
                                                <img src="<?php echo e($img); ?>" class="rounded shadow-sm" style="width: 40px; height: 40px; object-fit: cover;" loading="lazy">
                                            </a>
                                        <?php else: ?>
                                            <div class="bg-dark bg-opacity-25 rounded d-flex align-items-center justify-content-center shadow-sm border border-secondary" style="width: 40px; height: 40px;">
                                                <i class="fas fa-image text-muted opacity-25"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="prod-preview" data-id="<?php echo (int)$p['id']; ?>" style="cursor:pointer;" title="Kliknutím zobrazíš náhled položky">
                                        <div class="fw-bold"><?php echo e($p['title']); ?></div>
                                        <div class="small text-white-75">
                                            <?php echo e(trim(($p['manufacturer'] ?? '') . ' ' . ($p['model'] ?? ''))); ?>
                                            <?php if (!empty($p['capacity'])): ?> · <?php echo e($p['capacity']); ?><?php endif; ?>
                                            <?php if (!empty($p['color'])): ?> · <?php echo e($p['color']); ?><?php endif; ?>
                                        </div>
                                    </td>
                                    <td><code><?php echo e($p['product_code']); ?></code></td>
                                    <td>
                                        <?php if (!empty($p['grade'])): ?>
                                            <span class="badge bg-info text-dark">Stav <?php echo e($p['grade']); ?></span>
                                        <?php else: ?>
                                            <span class="text-white-75">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['battery'])): ?>
                                            <span class="fw-medium"><?php echo e($p['battery']); ?></span>
                                        <?php else: ?>
                                            <span class="text-white-75">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-primary">
                                        <?php
                                        // Sloupec „Cena" je PRODEJNÍ cena. U čerstvého výkupu ještě není
                                        // stanovená (0) — místo nuly ukážeme, za kolik jsme kus vykoupili,
                                        // ať obsluha vidí, s čím počítat (marži řeší § 90 při prodeji).
                                        $__pp = isset($p['purchase_price']) ? (float)$p['purchase_price'] : 0.0;
                                        $__showBuy = ((float)$p['price'] <= 0) && $__pp > 0;
                                        ?>
                                        <?php if ($__showBuy): ?>
                                            <span class="text-warning" title="Výkupní (nákupní) cena — prodejní cena zatím nenastavená"><?php echo formatMoney($__pp); ?></span>
                                            <div class="small text-white-75 fw-normal">výkup · prodejní cena nenastavena</div>
                                        <?php else: ?>
                                            <?php echo formatMoney((float)$p['price']); ?>
                                            <?php if ($__pp > 0): ?>
                                                <div class="small text-white-75 fw-normal" title="Výkupní / nákupní cena">nákup <?php echo formatMoney($__pp); ?></div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (productIsLoaned($p)): ?>
                                            <span class="badge" style="background:#8B5CF6" title="<?php echo e(($p['loan_to'] ?? '') . (!empty($p['loan_at']) ? ' · od ' . date('j.n.Y', strtotime($p['loan_at'])) : '') . (!empty($p['loan_note']) ? ' · ' . $p['loan_note'] : '')); ?>">Zapůjčeno/Komisní prod.</span>
                                            <div class="small text-white-75 mt-1"><i class="fas fa-user-tag me-1"></i><?php echo e($p['loan_to'] ?? ''); ?></div>
                                        <?php elseif ((int)$p['stock_qty'] > 1): ?>
                                            <span class="badge bg-success">Skladem <?php echo (int)$p['stock_qty']; ?> ks</span>
                                        <?php elseif ((int)$p['stock_qty'] > 0): ?>
                                            <span class="badge bg-success">Skladem</span>
                                        <?php elseif (!empty($p['pos_sold_at'])): ?>
                                            <span class="badge bg-warning text-dark" title="Prodáno přes Pokladnu — CRM ho automaticky drží vyprodaný, i kdyby ho soubor z appky ještě hlásil skladem">Prodáno na kase</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Vyprodáno</span>
                                        <?php endif; ?>
                                        <?php /* rezervace z e-shopu: kus fyzicky leží na prodejně, ale je zamluvený
                                                 objednávkou s platbou při vyzvednutí — prodat ho smí jen ta objednávka */ ?>
                                        <?php if ((int)($p['reserved_qty'] ?? 0) > 0): ?>
                                            <div class="small mt-1" style="color:#ffd479;" title="Objednávka z e-shopu s platbou při vyzvednutí — zaplatí se na kase přes „Rezervace e-shopu“"><i class="fas fa-clock me-1"></i>rezervováno pro e-shop<?php echo (int)$p['reserved_qty'] > 1 ? ' (' . (int)$p['reserved_qty'] . ' ks)' : ''; ?></div>
                                        <?php endif; ?>
                                        <?php if ((int)$p['stock_qty'] <= 0 && !empty($p['last_sold_at']) && !productIsLoaned($p)): ?>
                                            <div class="small text-white-75 mt-1" title="Kdy se kus prodal (kasa i e-shop)"><i class="fas fa-cart-shopping me-1"></i>prodáno <?php echo date('j. n. Y H:i', strtotime((string)$p['last_sold_at'])); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($p['stock_key'])): ?>
                                            <div class="small text-white-75 mt-1"><?php echo $p['stock_key'] === 'karlin' ? 'Karlín' : 'Václavák'; ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($p['hide_eshop'])): ?>
                                            <div class="mt-1"><span class="badge bg-secondary" title="Kus je v CRM, ale feed ho na e-shop neposílá"><i class="fas fa-eye-slash me-1"></i>skrytý na e-shopu</span></div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <?php if (!empty($p['added_at'])): ?>
                                            <div style="font-size:10.5px;line-height:1.4;"><?php echo crmDateTime($p['added_at']); ?></div>
                                        <?php else: ?>
                                            <span class="text-white-75">—</span>
                                        <?php endif; ?>
                                        <?php if (trim((string)($p['created_by'] ?? '')) !== ''): ?>
                                            <div style="font-size:11px;opacity:.62;line-height:1.35;"><i class="fas fa-user" style="font-size:.85em;margin-right:.25em;"></i><?php echo e($p['created_by']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($canManageBranch): ?>
                                    <td class="text-end pe-4">
                                        <div class="btn-group btn-group-sm afx-row-actions">
                                            <?php if ($isVykupTab): ?>
                                                <?php if ((int)($p['moved_to_inventory_id'] ?? 0) > 0): ?>
                                                    <a class="btn btn-white border" href="edit_inventory.php?id=<?php echo (int)$p['moved_to_inventory_id']; ?>" title="Už převedeno na sklad dílů — otevřít kartu dílu"><i class="fas fa-microchip text-success"></i></a>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-white border vykup-to-parts-btn" data-id="<?php echo (int)$p['id']; ?>" data-title="<?php echo e($p['title']); ?>" data-cost="<?php echo $p['purchase_price'] !== null && $p['purchase_price'] !== '' ? (float)$p['purchase_price'] : ''; ?>" title="Převést na sklad náhradních dílů (dárce na díly)"><i class="fas fa-microchip text-info"></i></button>
                                                    <button type="button" class="btn btn-white border vykup-to-sale-btn" data-id="<?php echo (int)$p['id']; ?>" data-title="<?php echo e($p['title']); ?>" data-price="<?php echo (float)($p['price'] ?? 0); ?>" title="Zařadit do prodeje (záložka Produkty / e-shop)"><i class="fas fa-store text-success"></i></button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-white border text-info product-label-btn" data-id="<?php echo (int)$p['id']; ?>" title="Vytisknout cenový štítek (Brother QL-8xx)"><i class="fas fa-tag"></i></button>
                                            <button type="button" class="btn btn-white border text-danger product-label-btn" data-id="<?php echo (int)$p['id']; ?>" data-akce="1" title="Štítek AKCE — cenovka červeně (jen s černo-červenou rolí DK-22251!)"><i class="fas fa-fire"></i></button>
                                            <button type="button" class="btn btn-white border product-loan-btn" data-id="<?php echo (int)$p['id']; ?>" data-title="<?php echo e($p['title']); ?>" data-loaned="<?php echo productIsLoaned($p) ? '1' : '0'; ?>" data-to="<?php echo e($p['loan_to'] ?? ''); ?>" data-note="<?php echo e($p['loan_note'] ?? ''); ?>" title="<?php echo productIsLoaned($p) ? 'Vrátit do skladu' : 'Zapůjčeno / komisní prodej'; ?>"><i class="fas fa-hand-holding-heart" style="color:#8B5CF6"></i></button>
                                            <button type="button" class="btn btn-white border product-edit-btn" data-id="<?php echo (int)$p['id']; ?>" title="Upravit produkt"><i class="fas fa-edit text-warning"></i></button>
                                            <button type="button" class="btn btn-white border tr-add-btn" data-type="product" data-id="<?php echo (int)$p['id']; ?>" data-name="<?php echo e($p['title']); ?>" title="Přesun na druhou pobočku"><i class="fas fa-right-left text-info"></i></button>
                                            <button type="button" class="btn btn-white border text-danger product-delete-btn" data-id="<?php echo (int)$p['id']; ?>" data-title="<?php echo e($p['title']); ?>" title="<?php echo __('delete'); ?>"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                    <?php else: ?>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-white border text-info product-label-btn" data-id="<?php echo (int)$p['id']; ?>" title="Vytisknout cenový štítek"><i class="fas fa-tag"></i></button>
                                        <button type="button" class="btn btn-sm btn-white border text-danger product-label-btn" data-id="<?php echo (int)$p['id']; ?>" data-akce="1" title="Štítek AKCE — cenovka červeně (jen s černo-červenou rolí DK-22251!)"><i class="fas fa-fire"></i></button>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <?php
            $params_p = $_GET;
            unset($params_p['p']);
            $qs = http_build_query($params_p);
            $url_pre = $qs ? "&$qs" : "";

            $pagination_window = 10;
            if ($total_pages <= $pagination_window) {
                $start_page = 1;
                $end_page = $total_pages;
            } else {
                $half_window = (int)floor($pagination_window / 2);
                $start_page = max(1, $page - $half_window);
                $end_page = $start_page + $pagination_window - 1;
                if ($end_page > $total_pages) {
                    $end_page = $total_pages;
                    $start_page = max(1, $end_page - $pagination_window + 1);
                }
            }
        ?>
        <nav class="mt-4">
            <ul class="pagination pagination-sm justify-content-center flex-wrap gap-1">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?p=<?php echo max(1, $page - 1) . $url_pre; ?>" aria-label="Previous"><i class="fas fa-chevron-left"></i></a>
                </li>
                <?php if ($start_page > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                        <a class="page-link" href="?p=<?php echo $i . $url_pre; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($end_page < $total_pages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?p=<?php echo min($total_pages, $page + 1) . $url_pre; ?>" aria-label="Next"><i class="fas fa-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManage): ?>
<!-- ═══ NASKLADNIT PRODUKT — náhrada Mac appky (v2.3.0) ═══ -->
<div class="modal fade" id="productCreateModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content glass-card">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-box-open me-2 text-success"></i><span id="pcTitleMode">Naskladnit produkt</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <!-- ── formulář ── -->
                    <?php /* Formulář dostává většinu šířky; pravý panel je jen kontrolní náhled
                             (název, popis, foto) — při 5/12 lámal popisky polí do dvou řádků. */ ?>
                    <div class="col-lg-8 col-xxl-9">
                        <input type="hidden" id="pcEditId" value="">
                        <input type="hidden" id="pcImageUrl" value="">
                        <input type="hidden" id="pcStudioUrl" value="">
                        <input type="hidden" id="pcGalleryUrls" value="">
                        <input type="hidden" id="pcVideo360Url" value="">
                        <div class="row g-3">
                            <!-- ── 1) ZAŘÍZENÍ — co naskladňuješ ─────────────────────────
                                 „Vlastní…" pole se VŽDY otevírá POD svým výběrem (jednotné). -->
                            <div class="col-12">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="fas fa-mobile-alt text-info" id="pcDeviceIcon"></i>
                                    <span class="fw-semibold" id="pcDeviceHeading">Zařízení</span>
                                </div>
                            </div>
                            <div class="col-md-4" id="pcManufacturerGroup">
                                <label class="form-label small">Výrobce</label>
                                <select id="pcManufacturer" class="form-select"></select>
                                <input type="text" id="pcManufacturerCustom" class="form-control mt-1" placeholder="vlastní výrobce…" style="display:none;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small" id="pcTypLabel">Typ zařízení</label>
                                <select id="pcTyp" class="form-select"></select>
                                <input type="text" id="pcTypCustom" class="form-control mt-1" placeholder="vlastní typ…" style="display:none;">
                            </div>
                            <div class="col-md-4" id="pcModelGroup">
                                <label class="form-label small">Model <span class="text-danger">*</span></label>
                                <select id="pcModel" class="form-select"></select>
                                <input type="text" id="pcModelCustom" class="form-control mt-1" placeholder="vlastní model…" style="display:none;">
                            </div>
                            <div class="col-md-6" id="pcAccessoryForModelGroup" style="display:none;">
                                <label class="form-label small">Pro model</label>
                                <input type="text" id="pcAccessoryForModel" class="form-control" placeholder="např. iPhone 15 Pro">
                            </div>
                            <div class="col-md-6" id="pcAccessoryPropertyGroup" style="display:none;">
                                <label class="form-label small">Vlastnost</label>
                                <select id="pcAccessoryProperty" class="form-select"></select>
                                <input type="text" id="pcAccessoryPropertyCustom" class="form-control mt-1" placeholder="vlastní vlastnost…" style="display:none;">
                            </div>
                            <?php /* Úložiště/Baterie/RAM jen kde dávají smysl — řídí syncFieldVisibility() */ ?>
                            <div class="col-md-3 pc-field-cap">
                                <label class="form-label small">Úložiště</label>
                                <select id="pcCap" class="form-select"></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Barva</label>
                                <select id="pcColor" class="form-select"></select>
                                <input type="text" id="pcColorCustom" class="form-control mt-1" placeholder="vlastní barva…" style="display:none;">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Stav</label>
                                <select id="pcGrade" class="form-select"></select>
                            </div>
                            <div class="col-md-3 pc-field-battery">
                                <label class="form-label small">Baterie</label>
                                <div class="input-group">
                                    <input type="number" id="pcBattery" class="form-control" min="0" max="100">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-3 pc-field-ram">
                                <label class="form-label small">RAM</label>
                                <select id="pcRam" class="form-select"></select>
                            </div>
                            <div class="col-md-3 pc-processor" style="display:none;">
                                <label class="form-label small">Procesor:</label>
                                <select id="pcProcessorFamily" class="form-select"></select>
                            </div>
                            <div class="col-md-6 pc-processor pc-processor-model" style="display:none;">
                                <label class="form-label small">Model procesoru</label>
                                <select id="pcProcessorModel" class="form-select"></select>
                                <input type="text" id="pcProcessorModelCustom" class="form-control mt-1" placeholder="vlastní procesor…" style="display:none;">
                            </div>
                            <?php /* Jádra jen kde dávají smysl: Mac = CPU+GPU jádra, běžný
                                     notebook/PC = CPU jádra + Grafická karta, ostatní nic. */ ?>
                            <div class="col-md-3 pc-core-cpu">
                                <label class="form-label small">Jader CPU</label>
                                <select id="pcCpu" class="form-select"></select>
                            </div>
                            <div class="col-md-3 pc-core-gpu">
                                <label class="form-label small">Jader GPU</label>
                                <select id="pcGpu" class="form-select"></select>
                            </div>
                            <div class="col-md-6 pc-gpu-model" style="display:none;">
                                <label class="form-label small">Grafická karta</label>
                                <select id="pcGpuModel" class="form-select"></select>
                                <input type="text" id="pcGpuModelCustom" class="form-control mt-1" placeholder="vlastní grafika…" style="display:none;">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Ročník</label>
                                <select id="pcRocnik" class="form-select"></select>
                            </div>
                            <div class="col-md-3 pc-ipad" style="display:none;">
                                <label class="form-label small">Generace</label>
                                <select id="pcGenerace" class="form-select"></select>
                            </div>

                            <!-- ── 2) IDENTIFIKACE KUSU ── -->
                            <div class="col-12">
                                <hr class="border-secondary opacity-25 my-1">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="fas fa-barcode text-info"></i>
                                    <span class="fw-semibold">Identifikace kusu</span>
                                </div>
                            </div>
                            <div class="col-md-8" id="pcSerialGroup">
                                <label class="form-label small">SN / IMEI <span class="text-white-50">(naskenuj čtečkou nebo zapiš)</span></label>
                                <input type="text" id="pcSerial" class="form-control" autocomplete="off">
                                <?php /* doplnění údajů z IMEI (v3.61.0) — vyplní se sem výsledek z iFreeiCloud */ ?>
                                <div id="pcImeiInfo" class="small mt-2" style="display:none;"></div>
                                <?php /* čtení z telefonu připojeného k Macu (v3.62.0) — zdarma, umí i baterii */ ?>
                                <button type="button" class="btn btn-sm btn-outline-info mt-2" id="pcDeviceRead">
                                    <i class="fab fa-apple me-1"></i>Načíst z připojeného zařízení
                                </button>
                            </div>
                            <div class="col-md-4 d-flex align-items-end<?php echo $isAccessoryTab ? ' d-none' : ''; ?>" id="pcPcrGroup">
                                <div id="pcPcrBadge" class="w-100 text-center small fw-bold rounded py-2" style="background:rgba(255,255,255,.06);color:#9aa3b2;">PČR: nekontrolováno</div>
                            </div>
                            <div class="col-12" id="pcAccessoryTextGroup" style="display:none;">
                                <label class="form-label small">Vlastní text</label>
                                <input type="text" id="pcAccessoryText" class="form-control" placeholder="např. poznámka k příslušenství">
                            </div>

                            <!-- ── 3) CENA A PRODEJ ── -->
                            <div class="col-12">
                                <hr class="border-secondary opacity-25 my-1">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="fas fa-coins text-warning"></i>
                                    <span class="fw-semibold">Cena a prodej</span>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Počet kusů</label>
                                <input type="number" id="pcQty" class="form-control" value="1" min="0" step="1"
                                       title="Kolik stejných kusů naskladňuješ. U zboží se sériovým číslem nechej 1 — SN má každý kus vlastní.">
                                <div class="form-text small text-white-50">u SN vždy 1</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Prodejní cena <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" id="pcPrice" class="form-control" inputmode="numeric">
                                    <span class="input-group-text">Kč</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Nákupní cena</label>
                                <div class="input-group">
                                    <input type="text" id="pcPurchasePrice" class="form-control" inputmode="numeric"
                                           title="Za kolik jsme kus vykoupili/nakoupili. Potřeba pro daň z přirážky u použitého zboží (§ 90) — bez ní ji zpětně nespočítáme.">
                                    <span class="input-group-text">Kč</span>
                                </div>
                                <div class="form-text small text-white-50">Nepovinné — § 90 (daň z přirážky).</div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Prodejna</label>
                                <select id="pcStockKey" class="form-select">
                                    <option value="karlin">Karlín</option>
                                    <option value="vaclavak">Černá Růže</option>
                                </select>
                            </div>
                            <div class="col-md-2 d-flex flex-column justify-content-end gap-1 pb-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="pcSold">
                                    <label class="form-check-label small" for="pcSold">Prodáno <span class="text-white-50">(Vyprodáno)</span></label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="pcHideEshop">
                                    <label class="form-check-label small" for="pcHideEshop">Nezobrazovat na e-shopu</label>
                                </div>
                                <div class="mt-2">
                                    <label class="form-label small">Popis pro e-shop <span class="text-white-50">(zobrazí se u produktu pod názvem)</span></label>
                                    <textarea id="pcEshopNote" class="form-control" rows="2" maxlength="2000" placeholder="Např. Kompletní původní balení, nová baterie, faktura s zárukou…"></textarea>
                                </div>
                            </div>

                            <!-- ── 4) FOTKY A MÉDIA ── -->
                            <div class="col-12">
                                <hr class="border-secondary opacity-25 my-1">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="fas fa-images text-primary"></i>
                                    <span class="fw-semibold">Fotky a média</span>
                                    <span class="text-white-50 small">— studiová fotka · klasické fotky (Sbazar/Bazos) · 360° video</span>
                                </div>
                                <div class="row g-3 mb-1">
                                    <div class="col-md-5">
                                        <label class="form-label small">Foto produktu <span class="text-white-50">(rychlá fotka — náhled v CRM)</span></label>
                                        <input type="file" id="pcPhoto" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,image/*">
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1 d-flex align-items-center gap-2">
                                            <input class="form-check-input mt-0" type="checkbox" id="pcShowStudio" checked>
                                            <span>1. Studiová fotka <span class="text-white-50">(bez pozadí → hlavní fotka na eshopu + Meta/Google katalog)</span></span>
                                        </label>
                                        <input type="file" id="pcStudioPhoto" class="form-control form-control-sm" accept="image/*">
                                        <div id="pcStudioWrap" class="mt-2 d-flex align-items-center gap-2" style="display:none!important;">
                                            <img id="pcStudioThumb" src="" alt="studio" class="rounded" style="max-height:78px;max-width:120px;object-fit:contain;background:rgba(255,255,255,.05);padding:4px;">
                                            <button type="button" id="pcStudioClear" class="btn btn-sm btn-link text-danger p-0">odebrat</button>
                                        </div>
                                        <div class="form-check form-switch mt-2">
                                            <input class="form-check-input" type="checkbox" id="pcStudioWholeModel" checked>
                                            <label class="form-check-label small text-white-75" for="pcStudioWholeModel">
                                                Použít pro <b>celý model</b> <span class="text-white-50">(všechny kusy + budoucí)</span>
                                            </label>
                                        </div>
                                        <div id="pcStudioModelNote" class="small mt-1"></div>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label small mb-1 d-flex align-items-center gap-2">
                                            <input class="form-check-input mt-0" type="checkbox" id="pcShowGallery" checked>
                                            <span>2. Klasické fotky <span class="text-white-50">(fotky kusu — Sbazar/Bazos + galerie na eshopu, max 10)</span></span>
                                        </label>
                                        <div id="pcGallerySlots" class="d-flex flex-wrap gap-2"></div>
                                        <button type="button" id="pcGalleryAdd" class="btn btn-sm btn-outline-secondary mt-2"><i class="fas fa-plus me-1"></i>Přidat foto</button>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1 d-flex align-items-center gap-2">
                                            <input class="form-check-input mt-0" type="checkbox" id="pcShow360" checked>
                                            <span>3. 360° prohlídka <span class="text-white-50">(FOTKY z točny — 8 až 48 kolem dokola; nebo video se dvěma otočkami. Server sám odmaže pozadí a vyrobí otáčení na eshop)</span></span>
                                        </label>
                                        <input type="file" id="pcVideo360" class="form-control form-control-sm" multiple accept="image/jpeg,image/png,image/webp,image/heic,.jpg,.jpeg,.png,.webp,.heic,.heif,video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm">
                                        <div id="pcVideoStatus" class="small mt-1"></div>
                                        <div id="pcVideo360Proc" class="small mt-1" style="display:none"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ── živý náhled ── -->
                    <div class="col-lg-4 col-xxl-3">
                        <div class="glass-panel p-3 border-secondary h-100 d-flex flex-column">
                            <div class="small text-white-50 mb-1">Název produktu (generuje se sám)</div>
                            <div id="pcPreviewTitle" class="fs-6 fw-bold mb-3" style="overflow-wrap:anywhere;">—</div>
                            <div class="small text-white-50 mb-1">Popis</div>
                            <div id="pcPreviewDesc" class="small mb-3" style="color:rgba(255,255,255,.75);">—</div>
                            <div id="pcPreviewImgWrap" class="mb-3" style="display:none;">
                                <img id="pcPreviewImg" src="" alt="foto" class="rounded shadow-sm" style="max-width:130px;max-height:130px;object-fit:cover;">
                            </div>
                            <div id="pcHint" class="alert alert-warning border-0 py-2 small" style="display:none;"></div>
                            <div class="mt-auto small text-white-50">Dnes přidáno: <strong id="pcTodayCount"><?php
                                try { echo (int)$pdo->query("SELECT COUNT(*) FROM products WHERE DATE(added_at) = CURDATE()")->fetchColumn(); }
                                catch (Throwable $e) { echo '—'; } ?></strong> ks</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <span id="pcMsg" class="me-auto small"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <?php /* Při NASKLADNĚNÍ je jediná možnost „Přidat a vytisknout štítek" (přání
                         majitele 1.8.2026): dřív tu bylo i holé „Přidat" a zboží pak leželo
                         v regále bez cenovky, protože si obsluha vybrala vedlejší tlačítko.
                         Tenhle knoflík zůstává jen pro EDITACI jako „Uložit změny". */ ?>
                <button type="button" class="btn btn-outline-success" id="pcSaveBtn" style="display:none;"><i class="fas fa-save me-1"></i> Uložit změny</button>
                <button type="button" class="btn btn-success" id="pcSavePrintBtn" title="Ctrl/Cmd + Enter"><i class="fas fa-tag me-1"></i> Přidat a vytisknout štítek</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function printProductLabel(productId, copies, akce) {
    copies = Math.max(1, Math.min(20, parseInt(copies || 1, 10) || 1));
    var fd = new FormData();
    fd.append('action', 'print_product');
    fd.append('id', productId);
    fd.append('copies', String(copies));
    if (akce) { fd.append('akce', '1'); }
    fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
    return fetch('api/print_label_server.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) { return { ok: true, copies: d.copies || copies, via_bridge: false }; }
            if (d.bridge_ok && d.bridge_product && (d.not_paired || d.unreachable) && window.afxProductLabelViaBridge) {
                return window.afxProductLabelViaBridge(d.bridge_product, d.copies || copies, d.printer_model)
                    .then(function (printed) {
                        if (printed) { return { ok: true, copies: d.copies || copies, via_bridge: true }; }
                        throw new Error(d.error || 'Lokální můstek štítek nevytiskl.');
                    });
            }
            throw new Error(d.error || 'Tisk selhal.');
        });
}

// tisk cenového štítku — smí každý přihlášený (recepce tiskne cenovky)
$(document).on('click', '.product-label-btn', function () {
    var btn = this, ic = btn.querySelector('i');
    if (btn.disabled) return;
    btn.disabled = true;
    ic.className = 'fas fa-spinner fa-spin';
    var akce = btn.dataset.akce === '1';
    var icDefault = akce ? 'fas fa-fire' : 'fas fa-tag';
    printProductLabel(btn.dataset.id, 1, akce)
        .then(function (d) {
            btn.disabled = false; ic.className = icDefault;
            showAlert((akce ? 'AKČNÍ štítek (červený) ' : 'Štítek ') + (d.via_bridge ? 'vytištěn přes tenhle počítač.' : 'odeslán na tiskárnu.'));
        })
        .catch(function (err) { btn.disabled = false; ic.className = icDefault; showAlert('Tisk selhal: ' + escHtml(err.message || '')); });
});

<?php if ($canManage): ?>
// ═══ Naskladnit produkt — port Mac appky ═══
(function () {
    <?php /* Seznamy = vestavěný katalog + vlastní hodnoty z product_catalog_custom
             (co se jednou vyplní přes „✏️ Vlastní…", je příště v nabídce). */
    $pcMerged = afxProductCatalogMerged(); ?>
    var CATALOG = <?php echo json_encode([
        'manufacturers' => $pcMerged['manufacturers'],
        'types' => $pcMerged['types'],
        'accessoryTypes' => $pcMerged['accessoryTypes'],
        'accessoryColors' => $pcMerged['accessoryColors'],
        'accessoryProperties' => $pcMerged['accessoryProperties'],
        'caps' => AFX_CAPS, 'rams' => AFX_RAMS, 'cpus' => AFX_CPU_CORES, 'gpus' => AFX_GPU_CORES,
        'gpuModels' => $pcMerged['gpuModels'],
        'processors' => $pcMerged['processors'],
        'grades' => AFX_GRADE_LABELS,
        'years' => array_map('strval', range(2026, 2010)),
        'gens' => array_map('strval', range(1, 11)),
    ], JSON_UNESCAPED_UNICODE); ?>;
    var CSRF = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    var DEFAULT_STOCK = '<?php echo $defaultStockKey; ?>';
    var ACCESSORY_MODE = <?php echo $isAccessoryTab ? 'true' : 'false'; ?>;
    var CUSTOM = '✏️ Vlastní…';

    var el = function (id) { return document.getElementById(id); };
    var $manuf = el('pcManufacturer'), $manufC = el('pcManufacturerCustom'), $typC = el('pcTypCustom');
    var $typ = el('pcTyp'), $model = el('pcModel'), $modelC = el('pcModelCustom'),
        $accessoryForModel = el('pcAccessoryForModel'), $accessoryProperty = el('pcAccessoryProperty'), $accessoryPropertyC = el('pcAccessoryPropertyCustom'),
        $accessoryText = el('pcAccessoryText'),
        $cap = el('pcCap'), $color = el('pcColor'), $colorC = el('pcColorCustom'),
        $grade = el('pcGrade'), $stockKey = el('pcStockKey'), $bat = el('pcBattery'),
        $price = el('pcPrice'), $purchase = el('pcPurchasePrice'), $serial = el('pcSerial'), $ram = el('pcRam'),
        $processorFamily = el('pcProcessorFamily'), $processorModel = el('pcProcessorModel'), $processorModelC = el('pcProcessorModelCustom'),
        $cpu = el('pcCpu'), $gpu = el('pcGpu'), $gpuModel = el('pcGpuModel'), $gpuModelC = el('pcGpuModelCustom'),
        $rocnik = el('pcRocnik'), $gen = el('pcGenerace'),
        $sold = el('pcSold'), $photo = el('pcPhoto'), $imageUrl = el('pcImageUrl'),
        $badge = el('pcPcrBadge'), $msg = el('pcMsg'), $hint = el('pcHint'), $editId = el('pcEditId');

    // Stejný nativní mechanismus jako pole Stav: pouze naplnění <select>.
    function fillSelect(sel, values, withEmpty, withCustom) {
        sel.innerHTML = '';
        if (withEmpty) sel.appendChild(new Option('—', ''));
        values.forEach(function (v) { sel.appendChild(new Option(v, v)); });
        if (withCustom) sel.appendChild(new Option(CUSTOM, CUSTOM));
    }
    function manufacturerVal() { return ACCESSORY_MODE ? '' : ($manuf.value === CUSTOM ? $manufC.value.trim() : $manuf.value.trim()); }
    function typVal() { return $typ.value === CUSTOM ? $typC.value.trim() : $typ.value; }
    function typeMatchesManufacturer(t, manuf) { return (t.manuf || '') === manuf; }
    function typeMatchesCategory(t, categoryCode) {
        return !!categoryCode && (t.k === categoryCode || ((t.kmatch || []).indexOf(categoryCode) >= 0));
    }
    function typeOptionsForManufacturer(manuf) {
        if (ACCESSORY_MODE) return CATALOG.accessoryTypes || [];
        var opts = CATALOG.types.filter(function (t) { return typeMatchesManufacturer(t, manuf); });
        // Vlastní výrobce může mít pár VLASTNÍCH typů (custom:true) — ty ale nesmí
        // vytlačit obecnou nabídku (Telefon, Tablet…), proto se obecné typy PŘIPOJÍ,
        // dokud výrobce nemá žádný vestavěný typ. Dedup níž nechá vyhrát specifický def.
        if (!opts.some(function (t) { return !t.custom; }) || $manuf.value === CUSTOM) {
            opts = opts.concat(CATALOG.types.filter(function (t) { return !(t.manuf || ''); }));
        }
        var seen = {};
        return opts.filter(function (t) {
            if (seen[t.id]) return false;
            seen[t.id] = true;
            return true;
        });
    }
    function typeDef() {
        var tv = typVal();
        var mv = manufacturerVal();
        if (ACCESSORY_MODE) {
            for (var a = 0; a < (CATALOG.accessoryTypes || []).length; a++) {
                if (CATALOG.accessoryTypes[a].id === tv) return CATALOG.accessoryTypes[a];
            }
            return { id: tv, manuf: '', k: '', cap: false, ram: false, gen: false, colors: CATALOG.accessoryColors || [], models: [], accessory: true };
        }
        for (var i = 0; i < CATALOG.types.length; i++) {
            if (CATALOG.types[i].id === tv && typeMatchesManufacturer(CATALOG.types[i], mv)) return CATALOG.types[i];
        }
        for (var j = 0; j < CATALOG.types.length; j++) {
            if (CATALOG.types[j].id === tv && !(CATALOG.types[j].manuf || '')) {
                var g = Object.assign({}, CATALOG.types[j]);
                g.manuf = mv;
                return g;
            }
        }
        return { id: tv, manuf: mv, k: '', cap: true, ram: false, gen: false, colors: [], models: [] };
    }
    function modelVal() {
        if (ACCESSORY_MODE) return typVal();
        return $model.value === CUSTOM ? $modelC.value.trim() : $model.value.trim();
    }
    function accessoryForModelVal() { return ACCESSORY_MODE ? $accessoryForModel.value.trim() : ''; }
    function accessoryPropertyVal() { return ACCESSORY_MODE ? ($accessoryProperty.value === CUSTOM ? $accessoryPropertyC.value.trim() : $accessoryProperty.value.trim()) : ''; }
    function accessoryTextVal() { return ACCESSORY_MODE ? $accessoryText.value.trim() : ''; }
    function serialVal() { return ACCESSORY_MODE ? '' : $serial.value.trim(); }
    function colorVal() { return $color.value === CUSTOM ? $colorC.value.trim() : $color.value.trim(); }
    function processorFamilyVal() {
        var fam = $processorFamily.value.trim();
        return processorFamiliesForType().indexOf(fam) >= 0 ? fam : '';
    }
    function processorModelVal() { return $processorModel.value === CUSTOM ? $processorModelC.value.trim() : $processorModel.value.trim(); }
    function typeHasProcessorFields() {
        if (ACCESSORY_MODE) return false;
        var hay = (typVal() + ' ' + modelVal()).toLowerCase();
        return ['macbook', 'notebook', 'laptop', 'počítač', 'pocitac', 'computer', 'desktop', 'pc', 'imac', 'mac mini', 'mac studio', 'mac pro']
            .some(function (needle) { return hay.indexOf(needle) >= 0; });
    }
    function gpuModelVal() { return $gpuModel.value === CUSTOM ? $gpuModelC.value.trim() : $gpuModel.value.trim(); }
    // JS zrcadlo afxProductCoreFieldsMode(): 'apple' = Jader CPU+GPU (Mac hardware),
    // 'pc' = Jader CPU + Grafická karta (běžný notebook/PC), '' = nic (telefony…).
    function coreFieldsMode() {
        if (ACCESSORY_MODE || !typeHasProcessorFields()) return '';
        if (typeDef().manuf === 'Apple') return 'apple';
        var hay = (typVal() + ' ' + modelVal()).toLowerCase();
        return ['macbook', 'imac', 'mac mini', 'mac studio', 'mac pro']
            .some(function (needle) { return hay.indexOf(needle) >= 0; }) ? 'apple' : 'pc';
    }
    function syncCoreVisibility() {
        var mode = coreFieldsMode();
        document.querySelectorAll('.pc-core-cpu').forEach(function (n) { n.style.display = mode !== '' ? '' : 'none'; });
        document.querySelectorAll('.pc-core-gpu').forEach(function (n) { n.style.display = mode === 'apple' ? '' : 'none'; });
        document.querySelectorAll('.pc-gpu-model').forEach(function (n) { n.style.display = mode === 'pc' ? '' : 'none'; });
        syncFieldVisibility();
    }
    // JS zrcadlo afxProductFieldRelevance(): Úložiště jen kde t.cap, Baterie jen
    // u zařízení s baterií, RAM jen u telefonů/tabletů/počítačů/konzolí.
    function fieldRelevance() {
        if (ACCESSORY_MODE) return { cap: false, battery: false, ram: false };
        var t = typeDef();
        var id = String(t.id || '').toLowerCase();
        var hay = (typVal() + ' ' + modelVal()).toLowerCase();
        var desktop = id === 'pc' || ['imac', 'mac mini', 'mac studio', 'mac pro', 'počítač', 'pocitac', 'desktop']
            .some(function (n) { return hay.indexOf(n) >= 0; });
        var noBattery = desktop || id === 'apple tv' || id === 'homepod';
        var noRam = ['apple watch', 'hodinky', 'airpods', 'sluchátka', 'apple tv', 'homepod'].indexOf(id) >= 0;
        return { cap: !!t.cap, battery: !noBattery, ram: !noRam };
    }
    function syncFieldVisibility() {
        var rel = fieldRelevance();
        document.querySelectorAll('.pc-field-cap').forEach(function (n) { n.style.display = rel.cap ? '' : 'none'; });
        document.querySelectorAll('.pc-field-battery').forEach(function (n) { n.style.display = rel.battery ? '' : 'none'; });
        document.querySelectorAll('.pc-field-ram').forEach(function (n) { n.style.display = rel.ram ? '' : 'none'; });
    }
    function processorFamiliesForType() {
        if (!typeHasProcessorFields()) return [];
        return typeDef().manuf === 'Apple' ? ['Intel', 'M chip - ARM'] : ['AMD', 'Intel'];
    }
    function processorDisplayVal() {
        if (!typeHasProcessorFields()) return '';
        var fam = processorFamilyVal(), model = processorModelVal();
        if (!fam) return '';
        if (!model) return fam;
        var low = model.toLowerCase();
        if (fam !== 'M chip - ARM' && low.indexOf(fam.toLowerCase()) >= 0) return model;
        if (fam === 'M chip - ARM') {
            if (/^(apple\s+)?m\d/i.test(model)) return model;
            return 'Apple ' + model;
        }
        return fam + ' ' + model;
    }
    function normSpec(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9á-ž]+/gi, ' ').replace(/\s+/g, ' ').trim(); }
    function titleHasProcessor(titleBase, processor) {
        var hay = normSpec(titleBase), needle = normSpec(processor);
        var noBrand = needle.replace(/^(apple|amd|intel)\s+/, '').trim();
        var chip = needle.match(/\bm\d(?:\s+(?:pro|max|ultra))?\b/);
        if (chip && hay.indexOf(chip[0]) >= 0) return true;
        return !!needle && (hay.indexOf(needle) >= 0 || (!!noBrand && hay.indexOf(noBrand) >= 0));
    }
    function processorTitlePart(titleBase, processor) {
        if (!processor || titleHasProcessor(titleBase, processor)) return '';
        var part = processor, m = part.match(/^(Intel|AMD|Apple)\s+/);
        if (m && (' ' + normSpec(titleBase) + ' ').indexOf(' ' + m[1].toLowerCase() + ' ') >= 0) {
            part = part.replace(/^(Intel|AMD|Apple)\s+/, '');
        }
        return part.trim();
    }
    function displayModelVal(t, model) {
        model = String(model || '').trim();
        if (ACCESSORY_MODE) {
            var base = model || String((t && t.id) || '').trim();
            var parts = [base];
            if (accessoryForModelVal()) parts.push('pro ' + accessoryForModelVal());
            if (accessoryPropertyVal()) parts.push(accessoryPropertyVal());
            return parts.filter(Boolean).join(' ').trim();
        }
        if (!model) return '';
        var typeId = String((t && t.id) || '').trim();
        var manuf = String((t && t.manuf) || manufacturerVal() || '').trim();
        var low = model.toLowerCase();
        if (typeId && low.indexOf(typeId.toLowerCase()) === 0) return model;
        if (manuf && low.indexOf(manuf.toLowerCase()) === 0) return model;
        if (manuf && manuf !== 'Apple') return model;
        if (typeId) return typeId + ' ' + model;
        return model;
    }
    function titleModelVal(t, displayModel) {
        var manuf = String((t && t.manuf) || manufacturerVal() || '').trim();
        if (manuf && manuf !== 'Apple' && displayModel.toLowerCase().indexOf(manuf.toLowerCase()) !== 0) {
            return manuf + ' ' + displayModel;
        }
        return displayModel;
    }
    function clearProcessor() {
        fillSelect($processorFamily, processorFamiliesForType(), true, false);
        $processorFamily.value = '';
        fillSelect($processorModel, [], true, false);
        $processorModel.value = '';
        $processorModelC.value = '';
        $processorModelC.style.display = 'none';
    }
    function syncProcessorFamilyOptions() {
        var families = processorFamiliesForType();
        var current = $processorFamily.value.trim();
        fillSelect($processorFamily, families, true, false);
        if (current && families.indexOf(current) >= 0) {
            $processorFamily.value = current;
            return;
        }
        $processorFamily.value = '';
        fillSelect($processorModel, [], true, false);
        $processorModel.value = '';
        $processorModelC.value = '';
        $processorModelC.style.display = 'none';
    }
    function syncProcessorVisibility() {
        var show = !ACCESSORY_MODE && typeHasProcessorFields();
        if (show) syncProcessorFamilyOptions();
        document.querySelectorAll('.pc-processor').forEach(function (n) { n.style.display = show ? '' : 'none'; });
        if (!show) clearProcessor();
        document.querySelectorAll('.pc-processor-model').forEach(function (n) { n.style.display = (show && processorFamilyVal()) ? '' : 'none'; });
        syncCoreVisibility();
    }
    function onProcessorFamily(clearModel) {
        var fam = processorFamilyVal();
        if (!fam) $processorFamily.value = '';
        fillSelect($processorModel, (fam && CATALOG.processors[fam]) ? CATALOG.processors[fam] : [], true, !!fam);
        if (clearModel) {
            $processorModel.value = '';
            $processorModelC.value = '';
            $processorModelC.style.display = 'none';
        }
        syncProcessorVisibility();
        refreshPreview();
    }
    function setProcessorValues(fam, model) {
        clearProcessor();
        if (!typeHasProcessorFields() || !fam || processorFamiliesForType().indexOf(fam) < 0 || !CATALOG.processors[fam]) { syncProcessorVisibility(); return; }
        $processorFamily.value = fam;
        onProcessorFamily(false);
        if ((CATALOG.processors[fam] || []).indexOf(model) >= 0) { $processorModel.value = model; }
        else if (model) { $processorModel.value = CUSTOM; $processorModelC.style.display = ''; $processorModelC.value = model; }
        syncProcessorVisibility();
        refreshPreview();
    }

    // JS zrcadlo build_title() — jen pro živý náhled, server počítá autoritativně
    function buildTitle() {
        var t = typeDef();
        var model = modelVal();
        var dm = displayModelVal(t, model);
        var titleModel = titleModelVal(t, dm);
        var cap = t.cap ? $cap.value : '';
        // t.ram = jen „macový" formát názvu (X/Y SSD); RAM a jádra dle relevance typu
        // (Mac = CPU+GPU, PC = jen CPU, hodinky/sluchátka bez RAM, ostatní nic).
        var coreMode = coreFieldsMode();
        var ram = fieldRelevance().ram ? $ram.value : '',
            cpu = coreMode !== '' ? $cpu.value : '', gpu = coreMode === 'apple' ? $gpu.value : '';
        var mem = t.ram
            ? ((ram && cap) ? ram + '/' + cap + ' SSD' : (ram ? ram + ' RAM' : cap))
            : [ram ? ram + ' RAM' : '', cap].filter(Boolean).join(' ');
        var cores = [cpu ? cpu + ' CPU' : '', gpu ? gpu + ' GPU' : ''].filter(Boolean).join(' ');
        var processor = processorDisplayVal();
        var specParts = [];
        var processorTitle = processorTitlePart(titleModel, processor);
        if (processorTitle) specParts.push(processorTitle);
        if (cores && mem) specParts.push(cores + ', ' + mem);
        else if (cores || mem) specParts.push(cores || mem);
        var spec = specParts.join(', ');
        // stav (grade) do názvu nepatří — jen v buňce/parametru/popisu
        return [titleModel, spec, colorVal()].filter(Boolean).join(' ').trim();
    }
    function buildDesc() {
        var t = typeDef();
        var out = [];
        var gr = ($grade.value || '').split(' ')[0] || 'A';
        if (gr) out.push('Stav: ' + gr);
        var rel = fieldRelevance();
        if (rel.battery && $bat.value) out.push('Kondice baterie: ' + $bat.value + ' %');
        var processor = processorDisplayVal();
        if (processor) out.push('Procesor: ' + processor);
        var coreMode = coreFieldsMode();
        if (coreMode !== '' && $cpu.value) out.push('Jader CPU: ' + $cpu.value);
        if (coreMode === 'apple' && $gpu.value) out.push('Jader GPU: ' + $gpu.value);
        if (coreMode === 'pc' && gpuModelVal()) out.push('Grafická karta: ' + gpuModelVal());
        if (rel.ram && $ram.value) out.push('RAM: ' + $ram.value);
        if (t.cap && $cap.value) out.push('Úložiště: ' + $cap.value);
        if (accessoryForModelVal()) out.push('Pro model: ' + accessoryForModelVal());
        if (accessoryPropertyVal()) out.push('Vlastnost: ' + accessoryPropertyVal());
        if (accessoryTextVal()) out.push('Vlastní text: ' + accessoryTextVal());
        if (colorVal()) out.push('Barva: ' + colorVal());
        if ($rocnik.value) out.push('Ročník: ' + $rocnik.value);
        if (t.gen && $gen.value) out.push('Generace: ' + $gen.value);
        out.push('Zvláštní režim DPH §90 (použité zboží)');
        return out.join(' | ');
    }
    function refreshPreview() {
        el('pcPreviewTitle').textContent = buildTitle() || '—';
        el('pcPreviewDesc').textContent = buildDesc();
    }

    function onManufacturer() {
        var opts = typeOptionsForManufacturer(manufacturerVal());
        fillSelect($typ, opts.map(function (t) { return t.id; }), false, true);
        $typC.style.display = 'none';
        onType();
    }

    function onType() {
        var t = typeDef();
        fillSelect($model, t.models, true, true);
        fillSelect($color, t.colors, true, true);
        $modelC.style.display = 'none'; $colorC.style.display = 'none';
        clearProcessor();
        syncProcessorVisibility();
        document.querySelectorAll('.pc-ipad').forEach(function (n) { n.style.display = t.gen ? '' : 'none'; });
        refreshPreview();
    }

    function syncCatalogModeLayout() {
        var accessoryOnlyIds = ['pcCap', 'pcBattery', 'pcRam', 'pcCpu', 'pcGpu', 'pcGpuModel', 'pcRocnik'];
        var heading = el('pcDeviceHeading'), icon = el('pcDeviceIcon'), typLabel = el('pcTypLabel');
        if (heading) heading.textContent = ACCESSORY_MODE ? 'Příslušenství' : 'Zařízení';
        if (typLabel) typLabel.textContent = ACCESSORY_MODE ? 'Typ příslušenství' : 'Typ zařízení';
        if (icon) icon.className = ACCESSORY_MODE ? 'fas fa-plug text-info' : 'fas fa-mobile-alt text-info';
        if (el('pcManufacturerGroup')) el('pcManufacturerGroup').style.display = ACCESSORY_MODE ? 'none' : '';
        if (el('pcModelGroup')) el('pcModelGroup').style.display = ACCESSORY_MODE ? 'none' : '';
        if (el('pcAccessoryForModelGroup')) el('pcAccessoryForModelGroup').style.display = ACCESSORY_MODE ? '' : 'none';
        if (el('pcAccessoryPropertyGroup')) el('pcAccessoryPropertyGroup').style.display = ACCESSORY_MODE ? '' : 'none';
        if (el('pcSerialGroup')) el('pcSerialGroup').style.display = ACCESSORY_MODE ? 'none' : '';
        if (el('pcPcrGroup')) el('pcPcrGroup').style.display = ACCESSORY_MODE ? 'none' : '';
        if (el('pcAccessoryTextGroup')) el('pcAccessoryTextGroup').style.display = ACCESSORY_MODE ? '' : 'none';
        accessoryOnlyIds.forEach(function (id) {
            var n = el(id), box = n ? n.closest('[class*="col-md-"]') : null;
            if (box) box.style.display = ACCESSORY_MODE ? 'none' : '';
            if (ACCESSORY_MODE && n) n.value = '';
        });
        if (ACCESSORY_MODE) {
            $bat.value = '';
            $ram.value = '';
            $cpu.value = '';
            $gpu.value = '';
            $gpuModel.value = '';
            $gpuModelC.value = '';
            $gpuModelC.style.display = 'none';
            $rocnik.value = '';
            $gen.value = '';
            $serial.value = '';
            // POZOR: editProductCode se tu NEmaže — syncCatalogModeLayout() běží i na KONCI
            // načtení existujícího kusu, takže by právě načtený kód zahodila a 360° by
            // u příslušenství nešla nahrát nikdy. Reset patří tam, kde začíná nový kus.
            clearProcessor();
            // Při prvotní inicializaci je badgeStyles ještě nedefinované.
            // Nevyhazovat zde JS chybu: jinak se onManufacturer() už nespustí
            // a dropdowny Typ a Barva zůstanou úplně bez položek.
            if (typeof badgeStyles !== 'undefined' && badgeStyles) setBadge('none');
        } else {
            $accessoryForModel.value = '';
            $accessoryProperty.value = '';
            $accessoryPropertyC.value = '';
            $accessoryPropertyC.style.display = 'none';
            $accessoryText.value = '';
        }
        // režim jader/grafiky až PO přepnutí layoutu — smyčka výš boxy plošně odkryla
        syncCoreVisibility();
    }

    // hodnota mimo výčet selectu se nesmí tiše zahodit (starší kusy: 3 TB, rok 2009…)
    function setSelectValue(sel, val) {
        val = val || '';
        if (val !== '' && !Array.prototype.some.call(sel.options, function (o) { return o.value === val; })) {
            sel.insertBefore(new Option(val, val), sel.options[sel.options.length] || null);
        }
        sel.value = val;
    }
    function setManufacturerValue(val) {
        val = val || '';
        if (val && CATALOG.manufacturers.indexOf(val) >= 0) {
            $manuf.value = val;
            $manufC.value = '';
            $manufC.style.display = 'none';
        } else {
            $manuf.value = CUSTOM;
            $manufC.value = val;
            $manufC.style.display = '';
        }
        onManufacturer();
    }
    function inferManufacturerFromProduct(p) {
        var manuf = p.manufacturer || '';
        if (manuf) return manuf;
        CATALOG.types.forEach(function (t) { if (!manuf && typeMatchesCategory(t, p.category_code || '')) manuf = t.manuf || ''; });
        CATALOG.manufacturers.forEach(function (m) {
            if (!manuf && p.title && p.title.toLowerCase().indexOf(m.toLowerCase() + ' ') === 0) manuf = m;
        });
        return manuf;
    }
    function inferAccessoryTypeFromText(text, opts) {
        if (!ACCESSORY_MODE) return '';
        var hay = (' ' + String(text || '').toLowerCase() + ' ').replace(/\s+/g, ' ');
        var hasOption = function (id) {
            return opts.some(function (t) { return t.id === id; });
        };
        var rules = [
            ['Kabel USB-C', ['usb-c', 'usb c', 'type-c', 'type c']],
            ['Kabel Lightning', ['lightning', 'lighting']],
            ['Kabel Micro USB', ['micro usb', 'micro-usb']],
            ['Kabel HDMI', ['hdmi']],
            ['Kabel USB-A', ['usb-a', 'usb a']],
            ['Obal / kryt', ['obal', 'kryt', 'pouzdro', 'case', 'cover']],
            ['Ochranné sklo', ['sklo', 'glass']],
            ['Fólie', ['folie', 'fólie']],
            ['Adaptér', ['adaptér', 'adapter']],
            ['Nabíječka', ['nabíječka', 'nabijecka', 'charger']],
            ['Redukce / hub', ['redukce', 'hub']],
            ['Klávesnice', ['klávesnice', 'klavesnice', 'keyboard']],
            ['Myš', ['myš', 'mys', 'mouse']],
            ['Sluchátka', ['sluchátka', 'sluchatka', 'airpods', 'beats', 'headphone', 'earphone']],
            ['Powerbanka', ['powerbanka', 'powerbank']],
            ['MagSafe příslušenství', ['magsafe']],
            ['Řemínek', ['řemínek', 'reminek', 'pásek', 'pasek', 'strap']],
            ['Stylus / Apple Pencil', ['stylus', 'pencil', 'apple pencil']],
            ['AirTag / lokátor', ['airtag', 'lokátor', 'lokator']],
            ['Dock / stanice', ['dock', 'stanice']],
            ['Držák / stojan', ['držák', 'drzak', 'stojan']],
            ['Čtečka karet', ['čtečka', 'ctecka', 'reader']],
            ['Externí disk / box', ['externí disk', 'externi disk', 'box']],
            ['Reproduktor', ['reproduktor', 'speaker']],
        ];
        for (var i = 0; i < rules.length; i++) {
            if (!hasOption(rules[i][0])) continue;
            for (var j = 0; j < rules[i][1].length; j++) {
                if (hay.indexOf(rules[i][1][j]) >= 0) return rules[i][0];
            }
        }
        return '';
    }
    function inferTypeFromProduct(p) {
        var opts = typeOptionsForManufacturer(manufacturerVal());
        var typ = p.typ || '';
        if (typ && opts.some(function (t) { return t.id === typ; })) return typ;
        opts.forEach(function (t) { if (!typ && typeMatchesCategory(t, p.category_code || '')) typ = t.id; });
        opts.forEach(function (t) { if (!typ && p.title && p.title.indexOf(t.id) === 0) typ = t.id; });
        if (ACCESSORY_MODE && !typ) {
            var hay = ((p.title || '') + ' ' + (p.model || '')).toLowerCase();
            typ = inferAccessoryTypeFromText(hay, opts);
            opts.forEach(function (t) {
                if (!typ && hay.indexOf(String(t.id || '').toLowerCase()) >= 0) typ = t.id;
            });
        }
        return typ;
    }

    // init
    fillSelect($manuf, CATALOG.manufacturers, false, true);
    fillSelect($cap, CATALOG.caps, true, false);
    fillSelect($grade, CATALOG.grades, false, false);
    fillSelect($ram, CATALOG.rams, true, false);
    fillSelect($processorFamily, [], true, false);
    fillSelect($processorModel, [], true, false);
    fillSelect($accessoryProperty, CATALOG.accessoryProperties || [], true, true);
    fillSelect($cpu, CATALOG.cpus, true, false);
    fillSelect($gpu, CATALOG.gpus, true, false);
    fillSelect($gpuModel, CATALOG.gpuModels || [], true, true);
    fillSelect($rocnik, CATALOG.years, true, false);
    fillSelect($gen, CATALOG.gens, true, false);
    $grade.value = 'Nový';
    $stockKey.value = DEFAULT_STOCK;
    if (!ACCESSORY_MODE && CATALOG.manufacturers.indexOf('Apple') >= 0) $manuf.value = 'Apple';
    syncCatalogModeLayout();
    onManufacturer();

    $manuf.addEventListener('change', function () {
        $manufC.style.display = $manuf.value === CUSTOM ? '' : 'none';
        onManufacturer();
    });
    $typ.addEventListener('change', function () {
        $typC.style.display = $typ.value === CUSTOM ? '' : 'none';
        onType();
    });
    $model.addEventListener('change', function () { $modelC.style.display = $model.value === CUSTOM ? '' : 'none'; syncProcessorVisibility(); refreshPreview(); });
    $color.addEventListener('change', function () { $colorC.style.display = $color.value === CUSTOM ? '' : 'none'; refreshPreview(); });
    $processorFamily.addEventListener('change', function () { onProcessorFamily(true); });
    $processorModel.addEventListener('change', function () { $processorModelC.style.display = $processorModel.value === CUSTOM ? '' : 'none'; refreshPreview(); });
    $gpuModel.addEventListener('change', function () { $gpuModelC.style.display = $gpuModel.value === CUSTOM ? '' : 'none'; refreshPreview(); });
    $accessoryProperty.addEventListener('change', function () { $accessoryPropertyC.style.display = $accessoryProperty.value === CUSTOM ? '' : 'none'; refreshPreview(); });
    [$manufC, $typC, $modelC].forEach(function (n) {
        n.addEventListener('input', function () { syncProcessorVisibility(); refreshPreview(); });
        n.addEventListener('change', function () { syncProcessorVisibility(); refreshPreview(); });
    });
    [$manufC, $typC, $modelC, $accessoryForModel, $accessoryPropertyC, $accessoryText, $colorC, $cap, $grade, $bat, $ram, $processorModelC, $cpu, $gpu, $gpuModelC, $rocnik, $gen].forEach(function (n) {
        n.addEventListener('input', refreshPreview);
        n.addEventListener('change', refreshPreview);
    });

    // ── PČR badge (živý, orientační — server kontroluje znovu) ──
    var badgeStyles = {
        clean: ['rgba(26,140,86,.25)', '#6fe08d', 'PČR: V POŘÁDKU'],
        stolen: ['rgba(200,40,40,.3)', '#ff8080', 'PČR: POZOR – ODCIZENO'],
        unknown: ['rgba(150,120,30,.25)', '#ffd76b', 'PČR: NEOVĚŘENO'],
        error: ['rgba(150,120,30,.25)', '#ffd76b', 'PČR: NEOVĚŘENO (chyba)'],
        notimei: ['rgba(255,255,255,.06)', '#9aa3b2', 'PČR: není IMEI'],
        none: ['rgba(255,255,255,.06)', '#9aa3b2', 'PČR: nekontrolováno'],
    };
    function setBadge(status) {
        var s = badgeStyles[status] || badgeStyles.none;
        $badge.style.background = s[0]; $badge.style.color = s[1]; $badge.textContent = s[2];
    }
    // formGen: generace formuláře — pozdní odpovědi (PČR, foto) z PŘEDCHOZÍHO kusu
    // nesmí zapsat do už vyčištěného/nového formuláře
    var formGen = 0;
    var pending = 0;   // počet BĚŽÍCÍCH uploadů (hlavní foto + studio + galerie + video) — save() počká na všechny

    $serial.addEventListener('blur', function () {
        var v = $serial.value.trim();
        if (v.replace(/\D/g, '').length < 14) { setBadge(v ? 'notimei' : 'none'); return; }
        $badge.textContent = 'PČR: kontroluji…';
        var gen = formGen;
        var fd = new FormData();
        fd.append('imei', v); fd.append('csrf_token', CSRF);
        fetch('api/product_pcr.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (gen === formGen) setBadge(d.status || 'error'); })
            .catch(function () { if (gen === formGen) setBadge('error'); });
    });

    // ── doplnění údajů podle IMEI (v3.61.0) ────────────────────────────────
    // Naskenuje se IMEI a formulář si sám doplní výrobce, typ, model, úložiště
    // a barvu; navíc ukáže Find My / SIM-lock / záruku, což je při výkupu
    // použitého kusu to nejdůležitější. Dotaz je PLACENÝ, proto se ptáme až
    // po opuštění pole, jen jednou na stejné IMEI (server má navíc cache).
    var $imeiInfo = el('pcImeiInfo');
    var imeiAsked = '';

    // Nastaví hodnotu POUZE tehdy, když ji katalog zná. Neznámé hodnoty se
    // schválně NEvyplňují do „✏️ Vlastní…" — po uložení by se natrvalo zapsaly
    // do číselníku (a s nimi i případný nesmysl z API). Jen se nabídnou v hlášce.
    function pcSetSelect(sel, custom, value) {
        if (!sel || !value) return false;
        var want = String(value).trim().toLowerCase();
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value.trim().toLowerCase() === want) {
                sel.value = sel.options[i].value;
                if (custom) { custom.value = ''; custom.style.display = 'none'; }
                return true;
            }
        }
        return false;
    }
    function pcFieldEmpty(sel, custom) {
        if (!sel) return false;
        if (sel.value === CUSTOM) { return !custom || custom.value.trim() === ''; }
        return sel.value.trim() === '';
    }

    function renderImeiInfo(html, tone) {
        if (!$imeiInfo) return;
        if (!html) { $imeiInfo.style.display = 'none'; $imeiInfo.innerHTML = ''; return; }
        var col = tone === 'bad' ? '#ff6b6b' : (tone === 'warn' ? '#ffc46b' : (tone === 'ok' ? '#7ce39a' : 'rgba(255,255,255,.6)'));
        $imeiInfo.style.color = col;
        $imeiInfo.innerHTML = html;
        $imeiInfo.style.display = '';
    }

    // Formulář je „čistý", dokud v něm není ručně vyplněný model ani barva.
    // Jen tehdy se smí sáhnout na výrobce/typ — jejich změna přeplní kaskádou
    // Model, Barvu i procesor (onManufacturer → onType → clearProcessor),
    // takže by jinak smazala to, co obsluha právě napsala.
    function pcFormUntouched() {
        return pcFieldEmpty($model, $modelC) && pcFieldEmpty($color, $colorC)
            && (!$cap || $cap.value.trim() === '');
    }

    function applyImeiInfo(info) {
        var filled = [], skipped = [];
        var untouched = pcFormUntouched();

        // typ zařízení: select NEMÁ prázdnou volbu (po otevření svítí „iPhone"),
        // takže se nesmí testovat na prázdnotu — porovnává se s tím, co říká IMEI
        if (untouched && info.device_type && typVal() !== info.device_type) {
            if (info.manufacturer && manufacturerVal() !== info.manufacturer
                && pcSetSelect($manuf, $manufC, info.manufacturer)) {
                onManufacturer(); filled.push('výrobce');
            }
            if (pcSetSelect($typ, $typC, info.device_type)) { onType(); filled.push('typ'); }
        }

        if (info.model && pcFieldEmpty($model, $modelC)) {
            if (pcSetSelect($model, $modelC, info.model)) { filled.push('model'); }
            else { skipped.push('model „' + info.model + '"'); }
        }
        if (info.capacity && $cap && $cap.value.trim() === '') {
            if (info.capacity_known && pcSetSelect($cap, null, info.capacity)) { filled.push('úložiště'); }
            else { skipped.push('úložiště „' + info.capacity + '"'); }
        }
        if (info.color && pcFieldEmpty($color, $colorC)) {
            if (info.color_known && pcSetSelect($color, $colorC, info.color)) { filled.push('barva'); }
            else { skipped.push('barva „' + info.color + '"'); }
        }
        syncProcessorVisibility();
        refreshPreview();
        return { filled: filled, skipped: skipped };
    }

    function imeiWarnings(info) {
        var w = [];
        if (info.find_my === true) w.push('<b>Find My je ZAPNUTÉ</b> — zařízení je zamčené na iCloud účet');
        if (info.lost_mode === true) w.push('<b>Režim ztraceno</b>');
        if (info.sim_lock === true) w.push('<b>SIM-lock</b> (zamčeno na operátora)');
        if (info.replaced === true) w.push('kus byl vyměněný Applem');
        return w;
    }

    var imeiBusy = false;      // běží dotaz → save() na něj počká (jinak by se
                               // varování „Find My" nikdy neukázalo)
    function lookupImei(value) {
        var digits = String(value || '').replace(/\D/g, '');
        if (ACCESSORY_MODE) { return; }
        if (digits.length < 14) { renderImeiInfo(''); imeiAsked = ''; return; }
        // u editace je formulář vyplněný z databáze — dotaz by jen stál kredit
        if ($editId.value) { return; }
        if (digits === imeiAsked) { return; }
        imeiAsked = digits;
        var gen = formGen;
        imeiBusy = true;
        renderImeiInfo('<i class="fas fa-spinner fa-spin me-1"></i>Zjišťuji údaje k IMEI…', '');
        var fd = new FormData();
        fd.append('imei', digits); fd.append('csrf_token', CSRF);
        return fetch('api/imei_lookup.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (gen !== formGen) return;         // mezitím se otevřel jiný kus
                if (!d || !d.ok || !d.info) {
                    if (!d || d.source !== 'cache') { imeiAsked = ''; }   // ať jde zkusit znovu
                    // ne-Apple: výrobce doplnit jen do nerozepsaného formuláře
                    var note = escapeHtmlSafe((d && d.error) || 'Údaje k IMEI se nepodařilo zjistit.');
                    if (d && d.brand && pcFormUntouched() && manufacturerVal() !== d.brand
                        && pcSetSelect($manuf, $manufC, d.brand)) {
                        onManufacturer(); refreshPreview();
                        note += ' <span style="color:#7ce39a;">Výrobce doplněn.</span>';
                    }
                    renderImeiInfo('<i class="fas fa-circle-info me-1"></i>' + note, 'warn');
                    return;
                }
                var info = d.info;
                var res = applyImeiInfo(info);
                var parts = [];
                if (info.raw_model) parts.push('<b>' + escapeHtmlSafe(info.raw_model) + '</b>');
                if (info.serial) parts.push('SN ' + escapeHtmlSafe(info.serial));
                if (info.warranty) parts.push(escapeHtmlSafe(info.warranty));
                if (info.purchase_date) parts.push('koupeno ' + escapeHtmlSafe(info.purchase_date));
                var warn = imeiWarnings(info);
                var line = '<i class="fas fa-wand-magic-sparkles me-1"></i>' + parts.join(' · ');
                if (res.filled.length) line += '<br><span style="color:#7ce39a;">Doplněno: ' + res.filled.join(', ') + '.</span>';
                if (res.skipped.length) {
                    line += '<br><span style="color:#ffc46b;">Katalog nezná ' + escapeHtmlSafe(res.skipped.join(', '))
                        + ' — vyber ručně (nebo přes „✏️ Vlastní…").</span>';
                }
                if (d.source === 'cache') {
                    line += ' <span style="opacity:.6;">(zjištěno ' + escapeHtmlSafe(String(d.checked_at || '').slice(0, 16)) + ')</span>';
                }
                if (warn.length) {
                    line += '<br><span style="color:#ff6b6b;"><i class="fas fa-triangle-exclamation me-1"></i>'
                        + warn.join(' · ') + '</span>';
                }
                renderImeiInfo(line, warn.length ? 'bad' : 'ok');
            })
            .catch(function () {
                imeiAsked = '';
                if (gen === formGen) { renderImeiInfo('<i class="fas fa-circle-info me-1"></i>Dotaz na údaje k IMEI se nepodařilo odeslat.', 'warn'); }
            })
            .then(function () { imeiBusy = false; });
    }
    function escapeHtmlSafe(t) {
        return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    // ── načtení z telefonu připojeného kabelem k Macu (v3.62.0) ──────────
    // Údaje posílá můstek (device-bridge) na server, odsud se jen vyzvednou.
    // Zdarma a navíc s kondicí baterie — proto je to první volba, iFreeiCloud
    // zůstává pro kusy, které zrovna nejsou po ruce.
    var $deviceBtn = el('pcDeviceRead');
    if ($deviceBtn) {
        $deviceBtn.addEventListener('click', function () {
            var gen = formGen;
            $deviceBtn.disabled = true;
            renderImeiInfo('<i class="fas fa-spinner fa-spin me-1"></i>Čtu připojené zařízení…', '');
            fetch('api/device_last.php', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (gen !== formGen) return;
                    if (!d || !d.ok || !d.info) {
                        renderImeiInfo('<i class="fas fa-circle-info me-1"></i>' + escapeHtmlSafe((d && d.error) || 'Zařízení se nepodařilo přečíst.'), 'warn');
                        return;
                    }
                    var info = d.info;
                    // IMEI a sériové číslo se přepíšou vždy — čtou se přímo ze zařízení,
                    // takže jsou spolehlivější než cokoli opsaného ručně
                    if (info.imei) { $serial.value = info.imei; imeiAsked = String(info.imei).replace(/\D/g, ''); }
                    else if (info.serial) { $serial.value = info.serial; }
                    var res = applyImeiInfo(info);
                    if (info.battery && $bat && $bat.value.trim() === '') {
                        $bat.value = String(info.battery); res.filled.push('baterie');
                    }
                    var parts = [];
                    if (info.model || info.product_type) parts.push('<b>' + escapeHtmlSafe(info.model || info.product_type) + '</b>');
                    if (info.capacity) parts.push(escapeHtmlSafe(info.capacity));
                    if (info.ios) parts.push('iOS ' + escapeHtmlSafe(info.ios));
                    if (info.battery) {
                        parts.push('baterie ' + (info.battery | 0) + ' %'
                            + (info.battery_cycles ? ' (' + (info.battery_cycles | 0) + ' cyklů)' : ''));
                    }
                    if (info.serial) parts.push('SN ' + escapeHtmlSafe(info.serial));
                    var line = '<i class="fab fa-apple me-1"></i>' + parts.join(' · ')
                        + ' <span style="opacity:.6;">(' + escapeHtmlSafe(d.station || 'Mac') + ')</span>';
                    if (res.filled.length) line += '<br><span style="color:#7ce39a;">Doplněno: ' + res.filled.join(', ') + '.</span>';
                    if (res.skipped.length) {
                        line += '<br><span style="color:#ffc46b;">Katalog nezná ' + escapeHtmlSafe(res.skipped.join(', ')) + ' — vyber ručně.</span>';
                    }
                    if (info.activation && info.activation !== 'Activated') {
                        line += '<br><span style="color:#ffc46b;">Stav aktivace: ' + escapeHtmlSafe(info.activation) + '</span>';
                    }
                    renderImeiInfo(line, 'ok');
                    refreshPreview();
                })
                .catch(function () {
                    if (gen === formGen) { renderImeiInfo('<i class="fas fa-circle-info me-1"></i>Můstek se nepodařilo oslovit.', 'warn'); }
                })
                .then(function () { $deviceBtn.disabled = false; });
        });
    }

    var imeiTimer = null;
    $serial.addEventListener('input', function () {
        clearTimeout(imeiTimer);
        var v = $serial.value;
        if (String(v).replace(/\D/g, '').length < 14) { renderImeiInfo(''); imeiAsked = ''; return; }
        imeiTimer = setTimeout(function () { lookupImei(v); }, 700);   // dopsání/doskenování
    });
    $serial.addEventListener('blur', function () { clearTimeout(imeiTimer); lookupImei($serial.value); });

    // ── foto: upload hned po výběru ──
    $photo.addEventListener('change', function () {
        if (!$photo.files.length) return;
        var code = $serial.value.trim() || ('foto-' + Date.now());
        var gen = formGen;
        var fd = new FormData();
        fd.append('image', $photo.files[0]);
        fd.append('code', code);
        fd.append('csrf_token', CSRF);
        pending++;
        $msg.textContent = 'Nahrávám fotku…';
        fetch('api/upload_product_image.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                pending--;
                if (gen !== formGen) return;   // formulář mezitím přešel na další kus
                if (d.success) {
                    $imageUrl.value = d.url;
                    el('pcPreviewImg').src = d.url;
                    el('pcPreviewImgWrap').style.display = '';
                    $msg.textContent = 'Foto přiloženo.';
                } else { $msg.textContent = 'Foto se nenahrálo: ' + (d.message || ''); }
            })
            .catch(function () { pending--; if (gen === formGen) $msg.textContent = 'Foto se nenahrálo (síť).'; });
    });

    // ── Galerie média: studiová fotka, klasické fotky (dynamické sloty), 360° video ──
    var $studioPhoto = el('pcStudioPhoto'), $studioUrl = el('pcStudioUrl'),
        $studioWrap = el('pcStudioWrap'), $studioThumb = el('pcStudioThumb'),
        $gallerySlots = el('pcGallerySlots'), $galleryUrls = el('pcGalleryUrls'), $galleryAdd = el('pcGalleryAdd'),
        $video360 = el('pcVideo360'), $video360Url = el('pcVideo360Url'), $videoStatus = el('pcVideoStatus');
    var galUrls = [];                                        // URL nahraných klasických fotek (dle pořadí slotů)
    function baseCode() { return $serial.value.trim() || ('foto-' + Date.now()); }
    // Kód editovaného kusu — u příslušenství je SN skryté a kód (AFX-…) generuje server,
    // takže bez tohohle by 360° u doplňků nešlo navázat vůbec.
    var editProductCode = '';
    // 360° se k produktu váže VÝHRADNĚ přes kód (složka produkty-360/<kód>/ na disku, žádné
    // pole v DB) — fallback 'foto-<timestamp>' z baseCode() by sadu poslal do složky, kterou
    // eshop nikdy nenajde. Prázdná návratová hodnota = 360° zatím nelze nahrát.
    function code360() { return $serial.value.trim() || editProductCode; }
    function require360Code() {
        var c = code360();
        if (!c) {
            showAlert('Nejdřív vyplň sériové číslo kusu — 360° prohlídka se k produktu váže přes něj.'
                + (ACCESSORY_MODE ? ' U příslušenství produkt nejdřív ulož a pak ho otevři k úpravě.' : ''));
        }
        return c;
    }

    // (1) studiová fotka — průhledné PNG (variant=studio, keep_alpha)
    $studioPhoto.addEventListener('change', function () {
        if (!this.files.length) return;
        var gen = formGen; pending++;
        var fd = new FormData();
        fd.append('image', this.files[0]); fd.append('code', baseCode());
        fd.append('variant', 'studio'); fd.append('keep_alpha', '1'); fd.append('csrf_token', CSRF);
        fetch('api/upload_product_image.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                pending--; if (gen !== formGen) return;
                if (!d.success) { showAlert('Studiová fotka se nenahrála: ' + (d.message || '')); return; }
                $studioUrl.value = d.url; $studioThumb.src = d.url; $studioWrap.style.setProperty('display', 'flex', 'important');
                // „Použít pro celý model" → zapiš i do knihovny fotek modelů (zdědí ji všechny kusy modelu)
                var note = el('pcStudioModelNote');
                if (!el('pcStudioWholeModel').checked) { note.textContent = ''; return; }
                var mv = modelVal(), cv = colorVal();
                if (!mv) { note.textContent = '⚠ Doplň model, ať se fotka přiřadí celému modelu.'; note.className = 'small mt-1 text-warning'; return; }
                note.textContent = 'Přiřazuji celému modelu…'; note.className = 'small mt-1 text-white-50';
                var mf = new FormData();
                mf.append('action', 'set'); mf.append('model', mv); mf.append('color', cv);
                mf.append('studio_url', d.url); mf.append('csrf_token', CSRF);
                fetch('api/model_photos.php', { method: 'POST', body: mf, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (s) {
                        note.textContent = s.success ? ('✓ přiřazeno celému modelu (' + mv + (cv ? ' · ' + cv : '') + ')') : ('Model se nepřiřadil: ' + (s.message || ''));
                        note.className = 'small mt-1 ' + (s.success ? 'text-success' : 'text-danger');
                    })
                    .catch(function () { note.textContent = 'Model se nepřiřadil (síť).'; note.className = 'small mt-1 text-danger'; });
            })
            .catch(function () { pending--; if (gen === formGen) showAlert('Studiová fotka se nenahrála (síť).'); });
    });
    el('pcStudioClear').addEventListener('click', function () {
        $studioUrl.value = ''; $studioThumb.src = ''; $studioWrap.style.setProperty('display', 'none', 'important'); $studioPhoto.value = '';
        el('pcStudioModelNote').textContent = '';
    });

    // (2) klasické fotky — dynamické sloty (3 → max 10)
    function syncGalleryHidden() {
        $galleryUrls.value = JSON.stringify(galUrls.filter(function (u) { return !!u; }));
    }
    function addGallerySlot(url) {
        var idx = $gallerySlots.children.length;
        if (idx >= 10) return;
        var slot = document.createElement('div'); slot.style.width = '98px';
        var thumb = document.createElement('img');
        thumb.className = 'rounded d-block mb-1';
        thumb.style.cssText = 'width:98px;height:74px;object-fit:cover;background:rgba(255,255,255,.05);' + (url ? '' : 'display:none;');
        if (url) { thumb.src = url; }
        var inp = document.createElement('input');
        inp.type = 'file'; inp.accept = 'image/*'; inp.className = 'form-control form-control-sm';
        inp.style.cssText = 'font-size:10px;padding:2px 4px;';
        inp.addEventListener('change', function () {
            if (!this.files.length) return;
            var gen = formGen; pending++;
            var fd = new FormData();
            fd.append('image', this.files[0]); fd.append('code', baseCode());
            fd.append('variant', 'g' + idx); fd.append('csrf_token', CSRF);
            fetch('api/upload_product_image.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    pending--; if (gen !== formGen) return;
                    if (d.success) { galUrls[idx] = d.url; thumb.src = d.url; thumb.style.display = ''; syncGalleryHidden(); }
                    else { showAlert('Fotka se nenahrála: ' + (d.message || '')); }
                })
                .catch(function () { pending--; if (gen === formGen) showAlert('Fotka se nenahrála (síť).'); });
        });
        slot.appendChild(thumb); slot.appendChild(inp);
        $gallerySlots.appendChild(slot);
        $galleryAdd.style.display = ($gallerySlots.children.length >= 10) ? 'none' : '';
    }
    $galleryAdd.addEventListener('click', function () { addGallerySlot(''); });

    // (3) 360° prohlídka — FOTKY z točny (preferované) NEBO video (starší cesta).
    //     Rozliší se podle vybraných souborů: obrázky → upload_product_360_photos.php,
    //     jedno video → upload_product_video.php. Míchat dohromady nejde.
    $video360.addEventListener('change', function () {
        if (!this.files.length) return;
        var files = Array.prototype.slice.call(this.files);
        var isImg = function (f) { return /^image\//.test(f.type) || /\.(jpe?g|png|webp|heic|heif)$/i.test(f.name); };
        var isVid = function (f) { return /^video\//.test(f.type) || /\.(mp4|mov|webm|m4v)$/i.test(f.name); };
        var imgs = files.filter(isImg), vids = files.filter(isVid);
        var gen = formGen;

        if (imgs.length && vids.length) {
            $videoStatus.textContent = 'Vyber buď fotky, nebo jedno video — ne obojí najednou.';
            $videoStatus.className = 'small mt-1 text-danger'; this.value = ''; return;
        }
        // bez kódu produktu nemá 360° kam patřit — odmítnout DŘÍV, než se nahraje ~1 GB fotek
        if (!require360Code()) {
            $videoStatus.textContent = 'Nejdřív vyplň sériové číslo — 360° se váže na kód kusu.';
            $videoStatus.className = 'small mt-1 text-danger'; this.value = ''; return;
        }
        if (imgs.length) {
            if (imgs.length < 8) {
                $videoStatus.textContent = 'Na 360° je potřeba aspoň 8 fotek dokola (vybráno ' + imgs.length + ').';
                $videoStatus.className = 'small mt-1 text-danger'; return;
            }
            pending++;
            $videoStatus.textContent = 'Nahrávám ' + imgs.length + ' fotek…'; $videoStatus.className = 'small mt-1 text-white-50';
            var fdp = new FormData();
            imgs.forEach(function (f) { fdp.append('photos[]', f); });
            // kolik fotek KLIENT vybral — PHP nad max_file_uploads soubory tiše zahazuje,
            // server podle expected_count ořezanou sadu odmítne místo tichého úspěchu
            fdp.append('expected_count', String(imgs.length));
            fdp.append('code', code360()); fdp.append('csrf_token', CSRF);
            fetch('api/upload_product_360_photos.php', { method: 'POST', body: fdp, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    pending--; if (gen !== formGen) return;
                    // server při uploadu fotek smazal případné starší video (poslední upload vyhrává)
                    // → nenechat v DB URL na neexistující soubor
                    if (d.success && d.count === imgs.length) { $video360Url.value = ''; $videoStatus.textContent = '✓ ' + d.count + ' fotek nahráno — server teď odmaže pozadí a vyrobí 360°.'; $videoStatus.className = 'small mt-1 text-success'; poll360(code360(), true); }
                    else if (d.success) { // pojistka proti TICHÉMU ořezu sady (server bez kontroly expected_count)
                        $video360Url.value = '';
                        $videoStatus.textContent = 'Pozor: server přijal jen ' + d.count + ' z ' + imgs.length + ' fotek — sada je neúplná, nahraj ji prosím celou znovu.';
                        $videoStatus.className = 'small mt-1 text-danger';
                    }
                    else { $videoStatus.textContent = 'Fotky se nenahrály: ' + (d.message || ''); $videoStatus.className = 'small mt-1 text-danger'; }
                })
                .catch(function () { pending--; if (gen === formGen) { $videoStatus.textContent = 'Fotky se nenahrály (síť).'; $videoStatus.className = 'small mt-1 text-danger'; } });
            return;
        }
        if (vids.length !== 1) {
            $videoStatus.textContent = 'Vyber fotky z točny (8+), nebo přesně jedno video.';
            $videoStatus.className = 'small mt-1 text-danger'; return;
        }
        pending++;
        $videoStatus.textContent = 'Nahrávám video…'; $videoStatus.className = 'small mt-1 text-white-50';
        var fd = new FormData();
        fd.append('video', vids[0]); fd.append('code', code360()); fd.append('csrf_token', CSRF);
        fetch('api/upload_product_video.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                pending--; if (gen !== formGen) return;
                if (d.success) { $video360Url.value = d.url; $videoStatus.textContent = '✓ Video nahráno — 360° se teď vyrobí na serveru.'; $videoStatus.className = 'small mt-1 text-success'; poll360(code360(), true); }
                else { $videoStatus.textContent = 'Video se nenahrálo: ' + (d.message || ''); $videoStatus.className = 'small mt-1 text-danger'; }
            })
            .catch(function () { pending--; if (gen === formGen) { $videoStatus.textContent = 'Video se nenahrálo (síť).'; $videoStatus.className = 'small mt-1 text-danger'; } });
    });

    // ── 360° zpracování (fáze 2): stav se odvozuje z disku na serveru, průběžně se pollne ──
    var $video360Proc = document.getElementById('pcVideo360Proc');
    var poll360Timer = null, poll360Left = 0;
    function render360(st) {
        if (!st || st.status === 'none') { $video360Proc.style.display = 'none'; $video360Proc.innerHTML = ''; return; }
        $video360Proc.style.display = '';
        if (st.status === 'ready') {
            $video360Proc.innerHTML = '<span class="text-success">✓ 360° prohlídka hotová (' + st.frames + ' snímků)</span>' +
                (st.preview ? ' <img src="' + st.preview + '" alt="" style="height:34px;vertical-align:middle;border-radius:5px;margin-left:6px;background:#fff">' : '') +
                ' <button type="button" id="pcRegen360" class="btn btn-outline-secondary btn-sm py-0 ms-1">Přegenerovat</button>';
        } else if (st.status === 'manual') { // sada *.webp v eshopu bez zdroje v CRM (nahraná ručně) — eshop ji zobrazuje, přegenerovat nejde
            $video360Proc.innerHTML = '<span class="text-success">✓ 360° prohlídka: ručně nahraná sada (' + st.frames + ' snímků)</span>' +
                (st.preview ? ' <img src="' + st.preview + '" alt="" style="height:34px;vertical-align:middle;border-radius:5px;margin-left:6px;background:#fff">' : '');
        } else if (st.status === 'failed') { // dispatcher zdroj odložil (marker .failed) — bez zásahu to znovu nepojede
            $video360Proc.innerHTML = '<span class="text-danger">✖ 360° zpracování selhalo. Zkontroluj sadu (ostré fotky, celý produkt v záběru) a zkus to znovu, případně nahraj fotky nové.</span>' +
                ' <button type="button" id="pcRegen360" class="btn btn-outline-secondary btn-sm py-0 ms-1">Zkusit znovu</button>';
        } else { // processing
            $video360Proc.innerHTML = '<span class="text-info"><span class="spinner-border spinner-border-sm me-1" style="width:.8rem;height:.8rem"></span>360° se zpracovává na serveru… (pár minut, u větší sady fotek až ~20)</span>';
        }
    }
    function poll360(code, first) {
        if (poll360Timer) { clearTimeout(poll360Timer); poll360Timer = null; }
        if (!code) return;
        if (first) { poll360Left = 300; }              // ~25 min stropu (300×5 s) — birefnet na CPU: ~29 s/fotku, 36 fotek ≈ 18 min
        fetch('api/status_360.php?code=' + encodeURIComponent(code), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (st) {
                if (!st || !st.success) { render360({ status: 'none' }); return; }
                render360(st);
                if (st.status === 'processing' && poll360Left-- > 0) {
                    poll360Timer = setTimeout(function () { poll360(code, false); }, 5000);
                }
            }).catch(function () {});
    }
    $video360Proc.addEventListener('click', function (e) {
        if (e.target && e.target.id === 'pcRegen360') {
            var code = code360(); if (!code) return;   // bez kódu není co přegenerovat
            $video360Proc.innerHTML = '<span class="text-info">Spouštím přegenerování…</span>';
            var fd = new FormData(); fd.append('action', 'regen'); fd.append('code', code); fd.append('csrf_token', CSRF);
            fetch('api/status_360.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); }).then(function () { poll360(code, true); })
                .catch(function () {});
        }
    });
    // dopsané/změněné SN → znovu zjistit 360° stav z disku serveru (znovunaskladnění kusu,
    // který už 360° má — bez tohohle by se stav ukázal až po zavření a novém otevření modalu)
    $serial.addEventListener('blur', function () {
        var sn = code360();
        if (sn) { poll360(sn, true); } else { render360({ status: 'none' }); }
    });

    // reset / naplnění celé Galerie (nový produkt = prázdno, editace = z produktu)
    function resetGalleryAll(gallery, studio, video, flags) {
        flags = flags || {};
        el('pcShowStudio').checked  = flags.studio  !== 0;   // výchozí zapnuto
        el('pcShowGallery').checked = flags.gallery !== 0;
        el('pcShow360').checked     = flags.v360    !== 0;
        $studioUrl.value = studio || ''; $studioPhoto.value = ''; el('pcStudioModelNote').textContent = '';
        if (studio) { $studioThumb.src = studio; $studioWrap.style.setProperty('display', 'flex', 'important'); }
        else { $studioThumb.src = ''; $studioWrap.style.setProperty('display', 'none', 'important'); }
        galUrls = []; $gallerySlots.innerHTML = ''; $galleryAdd.style.display = '';
        var list = (gallery && gallery.length) ? gallery : [];
        var n = Math.min(10, Math.max(3, list.length));
        for (var i = 0; i < n; i++) { if (list[i]) { galUrls[i] = list[i]; } addGallerySlot(list[i] || ''); }
        syncGalleryHidden();
        $video360Url.value = video || ''; $video360.value = '';
        if (video) { $videoStatus.textContent = '✓ Video nahráno.'; $videoStatus.className = 'small mt-1 text-success'; poll360(code360(), true); }
        else {
            $videoStatus.textContent = ''; $videoStatus.className = 'small mt-1';
            // i bez videa může mít kus 360° z FOTEK (stav žije jen na disku serveru) → zeptat se.
            // POZOR: ptát se jen se SKUTEČNÝM kódem — baseCode() má fallback 'foto-…' a byl by vždy
            // truthy (mrtvá else-větev + nesmyslný dotaz při každém otevření prázdného modalu).
            var sn360 = code360();
            if (sn360) { poll360(sn360, true); } else { render360({ status: 'none' }); }
        }
    }
    resetGalleryAll([], '', '');                              // úvodní stav = 3 prázdné sloty

    // ── uložení (create/update), stolen force flow, sériové naskladňování ──
    var saving = false;
    var savedSomething = false;   // řídí reload po zavření modalu (ne křehký text v $msg)
    var qtyOriginal = '';                   // počet při otevření editace (optimistický zámek)
    var qtyBeforeSold = '1';                // počet před zaškrtnutím „Prodáno" (pro vrácení)

    // ── Vlastní hodnoty po ÚSPĚŠNÉM uložení rovnou do nabídek ──
    // Server je trvale zapsal do product_catalog_custom (další načtení stránky je má
    // v CATALOG z DB); tohle je jen zrcadlo do běžící stránky, ať platí hned bez F5.
    function listHasCI(list, v) {
        v = String(v).toLowerCase();
        return (list || []).some(function (x) { return String(x).toLowerCase() === v; });
    }
    // vrátí hodnotu tak, jak už v seznamu je („apple" → „Apple"), jinak beze změny
    function canonicalCI(list, v) {
        var lv = String(v).toLowerCase();
        for (var i = 0; i < (list || []).length; i++) {
            if (String(list[i]).toLowerCase() === lv) return list[i];
        }
        return v;
    }
    function typeDefRef(id, manuf) {
        var lid = String(id).toLowerCase(), lm = String(manuf || '').toLowerCase();
        var list = ACCESSORY_MODE ? (CATALOG.accessoryTypes || []) : CATALOG.types;
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].id).toLowerCase() === lid && (ACCESSORY_MODE || String(list[i].manuf || '').toLowerCase() === lm)) return list[i];
        }
        return null;
    }
    function ensureTypeDefFor(id, manuf) {
        var d = typeDefRef(id, manuf);
        if (d) return d;
        if (ACCESSORY_MODE) {
            d = { id: id, manuf: '', k: '', cap: false, ram: false, gen: false, colors: (CATALOG.accessoryColors || []).slice(), models: [], accessory: true, custom: true };
            CATALOG.accessoryTypes.push(d);
            return d;
        }
        var generic = null;
        for (var g = 0; g < CATALOG.types.length; g++) {
            if (String(CATALOG.types[g].id).toLowerCase() === String(id).toLowerCase() && !(CATALOG.types[g].manuf || '')) { generic = CATALOG.types[g]; break; }
        }
        d = generic
            ? Object.assign({}, generic, { manuf: manuf, custom: true, models: (generic.models || []).slice(), colors: (generic.colors || []).slice() })
            : { id: id, manuf: manuf, k: '', cap: true, ram: false, gen: false, colors: [], models: [], custom: true };
        CATALOG.types.push(d);
        return d;
    }
    function absorbCustomValues() {
        try {
            var mv = ACCESSORY_MODE ? '' : manufacturerVal();
            var tv = typVal();
            if (!ACCESSORY_MODE && $manuf.value === CUSTOM && mv) {
                if (!listHasCI(CATALOG.manufacturers, mv)) CATALOG.manufacturers.push(mv);
                mv = canonicalCI(CATALOG.manufacturers, mv);   // „apple" → „Apple"
                fillSelect($manuf, CATALOG.manufacturers, false, true);
                $manuf.value = mv;
                $manufC.value = '';
                $manufC.style.display = 'none';
            }
            if ($typ.value === CUSTOM && tv) {
                tv = ensureTypeDefFor(tv, mv).id;   // kanonická podoba („iphone" → „iPhone")
                fillSelect($typ, typeOptionsForManufacturer(mv).map(function (t) { return t.id; }), false, true);
                $typ.value = tv;
                $typC.value = '';
                $typC.style.display = 'none';
            }
            var mdl = modelVal(), col = colorVal();
            if (!ACCESSORY_MODE && (($model.value === CUSTOM && mdl) || ($color.value === CUSTOM && col))) {
                var def = ensureTypeDefFor(tv, mv);
                if ($model.value === CUSTOM && mdl && !listHasCI(def.models, mdl)) def.models.push(mdl);
                if ($color.value === CUSTOM && col && !listHasCI(def.colors, col)) def.colors.push(col);
            }
            if (ACCESSORY_MODE && $color.value === CUSTOM && col) {
                if (!listHasCI(CATALOG.accessoryColors, col)) CATALOG.accessoryColors.push(col);
                (CATALOG.accessoryTypes || []).forEach(function (t) {
                    t.colors = t.colors || [];
                    if (!listHasCI(t.colors, col)) t.colors.push(col);
                });
            }
            var prop = accessoryPropertyVal();
            if (ACCESSORY_MODE && $accessoryProperty.value === CUSTOM && prop) {
                if (!listHasCI(CATALOG.accessoryProperties, prop)) CATALOG.accessoryProperties.push(prop);
                fillSelect($accessoryProperty, CATALOG.accessoryProperties, true, true);
                $accessoryProperty.value = canonicalCI(CATALOG.accessoryProperties, prop);
                $accessoryPropertyC.value = '';
                $accessoryPropertyC.style.display = 'none';
            }
            var fam = processorFamilyVal(), pm = processorModelVal();
            if (fam && $processorModel.value === CUSTOM && pm && CATALOG.processors[fam] && !listHasCI(CATALOG.processors[fam], pm)) {
                CATALOG.processors[fam].push(pm);
            }
            var gm = gpuModelVal();
            // stejné hradlo jako v save() — mimo režim 'pc' server grafiku nedostal
            // a neregistroval, lokální vstřebání by vyrobilo ducha do prvního F5
            if (coreFieldsMode() === 'pc' && $gpuModel.value === CUSTOM && gm) {
                if (!listHasCI(CATALOG.gpuModels, gm)) CATALOG.gpuModels.push(gm);
                fillSelect($gpuModel, CATALOG.gpuModels, true, true);
                $gpuModel.value = canonicalCI(CATALOG.gpuModels, gm);
                $gpuModelC.value = '';
                $gpuModelC.style.display = 'none';
            }
        } catch (e) { /* jen kosmetika běžící stránky — uložení už proběhlo */ }
    }

    function save(printAfter, force) {
        // Prázdné pole „Počet kusů" by se odeslalo jako 0 → kus by se naskladnil rovnou
        // jako VYPRODANÝ: v regále leží, na kase ani e-shopu ho nikdo nenajde.
        var qtyRaw = String((el('pcQty') || {}).value || '').trim();
        var qty = parseInt(qtyRaw, 10);
        if (qtyRaw === '' || isNaN(qty) || qty < 0) {
            $msg.innerHTML = '<span class="text-danger">Vyplň počet kusů (alespoň 1).</span>';
            if (el('pcQty')) { el('pcQty').focus(); }
            return;
        }
        if (qty > 9999) { qty = 9999; }
        if (!$sold.checked && qty < 1) {
            $msg.innerHTML = '<span class="text-danger">Vyplň počet kusů (alespoň 1).</span>';
            if (el('pcQty')) { el('pcQty').focus(); }
            return;
        }
        if (saving) { showAlert('Ukládání už běží — vydrž vteřinku.'); return; }
        // běžící dotaz na IMEI musí doběhnout — jinak by se kus uložil dřív,
        // než se ukáže varování „Find My je zapnuté" (a kredit by přišel vniveč)
        if (typeof imeiBusy !== 'undefined' && imeiBusy) {
            $msg.innerHTML = '<span class="text-warning fw-bold">Počkej — zjišťuji údaje k IMEI…</span>';
            setTimeout(function () { if (!imeiBusy && !saving) { save(printAfter, force); } }, 600);
            return;
        }
        if (pending > 0) {
            // zaseknuté nahrávání fotky umí naskladnění blokovat donekonečna — řekni to nahlas
            $msg.innerHTML = '<span class="text-warning fw-bold">Počkej — média se ještě nahrávají… (' + pending + ')</span>';
            showAlert('Ještě se nahrávají fotky (' + pending + '). Počkej pár vteřin, nebo obnov stránku (F5) — rozepsaný produkt zůstane jako koncept.');
            return;
        }
        if (!modelVal()) { $msg.innerHTML = '<span class="text-danger fw-bold">' + (ACCESSORY_MODE ? 'Vyber typ příslušenství.' : 'Vyplň model.') + '</span>'; showAlert(ACCESSORY_MODE ? 'Vyber typ příslušenství.' : 'Vyplň model.'); return; }
        if (!$price.value.trim()) { $msg.innerHTML = '<span class="text-danger fw-bold">Vyplň cenu.</span>'; showAlert('Vyplň cenu.'); return; }
        saving = true;
        $msg.textContent = 'Ukládám…';
        var fd = new FormData();
        fd.append('action', $editId.value ? 'update' : 'create');
        if ($editId.value) fd.append('id', $editId.value);
        fd.append('csrf_token', CSRF);
        fd.append('catalog_mode', ACCESSORY_MODE ? 'accessory' : 'product');
        fd.append('manufacturer', manufacturerVal());
        fd.append('typ', typVal());
        fd.append('model', modelVal());
        fd.append('accessory_for_model', accessoryForModelVal());
        fd.append('accessory_property', accessoryPropertyVal());
        fd.append('accessory_note', accessoryTextVal());
        // cap se posílá beze změny (stejně jako cpu/gpu/baterie/RAM) — ořez podle typu
        // dělá autoritativně server; JS ořez by kus z appky posílal jako „změněný"
        // a mikroúprava (doplnění nákupní ceny) by spadla do plného přepisu.
        fd.append('cap', $cap.value);
        fd.append('color', colorVal());
        fd.append('grade', $grade.value);
        fd.append('battery', $bat.value);
        fd.append('price', $price.value.trim());
        fd.append('purchase_price', $purchase.value.trim());   // nepovinné; prázdné = neznámá nákupní cena
        fd.append('serial', serialVal());
        fd.append('ram', $ram.value);
        fd.append('processor_family', typeHasProcessorFields() ? processorFamilyVal() : '');
        fd.append('processor_model', typeHasProcessorFields() ? processorModelVal() : '');
        // cpu/gpu se posílají VŽDY tak, jak jsou v polích — ořezává je autoritativně
        // až server (afxProductAssemble podle režimu jader). Kdyby je ořezal už JS,
        // kus z appky s historickými jádry v raw_csv by při pouhém doplnění nákupní
        // ceny vypadal „změněný" a import-neutrální mikroúprava by se překlopila na
        // plný přepis (jádra pryč z názvu + pos_sold_at = NULL). Náhled gatuje sám.
        fd.append('cpu', $cpu.value);
        fd.append('gpu', $gpu.value);
        fd.append('gpu_model', coreFieldsMode() === 'pc' ? gpuModelVal() : '');
        fd.append('rocnik', $rocnik.value);
        fd.append('generace', $gen.value);   // ořez podle typu dělá server (viz cap výš)
        if ($sold.checked) fd.append('sold', '1');
        fd.append('stock_qty', String(qty));
        if (qtyOriginal !== '') { fd.append('stock_qty_orig', qtyOriginal); }
        fd.append('stock_key', $stockKey.value);
        fd.append('image_url', $imageUrl.value);
        fd.append('studio_url', $studioUrl.value);
        fd.append('gallery_urls', $galleryUrls.value);
        fd.append('video360_url', $video360Url.value);
        // volba sekcí: co z Galerie se u tohohle kusu použije na eshopu/v katalogu
        fd.append('show_studio', el('pcShowStudio').checked ? '1' : '0');
        fd.append('show_gallery', el('pcShowGallery').checked ? '1' : '0');
        fd.append('show_360', el('pcShow360').checked ? '1' : '0');
        fd.append('hide_eshop', el('pcHideEshop').checked ? '1' : '0');
        fd.append('eshop_note', (el('pcEshopNote') ? el('pcEshopNote').value.trim() : ''));
        if (force) fd.append('force', '1');
        fetch('api/product_create.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                saving = false;
                if (d.needs_confirmation) {
                    if (confirm(d.confirm_text)) { save(printAfter, true); }
                    else { $msg.textContent = 'Neuloženo (odcizené zařízení).'; }
                    return;
                }
                if (!d.success) {
                    var m = d.message || 'Uložení selhalo.';
                    $msg.innerHTML = '<span class="text-danger fw-bold">' + escHtml(m) + '</span>';
                    // neplatný token = mezitím proběhlo (od)přihlášení jinde a tahle
                    // záložka je zastaralá — bez jasné hlášky to vypadá jako mrtvé tlačítko
                    if (/token|přihlá/i.test(m)) {
                        showAlert('Přihlášení se mezitím změnilo — obnov stránku (F5). Rozepsaný produkt zůstane uložený jako koncept.');
                    }
                    return;
                }
                savedSomething = true;
                absorbCustomValues();   // PŘED resetem — čte hodnoty z polí „Vlastní…"
                try { if (window.__clearProductDraft) window.__clearProductDraft(); } catch (e) {}   // úspěšně uloženo → koncept pryč
                el('pcTodayCount').textContent = d.today_count;
                $msg.textContent = ($editId.value ? 'Uloženo: ' : 'Naskladněno: ') + d.title;
                if (d.hint) { $hint.textContent = d.hint; $hint.style.display = ''; }
                else { $hint.style.display = 'none'; }
                // Selhání tisku se dřív jen připsalo za hlášku „Naskladněno" drobným
                // písmem — obsluha to přehlédla a zjistila až u regálu, že štítek není.
                function labelFail(err) {
                    $msg.innerHTML = '<span class="text-danger fw-bold">⚠️ Štítek se NEVYTISKL</span> ' +
                        '<span class="text-white-50">(' + String(err).replace(/</g, '&lt;') + ')</span> · ' +
                        'zboží je naskladněné, štítek dotiskni ikonou <i class="fas fa-tag"></i> u řádku';
                    if (window.showAlert) { showAlert('Štítek se nevytiskl: ' + err); }
                }
                var printPromise = Promise.resolve();
                if (printAfter && d.id) {
                    // dávka štítků jen při NASKLADNĚNÍ; editace by jinak vytiskla tolik
                    // štítků, kolik je zrovna kusů skladem
                    var labelCopies = $editId.value ? 1 : Math.min(20, Math.max(1, qty));
                    printPromise = printProductLabel(d.id, labelCopies)
                        .then(function (p) {
                            if (p.copies && p.copies > 1) { $msg.textContent += ' · vytištěno ' + p.copies + ' štítků'; }
                            else if (p.via_bridge) { $msg.textContent += ' · štítek vytištěn přes tenhle počítač'; }
                        })
                        .catch(function (err) { labelFail(err.message || 'tisk selhal'); });
                }
                // reload při editaci až PO doběhnutí tisku — unload by in-flight tisk zrušil
                if ($editId.value) { printPromise.then(function () { location.reload(); }); return; }
                // vyčistit vše KROMĚ Typ / Stav / Prodejna — sériové naskladňování jako v appce
                formGen++;
                imeiAsked = ''; renderImeiInfo('');   // výsledek z IMEI patří předchozímu kusu
                editProductCode = '';   // předchozí kus je uložený; 360° dalšího nesmí jít do jeho složky
                [$modelC, $accessoryForModel, $accessoryPropertyC, $accessoryText, $colorC, $bat, $price, $purchase, $serial].forEach(function (n) { n.value = ''; });
                onType();   // Model/Barva se přeplní z katalogu — včetně právě vstřebaných vlastních hodnot
                $model.value = ''; $color.value = ''; $cap.value = '';
                $accessoryProperty.value = ''; $accessoryPropertyC.style.display = 'none';
                clearProcessor(); syncProcessorVisibility();
                $ram.value = ''; $cpu.value = ''; $gpu.value = ''; $rocnik.value = ''; $gen.value = '';
                $gpuModel.value = ''; $gpuModelC.value = ''; $gpuModelC.style.display = 'none';
                $modelC.style.display = 'none'; $colorC.style.display = 'none';
                $sold.checked = false;
        // Počet patří ke KONKRÉTNÍ položce — bez vynulování by další kus (klidně telefon)
        // dostal počet toho předchozího a e-shop i kasa by nabízely zboží, které není.
        if (el('pcQty')) { el('pcQty').value = '1'; el('pcQty').disabled = false; }
        qtyBeforeSold = '1';
        syncQtyWithSerial();                    // SN je vyčištěné → pole zase odemknout
                $photo.value = ''; $imageUrl.value = '';
                resetGalleryAll([], '', '');
                el('pcPreviewImgWrap').style.display = 'none';
                setBadge('none');
                refreshPreview();
            })
            .catch(function () { saving = false; $msg.textContent = 'Síťová chyba — zkus to znovu.'; });
    }
    // Sériové číslo = jeden konkrétní kus → počet se zamkne na 1 (dva kusy se
    // stejným SN nedávají smysl a e-shop i kasa by je nerozlišily).
    function syncQtyWithSerial() {
        var q = el('pcQty'), sn = serialVal();
        if (!q) { return; }
        if (sn !== '') {
            // POZOR: jen SRÁŽET dolů, nikdy nezvyšovat. Prodaný kus má 0 ks a natvrdo
            // dosazená 1 by ho editací vrátila na sklad (a smazala stopu prodeje).
            if ((parseInt(q.value, 10) || 0) > 1) { q.value = '1'; }
            q.readOnly = true;
            q.title = 'Kus se sériovým číslem je vždy jeden.';
        } else {
            q.readOnly = false;
            q.title = 'Kolik stejných kusů naskladňuješ.';
        }
    }
    $serial.addEventListener('input', syncQtyWithSerial);
    syncQtyWithSerial();

    // „Prodáno" = vyprodáno (0 ks). Bez provázání by šlo mít zaškrtnuto Prodáno a v poli
    // 5 ks — server uloží 0 a pět kusů tiše zmizí ze skladu.
    $sold.addEventListener('change', function () {
        var q = el('pcQty'); if (!q) { return; }
        if ($sold.checked) { qtyBeforeSold = q.value || '1'; q.value = '0'; q.disabled = true; }
        else { q.disabled = false; q.value = (parseInt(qtyBeforeSold, 10) > 0) ? qtyBeforeSold : '1'; }
    });
    el('pcQty').addEventListener('input', function () {
        if (parseInt(this.value, 10) > 0 && $sold.checked) { $sold.checked = false; }
    });

    el('pcSaveBtn').addEventListener('click', function () { save(false, false); });
    el('pcSavePrintBtn').addEventListener('click', function () { save(true, false); });
    document.getElementById('productCreateModal').addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); save(true, false); }
    });
    // Typ a Barva jsou obyčejné nativní selecty stejně jako Stav.
    document.getElementById('productCreateModal').addEventListener('shown.bs.modal', function () {
        refreshPreview();
    });
    // po zavření modalu obnovit tabulku (nové kusy) — při otevřeném se nerefreshuje
    document.getElementById('productCreateModal').addEventListener('hidden.bs.modal', function () {
        if (savedSomething) { location.reload(); }
    });
    el('productCreateOpen').addEventListener('click', function () {
        // režim NOVÝ produkt — vyčistit VŠE (i pozůstatky předchozí editace)
        formGen++;
        imeiAsked = ''; renderImeiInfo('');
        $editId.value = '';
        editProductCode = '';   // jinak by 360° z nového kusu spadla do složky předchozího
        el('pcTitleMode').textContent = ACCESSORY_MODE ? 'Naskladnit příslušenství' : 'Naskladnit produkt';
        if (el('pcQty')) { el('pcQty').value = '1'; el('pcQty').readOnly = false; el('pcQty').disabled = false; }
        qtyOriginal = ''; qtyBeforeSold = '1';
        el('pcSaveBtn').style.display = 'none';          // při naskladnění se štítek tiskne vždy
        el('pcSavePrintBtn').innerHTML = '<i class="fas fa-tag me-1"></i> Přidat a vytisknout štítek';
        $manufC.value = ''; $manufC.style.display = 'none';
        if (!ACCESSORY_MODE && $manuf.value === CUSTOM) { $manuf.value = 'Apple'; onManufacturer(); }
        $typC.value = ''; $typC.style.display = 'none';
        if ($typ.value === CUSTOM) { onManufacturer(); }
        [$modelC, $accessoryForModel, $accessoryPropertyC, $accessoryText, $colorC, $bat, $price, $purchase, $serial].forEach(function (n) { n.value = ''; });
        $model.value = ''; $color.value = ''; $cap.value = '';
        $accessoryProperty.value = ''; $accessoryPropertyC.style.display = 'none';
        clearProcessor(); syncProcessorVisibility();
        $ram.value = ''; $cpu.value = ''; $gpu.value = ''; $rocnik.value = ''; $gen.value = '';
        $gpuModel.value = ''; $gpuModelC.value = ''; $gpuModelC.style.display = 'none';
        $modelC.style.display = 'none'; $colorC.style.display = 'none';
        $sold.checked = false;
        el('pcHideEshop').checked = false;
        if (el('pcEshopNote')) { el('pcEshopNote').value = ''; }
        $photo.value = ''; $imageUrl.value = '';
        resetGalleryAll([], '', '');
        el('pcPreviewImgWrap').style.display = 'none';
        $hint.style.display = 'none';
        $msg.textContent = '';
        setBadge('none');
        syncCatalogModeLayout();
        refreshPreview();
    });

    // ── editace existujícího produktu ──
    $(document).on('click', '.product-edit-btn', function () {
        var id = this.dataset.id;
        fetch('api/product_create.php?action=get&id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { showAlert(d.message || 'Načtení selhalo.'); return; }
                var p = d.product;
                formGen++;
                imeiAsked = ''; renderImeiInfo('');   // výsledek z IMEI patří předchozímu kusu
                $editId.value = p.id;
                el('pcTitleMode').textContent = 'Upravit produkt';
                if (el('pcQty')) {
                    el('pcQty').value = (p.stock_qty !== undefined && p.stock_qty !== null) ? p.stock_qty : (p.sold ? 0 : 1);
                    el('pcQty').disabled = false;
                }
                // původní počet — server podle něj pozná, že mezitím někdo prodával
                qtyOriginal = (p.stock_qty !== undefined && p.stock_qty !== null) ? String(p.stock_qty) : '';
                qtyBeforeSold = (parseInt(qtyOriginal, 10) > 0) ? qtyOriginal : '1';
                el('pcSaveBtn').style.display = '';   // u editace se štítek tisknout nemusí
                el('pcSaveBtn').innerHTML = '<i class="fas fa-save me-1"></i> Uložit změny';
                el('pcSavePrintBtn').innerHTML = '<i class="fas fa-tag me-1"></i> Uložit a vytisknout štítek';
                setManufacturerValue(inferManufacturerFromProduct(p));
                // typ odvodit z raw_csv / kategorie / názvu; NEZNÁMÝ typ se drží jako Vlastní,
                // aby editace starého nebo ručně založeného kusu nespadla na cizí kategorii.
                var typ = inferTypeFromProduct(p);
                if (typ) { $typ.value = typ; $typC.value = ''; $typC.style.display = 'none'; }
                else { $typ.value = CUSTOM; $typC.value = ''; $typC.style.display = ''; }
                onType();
                var t = typeDef();
                // model bez prefixu typu (display_model ho přidává zpět)
                var m = p.model || '';
                var mvNow = manufacturerVal();
                if (mvNow && mvNow !== 'Apple' && m.toLowerCase().indexOf(mvNow.toLowerCase() + ' ') === 0) {
                    m = m.substring(mvNow.length).trim();
                }
                if (t.models.indexOf(m) >= 0) { $model.value = m; }
                else if (m) { $model.value = CUSTOM; $modelC.style.display = ''; $modelC.value = m; }
                $accessoryForModel.value = p.accessory_for_model || '';
                $accessoryText.value = p.accessory_note || '';
                setSelectValue($accessoryProperty, p.accessory_property || '');
                if ((p.accessory_property || '') && (CATALOG.accessoryProperties || []).indexOf(p.accessory_property) < 0) {
                    $accessoryProperty.value = CUSTOM;
                    $accessoryPropertyC.style.display = '';
                    $accessoryPropertyC.value = p.accessory_property;
                } else {
                    $accessoryPropertyC.style.display = 'none';
                    $accessoryPropertyC.value = '';
                }
                if (t.colors.indexOf(p.color) >= 0) { $color.value = p.color; }
                else if (p.color) { $color.value = CUSTOM; $colorC.style.display = ''; $colorC.value = p.color; }
                syncProcessorVisibility();
                setSelectValue($cap, p.cap);
                // grade token → celý label
                var gl = CATALOG.grades.filter(function (g) { return g.split(' ')[0] === p.grade; });
                $grade.value = gl.length ? gl[0] : 'Nový';
                $bat.value = p.battery || '';
                $price.value = p.price || '';
                $purchase.value = p.purchase_price || '';   // '' = u kusu nikdy nezadaná (nepřepisovat nulou)
                $serial.value = p.serial || '';
                editProductCode = p.product_code || '';   // 360° u kusů bez SN (příslušenství)
                setSelectValue($ram, p.ram); setProcessorValues(p.processor_family || '', p.processor_model || '');
                setSelectValue($cpu, p.cpu); setSelectValue($gpu, p.gpu);
                if ((p.gpu_model || '') && (CATALOG.gpuModels || []).indexOf(p.gpu_model) < 0) {
                    $gpuModel.value = CUSTOM;
                    $gpuModelC.style.display = '';
                    $gpuModelC.value = p.gpu_model;
                } else {
                    $gpuModel.value = p.gpu_model || '';
                    $gpuModelC.value = '';
                    $gpuModelC.style.display = 'none';
                }
                setSelectValue($rocnik, p.rocnik); setSelectValue($gen, p.generace);
                $sold.checked = !!p.sold;
                syncQtyWithSerial();   // AŽ TEĎ — funkce se řídí i stavem „Prodáno"
                el('pcHideEshop').checked = !!parseInt(p.hide_eshop || 0, 10);
                if (el('pcEshopNote')) { el('pcEshopNote').value = p.eshop_note || ''; }
                $stockKey.value = p.stock_key || DEFAULT_STOCK;
                $imageUrl.value = p.image_url || '';
                if (p.image_url) { el('pcPreviewImg').src = p.image_url; el('pcPreviewImgWrap').style.display = ''; }
                else { el('pcPreviewImgWrap').style.display = 'none'; }
                var galArr = []; try { galArr = p.gallery_images ? JSON.parse(p.gallery_images) : []; } catch (e) { galArr = []; }
                resetGalleryAll(Array.isArray(galArr) ? galArr : [], p.studio_image_url || '', p.video_360_url || '',
                    { studio: p.show_studio, gallery: p.show_gallery, v360: p.show_360 });
                setBadge(p.pcr_status || 'none');
                syncCatalogModeLayout();
                $msg.textContent = p.source === 'app' ? 'Pozor: kus z appky — uložením ho převezme CRM (import appky ho už nepřepíše).' : '';
                refreshPreview();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('productCreateModal')).show();
            })
            .catch(function () { showAlert('Načtení produktu selhalo.'); });
    });
})();

// dataset.title = surový text (HTML entity už dekódované) — showConfirm ale renderuje
// přes innerHTML, takže se název MUSÍ escapovat tady, jinak <img onerror> z CSV = XSS
function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
$(document).on('click', '.product-delete-btn', function () {
    var id = this.dataset.id;
    var title = this.dataset.title || '';
    showConfirm('Smazat produkt „' + escHtml(title) + '" ze skladu? (V souboru appky zůstane — při dalším importu by se vrátil.)', function () {
        $.post('api/delete_product.php', { id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>' }, function (res) {
            if (res.success) { location.reload(); }
            else { showAlert('<?php echo __('error_prefix'); ?>' + (res.message || '')); }
        }).fail(function (xhr) {
            var msg = 'Smazání selhalo';
            try { msg = (JSON.parse(xhr.responseText).message || msg); } catch (e) {}
            showAlert(msg + ' — obnov stránku (⌘R) a zkus to znovu.');
        });
    });
});

// Zapůjčeno / komisní prodej — zapnutí přes modal, vrácení jedním klikem
$(document).on('click', '.product-loan-btn', function () {
    var d = this.dataset;
    if (d.loaned === '1') {
        showConfirm('Vrátit „' + escHtml(d.title || '') + '" zpět do skladu? Půjde zase na e-shop.', function () {
            $.post('api/product_loan.php', { id: d.id, action: 'return', csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>' }, function (res) {
                if (res.success) { location.reload(); } else { showAlert(res.message || 'Nepovedlo se'); }
            });
        });
        return;
    }
    document.getElementById('loanProductId').value = d.id;
    document.getElementById('loanProductTitle').textContent = d.title || '';
    document.getElementById('loanTo').value = d.to || '';
    document.getElementById('loanNote').value = d.note || '';
    new bootstrap.Modal(document.getElementById('productLoanModal')).show();
});
$(document).on('click', '#loanSaveBtn', function () {
    var to = document.getElementById('loanTo').value.trim();
    if (!to) { showAlert('Vyplň, komu je kus zapůjčen.'); return; }
    var btn = this; btn.disabled = true;
    $.post('api/product_loan.php', {
        id: document.getElementById('loanProductId').value, action: 'lend', loan_to: to,
        loan_note: document.getElementById('loanNote').value.trim(),
        csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>'
    }, function (res) {
        if (res.success) { location.reload(); }
        else { btn.disabled = false; showAlert(res.message || 'Nepovedlo se'); }
    }).fail(function () { btn.disabled = false; showAlert('Uložení selhalo — obnov stránku a zkus to znovu.'); });
});
<?php endif; ?>
</script>

<script>
// Přesun produktu na druhou pobočku — přidat do seznamu přesunu (produkt se přesouvá celý)
$(document).on('click', '.tr-add-btn', function () {
    var t = this.dataset.type, id = this.dataset.id, name = this.dataset.name || '';
    if (t === 'product' && !confirm('Přidat „' + name + '" do přesunu na druhou pobočku? Produkt se přesune celý (po potvrzení zdrojovou pobočkou).')) return;
    var fd = new FormData();
    fd.append('from_branch', '<?php echo (int)$skladBranch; ?>'); fd.append('type', t); fd.append('source_id', id); fd.append('qty', 1);
    fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
    fetch('api/transfer_add.php', {method: 'POST', body: fd, credentials: 'same-origin'})
        .then(function (r) { return r.json(); })
        .then(function (d) { if (window.showAlert) showAlert(d.message); else alert(d.message); })
        .catch(function () { alert('Síťová chyba.'); });
});
</script>

<!-- Náhled položky (read-only) — klik na název řádku; funguje ve všech záložkách produktů -->
<div class="modal fade" id="prodPreviewModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-truncate"><i class="fas fa-mobile-alt me-2 text-info"></i><span id="ppName">Náhled položky</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="ppBody"><div class="text-center py-4 text-white-75"><i class="fas fa-circle-notch fa-spin me-2"></i>Načítám…</div></div>
            <div class="modal-footer">
                <button type="button" id="ppLabel" class="btn btn-sm btn-outline-info"><i class="fas fa-tag me-1"></i>Cenový štítek</button>
                <span class="flex-grow-1"></span>
                <button type="button" id="ppEdit" class="btn btn-sm btn-warning" style="display:none;"><i class="fas fa-edit me-1"></i>Upravit</button>
            </div>
        </div>
    </div>
</div>
<script>
// ── náhled položky: klik na řádek → read-only detail (data z action=get) ──
(function () {
    var modalEl = document.getElementById('prodPreviewModal');
    var modal = modalEl ? new bootstrap.Modal(modalEl) : null;
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
    var kc = function (v) { return (v == null || v === '') ? '—' : (Number(v).toLocaleString('cs-CZ') + ' Kč'); };
    var ppId = 0;

    $(document).on('click', '.prod-preview', function () {
        var id = this.dataset.id;
        if (!id || !modal) { return; }
        ppId = id;
        document.getElementById('ppName').textContent = 'Náhled položky';
        document.getElementById('ppBody').innerHTML = '<div class="text-center py-4 text-white-75"><i class="fas fa-circle-notch fa-spin me-2"></i>Načítám…</div>';
        var editRowBtn = document.querySelector('.product-edit-btn[data-id="' + id + '"]');
        document.getElementById('ppEdit').style.display = editRowBtn ? '' : 'none';
        modal.show();
        fetch('api/product_create.php?action=get&id=' + id, {credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) { document.getElementById('ppBody').innerHTML = '<div class="alert alert-danger mb-0">' + esc(d.message || 'Chyba') + '</div>'; return; }
                var p = d.product;
                document.getElementById('ppName').textContent = p.title || 'Náhled položky';
                var img = p.image_path || p.image_url || '';
                var h = '<div class="row g-3"><div class="col-auto">';
                h += img
                    ? '<a href="' + esc(img) + '" data-fancybox="prodprev"><img src="' + esc(img) + '" class="rounded shadow-sm" style="width:96px;height:96px;object-fit:cover;"></a>'
                    : '<div class="bg-dark bg-opacity-25 rounded d-flex align-items-center justify-content-center border border-secondary" style="width:96px;height:96px;"><i class="fas fa-image fa-2x text-muted opacity-25"></i></div>';
                h += '</div><div class="col"><div class="row g-2 small">';
                var device = [p.manufacturer, p.model, p.capacity, p.color].filter(Boolean).join(' · ');
                var loaned = !!p.loan_at;
                var rows = [
                    ['Kus', '#' + p.id + (p.source ? ' <span class="text-white-75">· založeno: ' + (p.source === 'crm' ? 'v CRM' : esc(p.source)) + '</span>' : '')],
                    ['Kód / sériovko', p.product_code ? '<code>' + esc(p.product_code) + '</code>' : '—'],
                    ['Zařízení', device ? esc(device) : '—'],
                    ['Stav (grade)', p.grade ? esc(p.grade) : '—'],
                    ['Skladem', '<b>' + (p.stock_qty != null ? p.stock_qty : '—') + ' ks</b>' + (Number(p.stock_qty) > 0 ? ' <span class="badge bg-success">skladem</span>' : ' <span class="badge bg-secondary">vyprodáno</span>')],
                    ['Prodejní cena', kc(p.price) + (p.price > 0 && Number(p.stock_qty) > 0 ? ' <span class="text-white-75">· hodnota ' + kc(p.price * p.stock_qty) + '</span>' : '')],
                    ['Nákupní / výkupní cena', kc(p.purchase_price) + (p.price > 0 && p.purchase_price > 0 ? ' <span class="text-white-75">· marže ' + kc(p.price - p.purchase_price) + '</span>' : '')],
                    ['Prodejna', p.stock_key === 'vaclavak' ? 'Václavák' : (p.stock_key === 'karlin' ? 'Karlín' : '—')],
                    ['E-shop', Number(p.hide_eshop) ? '<span class="badge bg-secondary">skrytý</span>' : '<span class="badge bg-success">zobrazuje se</span>'],
                    ['Naskladněno', (p.added_at ? esc(p.added_at) : '—') + (p.created_by ? ' · ' + esc(p.created_by) : '')],
                ];
                if (loaned) {
                    rows.push(['Zapůjčeno / komise', '<span class="text-warning">' + (p.loan_to ? esc(p.loan_to) : 'ano') + '</span>'
                        + (p.loan_at ? ' <span class="text-white-75">· od ' + esc(p.loan_at) + '</span>' : '')
                        + (p.loan_by ? ' <span class="text-white-75">· zapsal(a) ' + esc(p.loan_by) + '</span>' : '')
                        + (p.loan_note ? '<div class="text-white-75">' + esc(p.loan_note) + '</div>' : '')]);
                }
                if (Number(p.is_vykup)) {
                    rows.push(['Původ', 'Výkup' + (p.vykup_document_id ? ' · <a href="dokument.php?id=' + Number(p.vykup_document_id) + '">výkupní list</a>' : '')]);
                }
                if (p.moved_to_inventory_id) {
                    rows.push(['Převedeno', '<a href="edit_inventory.php?id=' + Number(p.moved_to_inventory_id) + '">na sklad náhradních dílů (karta #' + Number(p.moved_to_inventory_id) + ')</a>']);
                }
                if (p.last_sold_at) { rows.push(['Prodáno', esc(p.last_sold_at)]); }
                if (p.updated_at) { rows.push(['Poslední změna', esc(p.updated_at)]); }
                rows.forEach(function (r2) {
                    h += '<div class="col-5 col-md-4 text-white-75">' + r2[0] + '</div><div class="col-7 col-md-8">' + r2[1] + '</div>';
                });
                h += '</div></div></div>';
                if (p.description) {
                    h += '<hr class="border-secondary my-3"><div class="small text-white-75 mb-1">Popis</div><div class="small">' + esc(p.description).replace(/\n/g, '<br>') + '</div>';
                }
                // ── „vše, co o kusu víme": zbylá neprázdná pole, která nemají vlastní řádek ──
                var shown = ['id', 'title', 'product_code', 'model', 'manufacturer', 'capacity', 'color', 'grade', 'price',
                    'purchase_price', 'stock_qty', 'stock_key', 'branch_id', 'hide_eshop', 'added_at', 'created_by',
                    'last_sold_at', 'updated_at', 'is_vykup', 'vykup_document_id', 'moved_to_inventory_id',
                    'loan_to', 'loan_at', 'loan_note', 'loan_by', 'description', 'image_path', 'image_url', 'source',
                    'first_seen_at', 'last_seen_at', 'sold'];
                var extra = '';
                Object.keys(p).forEach(function (k) {
                    if (shown.indexOf(k) >= 0) { return; }
                    if (/raw|_json|csv|html/i.test(k)) { return; }
                    var v = p[k];
                    if (v === null || v === '' || v === undefined || v === 0 || v === '0') { return; }
                    if (typeof v === 'object') { return; }
                    v = String(v);
                    if (v.length > 160) { v = v.slice(0, 160) + '…'; }
                    extra += '<div class="col-5 col-md-4 text-white-75"><code class="small">' + esc(k) + '</code></div><div class="col-7 col-md-8">' + esc(v) + '</div>';
                });
                if (extra) {
                    h += '<hr class="border-secondary my-3"><div class="small text-white-75 mb-1">Další údaje</div><div class="row g-2 small">' + extra + '</div>';
                }
                document.getElementById('ppBody').innerHTML = h;
            })
            .catch(function () { document.getElementById('ppBody').innerHTML = '<div class="alert alert-danger mb-0">Síťová chyba.</div>'; });
    });

    document.getElementById('ppEdit').addEventListener('click', function () {
        var btn = document.querySelector('.product-edit-btn[data-id="' + ppId + '"]');
        if (modal) { modal.hide(); }
        if (btn) { setTimeout(function () { btn.click(); }, 250); }
    });
    document.getElementById('ppLabel').addEventListener('click', function () {
        var btn = document.querySelector('.product-label-btn[data-id="' + ppId + '"]');
        if (btn) { if (modal) { modal.hide(); } setTimeout(function () { btn.click(); }, 250); }
    });
}());
</script>

<?php if ($isVykupTab && $canManageBranch): ?>
<!-- Výkup → prodej: prodejní cena + viditelnost na e-shopu -->
<div class="modal fade" id="vykupSaleModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-store me-2 text-success"></i>Zařadit do prodeje</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="vsProductId">
                <div class="alert alert-info border-0 mb-3">
                    <div class="small text-muted mb-1">Vykoupený kus</div>
                    <div class="fw-semibold" id="vsTitle"></div>
                </div>
                <label class="form-label">Prodejní cena</label>
                <div class="input-group mb-3">
                    <input type="number" id="vsPrice" class="form-control" min="1" step="1" placeholder="např. 4990">
                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?></span>
                </div>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="vsEshop" checked>
                    <label class="form-check-label" for="vsEshop">Rovnou zobrazit na e-shopu</label>
                </div>
                <div class="small text-white-50 mt-2">Kus se přesune ze záložky Výkupy do <b>Produktů</b>; fotky a popis mu případně doplníš tam.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Zavřít</button>
                <button type="button" class="btn btn-success" id="vsSave"><i class="fas fa-check me-1"></i>Zařadit do prodeje</button>
            </div>
        </div>
    </div>
</div>
<script>
// Výkupy → převody: na sklad dílů (dárce) / do prodeje
(function () {
    var saleModalEl = document.getElementById('vykupSaleModal');
    var saleModal = saleModalEl ? new bootstrap.Modal(saleModalEl) : null;

    function vykupPost(data, btn) {
        if (btn) { btn.disabled = true; }
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
        fetch('api/vykup_transfer.php', {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) { showAlert(d.message); setTimeout(function () { location.reload(); }, 900); }
                else { if (btn) { btn.disabled = false; } showAlert(d.message || 'Nepovedlo se'); }
            })
            .catch(function () { if (btn) { btn.disabled = false; } showAlert('Síťová chyba.'); });
    }

    $(document).on('click', '.vykup-to-parts-btn', function () {
        var id = this.dataset.id, title = this.dataset.title || '', cost = this.dataset.cost, btn = this;
        showConfirm('Převést „' + title + '" na sklad náhradních dílů (dárce na díly)? Vznikne karta dílu'
            + (cost ? ' s nákupní cenou ' + cost + ' Kč' : '') + '; kus ve Výkupech zůstane provázaný s 0 ks.', function () {
            vykupPost({op: 'to_parts', product_id: id}, btn);
        });
    });

    $(document).on('click', '.vykup-to-sale-btn', function () {
        document.getElementById('vsProductId').value = this.dataset.id;
        document.getElementById('vsTitle').textContent = this.dataset.title || '';
        var pr = parseFloat(this.dataset.price || '0');
        document.getElementById('vsPrice').value = pr > 0 ? pr : '';
        document.getElementById('vsEshop').checked = true;
        if (saleModal) { saleModal.show(); }
        setTimeout(function () { document.getElementById('vsPrice').focus(); }, 350);
    });

    document.getElementById('vsSave').addEventListener('click', function () {
        var price = document.getElementById('vsPrice').value;
        if (!price || parseFloat(price) <= 0) { showAlert('Zadej prodejní cenu.'); return; }
        vykupPost({
            op: 'to_sale',
            product_id: document.getElementById('vsProductId').value,
            price: price,
            show_eshop: document.getElementById('vsEshop').checked ? 1 : 0
        }, this);
    });
}());
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
