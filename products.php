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
require_once 'includes/header.php';
ensureProductsTable();

$canManage = crmCanManageProducts();

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
        <?php if ($canManage): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-upload me-2"></i> Nahrát soubor z appky
        </button>
        <?php endif; ?>
    </div>
</div>

<?php require 'includes/inventory_tabs.php'; ?>

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
                                <?php if ($canManage): ?><th class="text-end pe-4"><?php echo __('action'); ?></th><?php endif; ?>
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
                                <?php $stale = ($lastImportAt !== '' && (string)$p['last_seen_at'] < $lastImportAt); ?>
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
                                    </td>
                                    <?php if ($canManage): ?>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-white border text-danger product-delete-btn" data-id="<?php echo (int)$p['id']; ?>" data-title="<?php echo e($p['title']); ?>" title="<?php echo __('delete'); ?>"><i class="fas fa-trash"></i></button>
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
<?php if ($canManage): ?>
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
