<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureProcurementSchema();

$can_manage = hasPermission('procurement_manage') || hasPermission('admin_access');

// ── Lokální popisky/badge (aby nekolidovaly s procurement.php) ───────────────
function slPriorityLabel(string $p): string {
    return match ($p) {
        'today' => __('today_priority'),
        'this_week' => __('this_week_priority'),
        'later' => __('later_priority'),
        default => $p,
    };
}
function slPriorityBadge(string $p): string {
    return match ($p) {
        'today' => 'sl-badge sl-badge-danger',
        'this_week' => 'sl-badge sl-badge-info',
        'later' => 'sl-badge sl-badge-muted',
        default => 'sl-badge sl-badge-muted',
    };
}

// ── Načtení položek nákupního seznamu ────────────────────────────────────────
$requests = [];
try {
    $requests = $pdo->query("
        SELECT pr.*,
               o.order_code, o.device_brand, o.device_model,
               c.first_name, c.last_name,
               ru.full_name AS requested_by_name
        FROM purchase_requests pr
        LEFT JOIN orders o     ON o.id = pr.order_id
        LEFT JOIN customers c  ON c.id = o.customer_id
        LEFT JOIN users ru     ON ru.id = pr.requested_by
        ORDER BY FIELD(pr.status,'pending','ordered','received','cancelled'),
                 FIELD(pr.priority,'today','this_week','later'),
                 pr.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $requests = [];
}

$groups = ['pending' => [], 'ordered' => [], 'received' => [], 'cancelled' => []];
foreach ($requests as $r) {
    $st = (string)($r['status'] ?? 'pending');
    if (!isset($groups[$st])) { $st = 'pending'; }
    $groups[$st][] = $r;
}
$countPending   = count($groups['pending']);
$countOrdered   = count($groups['ordered']);
$countReceived  = count($groups['received']);
$countCancelled = count($groups['cancelled']);

/**
 * Vykreslí jednu položku seznamu jako kartu-řádek.
 */
function slRenderItem(array $r, bool $can_manage): void {
    $id        = (int)($r['id'] ?? 0);
    $status    = (string)($r['status'] ?? 'pending');
    $priority  = (string)($r['priority'] ?? 'this_week');
    $qty       = max(1, (int)($r['quantity'] ?? 1));
    $itemName  = (string)($r['item_name'] ?? '');
    $sku       = trim((string)($r['sku'] ?? ''));
    $supplier  = supplierLabel((string)($r['supplier_key'] ?? ''));
    $notes     = trim((string)($r['notes'] ?? ''));

    $orderId   = (int)($r['order_id'] ?? 0);
    $orderCode = trim((string)($r['order_code'] ?? ''));
    $device    = trim((string)($r['device_brand'] ?? '') . ' ' . (string)($r['device_model'] ?? ''));
    $client    = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
    $reqBy     = trim((string)($r['requested_by_name'] ?? ''));
    $created   = !empty($r['created_at']) ? date('j.n.Y', strtotime((string)$r['created_at'])) : '';
    ?>
    <div class="sl-item" id="sl-item-<?php echo $id; ?>">
        <div class="sl-item-main">
            <div class="sl-item-qty"><?php echo $qty; ?>&times;</div>
            <div class="sl-item-body">
                <div class="sl-item-name"><?php echo e($itemName); ?></div>
                <div class="sl-item-meta">
                    <?php if ($sku !== ''): ?><span class="sl-chip"><i class="fas fa-barcode"></i> <?php echo e($sku); ?></span><?php endif; ?>
                    <span class="sl-chip"><i class="fas fa-store"></i> <?php echo e($supplier); ?></span>
                    <span class="<?php echo slPriorityBadge($priority); ?>"><?php echo slPriorityLabel($priority); ?></span>
                    <?php if ($orderId > 0): ?>
                        <a class="sl-chip sl-chip-link" href="view_order.php?id=<?php echo $orderId; ?>">
                            <i class="fas fa-tools"></i> <?php echo e($orderCode !== '' ? $orderCode : (__('order_ref_prefix') . $orderId)); ?><?php if ($device !== ''): ?> · <?php echo e($device); ?><?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($created !== ''): ?><span class="sl-chip sl-chip-soft"><i class="far fa-calendar"></i> <?php echo e($created); ?></span><?php endif; ?>
                    <?php if ($reqBy !== ''): ?><span class="sl-chip sl-chip-soft"><i class="far fa-user"></i> <?php echo e($reqBy); ?></span><?php endif; ?>
                </div>
                <?php if ($notes !== ''): ?><div class="sl-item-notes"><i class="far fa-comment-dots me-1"></i><?php echo nl2br(e($notes)); ?></div><?php endif; ?>
            </div>
        </div>
        <?php if ($can_manage): ?>
        <div class="sl-item-actions">
            <?php if ($status === 'pending'): ?>
                <button class="sl-btn sl-btn-success" data-sl-action="ordered" data-sl-id="<?php echo $id; ?>"><i class="fas fa-check me-1"></i><?php echo __('approve_and_order'); ?></button>
                <button class="sl-btn sl-btn-ghost" data-sl-action="cancelled" data-sl-id="<?php echo $id; ?>" title="<?php echo e(__('reject')); ?>"><i class="fas fa-ban"></i></button>
            <?php elseif ($status === 'ordered'): ?>
                <button class="sl-btn sl-btn-success" data-sl-action="received" data-sl-id="<?php echo $id; ?>"><i class="fas fa-box-open me-1"></i><?php echo __('received_to_stock'); ?></button>
                <button class="sl-btn sl-btn-ghost" data-sl-action="pending" data-sl-id="<?php echo $id; ?>" title="<?php echo e(__('back_to_pending')); ?>"><i class="fas fa-rotate-left"></i></button>
            <?php elseif ($status === 'cancelled'): ?>
                <button class="sl-btn sl-btn-ghost" data-sl-action="pending" data-sl-id="<?php echo $id; ?>" title="<?php echo e(__('restore')); ?>"><i class="fas fa-rotate-left"></i></button>
            <?php endif; ?>
            <button class="sl-btn sl-btn-danger" data-sl-action="delete" data-sl-id="<?php echo $id; ?>" title="<?php echo e(__('delete')); ?>"><i class="fas fa-trash"></i></button>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
?>

<div class="container-fluid px-3 px-md-4 py-4 sl-page">

    <div class="sl-header">
        <div>
            <h1 class="sl-title"><i class="fas fa-cart-shopping me-2"></i><?php echo __('shopping_list'); ?></h1>
            <p class="sl-subtitle"><?php echo __('shopping_list_desc_1'); ?> <a href="procurement.php"><?php echo __('procurement'); ?></a> <?php echo __('shopping_list_desc_2'); ?></p>
        </div>
        <a href="procurement.php" class="sl-btn sl-btn-outline"><i class="fas fa-plus me-1"></i><?php echo __('add_part_from_procurement'); ?></a>
    </div>

    <div class="sl-stats">
        <div class="sl-stat sl-stat-pending">
            <div class="sl-stat-num"><?php echo $countPending; ?></div>
            <div class="sl-stat-label"><?php echo __('awaiting_approval'); ?></div>
        </div>
        <div class="sl-stat sl-stat-ordered">
            <div class="sl-stat-num"><?php echo $countOrdered; ?></div>
            <div class="sl-stat-label"><?php echo __('ordered_status'); ?></div>
        </div>
        <div class="sl-stat sl-stat-received">
            <div class="sl-stat-num"><?php echo $countReceived; ?></div>
            <div class="sl-stat-label"><?php echo __('received_to_stock'); ?></div>
        </div>
    </div>

    <?php if (!$can_manage): ?>
        <div class="sl-note"><i class="fas fa-circle-info me-2"></i><?php echo __('shopping_list_viewer_note'); ?></div>
    <?php endif; ?>

    <?php
    $sections = [
        'pending'   => [__('awaiting_approval'), 'fa-hourglass-half', 'sl-sec-pending'],
        'ordered'   => [__('ordered_status'), 'fa-paper-plane', 'sl-sec-ordered'],
        'received'  => [__('received_to_stock'), 'fa-box-open', 'sl-sec-received'],
        'cancelled' => [__('rejected_items'), 'fa-ban', 'sl-sec-cancelled'],
    ];
    $anyItems = false;
    foreach ($sections as $key => $meta):
        $items = $groups[$key];
        if (empty($items)) { continue; }
        $anyItems = true;
    ?>
        <section class="sl-section <?php echo $meta[2]; ?>">
            <div class="sl-section-head">
                <i class="fas <?php echo $meta[1]; ?> me-2"></i>
                <span><?php echo $meta[0]; ?></span>
                <span class="sl-section-count"><?php echo count($items); ?></span>
            </div>
            <div class="sl-section-body">
                <?php foreach ($items as $r) { slRenderItem($r, $can_manage); } ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if (!$anyItems): ?>
        <div class="sl-empty">
            <i class="fas fa-cart-shopping"></i>
            <h3><?php echo __('shopping_list_empty'); ?></h3>
            <p><?php echo __('shopping_empty_1'); ?> <a href="procurement.php"><?php echo __('procurement'); ?></a> <?php echo __('shopping_empty_2'); ?></p>
        </div>
    <?php endif; ?>

</div>

<style>
.sl-page { color: #e8ecf3; max-width: 1120px; margin: 0 auto; }
.sl-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
.sl-title { font-size: 1.7rem; font-weight: 800; margin: 0 0 4px; letter-spacing: -0.02em; }
.sl-title i { color: #22c55e; }
.sl-subtitle { color: #9aa4b2; margin: 0; font-size: .95rem; }
.sl-subtitle a { color: #7DBEFF; text-decoration: none; }
.sl-subtitle a:hover { text-decoration: underline; }

.sl-stats { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 14px; margin-bottom: 22px; }
.sl-stat { border-radius: 16px; padding: 18px 20px; border: 1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.03); }
.sl-stat-num { font-size: 2rem; font-weight: 800; line-height: 1; }
.sl-stat-label { color: #9aa4b2; font-size: .85rem; margin-top: 6px; }
.sl-stat-pending  { border-color: rgba(245,158,11,.34);  background: linear-gradient(135deg, rgba(245,158,11,.14), rgba(245,158,11,.04)); }
.sl-stat-pending  .sl-stat-num { color: #f59e0b; }
.sl-stat-ordered  { border-color: rgba(10,132,255,.34);  background: linear-gradient(135deg, rgba(10,132,255,.14), rgba(10,132,255,.04)); }
.sl-stat-ordered  .sl-stat-num { color: #4aa3ff; }
.sl-stat-received { border-color: rgba(34,197,94,.34);   background: linear-gradient(135deg, rgba(34,197,94,.14), rgba(34,197,94,.04)); }
.sl-stat-received .sl-stat-num { color: #22c55e; }

.sl-note { border: 1px solid rgba(10,132,255,.3); background: rgba(10,132,255,.08); color: #bcd7ff; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; font-size: .92rem; }

.sl-section { margin-bottom: 26px; }
.sl-section-head { display: flex; align-items: center; gap: 4px; font-weight: 700; font-size: 1.05rem; margin-bottom: 12px; padding-left: 2px; }
.sl-section-count { margin-left: 8px; font-size: .8rem; font-weight: 700; background: rgba(255,255,255,.1); color: #cfd6e0; border-radius: 999px; padding: 2px 10px; }
.sl-sec-pending  .sl-section-head i { color: #f59e0b; }
.sl-sec-ordered  .sl-section-head i { color: #4aa3ff; }
.sl-sec-received .sl-section-head i { color: #22c55e; }
.sl-sec-cancelled .sl-section-head { color: #8b93a1; }
.sl-sec-cancelled .sl-section-head i { color: #8b93a1; }

.sl-section-body { display: flex; flex-direction: column; gap: 10px; }
.sl-item { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
    border: 1px solid rgba(255,255,255,.09); background: rgba(255,255,255,.035); border-radius: 14px; padding: 14px 16px; transition: border-color .15s, background .15s; }
.sl-item:hover { border-color: rgba(255,255,255,.18); background: rgba(255,255,255,.05); }
.sl-sec-pending  .sl-item { border-left: 3px solid #f59e0b; }
.sl-sec-ordered  .sl-item { border-left: 3px solid #4aa3ff; }
.sl-sec-received .sl-item { border-left: 3px solid #22c55e; opacity: .92; }
.sl-sec-cancelled .sl-item { opacity: .6; }

.sl-item-main { display: flex; align-items: flex-start; gap: 14px; flex: 1 1 340px; min-width: 0; }
.sl-item-qty { font-size: 1.2rem; font-weight: 800; color: #fff; background: rgba(255,255,255,.08); border-radius: 10px; padding: 6px 12px; white-space: nowrap; }
.sl-item-body { min-width: 0; }
.sl-item-name { font-weight: 700; font-size: 1.05rem; color: #fff; margin-bottom: 6px; }
.sl-item-meta { display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; }
.sl-item-notes { margin-top: 8px; color: #aeb6c2; font-size: .88rem; }

.sl-chip { display: inline-flex; align-items: center; gap: 5px; font-size: .8rem; color: #cfd6e0;
    background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.08); border-radius: 8px; padding: 3px 9px; }
.sl-chip i { opacity: .8; font-size: .75rem; }
.sl-chip-soft { background: transparent; border-color: transparent; color: #8b93a1; padding-left: 2px; }
.sl-chip-link { color: #7DBEFF; text-decoration: none; }
.sl-chip-link:hover { color: #fff; border-color: rgba(125,190,255,.5); }

.sl-badge { display: inline-flex; align-items: center; font-size: .74rem; font-weight: 700; border-radius: 8px; padding: 3px 9px; text-transform: uppercase; letter-spacing: .02em; }
.sl-badge-danger { background: rgba(239,68,68,.18); color: #ff9a9a; border: 1px solid rgba(239,68,68,.35); }
.sl-badge-info   { background: rgba(56,189,248,.16); color: #7dd3fc; border: 1px solid rgba(56,189,248,.3); }
.sl-badge-muted  { background: rgba(255,255,255,.06); color: #9aa4b2; border: 1px solid rgba(255,255,255,.1); }

.sl-item-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.sl-btn { display: inline-flex; align-items: center; justify-content: center; font-size: .9rem; font-weight: 600; border-radius: 10px;
    padding: 9px 14px; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: all .15s; white-space: nowrap; }
.sl-btn:disabled { opacity: .55; cursor: default; }
.sl-btn-success { background: linear-gradient(135deg, #22c55e, #16a34a); color: #fff; box-shadow: 0 2px 10px rgba(34,197,94,.28); }
.sl-btn-success:hover { filter: brightness(1.08); box-shadow: 0 4px 16px rgba(34,197,94,.4); color:#fff; }
.sl-btn-danger { background: rgba(239,68,68,.12); color: #ff8f8f; border-color: rgba(239,68,68,.32); }
.sl-btn-danger:hover { background: rgba(239,68,68,.24); color: #fff; }
.sl-btn-ghost { background: rgba(255,255,255,.06); color: #cfd6e0; border-color: rgba(255,255,255,.12); }
.sl-btn-ghost:hover { background: rgba(255,255,255,.12); color: #fff; }
.sl-btn-outline { background: transparent; color: #7DBEFF; border-color: rgba(125,190,255,.4); }
.sl-btn-outline:hover { background: rgba(125,190,255,.12); color: #fff; }

.sl-empty { text-align: center; padding: 64px 20px; color: #9aa4b2; }
.sl-empty i { font-size: 3rem; color: rgba(34,197,94,.5); margin-bottom: 16px; }
.sl-empty h3 { color: #e8ecf3; font-weight: 700; margin-bottom: 6px; }
.sl-empty a { color: #7DBEFF; text-decoration: none; }

@media (max-width: 640px) {
    .sl-stats { grid-template-columns: 1fr; }
    .sl-item-actions { width: 100%; }
    .sl-item-actions .sl-btn-success { flex: 1; }
}
html[data-lg-theme="light"] .sl-page,
html[data-bs-theme="light"] .sl-page { color: #1d2530; }
html[data-lg-theme="light"] .sl-item-name,
html[data-lg-theme="light"] .sl-item-qty { color: #10151d; }
</style>

<script>
$(function () {
    var LABELS = {
        ordered:   { confirm: null, busy: '<?php echo __('busy_ordering'); ?>' },
        received:  { confirm: null, busy: '<?php echo __('busy_receiving'); ?>' },
        cancelled: { confirm: '<?php echo __('confirm_reject_item'); ?>', busy: '…' },
        pending:   { confirm: null, busy: '…' },
        'delete':  { confirm: '<?php echo __('confirm_remove_item'); ?>', busy: '…' }
    };

    $('.sl-page').on('click', '[data-sl-action]', function () {
        var $btn = $(this);
        var id = $btn.data('sl-id');
        var action = String($btn.data('sl-action'));
        var cfg = LABELS[action] || {};

        if (cfg.confirm && !confirm(cfg.confirm)) return;

        var post;
        if (action === 'delete') {
            post = { action: 'delete', id: id };
        } else {
            post = { action: 'update', id: id, status: action };
        }

        var $row = $('#sl-item-' + id);
        $row.find('.sl-btn').prop('disabled', true);
        var orig = $btn.html();
        $btn.html('<i class="fas fa-spinner fa-spin"></i>');

        $.post('api/procurement_request.php', post)
            .done(function (res) {
                if (res && res.success) {
                    if (action === 'delete') {
                        $row.slideUp(180, function () { $(this).remove(); });
                    } else {
                        location.reload();
                    }
                } else {
                    alert('<?php echo __('error_label'); ?>: ' + ((res && res.message) ? res.message : '<?php echo __('unknown_error'); ?>'));
                    $row.find('.sl-btn').prop('disabled', false);
                    $btn.html(orig);
                }
            })
            .fail(function (xhr) {
                var msg = '<?php echo __('request_failed'); ?>';
                try { var j = JSON.parse(xhr.responseText); if (j && j.message) msg = j.message; } catch (e) {}
                alert('<?php echo __('error_label'); ?>: ' + msg);
                $row.find('.sl-btn').prop('disabled', false);
                $btn.html(orig);
            });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
