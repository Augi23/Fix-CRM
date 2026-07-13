<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id'])) die(__("unauthorized"));
if (!isset($_GET['id'])) die("<?php echo __('missing_id'); ?>");

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT i.*, c.first_name, c.last_name, c.phone, c.address, c.company, c.ico, c.dic
                       FROM invoices i 
                       JOIN customers c ON i.customer_id = c.id 
                       WHERE i.id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();

if (!$invoice) die("<?php echo __('print_not_found'); ?>");

// Fetch invoice items
$stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll();

// Payment method translations
$payment_methods = [
    'bank_transfer' => 'Bankovním převodem',
    'cash' => 'Hotově',
    'card' => 'Kartou',
    'cod' => 'Na dobírku'
];
$payment_method = $payment_methods[$invoice['payment_method']] ?? $invoice['payment_method'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <title><?php echo ($invoice['invoice_type'] == 'credit_note') ? 'Dobropis' : 'Doklad'; ?> <?php echo e($invoice['invoice_number']); ?></title>
    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>">
    <style>
        /* Jednotný vizuál klientských dokumentů (dle zakázkového listu): SF Pro,
           tučné hodnoty × light popisky, adresa/IČO v patičce dole. */
        body { font-family: 'SF Pro Display', -apple-system, Arial, sans-serif; font-size: 13px;
               line-height: 1.3; margin: 0; padding: 0; background: #fff; color: #000; }
        .receipt { width: 72mm; padding: 2mm; margin: 0 auto; background: white; }
        .text-center { text-align: center; }
        .rule { border-bottom: 1px solid #000; margin: 8px 0; }
        .kick { font-size: 9px; letter-spacing: 0.22em; text-transform: uppercase; font-weight: 700; margin-top: 6px; }
        .doc-num { font-size: 21px; font-weight: 800; letter-spacing: -0.01em; margin: 2px 0 1px; }
        .date { font-size: 11px; font-weight: 300; }
        .label { font-size: 9.5px; font-weight: 400; letter-spacing: 0.14em; text-transform: uppercase; margin: 7px 0 2px; }
        .kv { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 3px; }
        .kv .k { font-weight: 300; }
        .kv .v { font-weight: 700; text-align: right; }
        .item { margin-bottom: 6px; }
        .item-name { font-weight: 700; display: block; }
        .item-details { display: flex; justify-content: space-between; font-size: 12px; }
        .item-details .qty { font-weight: 300; }
        .item-price { text-align: right; font-weight: 700; }
        .total-row { text-align: right; margin: 4px 0 2px; }
        .total-row .k { font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; font-weight: 400; }
        .total-row .v { font-size: 21px; font-weight: 800; }
        .barcode { text-align: center; margin: 10px 0 4px; font-size: 19px; letter-spacing: 3px; font-weight: 700; font-family: ui-monospace, Menlo, monospace; }
        .foot { margin-top: 12px; border-top: 1px solid #000; padding-top: 7px; text-align: center; }
        .foot .thanks { font-size: 11px; font-weight: 700; margin-bottom: 5px; }
        .foot .foot-name { font-size: 12px; font-weight: 800; }
        .foot .foot-line { font-size: 9.5px; font-weight: 300; margin-top: 2px; line-height: 1.45; }
        @media print {
            body { background: none; }
            .receipt { width: 72mm; margin: 0; padding: 0; }
            .no-print { display: none; }
            @page { margin: 0; size: 80mm auto; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center; margin: 20px;">
    <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #28a745; color: white; border: none; border-radius: 4px;">Tisk</button>
    <a href="accounting.php" style="padding: 10px 20px; text-decoration: none; background: #6c757d; color: white; border-radius: 4px; margin-left: 10px;">Zpět</a>
</div>

<?php
$__bid = 0;
try { $__bid = (int)$pdo->query("SELECT branch_id FROM orders WHERE id = " . (int)($invoice['order_id'] ?? 0))->fetchColumn(); } catch (Throwable $e) {}
$__bc = crmOrderBranchContact($__bid);   // kontakty dle pobočky zakázky faktury
$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';
$__acc_name = trim((string)get_setting('acc_company_name')) ?: (string)get_setting('company_name', 'AppleFix s.r.o.');
$__acc_ico  = trim((string)get_setting('acc_ico')) ?: trim((string)get_setting('company_ico', ''));
$__acc_dic  = trim((string)get_setting('acc_dic')) ?: trim((string)get_setting('company_dic', ''));
$__curr = $invoice['currency'] ?: 'Kč';
?>
<div class="receipt">
    <div class="text-center">
        <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="AppleFix" style="width: 46mm; height: auto; margin-top: 2mm;"><?php endif; ?>
        <div class="kick"><?php echo ($invoice['invoice_type'] == 'credit_note') ? 'Dobropis' : 'Doklad o platbě'; ?></div>
        <div class="doc-num"><?php echo e($invoice['invoice_number']); ?></div>
        <div class="date"><?php echo date('d.m.Y H:i', strtotime($invoice['created_at'])); ?></div>
        <div class="rule"></div>
    </div>

    <div class="kv"><span class="k">Variabilní symbol</span><span class="v"><?php echo e($invoice['variable_symbol']); ?></span></div>
    <div class="kv"><span class="k">Způsob platby</span><span class="v"><?php echo e($payment_method); ?></span></div>

    <div class="rule"></div>

    <div class="label">Odběratel</div>
    <div style="font-weight: 700;"><?php echo htmlspecialchars($invoice['company'] ?: $invoice['first_name'] . ' ' . $invoice['last_name']); ?></div>
    <?php if($invoice['ico']): ?><div class="kv"><span class="k">IČO</span><span class="v"><?php echo e($invoice['ico']); ?></span></div><?php endif; ?>
    <?php if($invoice['dic']): ?><div class="kv"><span class="k">DIČ</span><span class="v"><?php echo e($invoice['dic']); ?></span></div><?php endif; ?>

    <div class="rule"></div>

    <div class="label">Položky</div>
    <?php foreach($items as $item): ?>
    <div class="item">
        <span class="item-name"><?php echo htmlspecialchars($item['item_name']); ?></span>
        <div class="item-details">
            <span class="qty"><?php echo $item['quantity']; ?> <?php echo e($item['unit']); ?> × <?php echo number_format($item['price'], 2, ',', ' '); ?></span>
            <span class="item-price"><?php echo number_format($item['quantity'] * $item['price'], 2, ',', ' '); ?> <?php echo e($__curr); ?></span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($items)): ?>
    <div class="item">
        <span class="item-name"><?php echo htmlspecialchars($invoice['notes'] ?: 'Servisní služby'); ?></span>
        <div class="item-details">
            <span class="qty">1 ks</span>
            <span class="item-price"><?php echo number_format($invoice['total_amount'], 2, ',', ' '); ?> <?php echo e($__curr); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <div class="rule"></div>

    <div class="total-row">
        <div class="k">Celkem</div>
        <div class="v"><?php echo number_format($invoice['total_amount'], 2, ',', ' '); ?> <?php echo e($__curr); ?></div>
    </div>

    <div class="barcode">*<?php echo e($invoice['variable_symbol']); ?>*</div>

    <div class="foot">
        <div class="thanks">Děkujeme za vaši důvěru!</div>
        <div class="foot-name"><?php echo htmlspecialchars($__acc_name); ?></div>
        <div class="foot-line">
            <?php echo htmlspecialchars($__bc['address_inline']); ?><br>
            <?php if ($__acc_ico): ?>IČO: <?php echo htmlspecialchars($__acc_ico); ?><?php endif; ?><?php if ($__acc_dic): ?> · DIČ: <?php echo htmlspecialchars($__acc_dic); ?><?php endif; ?><br>
            Tel.: <?php echo htmlspecialchars($__bc['phone']); ?> · applefix.cz
        </div>
    </div>
</div>

</body>
</html>
