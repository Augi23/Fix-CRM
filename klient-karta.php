<?php
/**
 * RECEPCE — dohledání klienta podle karty (QR z Apple/Google Peněženky).
 * Firemní iPhone / čtečka na recepci naskenuje QR na kartě → tato stránka
 * okamžitě ukáže klienta, jeho zakázky, body a tlačítko „Nová zakázka".
 * URL: klient-karta.php?t=<card_token>
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

$token = trim((string)($_GET['t'] ?? ''));
$custId = crmCustomerIdByCardToken($token);
$card = $custId > 0 ? crmClientCardData($custId) : null;

// Režim recepce: úspěšný sken (typicky z firemního iPhonu) pošle klienta i na
// počítač recepce, který má zapnutý poslech (api/reception_poll.php).
// &nopush=1 = otevřeno právě poslechem — nezapisovat znovu (smyčka).
if ($card && !isset($_GET['nopush'])) {
    try {
        $__rb = getCurrentStaffBranchId() ?: getDefaultBranchId();
        $__rkey = 'reception_scan_b' . (int)$__rb;
        // Dedup: obnovení téže stránky (pull-to-refresh, Zpět/Vpřed v Safari) do
        // 2 minut NEposílá znovu — jinak by Mac recepce skákal zpět na klienta,
        // i když nikdo nic neskenoval. Nový sken po 2 minutách projde normálně.
        $__prev = json_decode((string)get_setting($__rkey, ''), true);
        $__dup = is_array($__prev)
            && (string)($__prev['t'] ?? '') === (string)$card['token']
            && (time() - (int)($__prev['ts'] ?? 0)) < 120;
        if (!$__dup) {
            set_setting($__rkey, json_encode([
                'n' => bin2hex(random_bytes(6)),
                't' => $card['token'],
                'name' => $card['name'],
                'ts' => time(),
            ], JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) { /* best-effort */ }
}

$orders = [];
if ($card) {
    try {
        $st = $pdo->prepare("SELECT o.id, o.order_code, o.status, o.device_brand, o.device_model, o.final_cost, o.estimated_cost, o.created_at, t.name AS tech_name
            FROM orders o LEFT JOIN technicians t ON t.id = o.technician_id
            WHERE o.customer_id = ? ORDER BY o.id DESC LIMIT 30");
        $st->execute([$custId]);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $orders = []; }
}
?>
<div class="container-fluid" style="max-width: 900px;">
<?php if (!$card): ?>
    <div class="glass-panel p-5 border-secondary text-center">
        <i class="fas fa-id-card fa-3x mb-3 text-white-50"></i>
        <h5 class="text-white">Karta nenalezena</h5>
        <p class="text-white-75 mb-3">Kód karty <code><?php echo e($token ?: '—'); ?></code> neodpovídá žádnému klientovi. Zkus sken znovu, nebo klienta najdi ručně.</p>
        <a href="customers.php" class="btn btn-outline-secondary"><i class="fas fa-users me-1"></i> Klienti</a>
    </div>
<?php else: ?>
    <div class="glass-panel p-4 border-secondary mb-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge" style="background:rgba(10,132,255,.18);color:#64D2FF;border:1px solid rgba(10,132,255,.4);"><i class="fas fa-id-card me-1"></i>Klientská karta</span>
                    <span class="text-white-50 small"><?php echo e($card['token']); ?></span>
                </div>
                <h3 class="text-white mb-1"><?php echo e($card['name']); ?></h3>
                <div class="text-white-75">
                    <?php if ($card['phone']): ?><a href="tel:<?php echo e(normalizePhoneForTel($card['phone'])); ?>" class="text-decoration-none text-white-75"><i class="fas fa-phone me-1 text-success"></i><?php echo e($card['phone']); ?></a><?php endif; ?>
                    <?php if ($card['email']): ?><span class="ms-3"><i class="fas fa-envelope me-1 text-info"></i><?php echo e($card['email']); ?></span><?php endif; ?>
                </div>
            </div>
            <div class="text-end">
                <div class="d-flex gap-3">
                    <div class="text-center px-3 py-2 rounded-3" style="background:rgba(191,90,242,.12);border:1px solid rgba(191,90,242,.35);">
                        <div class="h3 mb-0 text-white"><?php echo (int)$card['points']; ?></div>
                        <div class="small text-white-75">věrnostních bodů</div>
                    </div>
                    <div class="text-center px-3 py-2 rounded-3" style="background:rgba(52,199,89,.10);border:1px solid rgba(52,199,89,.30);">
                        <div class="h3 mb-0 text-white"><?php echo (int)$card['devices_total']; ?></div>
                        <div class="small text-white-75">zařízení u nás<?php echo $card['devices_active'] > 0 ? ' (' . (int)$card['devices_active'] . ' aktivních)' : ''; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <a class="btn btn-primary" href="orders.php#newOrderModal" onclick="try{sessionStorage.setItem('afx_prefill_customer','<?php echo (int)$card['customer_id']; ?>');sessionStorage.setItem('afx_prefill_customer_label','<?php echo e(addslashes($card['name'] . ($card['phone'] ? ' (' . $card['phone'] . ')' : ''))); ?>')}catch(e){}"><i class="fas fa-plus me-1"></i> Nová zakázka pro klienta</a>
            <a class="btn btn-outline-secondary" href="customers.php?search=<?php echo urlencode($card['phone'] ?: $card['name']); ?>"><i class="fas fa-user me-1"></i> Karta klienta</a>
        </div>
    </div>

    <div class="glass-panel p-0 border-secondary">
        <div class="p-3 border-bottom border-secondary"><span class="fw-semibold text-white"><i class="fas fa-tools me-2 text-primary"></i>Zakázky klienta (<?php echo count($orders); ?>)</span></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <tbody>
                <?php if (!$orders): ?>
                    <tr><td class="text-center text-white-75 py-4">Klient zatím nemá žádnou zakázku. <a href="orders.php#newOrderModal">Založit první →</a></td></tr>
                <?php else: foreach ($orders as $o): ?>
                    <tr class="order-row order-row--status-<?php echo e(getOrderStatusBadgeToken($o['status'])); ?>" style="cursor:pointer;" onclick="window.location.href='view_order.php?id=<?php echo (int)$o['id']; ?>'">
                        <td class="ps-3"><a href="view_order.php?id=<?php echo (int)$o['id']; ?>" class="fw-bold text-decoration-none"><?php echo e($o['order_code'] ?: ('#' . $o['id'])); ?></a>
                            <div class="small text-white-75"><?php echo date('d.m.Y', strtotime($o['created_at'])); ?></div></td>
                        <td><?php echo e(trim(($o['device_brand'] ?? '') . ' ' . ($o['device_model'] ?? ''))); ?></td>
                        <td><?php echo getStatusBadge($o['status']); ?></td>
                        <td class="text-end pe-3 fw-bold text-white"><?php echo formatMoney($o['final_cost'] ?: $o['estimated_cost']); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
</div>
<?php require_once 'includes/footer.php'; ?>
