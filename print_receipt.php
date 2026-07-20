<?php
/**
 * Prodejní doklad (účtenka) z kasy — samostatná tisková stránka.
 * print_receipt.php?id=<pos_sales.id>[&auto=1 → rovnou otevře tiskový dialog]
 * DPH: běžné zboží (díly) u plátce s rekapitulací; použité zboží jede ve zvláštním
 * režimu § 90 — DPH se u něj NEVYČÍSLUJE a doklad nese povinnou větu.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!crmCanUsePos()) { die(__('unauthorized')); }

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { die(__('missing_id')); }

ensurePosTables();

$st = $pdo->prepare("SELECT s.*, c.first_name, c.last_name, c.company, c.preferred_language, i.invoice_number
    FROM pos_sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    LEFT JOIN invoices i ON s.invoice_id = i.id
    WHERE s.id = ?");
$st->execute([$id]);
$sale = $st->fetch();
if (!$sale) { die(__('print_not_found')); }

// technici vedlejší pobočky vidí jen doklady své pobočky (stejná hranice jako Historie)
if (!crmCanViewHistory() && (int)($sale['branch_id'] ?? 0) !== (int)getCurrentStaffBranchId()) {
    die(__('rcpt_wrong_branch'));
}

// jazyk dokladu: dle zákazníka prodeje; walk-in bez zákazníka → česky (vzor print_order.php)
$__pref = (string)($sale['preferred_language'] ?? '');
$target_lang = $_GET['lang'] ?? ($__pref !== '' ? crmCustomerDocLang($__pref) : 'cs');
if (!function_exists('_l')) {
    function _l($key) { global $target_lang; return __($key, $target_lang); }
}

$it = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ? ORDER BY id");
$it->execute([$id]);
$items = $it->fetchAll();

/* ---- firma / pobočka ---- */
$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';
$co_name  = get_setting('company_name', 'AppleFix s.r.o.');
$co_ico   = trim((string)get_setting('company_ico', ''));
$co_dic   = trim((string)get_setting('company_dic', ''));
$co_web   = trim((string)get_setting('company_web', '')) ?: 'www.applefix.cz';
$__bc     = crmOrderBranchContact((int)($sale['branch_id'] ?? 0));
$co_addr  = trim(preg_replace('/\s*[\r\n]+\s*/u', ', ', (string)$__bc['address']));
$co_phone = (string)$__bc['phone'];
$co_email = (string)$__bc['email'];

/* ---- DPH — ze SNAPSHOTU v dokladu, ne z aktuálního nastavení (dotisk
   historického dokladu se po změně sazby/plátcovství nesmí tiše přepsat) ---- */
$isVat = (int)($sale['is_vat_payer'] ?? 0) === 1;
$vatRate = (float)($sale['vat_rate'] ?? 0);
$stdTotal = 0.0; $usedTotal = 0.0;
foreach ($items as $l) {
    $line = (float)$l['unit_price'] * (int)$l['quantity'];
    if ((int)$l['is_used_goods'] === 1) { $usedTotal += $line; } else { $stdTotal += $line; }
}
// rekapitulace v celých Kč tak, aby základ + DPH VŽDY dalo přesně řádek s celkem
$stdKc  = (int)round($stdTotal);
$baseKc = ($isVat && $vatRate > 0) ? (int)round($stdTotal * 100 / (100 + $vatRate)) : $stdKc;
$vatKc  = $stdKc - $baseKc;
$hasUsed = $usedTotal > 0 && $isVat;   // §90 má smysl jen u plátce DPH

$payLabel = ['cash' => _l('rcpt_pay_cash'), 'card' => _l('rcpt_pay_card'), 'invoice' => _l('rcpt_pay_invoice')][(string)$sale['payment_method']] ?? (string)$sale['payment_method'];
$custName = trim((string)($sale['company'] ?? '')) ?: trim((string)($sale['first_name'] ?? '') . ' ' . (string)($sale['last_name'] ?? ''));
$cancelled = (string)$sale['status'] === 'cancelled';
?>
<!DOCTYPE html>
<html lang="<?php echo e($target_lang); ?>">
<head>
<meta charset="utf-8">
<title><?php echo _l('rcpt_doc_title'); ?> <?php echo e($sale['sale_number']); ?></title>
<link rel="stylesheet" href="assets/css/sf-pro.css">
<style>
:root { --ink:#111318; --sub:#4d5560; --muted:#949aa4; --line:#e8ebf0; --accent:#0a84ff; --soft:#f6f8fb; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'SF Pro Display',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:13px; color:var(--ink); background:#eceff3; padding:26px 14px; }
.sheet { max-width:620px; margin:auto; background:#fff; border-radius:18px; overflow:hidden; box-shadow:0 24px 64px rgba(17,20,24,.12); }
.accent-bar { height:5px; background:linear-gradient(90deg,#0a84ff,#5ac8fa 55%,#64d2ff); }
.pad { padding:26px 30px 22px; }
.head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:18px; }
.head img { height:34px; }
.doc { text-align:right; }
.doc .t { font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:var(--muted); font-weight:600; }
.doc .n { font-size:20px; font-weight:700; letter-spacing:-.01em; }
.doc .d { font-size:12px; color:var(--sub); margin-top:2px; }
.meta { display:flex; flex-wrap:wrap; gap:6px 22px; background:var(--soft); border-radius:12px; padding:10px 14px; margin-bottom:16px; font-size:12px; }
.meta b { font-weight:600; }
.meta span { color:var(--sub); }
table.items { width:100%; border-collapse:collapse; margin-bottom:6px; }
table.items th { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); text-align:left; padding:6px 8px; border-bottom:1.5px solid var(--line); }
table.items td { padding:8px; border-bottom:1px solid var(--line); vertical-align:top; }
table.items .num { text-align:right; white-space:nowrap; }
.code { color:var(--muted); font-size:11px; }
.tag90 { display:inline-block; font-size:10px; font-weight:600; color:#8a6d00; background:#fff5d6; border-radius:6px; padding:1px 6px; margin-left:6px; vertical-align:1px; }
.sum { display:flex; justify-content:flex-end; margin-top:10px; }
.sum table td { padding:3px 8px; font-size:13px; }
.sum .total td { font-size:18px; font-weight:700; border-top:2px solid var(--ink); padding-top:8px; }
.legal { margin-top:16px; font-size:11px; color:var(--sub); line-height:1.5; }
.cancelstamp { margin:14px 0; padding:10px 14px; border:2px solid #d64545; color:#d64545; border-radius:10px; font-weight:700; text-align:center; letter-spacing:.06em; }
.foot { margin-top:20px; padding-top:12px; border-top:1px solid var(--line); font-size:10.5px; color:var(--muted); text-align:center; line-height:1.6; }
.doclink { color:var(--accent); text-decoration:none; }
.toolbar { max-width:620px; margin:0 auto 14px; display:flex; justify-content:flex-end; gap:8px; }
.toolbar button { font:inherit; font-weight:600; border:0; border-radius:10px; padding:9px 18px; background:var(--accent); color:#fff; cursor:pointer; }
@page { size:A4 portrait; margin:0; }
@media print {
  body { background:#fff; padding:0; }
  .sheet { box-shadow:none; border-radius:0; max-width:none; }
  .no-print { display:none !important; }
  .accent-bar, .meta, .tag90, .cancelstamp { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>
</head>
<body>
<div class="toolbar no-print">
    <button onclick="window.print()">🖨 <?php echo _l('print'); ?></button>
</div>
<div class="sheet">
    <div class="accent-bar"></div>
    <div class="pad">
        <div class="head">
            <div>
                <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="<?php echo e($co_name); ?>"><?php endif; ?>
            </div>
            <div class="doc">
                <div class="t"><?php echo _l('rcpt_doc_title'); ?></div>
                <div class="n"><?php echo e($sale['sale_number']); ?></div>
                <div class="d"><?php echo date('d.m.Y H:i', strtotime((string)$sale['created_at'])); ?></div>
            </div>
        </div>

        <?php if ($cancelled): ?>
            <div class="cancelstamp"><?php echo _l('rcpt_cancelled'); ?> <?php echo $sale['cancelled_at'] ? date('d.m.Y H:i', strtotime((string)$sale['cancelled_at'])) : ''; ?></div>
        <?php endif; ?>

        <div class="meta">
            <div><span><?php echo _l('rcpt_seller'); ?>:</span> <b><?php echo e($sale['seller_name'] ?: '—'); ?></b></div>
            <div><span><?php echo _l('payment_method'); ?>:</span> <b><?php echo e($payLabel); ?></b></div>
            <?php if ($custName !== ''): ?><div><span><?php echo _l('customer_col'); ?>:</span> <b><?php echo e($custName); ?></b></div><?php endif; ?>
            <?php if (!empty($sale['invoice_number'])): ?><div><span><?php echo _l('invoice'); ?>:</span> <b><?php echo e($sale['invoice_number']); ?></b></div><?php endif; ?>
        </div>

        <table class="items">
            <thead>
                <tr><th><?php echo _l('item_name'); ?></th><th class="num"><?php echo _l('rcpt_qty'); ?></th><th class="num"><?php echo _l('price_per_unit'); ?></th><th class="num"><?php echo _l('table_total'); ?></th></tr>
            </thead>
            <tbody>
            <?php foreach ($items as $l): ?>
                <tr>
                    <td>
                        <?php echo e($l['item_name']); ?><?php if ($isVat && (int)$l['is_used_goods'] === 1): ?><span class="tag90">§ 90</span><?php endif; ?>
                        <?php if (!empty($l['item_code'])): ?><div class="code"><?php echo e($l['item_code']); ?></div><?php endif; ?>
                    </td>
                    <td class="num"><?php echo (int)$l['quantity']; ?></td>
                    <td class="num"><?php echo formatMoney((float)$l['unit_price']); ?></td>
                    <td class="num"><?php echo formatMoney((float)$l['unit_price'] * (int)$l['quantity']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="sum">
            <table>
                <?php if ($isVat && $stdTotal > 0): ?>
                <tr><td><?php echo _l('rcpt_vat_base'); ?> <?php echo rtrim(rtrim(number_format($vatRate, 1, ',', ' '), '0'), ','); ?> %</td><td class="num" style="text-align:right;"><?php echo formatMoney($baseKc); ?></td></tr>
                <tr><td><?php echo _l('vat_short'); ?></td><td style="text-align:right;"><?php echo formatMoney($vatKc); ?></td></tr>
                <?php if ($hasUsed): ?><tr><td><?php echo _l('rcpt_used_goods'); ?> (§ 90)</td><td style="text-align:right;"><?php echo formatMoney($usedTotal); ?></td></tr><?php endif; ?>
                <?php endif; ?>
                <tr class="total"><td><?php echo _l('table_total'); ?></td><td style="text-align:right;"><?php echo formatMoney((float)$sale['total']); ?></td></tr>
            </table>
        </div>

        <div class="legal">
            <?php if ($hasUsed): ?>
                Zvláštní režim – použité zboží dle § 90 zákona č. 235/2004 Sb., o DPH. U položek označených „§ 90" se DPH na dokladu nevyčísluje.<br>
            <?php endif; ?>
            <?php if (!$isVat): ?><?php echo _l('rcpt_not_vat_payer'); ?><br><?php endif; ?>
            <?php echo _l('rcpt_check_notice'); ?>
        </div>

        <div class="foot">
            <?php echo e($co_name); ?><?php if ($co_addr !== ''): ?> · <?php echo e($co_addr); ?><?php endif; ?>
            <?php if ($co_ico !== ''): ?> · IČO: <?php echo e($co_ico); ?><?php endif; ?><?php if ($co_dic !== ''): ?> · DIČ: <?php echo e($co_dic); ?><?php endif; ?><br>
            <?php if ($co_phone !== ''): ?>Tel.: <?php echo e($co_phone); ?> · <?php endif; ?><?php echo e($co_web); ?> · <?php echo e($co_email); ?>
        </div>
    </div>
</div>
<?php if (!empty($_GET['auto'])): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });</script>
<?php endif; ?>
</body>
</html>
