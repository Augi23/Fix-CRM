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
$avail = (string)($_GET['avail'] ?? '');   // '' = vše, 'in' = skladem, 'out' = vyprodáno

$where_clauses = ['1=1'];
$params = [];
if ($search !== '') {
    $where_clauses[] = "(title LIKE ? OR product_code LIKE ? OR model LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($avail === 'in')  { $where_clauses[] = "stock_qty > 0"; }
if ($avail === 'out') { $where_clauses[] = "stock_qty <= 0"; }
$where_sql = " WHERE " . implode(" AND ", $where_clauses);

$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM products" . $where_sql);
$total_stmt->execute($params);
$total_count = (int)$total_stmt->fetchColumn();
$total_pages = (int)ceil($total_count / $limit);

$stmt = $pdo->prepare("SELECT * FROM products" . $where_sql . " ORDER BY added_at DESC, id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$products = $stmt->fetchAll();

$stats = $pdo->query("SELECT COUNT(*) AS total,
        SUM(CASE WHEN stock_qty > 0 THEN 1 ELSE 0 END) AS in_stock,
        SUM(CASE WHEN stock_qty > 0 THEN price ELSE 0 END) AS stock_value
    FROM products")->fetch();

// poslední import — řádky, které v něm nebyly, dostanou upozornění (nemažou se samy)
$lastImportAt = (string)get_setting('products_last_import_at', '');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><?php echo __('inventory'); ?></h2>
        <small class="text-muted">Produkty pro e-shop: <?php echo (int)($stats['total'] ?? 0); ?> ·
            skladem <?php echo (int)($stats['in_stock'] ?? 0); ?> ·
            hodnota <?php echo formatMoney((float)($stats['stock_value'] ?? 0)); ?></small>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
        <button class="btn btn-outline-info" data-bs-toggle="collapse" data-bs-target="#filterPanel">
            <i class="fas fa-filter me-2"></i> <?php echo __('filters'); ?>
        </button>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#stockHistoryModal" title="Posledních 20 naskladněných produktů — kdo je naskladnil a kdy">
            <i class="fas fa-clock-rotate-left me-2"></i> Historie naskladnění
        </button>
        <?php if ($canManage): ?>
        <button class="btn btn-success" id="productCreateOpen" data-bs-toggle="modal" data-bs-target="#productCreateModal">
            <i class="fas fa-box-open me-2"></i> Naskladnit produkt
        </button>
        <a class="btn btn-outline-secondary" href="api/export_products_csv.php" title="Kompletní sklad ve formátu souboru appky — pro ruční import do Upgates">
            <i class="fas fa-file-csv me-2"></i> CSV pro Upgates
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-upload me-2"></i> Nahrát soubor z appky
        </button>
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
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><?php echo __('apply_btn'); ?></button>
                <a href="products.php" class="btn btn-sm btn-outline-secondary"><?php echo __('reset_btn'); ?></a>
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
                                        Zatím žádné produkty.<?php echo $canManage ? ' Nahraj soubor z naskladňovací appky tlačítkem vpravo nahoře.' : ''; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): ?>
                                <?php $img = productImageDisplayUrl($p['image_url'] ?? ''); ?>
                                <?php // kusy spravované v CRM v souboru appky nejsou ZÁMĚRNĚ — badge „není v souboru" se jich netýká
                                $stale = ($lastImportAt !== '' && (string)$p['last_seen_at'] < $lastImportAt && (string)($p['source'] ?? 'app') !== 'crm'); ?>
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
                                    <td>
                                        <div class="fw-bold"><?php echo e($p['title']); ?></div>
                                        <div class="small text-white-75">
                                            <?php echo e(trim(($p['manufacturer'] ?? '') . ' ' . ($p['model'] ?? ''))); ?>
                                            <?php if (!empty($p['capacity'])): ?> · <?php echo e($p['capacity']); ?><?php endif; ?>
                                            <?php if (!empty($p['color'])): ?> · <?php echo e($p['color']); ?><?php endif; ?>
                                        </div>
                                        <?php if ($stale): ?>
                                            <span class="badge bg-warning text-dark mt-1" title="Tento produkt nebyl v naposledy nahraném souboru — buď byl v appce smazán, nebo byl nahrán jiný soubor. Nemaže se automaticky.">není v posledním souboru</span>
                                        <?php endif; ?>
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
                                    <td class="fw-bold text-primary"><?php echo formatMoney((float)$p['price']); ?></td>
                                    <td>
                                        <?php if ((int)$p['stock_qty'] > 0): ?>
                                            <span class="badge bg-success">Skladem</span>
                                        <?php elseif (!empty($p['pos_sold_at'])): ?>
                                            <span class="badge bg-warning text-dark" title="Prodáno přes Pokladnu — CRM ho automaticky drží vyprodaný, i kdyby ho soubor z appky ještě hlásil skladem">Prodáno na kase</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Vyprodáno</span>
                                        <?php endif; ?>
                                        <?php if (!empty($p['stock_key'])): ?>
                                            <div class="small text-white-75 mt-1"><?php echo $p['stock_key'] === 'karlin' ? 'Karlín' : 'Václavák'; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['added_at'])): ?>
                                            <span class="small"><?php echo date('j.n.Y', strtotime($p['added_at'])); ?></span>
                                        <?php else: ?>
                                            <span class="text-white-75">—</span>
                                        <?php endif; ?>
                                        <?php if (trim((string)($p['created_by'] ?? '')) !== ''): ?>
                                            <div class="small text-white-50"><i class="fas fa-user me-1"></i><?php echo e($p['created_by']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($canManage): ?>
                                    <td class="text-end pe-4">
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-white border text-info product-label-btn" data-id="<?php echo (int)$p['id']; ?>" title="Vytisknout cenový štítek (Brother QL-810W)"><i class="fas fa-tag"></i></button>
                                            <button type="button" class="btn btn-white border product-edit-btn" data-id="<?php echo (int)$p['id']; ?>" title="Upravit produkt"><i class="fas fa-edit text-warning"></i></button>
                                            <button type="button" class="btn btn-white border text-danger product-delete-btn" data-id="<?php echo (int)$p['id']; ?>" data-title="<?php echo e($p['title']); ?>" title="<?php echo __('delete'); ?>"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </td>
                                    <?php else: ?>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-white border text-info product-label-btn" data-id="<?php echo (int)$p['id']; ?>" title="Vytisknout cenový štítek"><i class="fas fa-tag"></i></button>
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
                    <div class="col-lg-7">
                        <input type="hidden" id="pcEditId" value="">
                        <input type="hidden" id="pcImageUrl" value="">
                        <input type="hidden" id="pcStudioUrl" value="">
                        <input type="hidden" id="pcGalleryUrls" value="">
                        <input type="hidden" id="pcVideo360Url" value="">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small">Typ zařízení</label>
                                <select id="pcTyp" class="form-select"></select>
                                <input type="text" id="pcTypCustom" class="form-control mt-1" placeholder="vlastní typ…" style="display:none;">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small">Model <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <select id="pcModel" class="form-select"></select>
                                    <input type="text" id="pcModelCustom" class="form-control" placeholder="vlastní model…" style="display:none;">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Úložiště</label>
                                <select id="pcCap" class="form-select"></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Barva</label>
                                <div class="d-flex gap-2">
                                    <select id="pcColor" class="form-select"></select>
                                    <input type="text" id="pcColorCustom" class="form-control" placeholder="vlastní…" style="display:none;">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Stav</label>
                                <select id="pcGrade" class="form-select"></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Prodejna</label>
                                <select id="pcStockKey" class="form-select">
                                    <option value="karlin">Karlín</option>
                                    <option value="vaclavak">Černá Růže</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Baterie</label>
                                <div class="input-group">
                                    <input type="number" id="pcBattery" class="form-control" min="0" max="100">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small">Prodejní cena <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" id="pcPrice" class="form-control" inputmode="numeric">
                                    <span class="input-group-text">Kč</span>
                                </div>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label small">SN / IMEI <span class="text-white-50">(naskenuj čtečkou nebo zapiš)</span></label>
                                <input type="text" id="pcSerial" class="form-control" autocomplete="off">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div id="pcPcrBadge" class="w-100 text-center small fw-bold rounded py-2" style="background:rgba(255,255,255,.06);color:#9aa3b2;">PČR: nekontrolováno</div>
                            </div>
                            <div class="col-md-3 pc-mac" style="display:none;">
                                <label class="form-label small">RAM</label>
                                <select id="pcRam" class="form-select"></select>
                            </div>
                            <div class="col-md-3 pc-mac" style="display:none;">
                                <label class="form-label small">Jader CPU</label>
                                <select id="pcCpu" class="form-select"></select>
                            </div>
                            <div class="col-md-3 pc-mac" style="display:none;">
                                <label class="form-label small">Jader GPU</label>
                                <select id="pcGpu" class="form-select"></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Ročník</label>
                                <select id="pcRocnik" class="form-select"></select>
                            </div>
                            <div class="col-md-3 pc-ipad" style="display:none;">
                                <label class="form-label small">Generace</label>
                                <select id="pcGenerace" class="form-select"></select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small">Foto produktu</label>
                                <input type="file" id="pcPhoto" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/*">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="pcSold">
                                    <label class="form-check-label small" for="pcSold">Prodáno (uloží se jako Vyprodáno)</label>
                                </div>
                            </div>

                            <!-- ── Galerie média ── -->
                            <div class="col-12">
                                <hr class="border-secondary opacity-25 my-1">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <i class="fas fa-images text-primary"></i>
                                    <span class="fw-semibold">Galerie média</span>
                                    <span class="text-white-50 small">— studiová fotka · klasické fotky (Sbazar/Bazos) · 360° video</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label small mb-1">1. Studiová fotka
                                            <span class="text-white-50">(bez pozadí, jako Apple → eshop + katalog)</span></label>
                                        <input type="file" id="pcStudioPhoto" class="form-control form-control-sm" accept="image/*">
                                        <div id="pcStudioWrap" class="mt-2 d-flex align-items-center gap-2" style="display:none!important;">
                                            <img id="pcStudioThumb" src="" alt="studio" class="rounded" style="max-height:78px;max-width:120px;object-fit:contain;background:rgba(255,255,255,.05);padding:4px;">
                                            <button type="button" id="pcStudioClear" class="btn btn-sm btn-link text-danger p-0">odebrat</button>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label small mb-1">2. Klasické fotky
                                            <span class="text-white-50">(běžné fotky kusu — Sbazar/Bazos + galerie na eshopu, max 10)</span></label>
                                        <div id="pcGallerySlots" class="d-flex flex-wrap gap-2"></div>
                                        <button type="button" id="pcGalleryAdd" class="btn btn-sm btn-outline-secondary mt-2"><i class="fas fa-plus me-1"></i>Přidat foto</button>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1">3. 360° video
                                            <span class="text-white-50">(dvě celé otočky — eshop z něj po naskladnění vyrobí 360° bez pozadí)</span></label>
                                        <input type="file" id="pcVideo360" class="form-control form-control-sm" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm">
                                        <div id="pcVideoStatus" class="small mt-1"></div>
                                        <div id="pcVideo360Proc" class="small mt-1" style="display:none"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ── živý náhled ── -->
                    <div class="col-lg-5">
                        <div class="glass-panel p-3 border-secondary h-100 d-flex flex-column">
                            <div class="small text-white-50 mb-1">Název produktu (generuje se sám)</div>
                            <div id="pcPreviewTitle" class="fs-5 fw-bold mb-3">—</div>
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
                <button type="button" class="btn btn-outline-success" id="pcSaveBtn"><i class="fas fa-plus me-1"></i> Přidat</button>
                <button type="button" class="btn btn-success" id="pcSavePrintBtn" title="Ctrl/Cmd + Enter"><i class="fas fa-tag me-1"></i> Přidat a vytisknout štítek</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="importModal" tabindex="-1" data-bs-focus="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="importForm">
                <?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-upload me-2 text-primary"></i>Nahrát produkty z appky</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-white-75 mb-3">
                        Vyber soubor <code>AppleFix-produkty.csv</code> — ten, do kterého ukládá
                        naskladňovací aplikace (bývá na Ploše). Import produkty <strong>přidá nebo
                        aktualizuje podle kódu</strong>; nic sám nemaže, takže opakované nahrání je bezpečné.
                    </p>
                    <input type="file" name="file" class="form-control" accept=".csv,text/csv" required>
                    <div id="importResult" class="alert alert-info border-0 mt-3" style="display:none;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary" id="importSubmitBtn"><i class="fas fa-file-import me-2"></i>Importovat</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// tisk cenového štítku — smí každý přihlášený (recepce tiskne cenovky)
$(document).on('click', '.product-label-btn', function () {
    var btn = this, ic = btn.querySelector('i');
    if (btn.disabled) return;
    btn.disabled = true;
    ic.className = 'fas fa-spinner fa-spin';
    var fd = new FormData();
    fd.append('action', 'print_product');
    fd.append('id', btn.dataset.id);
    fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
    fetch('api/print_label_server.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false; ic.className = 'fas fa-tag';
            if (d.ok) { showAlert('Štítek odeslán na tiskárnu.'); }
            else { showAlert('Tisk selhal: ' + escHtml(d.error || '')); }
        })
        .catch(function () { btn.disabled = false; ic.className = 'fas fa-tag'; showAlert('Síťová chyba při tisku.'); });
});

<?php if ($canManage): ?>
// ═══ Naskladnit produkt — port Mac appky ═══
(function () {
    var CATALOG = <?php echo json_encode([
        'types' => afxProductTypes(),
        'caps' => AFX_CAPS, 'rams' => AFX_RAMS, 'cpus' => AFX_CPU_CORES, 'gpus' => AFX_GPU_CORES,
        'grades' => AFX_GRADE_LABELS,
        'years' => array_map('strval', range(2026, 2010)),
        'gens' => array_map('strval', range(1, 11)),
    ], JSON_UNESCAPED_UNICODE); ?>;
    var CSRF = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    var DEFAULT_STOCK = '<?php echo $defaultStockKey; ?>';
    var CUSTOM = '✏️ Vlastní…';

    var el = function (id) { return document.getElementById(id); };
    var $typC = el('pcTypCustom');
    var $typ = el('pcTyp'), $model = el('pcModel'), $modelC = el('pcModelCustom'),
        $cap = el('pcCap'), $color = el('pcColor'), $colorC = el('pcColorCustom'),
        $grade = el('pcGrade'), $stockKey = el('pcStockKey'), $bat = el('pcBattery'),
        $price = el('pcPrice'), $serial = el('pcSerial'), $ram = el('pcRam'),
        $cpu = el('pcCpu'), $gpu = el('pcGpu'), $rocnik = el('pcRocnik'), $gen = el('pcGenerace'),
        $sold = el('pcSold'), $photo = el('pcPhoto'), $imageUrl = el('pcImageUrl'),
        $badge = el('pcPcrBadge'), $msg = el('pcMsg'), $hint = el('pcHint'), $editId = el('pcEditId');

    function fillSelect(sel, values, withEmpty, withCustom) {
        sel.innerHTML = '';
        if (withEmpty) sel.appendChild(new Option('—', ''));
        values.forEach(function (v) { sel.appendChild(new Option(v, v)); });
        if (withCustom) sel.appendChild(new Option(CUSTOM, CUSTOM));
    }
    function typVal() { return $typ.value === CUSTOM ? $typC.value.trim() : $typ.value; }
    function typeDef() {
        var tv = typVal();
        for (var i = 0; i < CATALOG.types.length; i++) if (CATALOG.types[i].id === tv) return CATALOG.types[i];
        return { id: tv, manuf: '', k: '', cap: true, ram: false, gen: false, colors: [], models: [] };
    }
    function modelVal() { return $model.value === CUSTOM ? $modelC.value.trim() : $model.value.trim(); }
    function colorVal() { return $color.value === CUSTOM ? $colorC.value.trim() : $color.value.trim(); }

    // JS zrcadlo build_title() — jen pro živý náhled, server počítá autoritativně
    function buildTitle() {
        var t = typeDef();
        var model = modelVal();
        var dm = model;
        if (model && t.id && model.toLowerCase().indexOf(t.id.toLowerCase()) !== 0) dm = t.id + ' ' + model;
        var cap = t.cap ? $cap.value : '';
        var ram = t.ram ? $ram.value : '', cpu = t.ram ? $cpu.value : '', gpu = t.ram ? $gpu.value : '';
        var mem = (ram && cap) ? ram + '/' + cap + ' SSD' : (ram ? ram + ' RAM' : cap);
        var cores = [cpu ? cpu + ' CPU' : '', gpu ? gpu + ' GPU' : ''].filter(Boolean).join(' ');
        var spec = (cores && mem) ? cores + ', ' + mem : (cores || mem);
        var gr = ($grade.value || '').split(' ')[0] || 'A';
        return [dm, spec, colorVal(), gr].filter(Boolean).join(' ').trim();
    }
    function buildDesc() {
        var t = typeDef();
        var out = [];
        var gr = ($grade.value || '').split(' ')[0] || 'A';
        if (gr) out.push('Stav: ' + gr);
        if ($bat.value) out.push('Kondice baterie: ' + $bat.value + ' %');
        if (t.ram && $cpu.value) out.push('Jader CPU: ' + $cpu.value);
        if (t.ram && $gpu.value) out.push('Jader GPU: ' + $gpu.value);
        if (t.ram && $ram.value) out.push('RAM: ' + $ram.value);
        if (t.cap && $cap.value) out.push('Úložiště: ' + $cap.value);
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

    function onType() {
        var t = typeDef();
        fillSelect($model, t.models, true, true);
        fillSelect($color, t.colors, true, true);
        $modelC.style.display = 'none'; $colorC.style.display = 'none';
        document.querySelectorAll('.pc-mac').forEach(function (n) { n.style.display = t.ram ? '' : 'none'; });
        document.querySelectorAll('.pc-ipad').forEach(function (n) { n.style.display = t.gen ? '' : 'none'; });
        refreshPreview();
    }

    // hodnota mimo výčet selectu se nesmí tiše zahodit (starší kusy: 3 TB, rok 2009…)
    function setSelectValue(sel, val) {
        val = val || '';
        if (val !== '' && !Array.prototype.some.call(sel.options, function (o) { return o.value === val; })) {
            sel.insertBefore(new Option(val, val), sel.options[sel.options.length] || null);
        }
        sel.value = val;
    }

    // init
    fillSelect($typ, CATALOG.types.map(function (t) { return t.id; }), false, true);
    fillSelect($cap, CATALOG.caps, true, false);
    fillSelect($grade, CATALOG.grades, false, false);
    fillSelect($ram, CATALOG.rams, true, false);
    fillSelect($cpu, CATALOG.cpus, true, false);
    fillSelect($gpu, CATALOG.gpus, true, false);
    fillSelect($rocnik, CATALOG.years, true, false);
    fillSelect($gen, CATALOG.gens, true, false);
    $grade.value = 'Nový';
    $stockKey.value = DEFAULT_STOCK;
    onType();

    $typ.addEventListener('change', function () {
        $typC.style.display = $typ.value === CUSTOM ? '' : 'none';
        onType();
    });
    $model.addEventListener('change', function () { $modelC.style.display = $model.value === CUSTOM ? '' : 'none'; refreshPreview(); });
    $color.addEventListener('change', function () { $colorC.style.display = $color.value === CUSTOM ? '' : 'none'; refreshPreview(); });
    [$typC, $modelC, $colorC, $cap, $grade, $bat, $ram, $cpu, $gpu, $rocnik, $gen].forEach(function (n) {
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
                if (d.success) { $studioUrl.value = d.url; $studioThumb.src = d.url; $studioWrap.style.setProperty('display', 'flex', 'important'); }
                else { showAlert('Studiová fotka se nenahrála: ' + (d.message || '')); }
            })
            .catch(function () { pending--; if (gen === formGen) showAlert('Studiová fotka se nenahrála (síť).'); });
    });
    el('pcStudioClear').addEventListener('click', function () {
        $studioUrl.value = ''; $studioThumb.src = ''; $studioWrap.style.setProperty('display', 'none', 'important'); $studioPhoto.value = '';
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

    // (3) 360° video → vlastní endpoint (obrázkový by ho odmítl)
    $video360.addEventListener('change', function () {
        if (!this.files.length) return;
        var gen = formGen; pending++;
        $videoStatus.textContent = 'Nahrávám video…'; $videoStatus.className = 'small mt-1 text-white-50';
        var fd = new FormData();
        fd.append('video', this.files[0]); fd.append('code', baseCode()); fd.append('csrf_token', CSRF);
        fetch('api/upload_product_video.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                pending--; if (gen !== formGen) return;
                if (d.success) { $video360Url.value = d.url; $videoStatus.textContent = '✓ Video nahráno — 360° se teď vyrobí na serveru.'; $videoStatus.className = 'small mt-1 text-success'; poll360(baseCode(), true); }
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
        } else { // processing
            $video360Proc.innerHTML = '<span class="text-info"><span class="spinner-border spinner-border-sm me-1" style="width:.8rem;height:.8rem"></span>360° se zpracovává na serveru… (pár minut)</span>';
        }
    }
    function poll360(code, first) {
        if (poll360Timer) { clearTimeout(poll360Timer); poll360Timer = null; }
        if (!code) return;
        if (first) { poll360Left = 60; }               // ~5 min stropu (60×5 s)
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
            var code = baseCode(); if (!code) return;
            $video360Proc.innerHTML = '<span class="text-info">Spouštím přegenerování…</span>';
            var fd = new FormData(); fd.append('action', 'regen'); fd.append('code', code); fd.append('csrf_token', CSRF);
            fetch('api/status_360.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); }).then(function () { poll360(code, true); })
                .catch(function () {});
        }
    });

    // reset / naplnění celé Galerie (nový produkt = prázdno, editace = z produktu)
    function resetGalleryAll(gallery, studio, video) {
        $studioUrl.value = studio || ''; $studioPhoto.value = '';
        if (studio) { $studioThumb.src = studio; $studioWrap.style.setProperty('display', 'flex', 'important'); }
        else { $studioThumb.src = ''; $studioWrap.style.setProperty('display', 'none', 'important'); }
        galUrls = []; $gallerySlots.innerHTML = ''; $galleryAdd.style.display = '';
        var list = (gallery && gallery.length) ? gallery : [];
        var n = Math.min(10, Math.max(3, list.length));
        for (var i = 0; i < n; i++) { if (list[i]) { galUrls[i] = list[i]; } addGallerySlot(list[i] || ''); }
        syncGalleryHidden();
        $video360Url.value = video || ''; $video360.value = '';
        if (video) { $videoStatus.textContent = '✓ Video nahráno.'; $videoStatus.className = 'small mt-1 text-success'; poll360(baseCode(), true); }
        else { $videoStatus.textContent = ''; $videoStatus.className = 'small mt-1'; render360({ status: 'none' }); }
    }
    resetGalleryAll([], '', '');                              // úvodní stav = 3 prázdné sloty

    // ── uložení (create/update), stolen force flow, sériové naskladňování ──
    var saving = false;
    var savedSomething = false;   // řídí reload po zavření modalu (ne křehký text v $msg)
    function save(printAfter, force) {
        if (saving) return;
        if (pending > 0) { $msg.textContent = 'Počkej — média se ještě nahrávají…'; return; }
        if (!modelVal()) { $msg.textContent = 'Vyplň model.'; return; }
        if (!$price.value.trim()) { $msg.textContent = 'Vyplň cenu.'; return; }
        saving = true;
        $msg.textContent = 'Ukládám…';
        var fd = new FormData();
        fd.append('action', $editId.value ? 'update' : 'create');
        if ($editId.value) fd.append('id', $editId.value);
        fd.append('csrf_token', CSRF);
        fd.append('typ', typVal());
        fd.append('model', modelVal());
        fd.append('cap', typeDef().cap ? $cap.value : '');
        fd.append('color', colorVal());
        fd.append('grade', $grade.value);
        fd.append('battery', $bat.value);
        fd.append('price', $price.value.trim());
        fd.append('serial', $serial.value.trim());
        fd.append('ram', typeDef().ram ? $ram.value : '');
        fd.append('cpu', typeDef().ram ? $cpu.value : '');
        fd.append('gpu', typeDef().ram ? $gpu.value : '');
        fd.append('rocnik', $rocnik.value);
        fd.append('generace', typeDef().gen ? $gen.value : '');
        if ($sold.checked) fd.append('sold', '1');
        fd.append('stock_key', $stockKey.value);
        fd.append('image_url', $imageUrl.value);
        fd.append('studio_url', $studioUrl.value);
        fd.append('gallery_urls', $galleryUrls.value);
        fd.append('video360_url', $video360Url.value);
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
                if (!d.success) { $msg.textContent = d.message || 'Uložení selhalo.'; return; }
                savedSomething = true;
                el('pcTodayCount').textContent = d.today_count;
                $msg.textContent = ($editId.value ? 'Uloženo: ' : 'Naskladněno: ') + d.title;
                if (d.hint) { $hint.textContent = d.hint; $hint.style.display = ''; }
                else { $hint.style.display = 'none'; }
                var printPromise = Promise.resolve();
                if (printAfter && d.id) {
                    var pf = new FormData();
                    pf.append('action', 'print_product'); pf.append('id', d.id); pf.append('csrf_token', CSRF);
                    printPromise = fetch('api/print_label_server.php', { method: 'POST', body: pf, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (p) { if (!p.ok) { $msg.textContent += ' · Tisk štítku selhal: ' + (p.error || ''); } })
                        .catch(function () { $msg.textContent += ' · Tisk štítku selhal (síť).'; });
                }
                // reload při editaci až PO doběhnutí tisku — unload by in-flight tisk zrušil
                if ($editId.value) { printPromise.then(function () { location.reload(); }); return; }
                // vyčistit vše KROMĚ Typ / Stav / Prodejna — sériové naskladňování jako v appce
                formGen++;
                [$modelC, $colorC, $bat, $price, $serial].forEach(function (n) { n.value = ''; });
                $model.value = ''; $color.value = ''; $cap.value = '';
                $ram.value = ''; $cpu.value = ''; $gpu.value = ''; $rocnik.value = ''; $gen.value = '';
                $modelC.style.display = 'none'; $colorC.style.display = 'none';
                $sold.checked = false;
                $photo.value = ''; $imageUrl.value = '';
                resetGalleryAll([], '', '');
                el('pcPreviewImgWrap').style.display = 'none';
                setBadge('none');
                refreshPreview();
            })
            .catch(function () { saving = false; $msg.textContent = 'Síťová chyba — zkus to znovu.'; });
    }
    el('pcSaveBtn').addEventListener('click', function () { save(false, false); });
    el('pcSavePrintBtn').addEventListener('click', function () { save(true, false); });
    document.getElementById('productCreateModal').addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); save(true, false); }
    });
    // po zavření modalu obnovit tabulku (nové kusy) — při otevřeném se nerefreshuje
    document.getElementById('productCreateModal').addEventListener('hidden.bs.modal', function () {
        if (savedSomething) { location.reload(); }
    });
    el('productCreateOpen').addEventListener('click', function () {
        // režim NOVÝ produkt — vyčistit VŠE (i pozůstatky předchozí editace)
        formGen++;
        $editId.value = '';
        el('pcTitleMode').textContent = 'Naskladnit produkt';
        el('pcSaveBtn').innerHTML = '<i class="fas fa-plus me-1"></i> Přidat';
        $typC.value = ''; $typC.style.display = 'none';
        if ($typ.value === CUSTOM) { $typ.value = CATALOG.types[0].id; onType(); }
        [$modelC, $colorC, $bat, $price, $serial].forEach(function (n) { n.value = ''; });
        $model.value = ''; $color.value = ''; $cap.value = '';
        $ram.value = ''; $cpu.value = ''; $gpu.value = ''; $rocnik.value = ''; $gen.value = '';
        $modelC.style.display = 'none'; $colorC.style.display = 'none';
        $sold.checked = false;
        $photo.value = ''; $imageUrl.value = '';
        resetGalleryAll([], '', '');
        el('pcPreviewImgWrap').style.display = 'none';
        $hint.style.display = 'none';
        $msg.textContent = '';
        setBadge('none');
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
                $editId.value = p.id;
                el('pcTitleMode').textContent = 'Upravit produkt';
                el('pcSaveBtn').innerHTML = '<i class="fas fa-save me-1"></i> Uložit změny';
                // typ odvodit z kategorie (K-kód), fallback z názvu; NEZNÁMÝ typ (z appky
                // „Vlastní…") NIKDY nespadne na iPhone — přešel by na cizí K-kód a výrobce
                var typ = '';
                CATALOG.types.forEach(function (t) { if (t.k && t.k === p.category_code) typ = t.id; });
                if (!typ) { CATALOG.types.forEach(function (t) { if (p.title.indexOf(t.id) === 0) typ = t.id; }); }
                if (typ) { $typ.value = typ; $typC.value = ''; $typC.style.display = 'none'; }
                else { $typ.value = CUSTOM; $typC.value = ''; $typC.style.display = ''; }
                onType();
                var t = typeDef();
                // model bez prefixu typu (display_model ho přidává zpět)
                var m = p.model || '';
                if (t.models.indexOf(m) >= 0) { $model.value = m; }
                else if (m) { $model.value = CUSTOM; $modelC.style.display = ''; $modelC.value = m; }
                if (t.colors.indexOf(p.color) >= 0) { $color.value = p.color; }
                else if (p.color) { $color.value = CUSTOM; $colorC.style.display = ''; $colorC.value = p.color; }
                setSelectValue($cap, p.cap);
                // grade token → celý label
                var gl = CATALOG.grades.filter(function (g) { return g.split(' ')[0] === p.grade; });
                $grade.value = gl.length ? gl[0] : 'Nový';
                $bat.value = p.battery || '';
                $price.value = p.price || '';
                $serial.value = p.serial || '';
                setSelectValue($ram, p.ram); setSelectValue($cpu, p.cpu); setSelectValue($gpu, p.gpu);
                setSelectValue($rocnik, p.rocnik); setSelectValue($gen, p.generace);
                $sold.checked = !!p.sold;
                $stockKey.value = p.stock_key || DEFAULT_STOCK;
                $imageUrl.value = p.image_url || '';
                if (p.image_url) { el('pcPreviewImg').src = p.image_url; el('pcPreviewImgWrap').style.display = ''; }
                else { el('pcPreviewImgWrap').style.display = 'none'; }
                var galArr = []; try { galArr = p.gallery_images ? JSON.parse(p.gallery_images) : []; } catch (e) { galArr = []; }
                resetGalleryAll(Array.isArray(galArr) ? galArr : [], p.studio_image_url || '', p.video_360_url || '');
                setBadge(p.pcr_status || 'none');
                $msg.textContent = p.source === 'app' ? 'Pozor: kus z appky — uložením ho převezme CRM (import appky ho už nepřepíše).' : '';
                refreshPreview();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('productCreateModal')).show();
            })
            .catch(function () { showAlert('Načtení produktu selhalo.'); });
    });
})();

document.getElementById('importForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = document.getElementById('importSubmitBtn');
    var box = document.getElementById('importResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Importuji…';
    var fd = new FormData(this);
    fetch('api/import_products.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            box.style.display = 'block';
            box.className = 'alert border-0 mt-3 ' + (d.success ? 'alert-success' : 'alert-danger');
            box.textContent = d.message || (d.success ? 'Hotovo.' : 'Chyba importu.');
            if (d.success) { setTimeout(function () { location.reload(); }, 1600); }
            else { btn.disabled = false; btn.innerHTML = '<i class="fas fa-file-import me-2"></i>Importovat'; }
        })
        .catch(function () {
            box.style.display = 'block';
            box.className = 'alert alert-danger border-0 mt-3';
            box.textContent = 'Síťová chyba — zkus to znovu.';
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-file-import me-2"></i>Importovat';
        });
});

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
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
