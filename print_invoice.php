<?php
/* Faktura (A4). Lze vložit i z klientského portálu: includer nastaví
   INVOICE_DOC_EMBED a připraví $invoice (s joinem zákazníka/zakázky), $items,
   $is_vat_payer — tím se přeskočí staff auth i načtení dle ?id. */
if (!defined('INVOICE_DOC_EMBED')) {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';

    if (!isset($_SESSION['user_id'])) die(__("unauthorized"));
    if (!isset($_GET['id'])) die(__('missing_id'));

    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT i.*, c.first_name, c.last_name, c.phone, c.address, c.company, c.ico, c.dic, c.preferred_language, o.device_brand, o.device_model, o.serial_number
                           FROM invoices i
                           JOIN customers c ON i.customer_id = c.id
                           LEFT JOIN orders o ON i.order_id = o.id
                           WHERE i.id = ?");
    $stmt->execute([$id]);
    $invoice = $stmt->fetch();

    if (!$invoice) die(__('print_not_found'));

    // Fetch invoice items
    $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    $is_vat_payer = $invoice['is_vat_payer'];

    // bez ?lang → doklad v jazyce KLIENTA (customers.preferred_language); uk → en
    $target_lang = $_GET['lang'] ?? crmCustomerDocLang($invoice['preferred_language'] ?? 'cs');
}
if (!function_exists('_l')) {
    function _l($key) { global $target_lang; return __($key, $target_lang); }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($target_lang); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo _l('print_title_invoice'); ?> <?php echo $invoice['invoice_number']; ?></title>
    <style>
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #000; margin: 0; padding: 0; background-color: #f5f5f5; }
        .page { width: 210mm; min-height: 297mm; padding: 15mm; margin: 10mm auto; background: white; border: 1px solid #ddd; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); position: relative; }
        
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header-table td { vertical-align: bottom; padding-bottom: 5px; }
        .doc-title { font-size: 22px; font-weight: bold; }
        .doc-number { font-size: 16px; font-weight: bold; }

        .addresses-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #000; }
        .addresses-table td { width: 50%; padding: 10px; vertical-align: top; border: 1px solid #000; }
        .addr-title { font-weight: bold; text-transform: uppercase; font-size: 9px; color: #666; margin-bottom: 8px; border-bottom: 1px solid #eee; }
        .addr-name { font-size: 14px; font-weight: bold; margin-bottom: 5px; }

        .payment-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; border: 1px solid #000; }
        .payment-table td { width: 25%; padding: 8px; border: 1px solid #000; vertical-align: top; }
        .pay-label { font-size: 9px; color: #666; text-transform: uppercase; margin-bottom: 3px; }
        .pay-value { font-weight: bold; font-size: 11px; }

        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .items-table th { background: #f0f0f0; border: 1px solid #000; padding: 6px; text-align: left; font-size: 10px; text-transform: uppercase; }
        .items-table td { border: 1px solid #000; padding: 6px; }
        .text-right { text-align: right; }

        .totals-section { display: flex; justify-content: flex-end; align-items: flex-start; }
        .vat-summary-table { width: 50%; border-collapse: collapse; margin-bottom: 20px; margin-right: 20px; }
        .vat-summary-table th, .vat-summary-table td { border: 1px solid #000; padding: 5px; font-size: 10px; }
        
        .grand-total-box { border: 2px solid #000; padding: 15px; width: 40%; text-align: right; background: #f9f9f9; }
        .grand-label { font-size: 10px; text-transform: uppercase; margin-bottom: 5px; font-weight: bold; }
        .grand-value { font-size: 20px; font-weight: bold; }

        .footer-note { margin-top: 30px; font-style: italic; color: #444; border-top: 1px solid #eee; padding-top: 10px; }
        .signatures { margin-top: 60px; position: relative; height: 100px; }
        .signature-box { position: absolute; right: 0; bottom: 0; width: 200px; text-align: center; border-top: 1px solid #000; padding-top: 5px; font-size: 10px; }

        @media print {
            body { background: none; }
            .no-print { display: none; }
            .page { margin: 0; border: none; padding: 10mm; box-shadow: none; }
        }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center; margin: 20px;">
    <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #007bff; color: white; border: none; border-radius: 4px;"><?php echo _l('print_btn'); ?></button>
    <a href="accounting.php" style="padding: 10px 20px; text-decoration: none; background: #6c757d; color: white; border-radius: 4px; margin-left: 10px;"><?php echo _l('back'); ?></a>
</div>

<div class="page">
    <table class="header-table">
        <tr>
            <td>
                <div class="doc-title"><?php echo ($invoice['invoice_type'] == 'credit_note') ? _l('credit_note') : _l('inv_tax_document_title'); ?></div>
                <div class="doc-number"><?php echo _l('invoice_number'); ?>: <?php echo $invoice['invoice_number']; ?></div>
            </td>
            <td class="text-right">
                <?php if ($invoice['variable_symbol']): ?>
                    <div><strong><?php echo _l('variable_symbol'); ?>:</strong> <?php echo $invoice['variable_symbol']; ?></div>
                <?php endif; ?>
                <div><strong><?php echo _l('inv_constant_symbol'); ?>:</strong> 0308</div>
            </td>
        </tr>
    </table>

    <table class="addresses-table">
        <tr>
            <td>
                <div class="addr-title"><?php echo _l('inv_supplier'); ?></div>
                <div class="addr-name"><?php echo htmlspecialchars(get_setting('acc_company_name')); ?></div>
                <div><?php echo nl2br(htmlspecialchars(get_setting('acc_address'))); ?></div>
                <div style="margin-top: 10px;">
                    <strong>IČO:</strong> <?php echo htmlspecialchars(get_setting('acc_ico')); ?><br>
                    <strong>DIČ:</strong> <?php echo htmlspecialchars(get_setting('acc_dic')); ?>
                </div>
                <div style="margin-top: 10px; font-size: 9px; color: #666;">
                    <?php echo htmlspecialchars(get_setting('acc_trade_register')); ?>
                </div>
            </td>
            <td>
                <div class="addr-title"><?php echo _l('customer'); ?></div>
                <?php 
                $cust_name = $invoice['cust_name_override'] ?: ($invoice['company'] ?: $invoice['first_name'] . ' ' . $invoice['last_name']);
                $cust_address = $invoice['cust_address_override'] ?: $invoice['address'];
                $cust_ico = $invoice['cust_ico_override'] ?: $invoice['ico'];
                $cust_dic = $invoice['cust_dic_override'] ?: $invoice['dic'];
                ?>
                <div class="addr-name"><?php echo htmlspecialchars($cust_name); ?></div>
                <div><?php echo nl2br(htmlspecialchars($cust_address)); ?></div>
                <div style="margin-top: 10px;">
                    <?php if ($cust_ico): ?><strong>IČO:</strong> <?php echo htmlspecialchars($cust_ico); ?><br><?php endif; ?>
                    <?php if ($cust_dic): ?><strong>DIČ:</strong> <?php echo htmlspecialchars($cust_dic); ?><?php endif; ?>
                </div>
                <?php if ($invoice['order_id']): ?>
                    <div style="margin-top: 5px; font-size: 10px; color: #666;">
                        <?php echo _l('inv_for_order'); ?>: #<?php echo $invoice['order_id']; ?> (<?php echo $invoice['device_brand'] . ' ' . $invoice['device_model']; ?>)
                    </div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <table class="payment-table">
        <tr>
            <td>
                <div class="pay-label"><?php echo _l('inv_bank'); ?></div>
                <div class="pay-value"><?php echo htmlspecialchars(get_setting('acc_bank_name')); ?></div>
            </td>
            <td>
                <div class="pay-label"><?php echo _l('account_number'); ?></div>
                <div class="pay-value"><?php echo htmlspecialchars(get_setting('acc_bank_account')); ?></div>
            </td>
            <td>
                <div class="pay-label"><?php echo _l('date_issue'); ?></div>
                <div class="pay-value"><?php echo date('d.m.Y', strtotime($invoice['date_issue'])); ?></div>
            </td>
            <td>
                <div class="pay-label"><?php echo _l('payment_method'); ?></div>
                <div class="pay-value">
                    <?php
                    $p_method = $invoice['payment_method'];
                    if ($p_method == 'cash') echo _l('cash');
                    elseif ($p_method == 'card') echo _l('card');
                    elseif ($p_method == 'cod') echo _l('cod');
                    else echo _l('bank_transfer');
                    ?>
                </div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="pay-label">IBAN</div>
                <div class="pay-value" style="font-size: 9px;"><?php echo htmlspecialchars(get_setting('acc_iban')); ?></div>
            </td>
            <td>
                <div class="pay-label">SWIFT</div>
                <div class="pay-value"><?php echo htmlspecialchars(get_setting('acc_swift')); ?></div>
            </td>
            <td>
                <div class="pay-label"><?php echo _l('date_due'); ?></div>
                <div class="pay-value"><?php echo date('d.m.Y', strtotime($invoice['date_due'])); ?></div>
            </td>
            <td>
                <div class="pay-label"><?php echo _l('date_tax'); ?></div>
                <div class="pay-value"><?php echo date('d.m.Y', strtotime($invoice['date_tax'])); ?></div>
            </td>
        </tr>
    </table>

    <?php
    // ── QR PLATBA (standard ČBA SPAYD) — jen u nezaplacených převodních faktur,
    //    ne v e-mail embedu (externí skript by mailový klient nenačetl)
    $__qrSpayd = '';
    if (!defined('INVOICE_DOC_EMBED')
        && (string)($invoice['invoice_type'] ?? 'invoice') === 'invoice'   // ne dobropis
        && in_array((string)$invoice['status'], ['issued', 'overdue'], true)   // ne draft/paid/cancelled
        && !in_array((string)$invoice['payment_method'], ['cash', 'card', 'cod'], true)) {
        require_once __DIR__ . '/includes/kb_api.php';
        $__qrSpayd = afxSpaydForInvoice($invoice);
    }
    ?>
    <?php if ($__qrSpayd !== ''): ?>
    <div style="display:flex;align-items:center;gap:14px;margin:10px 0 4px;padding:10px 12px;border:1px solid #e0e0e0;border-radius:8px;">
        <div id="qrPlatba" style="line-height:0;"></div>
        <div>
            <div style="font-weight:bold;font-size:12px;"><?php echo _l('inv_qr_payment'); ?></div>
            <div style="font-size:9px;color:#666;"><?php echo _l('inv_qr_hint'); ?></div>
        </div>
    </div>
    <script src="assets/js/qrcode.js"></script>
    <script>
    (function () {
        try {
            var qr = qrcode(0, 'M');
            qr.addData(<?php echo json_encode($__qrSpayd); ?>);
            qr.make();
            document.getElementById('qrPlatba').innerHTML = qr.createSvgTag({ cellSize: 3, margin: 2 });
        } catch (e) { /* QR je bonus — faktura funguje i bez něj */ }
    })();
    </script>
    <?php endif; ?>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: <?php echo $is_vat_payer ? '40%' : '70%'; ?>"><?php echo _l('item_name'); ?></th>
                <th class="text-right"><?php echo _l('table_quantity'); ?></th>
                <th class="text-right"><?php echo _l('table_price'); ?></th>
                <?php if ($is_vat_payer): ?>
                    <th class="text-right"><?php echo _l('table_vat_rate'); ?></th>
                    <th class="text-right"><?php echo _l('inv_vat_base'); ?></th>
                    <th class="text-right"><?php echo _l('inv_vat'); ?></th>
                <?php endif; ?>
                <th class="text-right"><?php echo _l('table_total'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $vat_summary = [];
            foreach ($items as $item): 
                $subtotal = $item['price'] * $item['quantity'];
                $vat = $is_vat_payer ? ($subtotal * ($item['vat_rate'] / 100)) : 0;
                $line_total = $is_vat_payer ? ($subtotal + $vat) : $subtotal;
                
                if ($is_vat_payer) {
                    $rate = (string)$item['vat_rate'];
                    if (!isset($vat_summary[$rate])) $vat_summary[$rate] = ['base' => 0, 'vat' => 0];
                    $vat_summary[$rate]['base'] += $subtotal;
                    $vat_summary[$rate]['vat'] += $vat;
                }
            ?>
            <tr>
                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                <td class="text-right"><?php echo $item['quantity'] . ' ' . $item['unit']; ?></td>
                <td class="text-right"><?php echo number_format($item['price'], 2, ',', ' '); ?></td>
                <?php if ($is_vat_payer): ?>
                    <td class="text-right"><?php echo $item['vat_rate']; ?>%</td>
                    <td class="text-right"><?php echo number_format($subtotal, 2, ',', ' '); ?></td>
                    <td class="text-right"><?php echo number_format($vat, 2, ',', ' '); ?></td>
                <?php endif; ?>
                <td class="text-right"><?php echo number_format($line_total, 2, ',', ' '); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals-section">
        <?php if ($is_vat_payer && !empty($vat_summary)): ?>
        <table class="vat-summary-table">
            <thead>
                <tr>
                    <th><?php echo _l('table_vat_rate'); ?></th>
                    <th class="text-right"><?php echo _l('inv_vat_base'); ?></th>
                    <th class="text-right"><?php echo _l('inv_vat'); ?></th>
                    <th class="text-right"><?php echo _l('table_total'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vat_summary as $rate => $vals): ?>
                <tr>
                    <td><?php echo $rate; ?> %</td>
                    <td class="text-right"><?php echo number_format($vals['base'], 2, ',', ' '); ?></td>
                    <td class="text-right"><?php echo number_format($vals['vat'], 2, ',', ' '); ?></td>
                    <td class="text-right"><?php echo number_format($vals['base'] + $vals['vat'], 2, ',', ' '); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div style="flex: 1; font-weight: bold; font-size: 12px; color: #666;">
                <?php echo _l('inv_non_vat_payer'); ?>
            </div>
        <?php endif; ?>

        <div class="grand-total-box">
            <div class="grand-label"><?php echo _l('inv_total_due'); ?></div>
            <div class="grand-value"><?php echo number_format($invoice['total_amount'], 2, ',', ' ') . ' ' . $invoice['currency']; ?></div>
        </div>
    </div>

    <div class="footer-note">
        <?php if (!$is_vat_payer): ?>
            <p><?php echo _l('inv_not_vat_payer_note'); ?></p>
        <?php endif; ?>
        <?php if ($invoice['notes']): ?>
            <p><strong><?php echo _l('inv_note_label'); ?>:</strong> <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
        <?php endif; ?>
        <p><?php echo _l('print_title_invoice'); ?> <?php echo _l('inv_footer_legal_note'); ?></p>
    </div>

    <div class="signatures">
        <div style="margin-top: 40px;">
            <strong><?php echo _l('inv_issued_by'); ?>:</strong> <?php echo $_SESSION['full_name'] ?? 'Admin'; ?>
        </div>
        <div class="signature-box">
            <div style="height: 60px;"></div>
            <?php echo _l('inv_stamp_signature'); ?>
        </div>
    </div>
</div>

</body>
</html>
