<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_GET['id']) && !isset($_GET['order_id'])) die("Order ID is not specified");

$id = $_GET['id'] ?? $_GET['order_id'];
$target_lang = crm_normalize_language((string)($_GET['lang'] ?? 'cs')) ?? 'cs';

// Helper for local translations
function _l($key) {
    global $target_lang;
    return __($key, $target_lang);
}

$stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.preferred_language 
                       FROM orders o 
                       JOIN customers c ON o.customer_id = c.id 
                       WHERE o.id = ?");
$stmt->execute([$id]);
$order = $stmt->fetch();
// bez ?lang → jazyk klienta (crm_normalize_language('' ) vrátí null → default níže)
if (!isset($_GET['lang']) && $order) {
    $target_lang = crmCustomerDocLang($order['preferred_language'] ?? 'cs');
}

if (!$order) die(__("print_not_found"));

$currency = get_setting('currency', 'Kč');
?>
<!DOCTYPE html>
<html lang="<?php echo $target_lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo _l('reception_act'); ?> <?php echo e(orderDisplayCode($order)); ?></title>
    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>">
    <style>
        /* Jednotný vizuál klientských dokumentů (dle zakázkového listu): SF Pro,
           tučné hodnoty × light popisky, adresa pobočky v patičce dole. */
        body { font-family: 'SF Pro Display', -apple-system, Arial, sans-serif; font-size: 13px;
               width: 72mm; margin: 0; padding: 0; color: #000; background: #fff; }
        .container { width: 100%; padding: 1mm; }
        .text-center { text-align: center; }
        .bold { font-weight: 700; }
        .rule { border-bottom: 1px solid #000; margin: 8px 0; }
        .kick { font-size: 9px; letter-spacing: 0.22em; text-transform: uppercase; font-weight: 700; margin-top: 6px; }
        .order-num { font-size: 21px; font-weight: 800; letter-spacing: -0.01em; margin: 2px 0 1px; }
        .date { font-size: 11px; font-weight: 300; }
        .label { font-size: 9.5px; font-weight: 400; letter-spacing: 0.14em; text-transform: uppercase; margin: 7px 0 2px; }
        .val { font-weight: 700; }
        .kv { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 3px; }
        .kv .k { font-weight: 300; }
        .kv .v { font-weight: 700; text-align: right; }
        .price-row { display: flex; justify-content: space-between; align-items: baseline; margin: 2px 0; }
        .price-row .k { font-size: 11px; letter-spacing: 0.1em; text-transform: uppercase; font-weight: 400; }
        .price-row .v { font-size: 17px; font-weight: 800; }
        .qr-code { margin-top: 10px; }
        .qr-code img { width: 38mm; height: 38mm; }
        .qr-note { font-size: 10px; font-weight: 700; margin-top: 3px; }
        a.doclink { color: inherit; text-decoration: none; }
        @media screen { a.doclink { text-decoration: underline; text-underline-offset: 2px; } }
        .qr-sub { font-size: 9.5px; font-weight: 300; margin-top: 1px; }
        .terms { font-size: 10px; line-height: 1.35; margin-top: 12px; font-weight: 300; }
        .foot { margin-top: 14px; border-top: 1px solid #000; padding-top: 7px; text-align: center; }
        .foot .foot-name { font-size: 12px; font-weight: 800; }
        .foot .foot-line { font-size: 9.5px; font-weight: 300; margin-top: 2px; line-height: 1.45; }
        @media print {
            @page { margin: 0; size: 80mm auto; }
            body { width: 72mm; background: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body<?php if (empty($_GET['embed'])): ?> onload="window.print()"<?php endif; ?>>

<?php
$__bc = crmOrderBranchContact((int)($order['branch_id'] ?? 0));   // kontakty dle pobočky zakázky
$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';
$__portal = crmClientPortalUrl();   // klientský portál (applefix.help po aktivaci)
?>
<div class="container">
    <div class="text-center">
        <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="AppleFix" style="width: 46mm; height: auto; margin-top: 2mm;"><?php endif; ?>
        <div class="kick"><?php echo _l('reception_act'); ?></div>
        <div class="order-num"><?php echo e(orderDisplayCode($order)); ?></div>
        <div class="date"><?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></div>
        <div class="rule"></div>
    </div>

    <div class="label"><?php echo _l('client'); ?></div>
    <div class="val"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></div>
    <div class="kv"><span class="k"><?php echo _l('phone'); ?></span><span class="v"><?php echo htmlspecialchars($order['phone']); ?></span></div>

    <div class="rule"></div>

    <div class="label"><?php echo _l('device_model'); ?></div>
    <div class="val" style="font-size: 15px;"><?php echo htmlspecialchars($order['device_brand'] . ' ' . $order['device_model']); ?></div>
    <div class="kv"><span class="k">S/N</span><span class="v"><?php echo htmlspecialchars($order['serial_number'] ?: '---'); ?></span></div>
    <?php if($order['pin_code']): ?>
    <div class="kv"><span class="k"><?php echo _l('pin'); ?></span><span class="v"><?php echo htmlspecialchars($order['pin_code']); ?></span></div>
    <?php endif; ?>

    <div class="rule"></div>

    <div class="label"><?php echo _l('appearance'); ?></div>
    <div style="word-wrap: break-word; font-weight: 300;"><?php echo htmlspecialchars($order['appearance'] ?: '---'); ?></div>

    <div class="label"><?php echo _l('problem'); ?></div>
    <div style="word-wrap: break-word;"><?php echo htmlspecialchars($order['problem_description']); ?></div>

    <div class="rule"></div>

    <div class="price-row">
        <span class="k"><?php echo _l('cost_est'); ?></span>
        <span class="v"><?php echo formatMoney($order['estimated_cost']); ?></span>
    </div>

    <div class="rule"></div>

    <div class="text-center qr-code">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?php echo urlencode($__portal); ?>" alt="QR">
        <div class="qr-note"><?php echo _l('status_page_label'); ?>: <a class="doclink" href="<?php echo $__portal; ?>"><?php echo e(preg_replace('~^https?://|/login\.php$~', '', crmClientPortalUrl())); ?></a></div>
        <div class="qr-sub"><?php echo _l('client_portal_login_hint'); ?></div>
    </div>

    <div class="terms text-center"><?php echo _l('print_reception_terms'); ?></div>

    <div class="foot">
        <div class="foot-name"><?php echo htmlspecialchars(get_setting('company_name', 'AppleFix s.r.o.')); ?></div>
        <div class="foot-line">
            <?php echo htmlspecialchars($__bc['address_inline']); ?><br>
            <?php echo _l('phone'); ?>: <a class="doclink" href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', (string)$__bc['phone'])); ?>"><?php echo htmlspecialchars($__bc['phone']); ?></a> · <a class="doclink" href="https://applefix.cz">applefix.cz</a>
        </div>
    </div>
</div>

<div class="no-print text-center" style="margin-top: 20px; padding-bottom: 30px;">
    <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px;"><?php echo _l('print'); ?></button>
    <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; margin-left: 10px;"><?php echo _l('close'); ?></button>
</div>

</body>
</html>
