<?php
/**
 * SKLAD — cílová stránka QR kódu z regálu (mobil technika).
 * Otevře se skenem QR (sklad.php?qr=<inventory_id>) a nabídne dvě akce:
 *   NASKLADNIT  — přijaté kusy ihned přičte ke skladu
 *   VYDAT NA ZAKÁZKU — přidá díl k zakázce s cenou a ihned odečte sklad
 * Druhý režim: sklad.php?loc=<location_id> — QR z krabičky/police ukáže OBSAH
 * umístění (drobné díly sdílející krabičku): ťuknutím na díl se přejde na jeho
 * kartu, vedení může rovnou opravit počty (inventura) a přiřazovat díly sem.
 * Předvybraná zakázka = ta, u které technik klikl „Vzít díl skenem QR"
 * (drží se 30 minut v relaci), jinak výběr z aktivních zakázek.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureInventoryMovesTable();
ensureOrderItemStockFlag();

ensureStockLocationsSchema();

$qrId = (int)($_GET['qr'] ?? $_GET['id'] ?? 0);
$inv = null;
$invLoc = null;   // umístění dílu (krabička/police) pro odznak na kartě
if ($qrId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ?");
    $stmt->execute([$qrId]);
    $inv = $stmt->fetch();
    if ($inv && !empty($inv['location_id'])) {
        try {
            $ls = $pdo->prepare("SELECT l.*, p.code AS parent_code FROM stock_locations l LEFT JOIN stock_locations p ON p.id = l.parent_id WHERE l.id = ?");
            $ls->execute([(int)$inv['location_id']]);
            $invLoc = $ls->fetch() ?: null;
        } catch (Throwable $e) {}
    }
}

// součástky uvnitř dílu (zařízení-dárce) — na kartě jako štítky
$invComps = [];
if ($inv) {
    try {
        ensureInventoryComponentsTable();
        $cq = $pdo->prepare("SELECT name, is_used FROM inventory_components WHERE inventory_id = ? ORDER BY is_used ASC, id ASC");
        $cq->execute([(int)$inv['id']]);
        $invComps = $cq->fetchAll();
    } catch (Throwable $e) {}
}

// ── režim UMÍSTĚNÍ (QR na krabičce/polici): sklad.php?loc=<id> ──
$loc = null; $locParts = []; $locChildren = [];
if (!$inv && (int)($_GET['loc'] ?? 0) > 0) {
    try {
        $ls = $pdo->prepare("SELECT l.*, p.code AS parent_code, p.name AS parent_name FROM stock_locations l LEFT JOIN stock_locations p ON p.id = l.parent_id WHERE l.id = ?");
        $ls->execute([(int)$_GET['loc']]);
        $loc = $ls->fetch() ?: null;
        if ($loc) {
            $ps = $pdo->prepare("SELECT id, part_name, sku, quantity, sale_price, image_path, device_model FROM inventory WHERE location_id = ? ORDER BY part_name ASC");
            $ps->execute([(int)$loc['id']]);
            $locParts = $ps->fetchAll();
            $cs = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM inventory i WHERE i.location_id = c.id) AS part_count FROM stock_locations c WHERE c.parent_id = ? AND c.is_active = 1 ORDER BY c.code ASC");
            $cs->execute([(int)$loc['id']]);
            $locChildren = $cs->fetchAll();
        }
    } catch (Throwable $e) {}
}

// „ozbrojená" zakázka z detailu (Vzít díl skenem QR) — per-uživatel v DB,
// takže funguje i klik na počítači + sken telefonem (jiná session)
$armed = null;
try {
    $__armKey = 'qr_arm_' . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$_SESSION['user_id']);
    $__armRaw = (string)get_setting($__armKey, '');
    if ($__armRaw !== '') {
        $__arm = json_decode($__armRaw, true);
        if (is_array($__arm) && (int)($__arm['expires'] ?? 0) > time()) { $armed = $__arm; }
    }
} catch (Throwable $e) {}

// nabídka aktivních zakázek pro výdej (posledních 50 v mém rozsahu + rychlý filtr)
$activeOrders = [];
try {
    $activeSt = orderStatusSqlIn($pdo, 'active');
    $scope = orderBranchScopeSql('o.branch_id', 'o.technician_id');
    $activeOrders = $pdo->query("SELECT o.id, o.order_code, o.device_brand, o.device_model, c.first_name, c.last_name
        FROM orders o JOIN customers c ON c.id = o.customer_id
        WHERE o.status IN ($activeSt)$scope ORDER BY o.id DESC LIMIT 50")->fetchAll();
} catch (Throwable $e) { $activeOrders = []; }

// posledních 6 pohybů dílu
$moves = [];
if ($inv) {
    try {
        $mq = $pdo->prepare("SELECT delta, reason, order_id, actor_name, created_at FROM inventory_moves WHERE inventory_id = ? ORDER BY id DESC LIMIT 6");
        $mq->execute([(int)$inv['id']]);
        $moves = $mq->fetchAll();
    } catch (Throwable $e) {}
}
?>
<div class="container-fluid" style="max-width: 560px;">
<?php if ($loc): ?>
    <?php $canCorrect = hasPermission('admin_access') || isBranchGlobalViewer(); ?>
    <div class="glass-panel p-3 border-secondary mb-3">
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width:64px;height:64px;border-radius:12px;background:rgba(100,210,255,.12);">
                <i class="fas fa-box-open fa-lg text-info"></i>
            </div>
            <div class="min-w-0">
                <div class="fw-bold text-white fs-5"><?php echo e($loc['code']); ?><?php echo trim((string)$loc['name']) !== '' ? ' · ' . e($loc['name']) : ''; ?></div>
                <div class="small text-white-75"><?php echo stockLocationTypeLabel((string)$loc['type']); ?><?php echo trim((string)($loc['parent_code'] ?? '')) !== '' ? ' · na ' . e($loc['parent_code']) : ''; ?><?php echo !(int)$loc['is_active'] ? ' · deaktivované' : ''; ?></div>
                <div class="small mt-1"><span class="badge bg-info text-dark"><?php echo count($locParts); ?> dílů · <?php echo array_sum(array_map(fn($p) => (int)$p['quantity'], $locParts)); ?> ks</span></div>
            </div>
        </div>
    </div>

    <?php if ($armed): ?>
        <div class="alert alert-info py-2 small mb-3"><i class="fas fa-link me-1"></i>Připravena zakázka <b><?php echo e($armed['code'] ?: ('#' . $armed['id'])); ?></b> — ťukni na díl a rovnou ho vydáš.</div>
    <?php endif; ?>

    <?php if ($locChildren): ?>
    <div class="glass-panel p-3 border-secondary mb-3">
        <div class="fw-semibold text-white mb-2"><i class="fas fa-boxes-stacked me-2 text-info"></i>Uvnitř</div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($locChildren as $ch): ?>
                <a href="sklad.php?loc=<?php echo (int)$ch['id']; ?>" class="badge bg-secondary text-decoration-none p-2"><?php echo e($ch['code']); ?><?php echo trim((string)$ch['name']) !== '' ? ' · ' . e($ch['name']) : ''; ?> (<?php echo (int)$ch['part_count']; ?>)</a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div id="qrMsg" class="mb-3" style="display:none;"></div>

    <div class="glass-panel p-3 border-secondary mb-3">
        <div class="fw-semibold text-white mb-2"><i class="fas fa-microchip me-2 text-info"></i>Díly v umístění</div>
        <?php if (!$locParts): ?>
            <div class="text-white-75 small">Zatím prázdné — přiřaď díly níže, nebo ze Skladu (zaškrtat díly → Přiřadit umístění).</div>
        <?php endif; ?>
        <?php foreach ($locParts as $p): ?>
            <div class="d-flex align-items-center gap-2 py-2 border-bottom border-secondary border-opacity-25">
                <a class="d-flex align-items-center gap-2 flex-grow-1 min-w-0 text-decoration-none" href="sklad.php?qr=<?php echo (int)$p['id']; ?>">
                    <?php if (!empty($p['image_path'])): ?>
                        <img src="<?php echo e($p['image_path']); ?>" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:8px;flex:0 0 auto;">
                    <?php else: ?>
                        <span class="d-flex align-items-center justify-content-center" style="width:40px;height:40px;border-radius:8px;background:rgba(255,255,255,.06);flex:0 0 auto;"><i class="fas fa-microchip text-white-50"></i></span>
                    <?php endif; ?>
                    <span class="min-w-0">
                        <span class="d-block text-white text-truncate"><?php echo e($p['part_name']); ?></span>
                        <span class="d-block small text-white-75"><?php echo $p['sku'] ? 'SKU ' . e($p['sku']) . ' · ' : ''; ?><?php echo number_format((float)$p['sale_price'], 0, ',', ' '); ?> Kč<?php echo trim((string)($p['device_model'] ?? '')) !== '' ? ' · ' . e($p['device_model']) : ''; ?></span>
                    </span>
                </a>
                <span class="badge <?php echo (int)$p['quantity'] > 0 ? 'bg-success' : 'bg-danger'; ?>" id="locQty<?php echo (int)$p['id']; ?>"><?php echo (int)$p['quantity']; ?> ks</span>
                <?php if ($canCorrect): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary loc-correct" data-id="<?php echo (int)$p['id']; ?>" data-qty="<?php echo (int)$p['quantity']; ?>" data-name="<?php echo e($p['part_name']); ?>" title="Opravit stav (inventura)"><i class="fas fa-pen"></i></button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="glass-panel p-3 border-secondary mb-3">
        <div class="fw-semibold text-white mb-2"><i class="fas fa-plus me-2 text-success"></i>Přiřadit díl sem</div>
        <input type="text" id="locAssignSearch" class="form-control mb-2" placeholder="Hledej díl (název, SKU)…" autocomplete="off">
        <div id="locAssignResults"></div>
    </div>

    <div class="d-flex gap-2 mb-3">
        <a href="location_labels.php?id=<?php echo (int)$loc['id']; ?>" target="_blank" class="btn btn-outline-secondary flex-grow-1"><i class="fas fa-qrcode me-1"></i> Štítek</a>
        <a href="inventory.php?branch=<?php echo (int)($loc['branch_id'] ?? 0) ?: getDefaultBranchId(); ?>&amp;location=<?php echo (int)$loc['id']; ?>" class="btn btn-outline-secondary flex-grow-1"><i class="fas fa-boxes me-1"></i> Ve skladu</a>
    </div>

<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var locId = <?php echo (int)$loc['id']; ?>;
    function show(ok, html) {
        var box = document.getElementById('qrMsg');
        box.style.display = '';
        box.innerHTML = '<div class="alert ' + (ok ? 'alert-success' : 'alert-danger') + ' mb-0">' + html + '</div>';
        window.scrollTo({top: 0, behavior: 'smooth'});
    }
    // inventura: oprava stavu přímo z krabičky (jen vedení)
    document.querySelectorAll('.loc-correct').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var v = prompt('Skutečný (napočítaný) počet kusů „' + this.dataset.name + '":', this.dataset.qty);
            if (v === null) return;
            v = parseInt(v, 10);
            if (isNaN(v) || v < 0) { alert('Zadej nezáporné číslo.'); return; }
            var fd = new FormData();
            fd.append('op', 'correct'); fd.append('inventory_id', this.dataset.id); fd.append('qty', v);
            fd.append('note', 'Inventura v umístění <?php echo e($loc['code']); ?>');
            fd.append('csrf_token', csrf);
            var self = this;
            fetch('api/inventory_move.php', {method: 'POST', body: fd, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success) { show(false, d.message || 'Chyba'); return; }
                    var b = document.getElementById('locQty' + self.dataset.id);
                    b.textContent = d.new_quantity + ' ks';
                    b.className = 'badge ' + (d.new_quantity > 0 ? 'bg-success' : 'bg-danger');
                    self.dataset.qty = d.new_quantity;
                    show(true, d.message);
                })
                .catch(function () { show(false, 'Síťová chyba.'); });
        });
    });
    // přiřazení dílu do tohoto umístění (hledání ve skladu)
    var t = null;
    document.getElementById('locAssignSearch').addEventListener('input', function () {
        var q = this.value.trim();
        clearTimeout(t);
        if (q.length < 2) { document.getElementById('locAssignResults').innerHTML = ''; return; }
        t = setTimeout(function () {
            fetch('api/search_catalog_items.php?q=' + encodeURIComponent(q) + '&limit=8', {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var out = '';
                    (d.results || []).forEach(function (it) {
                        out += '<div class="d-flex align-items-center gap-2 py-1 border-bottom border-secondary border-opacity-25">' +
                            '<span class="flex-grow-1 small text-white">' + String(it.text).replace(/</g, '&lt;') + '</span>' +
                            '<button type="button" class="btn btn-sm btn-success" data-assign="' + it.id + '">Sem</button></div>';
                    });
                    document.getElementById('locAssignResults').innerHTML = out
                        || ('<div class="small text-white-75">'
                            + (d.message ? String(d.message).replace(/</g, '&lt;') : 'Nic nenalezeno.')
                            + '</div>');
                })
                .catch(function () {});
        }, 300);
    });
    document.getElementById('locAssignResults').addEventListener('click', function (e) {
        var id = e.target && e.target.dataset ? e.target.dataset.assign : null;
        if (!id) return;
        var fd = new FormData();
        fd.append('op', 'assign'); fd.append('inventory_ids', id); fd.append('location_id', locId);
        fd.append('csrf_token', csrf);
        fetch('api/stock_locations.php', {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.success) { location.reload(); } else { show(false, d.message || 'Chyba'); } })
            .catch(function () { show(false, 'Síťová chyba.'); });
    });
}());
</script>
<?php elseif (!$inv): ?>
    <div class="glass-panel p-4 border-secondary text-center">
        <i class="fas fa-qrcode fa-3x mb-3 text-info"></i>
        <h5 class="text-white">Sklad — sken QR</h5>
        <p class="text-white-75 mb-1">Naskenuj QR kód dílu na regálu (kamerou telefonu nebo skenerem <i class="fas fa-qrcode"></i> v horní liště CRM).</p>
        <?php if ($qrId > 0): ?><div class="alert alert-warning mt-3 mb-0">Díl #<?php echo $qrId; ?> nebyl nalezen.</div><?php endif; ?>
        <a href="inventory.php" class="btn btn-outline-secondary mt-3"><i class="fas fa-boxes me-1"></i> Přejít na sklad</a>
    </div>
<?php else: ?>
    <div class="glass-panel p-3 border-secondary mb-3 d-flex align-items-center gap-3">
        <?php if (!empty($inv['image_path'])): ?>
            <img src="<?php echo e($inv['image_path']); ?>" alt="" style="width:64px;height:64px;object-fit:cover;border-radius:12px;">
        <?php else: ?>
            <div class="d-flex align-items-center justify-content-center" style="width:64px;height:64px;border-radius:12px;background:rgba(255,255,255,.06);"><i class="fas fa-microchip fa-lg text-white-50"></i></div>
        <?php endif; ?>
        <div class="min-w-0">
            <div class="fw-bold text-white text-truncate"><?php echo e($inv['part_name']); ?></div>
            <div class="small text-white-75"><?php echo $inv['sku'] ? 'SKU: ' . e($inv['sku']) . ' · ' : ''; ?><?php echo number_format((float)$inv['sale_price'], 0, ',', ' '); ?> Kč</div>
            <div class="small mt-1"><span class="badge <?php echo (int)$inv['quantity'] > 0 ? 'bg-success' : 'bg-danger'; ?>" id="stockBadge">Skladem: <?php echo (int)$inv['quantity']; ?> ks</span>
                <?php if ($invLoc): ?>
                    <a href="sklad.php?loc=<?php echo (int)$invLoc['id']; ?>" class="badge bg-info text-dark text-decoration-none" title="Otevřít obsah umístění"><i class="fas fa-location-dot me-1"></i><?php echo e($invLoc['code']); ?><?php echo trim((string)($invLoc['parent_code'] ?? '')) !== '' ? ' (' . e($invLoc['parent_code']) . ')' : ''; ?></a>
                <?php endif; ?>
            </div>
            <?php if ($invComps): ?>
                <div class="small mt-1" title="Součástky uvnitř dílu (přeškrtnuté už jsou vyjmuté)"><i class="fas fa-puzzle-piece me-1 text-white-50"></i><?php foreach ($invComps as $c): ?><span class="badge bg-secondary<?php echo (int)$c['is_used'] ? ' opacity-50 text-decoration-line-through' : ''; ?> me-1"><?php echo e($c['name']); ?></span><?php endforeach; ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div id="qrMsg" class="mb-3" style="display:none;"></div>

    <?php /* ── VYDAT NA ZAKÁZKU ── */ ?>
    <div class="glass-panel p-3 border-secondary mb-3">
        <div class="fw-semibold text-white mb-2"><i class="fas fa-hand-holding me-2 text-warning"></i>Vydat na zakázku</div>
        <?php if ($armed): ?>
            <div class="alert alert-info py-2 small mb-2 d-flex justify-content-between align-items-center">
                <span><i class="fas fa-link me-1"></i>Připraveno pro zakázku <b><?php echo e($armed['code'] ?: ('#' . $armed['id'])); ?></b></span>
                <button type="button" class="btn btn-sm btn-outline-light py-0" id="btnDisarm" title="Zrušit připravenou zakázku">✕ Zrušit</button>
            </div>
        <?php endif; ?>
        <input type="text" id="orderFilter" class="form-control mb-2" placeholder="Hledat zakázku (kód, zařízení, klient)…" autocomplete="off">
        <select id="issueOrder" class="form-select mb-2" size="1">
            <option value="">— vyber zakázku —</option>
            <?php foreach ($activeOrders as $ao): ?>
                <option value="<?php echo (int)$ao['id']; ?>" <?php echo ($armed && (int)$armed['id'] === (int)$ao['id']) ? 'selected' : ''; ?>>
                    <?php echo e(($ao['order_code'] ?: ('#' . $ao['id'])) . ' · ' . trim($ao['device_brand'] . ' ' . $ao['device_model']) . ' · ' . trim($ao['first_name'] . ' ' . $ao['last_name'])); ?>
                </option>
            <?php endforeach; ?>
            <?php if ($armed && !in_array((int)$armed['id'], array_map(fn($a) => (int)$a['id'], $activeOrders), true)): ?>
                <option value="<?php echo (int)$armed['id']; ?>" selected><?php echo e(($armed['code'] ?: ('#' . $armed['id'])) . ' (z detailu)'); ?></option>
            <?php endif; ?>
        </select>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group" style="max-width: 170px;">
                <button type="button" class="btn btn-outline-secondary" onclick="qrStep('issueQty',-1)">−</button>
                <input type="number" id="issueQty" class="form-control text-center" value="1" min="1" max="<?php echo max(1, (int)$inv['quantity']); ?>">
                <button type="button" class="btn btn-outline-secondary" onclick="qrStep('issueQty',1)">+</button>
            </div>
            <button type="button" class="btn btn-warning flex-grow-1 fw-semibold" id="btnIssue" <?php echo (int)$inv['quantity'] <= 0 ? 'disabled' : ''; ?>>
                <i class="fas fa-hand-holding me-1"></i> Vzít ze skladu
            </button>
        </div>
        <?php if ((int)$inv['quantity'] <= 0): ?><div class="small text-danger mt-2">Díl není skladem — nejdřív ho naskladni, nebo objednej v Nákupech.</div><?php endif; ?>
    </div>

    <?php /* ── NASKLADNIT ── */ ?>
    <div class="glass-panel p-3 border-secondary mb-3">
        <div class="fw-semibold text-white mb-2"><i class="fas fa-truck-loading me-2 text-success"></i>Naskladnit (příjem)</div>
        <div class="d-flex align-items-center gap-2">
            <div class="input-group" style="max-width: 170px;">
                <button type="button" class="btn btn-outline-secondary" onclick="qrStep('restockQty',-1)">−</button>
                <input type="number" id="restockQty" class="form-control text-center" value="1" min="1" max="10000">
                <button type="button" class="btn btn-outline-secondary" onclick="qrStep('restockQty',1)">+</button>
            </div>
            <button type="button" class="btn btn-success flex-grow-1 fw-semibold" id="btnRestock">
                <i class="fas fa-plus me-1"></i> Přidat do skladu
            </button>
        </div>
    </div>

    <?php if ($moves): ?>
    <div class="glass-panel p-3 border-secondary mb-3">
        <div class="fw-semibold text-white mb-2"><i class="fas fa-clock-rotate-left me-2 text-info"></i>Poslední pohyby</div>
        <?php foreach ($moves as $m): ?>
            <div class="d-flex justify-content-between small text-white-75 py-1 border-bottom border-secondary border-opacity-25">
                <span><?php echo $m['delta'] > 0 ? '<span class="text-success">+' . (int)$m['delta'] . '</span>' : '<span class="text-warning">' . (int)$m['delta'] . '</span>'; ?> ks
                    · <?php
                        $__rl = ['restock' => 'naskladnění', 'issue' => 'výdej', 'return' => 'vráceno', 'adjust' => 'úprava počtu', 'correction' => 'korekce'];
                        echo $__rl[$m['reason']] ?? e($m['reason']);
                        if ($m['order_id']) { echo ' → <a href="view_order.php?id=' . (int)$m['order_id'] . '">zakázka</a>'; }
                    ?></span>
                <span><?php echo e($m['actor_name'] ?: ''); ?> · <?php echo date('j.n. H:i', strtotime($m['created_at'])); ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<script>
function qrStep(id, d) {
    var el = document.getElementById(id);
    var v = Math.max(parseInt(el.min || '1', 10), Math.min(parseInt(el.max || '10000', 10), (parseInt(el.value, 10) || 1) + d));
    el.value = v;
}
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var invId = <?php echo (int)$inv['id']; ?>;
    function show(ok, html) {
        var box = document.getElementById('qrMsg');
        box.style.display = '';
        box.innerHTML = '<div class="alert ' + (ok ? 'alert-success' : 'alert-danger') + ' mb-0">' + html + '</div>';
        window.scrollTo({top: 0, behavior: 'smooth'});
    }
    function post(data, btn, after) {
        btn.disabled = true;
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fd.append('csrf_token', csrf);
        fd.append('inventory_id', invId);
        fetch('api/inventory_move.php', {method: 'POST', body: fd, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false;
                show(!!d.success, (d.message || 'Chyba') + (d.success && d.order_url ? ' <a class="fw-bold" href="' + d.order_url + '">Otevřít zakázku →</a>' : ''));
                if (d.success && typeof d.new_quantity !== 'undefined') {
                    var b = document.getElementById('stockBadge');
                    b.textContent = 'Skladem: ' + d.new_quantity + ' ks';
                    b.className = 'badge ' + (d.new_quantity > 0 ? 'bg-success' : 'bg-danger');
                    if (after) after(d);
                }
                if (window.afxChime && d.success) { try { window.afxChime('status'); } catch (e) {} }
            })
            .catch(function () { btn.disabled = false; show(false, 'Síťová chyba — zkus to znovu.'); });
    }
    document.getElementById('btnRestock').addEventListener('click', function () {
        post({op: 'restock', qty: document.getElementById('restockQty').value}, this);
    });
    document.getElementById('btnIssue').addEventListener('click', function () {
        var oid = document.getElementById('issueOrder').value;
        if (!oid) { show(false, 'Nejdřív vyber zakázku, na kterou díl bereš.'); return; }
        var qtyEl = document.getElementById('issueQty');
        post({op: 'issue', qty: qtyEl.value, order_id: oid}, this, function (d) {
            qtyEl.max = Math.max(1, d.new_quantity);
            if (d.new_quantity < 1) { document.getElementById('btnIssue').disabled = true; }
        });
    });

    // rychlý filtr nabídky zakázek (kód / zařízení / klient)
    var filterEl = document.getElementById('orderFilter');
    if (filterEl) {
        filterEl.addEventListener('input', function () {
            var q = this.value.toLowerCase();
            var sel = document.getElementById('issueOrder');
            Array.prototype.forEach.call(sel.options, function (o) {
                if (!o.value) return;
                o.hidden = q !== '' && o.text.toLowerCase().indexOf(q) === -1;
            });
        });
    }

    // zrušení připravené zakázky
    var disarmBtn = document.getElementById('btnDisarm');
    if (disarmBtn) {
        disarmBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append('order_id', '0'); fd.append('csrf_token', csrf);
            fetch('api/qr_arm.php', {method: 'POST', body: fd, credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function () { location.reload(); })
                .catch(function () { location.reload(); });
        });
    }
}());
</script>
<?php endif; ?>
</div>
<?php require_once 'includes/footer.php'; ?>
