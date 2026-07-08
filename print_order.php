<?php
/* Zakázkový list (A4). VZHLED přepracován 8.7.2026 — pole a texty (vč. podmínek
   malým písmem) zůstávají BEZE ZMĚNY. Lze vložit i pro e-mail: includer nastaví
   ORDER_DOC_EMBED (má připravené $order, $items, $target_lang) a $__EMAIL_MODE. */
if (!defined('ORDER_DOC_EMBED')) {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';

    if (!isset($_SESSION['user_id'])) die(__("unauthorized"));
    if (!isset($_GET['id']) && !isset($_GET['order_id'])) die("Order ID is not specified");

    $id = $_GET['id'] ?? $_GET['order_id'];
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.email
                           FROM orders o
                           JOIN customers c ON o.customer_id = c.id
                           WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) die(__("print_not_found"));

    $stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    $target_lang = $_GET['lang'] ?? 'cs';
}
if (!function_exists('_l')) {
    function _l($key) { global $target_lang; return __($key, $target_lang); }
}
$__EMAIL_MODE = $__EMAIL_MODE ?? false;

// firemní logo (pokud existuje) — jen vzhled
$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';

// součet (stejný výpočet jako dřív)
$__total = (float)($order['final_cost'] ?: $order['estimated_cost']);
foreach ($items as $__it) { $__total += ($__it['price'] * $__it['quantity']); }
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title>Order confirmation <?php echo e(orderDisplayCode($order)); ?></title>
    <style>
        :root { --accent:#0A84FF; --ink:#1d1d1f; --muted:#6b7280; --line:#e5e7eb; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; font-size: 13.5px; line-height: 1.55;
               color: var(--ink); margin: 0; padding: 24px; background: #f4f5f7; }
        .sheet { max-width: 820px; margin: auto; background: #fff; border-radius: 16px; overflow: hidden;
                 box-shadow: 0 10px 40px rgba(0,0,0,0.08); }
        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;
                    padding: 26px 32px; background: linear-gradient(135deg,#0b1220,#131a2b); color: #fff; }
        .doc-head .brand { display: flex; align-items: center; gap: 14px; }
        .doc-head .brand img { height: 40px; width: auto; filter: brightness(0) invert(1); }
        .doc-head .company-name { font-size: 20px; font-weight: 800; letter-spacing: -0.01em; margin: 0; }
        .doc-head .company-meta { font-size: 12px; color: rgba(255,255,255,0.72); margin-top: 2px; }
        .doc-head .doc-meta { text-align: right; }
        .doc-head .doc-kicker { font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: rgba(255,255,255,0.6); }
        .doc-head .order-code { font-size: 26px; font-weight: 800; margin: 2px 0; }
        .doc-head .doc-date { font-size: 12px; color: rgba(255,255,255,0.75); }
        .body { padding: 26px 32px 30px; }
        .grid2 { display: flex; gap: 18px; margin-bottom: 18px; }
        .panel { flex: 1; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; }
        .panel h3 { margin: 0 0 8px; font-size: 10.5px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--muted); }
        .panel .big { font-size: 15px; font-weight: 700; }
        .panel .row { margin-top: 3px; }
        .panel .k { color: var(--muted); }
        .block { margin: 16px 0; }
        .block .lbl { font-size: 10.5px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--muted); margin-bottom: 4px; }
        .chip { display: inline-block; background: #f1f5ff; color: var(--accent); border: 1px solid #d5e4ff;
                border-radius: 8px; padding: 3px 10px; font-weight: 700; font-family: ui-monospace, Menlo, monospace; }
        table.items { width: 100%; border-collapse: collapse; margin: 18px 0 6px; }
        table.items th { background: #f7f8fa; text-align: left; font-size: 11px; letter-spacing: 0.04em;
                         text-transform: uppercase; color: var(--muted); padding: 9px 12px; border-bottom: 1px solid var(--line); }
        table.items td { padding: 10px 12px; border-bottom: 1px solid var(--line); }
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        .total { text-align: right; font-size: 19px; font-weight: 800; margin-top: 10px; }
        .total small { color: var(--muted); font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; font-weight: 700; }
        .signatures { display: flex; justify-content: space-between; gap: 40px; margin-top: 44px; }
        .signatures .sig { flex: 1; }
        .sig-line { border-top: 1.5px solid var(--ink); padding-top: 6px; text-align: center; font-size: 12px; color: var(--muted); }
        .footer { margin-top: 34px; border-top: 1px solid var(--line); padding-top: 12px; font-size: 11px; color: var(--muted); }
        .toolbar { text-align: center; margin-bottom: 18px; }
        .toolbar button { padding: 11px 26px; cursor: pointer; background: var(--accent); color: #fff; border: none;
                          border-radius: 10px; font-size: 14px; font-weight: 600; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; border-radius: 0; max-width: none; }
            .no-print { display: none !important; }
            .doc-head { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<?php if (!$__EMAIL_MODE): ?>
<div class="no-print toolbar">
    <button onclick="window.print()"><?php echo _l('print'); ?></button>
</div>
<?php endif; ?>

<div class="sheet">
    <div class="doc-head">
        <div class="brand">
            <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt=""><?php endif; ?>
            <div>
                <h1 class="company-name"><?php echo htmlspecialchars(get_setting('company_name', 'Repair CRM')); ?></h1>
                <div class="company-meta">
                    <?php echo nl2br(htmlspecialchars(get_setting('company_address'))); ?><br>
                    <?php echo _l('phone'); ?>: <?php echo htmlspecialchars(get_setting('company_phone')); ?>
                </div>
            </div>
        </div>
        <div class="doc-meta">
            <div class="doc-kicker"><?php echo mb_strtoupper(_l('order')); ?></div>
            <div class="order-code"><?php echo e(orderDisplayCode($order)); ?></div>
            <div class="doc-date"><?php echo _l('created'); ?>: <?php echo date('d.m.Y', strtotime($order['created_at'])); ?></div>
        </div>
    </div>

    <div class="body">
        <div class="grid2">
            <div class="panel">
                <h3><?php echo mb_strtoupper(_l('client')); ?></h3>
                <div class="big"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></div>
                <div class="row"><span class="k"><?php echo _l('phone'); ?>:</span> <?php echo htmlspecialchars($order['phone']); ?></div>
                <div class="row"><?php echo htmlspecialchars($order['address']); ?></div>
            </div>
            <div class="panel">
                <h3><?php echo mb_strtoupper(_l('device_model')); ?></h3>
                <div class="big"><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></div>
                <div class="row"><?php echo htmlspecialchars($order['device_type']); ?> | S/N: <?php echo htmlspecialchars($order['serial_number']); ?></div>
                <?php if($order['serial_number_2']): ?><div class="row"><?php echo _l('serial_2'); ?>: <?php echo htmlspecialchars($order['serial_number_2']); ?></div><?php endif; ?>
            </div>
        </div>

        <div class="block">
            <div class="lbl"><?php echo _l('problem'); ?></div>
            <?php echo nl2br(htmlspecialchars($order['problem_description'])); ?>
        </div>

        <?php if($order['appearance']): ?>
        <div class="block">
            <div class="lbl"><?php echo _l('appearance'); ?></div>
            <?php echo htmlspecialchars($order['appearance']); ?>
        </div>
        <?php endif; ?>

        <?php if($order['pin_code']): ?>
        <div class="block">
            <div class="lbl"><?php echo _l('pin'); ?></div>
            <span class="chip"><?php echo htmlspecialchars($order['pin_code']); ?></span>
        </div>
        <?php endif; ?>

        <table class="items">
            <thead>
                <tr>
                    <th><?php echo _l('part_name'); ?></th>
                    <th class="num"><?php echo _l('quantity'); ?></th>
                    <th class="num"><?php echo _l('buy_price'); ?></th>
                    <th class="num"><?php echo _l('sum'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo _l('work_cost'); ?></td>
                    <td class="num">1</td>
                    <td class="num"><?php echo formatMoney((float)($order['final_cost'] ?: $order['estimated_cost'])); ?></td>
                    <td class="num"><?php echo formatMoney((float)($order['final_cost'] ?: $order['estimated_cost'])); ?></td>
                </tr>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['part_name']); ?></td>
                    <td class="num"><?php echo $item['quantity']; ?></td>
                    <td class="num"><?php echo formatMoney($item['price']); ?></td>
                    <td class="num"><?php echo formatMoney($item['price'] * $item['quantity']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total">
            <small><?php echo mb_strtoupper(_l('total_pay')); ?>:</small>
            <?php echo formatMoney($__total); ?>
        </div>

        <div class="signatures">
            <div class="sig"><div class="sig-line">Customer signature</div></div>
            <div class="sig"><div class="sig-line">Received by (stamp)</div></div>
        </div>

        <div class="footer">
            <p>Warranty for performed work is 30 days. Warranty does not apply to damage caused by improper device use.</p>
        </div>
    </div>
</div>

</body>
</html>
