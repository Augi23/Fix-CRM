<?php
/**
 * SKLAD — cílová stránka QR kódu z regálu (mobil technika).
 * Otevře se skenem QR (sklad.php?qr=<inventory_id>) a nabídne dvě akce:
 *   NASKLADNIT  — přijaté kusy ihned přičte ke skladu
 *   VYDAT NA ZAKÁZKU — přidá díl k zakázce s cenou a ihned odečte sklad
 * Předvybraná zakázka = ta, u které technik klikl „Vzít díl skenem QR"
 * (drží se 30 minut v relaci), jinak výběr z aktivních zakázek.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureInventoryMovesTable();
ensureOrderItemStockFlag();

$qrId = (int)($_GET['qr'] ?? $_GET['id'] ?? 0);
$inv = null;
if ($qrId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ?");
    $stmt->execute([$qrId]);
    $inv = $stmt->fetch();
}

// „ozbrojená" zakázka z detailu (Vzít díl skenem QR)
$armed = null;
if (!empty($_SESSION['qr_issue_order']) && (int)($_SESSION['qr_issue_order']['expires'] ?? 0) > time()) {
    $armed = $_SESSION['qr_issue_order'];
}

// nabídka aktivních zakázek pro výdej (posledních 25 v mém rozsahu)
$activeOrders = [];
try {
    $activeSt = orderStatusSqlIn($pdo, 'active');
    $scope = orderBranchScopeSql('o.branch_id');
    $activeOrders = $pdo->query("SELECT o.id, o.order_code, o.device_brand, o.device_model, c.first_name, c.last_name
        FROM orders o JOIN customers c ON c.id = o.customer_id
        WHERE o.status IN ($activeSt)$scope ORDER BY o.id DESC LIMIT 25")->fetchAll();
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
<?php if (!$inv): ?>
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
            <div class="small mt-1"><span class="badge <?php echo (int)$inv['quantity'] > 0 ? 'bg-success' : 'bg-danger'; ?>" id="stockBadge">Skladem: <?php echo (int)$inv['quantity']; ?> ks</span></div>
        </div>
    </div>

    <div id="qrMsg" class="mb-3" style="display:none;"></div>

    <?php /* ── VYDAT NA ZAKÁZKU ── */ ?>
    <div class="glass-panel p-3 border-secondary mb-3">
        <div class="fw-semibold text-white mb-2"><i class="fas fa-hand-holding me-2 text-warning"></i>Vydat na zakázku</div>
        <?php if ($armed): ?>
            <div class="alert alert-info py-2 small mb-2"><i class="fas fa-link me-1"></i>Připraveno pro zakázku <b><?php echo e($armed['code'] ?: ('#' . $armed['id'])); ?></b> (z detailu zakázky)</div>
        <?php endif; ?>
        <select id="issueOrder" class="form-select mb-2">
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
                    · <?php echo $m['reason'] === 'restock' ? 'naskladnění' : ($m['reason'] === 'issue' ? 'výdej' . ($m['order_id'] ? ' → <a href="view_order.php?id=' . (int)$m['order_id'] . '">zakázka</a>' : '') : 'korekce'); ?></span>
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
}());
</script>
<?php endif; ?>
</div>
<?php require_once 'includes/footer.php'; ?>
