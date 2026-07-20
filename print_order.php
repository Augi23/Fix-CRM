<?php
/* Zakázkový list (A4) — obsah dle firemního vzoru: typy polí + KOMPLETNÍ text
   podmínek drobným písmem (české znění NEMĚNIT — žije v lang_custom.php jako
   ord_terms_p1/p2, EN/RU jsou překlady; rozhodné je české znění).
   Lze vložit i pro e-mail: includer nastaví ORDER_DOC_EMBED ($order,$items,$target_lang) + $__EMAIL_MODE. */
if (!defined('ORDER_DOC_EMBED')) {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';

    if (!isset($_SESSION['user_id'])) die(__("unauthorized"));
    if (!isset($_GET['id']) && !isset($_GET['order_id'])) die(__("ord_id_missing"));

    $id = $_GET['id'] ?? $_GET['order_id'];
    $stmt = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.email, c.preferred_language
                           FROM orders o
                           JOIN customers c ON o.customer_id = c.id
                           WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();

    if (!$order) die(__("print_not_found"));

    $stmt = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll();

    // bez ?lang → dokument v jazyce KLIENTA (volba při zakládání zakázky)
    $target_lang = $_GET['lang'] ?? crmCustomerDocLang($order['preferred_language'] ?? 'cs');
}
if (!function_exists('_l')) {
    function _l($key) { global $target_lang; return __($key, $target_lang); }
}
$__EMAIL_MODE = $__EMAIL_MODE ?? false;

/* ---- data pro doklad ---- */
$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';

$co_name  = get_setting('company_name', 'AppleFix s.r.o.');
$co_ico   = trim((string) get_setting('company_ico', ''));
$co_dic   = trim((string) get_setting('company_dic', ''));
$co_web   = trim((string) get_setting('company_web', '')) ?: 'www.applefix.cz';
// adresa/telefon/e-mail dle POBOČKY zakázky (Karlín / Černá růže), fallback firma
$__bc     = crmOrderBranchContact((int)($order['branch_id'] ?? 0));
$co_addr  = $__bc['address'];
$co_phone = $__bc['phone'];
$co_email = $__bc['email'];

// firemní identita do patičky — kontakty jako klikací odkazy (na papíře prostý text)
$__foot_bits = [];
$__addr1 = trim(preg_replace('/\s*[\r\n]+\s*/u', ', ', $co_addr));
if ($__addr1 !== '') $__foot_bits[] = e($__addr1);
if ($co_ico !== '') $__foot_bits[] = 'IČO: ' . e($co_ico) . ($co_dic !== '' ? ' · DIČ: ' . e($co_dic) : '');
if ($co_phone !== '') $__foot_bits[] = 'Tel.: <a class="doclink" href="tel:' . e(preg_replace('/[^0-9+]/', '', $co_phone)) . '">' . e($co_phone) . '</a>';
$__foot_bits[] = '<a class="doclink" href="https://applefix.cz">' . e($co_web) . '</a>';
$__foot_bits[] = '<a class="doclink" href="mailto:' . e($co_email) . '">' . e($co_email) . '</a>';
$co_line_html = implode('  ·  ', $__foot_bits);

$orderCode = orderDisplayCode($order);
$custName  = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
$deviceStr = trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? ''));
$pin       = trim((string)($order['pin_code'] ?? ''));
$estimated = ($order['estimated_cost'] !== null && $order['estimated_cost'] !== '') ? (float)$order['estimated_cost'] : null;
$receivedAt = !empty($order['created_at']) ? date('d.m.Y', strtotime((string)$order['created_at'])) : date('d.m.Y');
$__rawShip = trim((string)($order['shipping_method'] ?? ''));
// prázdné NEBO historicky ukládaný český default → přeložit dle jazyka dokladu;
// jakákoli jiná hodnota je data zakázky a tiskne se, jak je uložená
$pickupMethod = ($__rawShip === '' || $__rawShip === 'Osobní předání na pobočce')
    ? _l('ord_pickup_in_person') : $__rawShip;

// datum ukončení opravy (den) = kdy zakázka přešla do dokončeného stavu; prázdné při příjmu
$completedAt = '';
if (isset($pdo) && function_exists('getOrderStatusList')) {
    try {
        $doneList = getOrderStatusList('done');
        if (!empty($doneList)) {
            $ph = implode(',', array_fill(0, count($doneList), '?'));
            $qc = $pdo->prepare("SELECT MAX(changed_at) FROM order_status_log WHERE order_id = ? AND new_status IN ($ph)");
            $qc->execute(array_merge([(int)$order['id']], $doneList));
            $ts = $qc->fetchColumn();
            if ($ts) $completedAt = date('d.m.Y', strtotime((string)$ts));
        }
    } catch (Throwable $e) { $completedAt = ''; }
}

// Podpisy klienta (příjem/výdej) — vytisknou se nad podpisovou čarou
$__sigs = ['prijem' => null, 'vydej' => null];
if (function_exists('crmGetOrderSignatures')) {
    foreach (crmGetOrderSignatures((int)$order['id']) as $sigT => $sigRow) {
        $p = __DIR__ . '/' . ltrim((string)$sigRow['file_path'], '/');
        if (isset($__sigs[$sigT]) || array_key_exists($sigT, $__sigs)) {
            $__sigs[$sigT] = is_file($p)
                ? ['img' => 'data:image/png;base64,' . base64_encode((string)file_get_contents($p)), 'at' => (string)$sigRow['signed_at']]
                : null;
        }
    }
}

// Rozpis ceny (oprava + expresní příplatek / sleva…) — tiskne se od 2 řádků výš
$priceLines = [];
if (isset($pdo) && function_exists('crmGetOrderPriceLines')) {
    $priceLines = crmGetOrderPriceLines((int)$order['id']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo e($target_lang ?? 'cs'); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo _l('order_sheet'); ?> <?php echo e($orderCode); ?></title>
    <?php if (!$__EMAIL_MODE): ?><link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>"><?php endif; ?>
    <style>
        :root {
            --ink:#111318; --sub:#4d5560; --muted:#949aa4; --line:#e8ebf0;
            --accent:#0a84ff; --accent-ink:#0a5bd6; --soft:#f6f8fb;
        }
        * { box-sizing: border-box; }
        body { font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Arial, sans-serif;
               font-size: 12.5px; line-height: 1.5; color: var(--ink); margin: 0; padding: 26px 18px;
               background: #eceff3; -webkit-font-smoothing: antialiased; }
        .sheet { max-width: 840px; margin: auto; background: #fff; border-radius: 18px; overflow: hidden;
                 box-shadow: 0 24px 64px rgba(17,20,24,0.12); }
        .accent-bar { height: 5px; background: linear-gradient(90deg, #0a84ff, #5ac8fa 55%, #64d2ff); }
        .pad { padding: 28px 34px 34px; }

        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .head img { height: 34px; width: auto; }
        .doc { text-align: right; }
        .doc .kick { font-size: 10px; letter-spacing: 0.22em; text-transform: uppercase; color: var(--accent-ink); font-weight: 800; }
        .doc h1 { margin: 3px 0 0; font-size: 24px; font-weight: 800; letter-spacing: -0.03em; font-family: ui-monospace, Menlo, monospace; }
        .doc .date { font-size: 11px; color: var(--muted); margin-top: 5px; font-weight: 300; }

        /* firemní identita — designová PATIČKA dole (dřív řádek nahoře) */
        .head-sep { margin-top: 16px; border-bottom: 1px solid var(--line); }
        .foot { margin-top: 24px; padding-top: 14px; border-top: 1px solid var(--line); text-align: center; }
        .foot .foot-name { font-size: 12px; font-weight: 800; letter-spacing: 0.02em; color: var(--ink); }
        .foot .foot-line { font-size: 10px; color: var(--muted); font-weight: 300; margin-top: 4px; letter-spacing: 0.02em; }
        .foot .foot-line b { color: var(--sub); font-weight: 600; }

        /* dva sloupce: KLIENT | ZAŘÍZENÍ A OPRAVA */
        .cols { display: flex; gap: 16px; margin-top: 18px; }
        .panel { flex: 1; border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; }
        .panel.client { flex: 0 0 40%; background: var(--soft); }
        .panel .title { font-size: 9.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent-ink);
                        font-weight: 800; margin-bottom: 10px; }
        .client .name { font-size: 21px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15; }
        .client .contact { margin-top: 8px; font-size: 12.5px; color: var(--sub); line-height: 1.7; }
        .client .contact .ic { display: inline-block; width: 15px; color: var(--muted); }

        /* .kv jako TABULKA, ne flex: Gmail zahazuje justify-content/gap i var(--x),
           takže se v e-mailu popisek a hodnota slévaly („ZařízeníApple iPhone…").
           Tabulka drží rozvržení ve všech klientech; v prohlížeči i tisku vypadá
           stejně jako dřív. Barvy tu jsou záměrně natvrdo (var() v mailu nefunguje). */
        table.kv { width: 100%; border-collapse: collapse; border-bottom: 1px dashed #e8ebf0; }
        table.kv:last-child { border-bottom: none; }
        .kv td { padding: 6px 0; vertical-align: top; }
        .kv .k { font-size: 11px; color: #949aa4; font-weight: 300; text-align: left; padding-right: 14px; }
        .kv .v { font-size: 13px; font-weight: 700; text-align: right; word-break: break-word; }
        .kv .v.mono { font-family: ui-monospace, Menlo, monospace; }
        .kv .v.price { font-size: 15px; font-weight: 800; }
        .kv .v.repair { color: var(--ink); }
        .kv .v.done { color: var(--accent-ink); }
        .note { margin-top: 8px; font-size: 11px; color: #8a5a1a; background: #fff7ec; border: 1px solid #f6dcb4;
                border-radius: 9px; padding: 8px 10px; line-height: 1.5; }

        .legal { margin-top: 20px; padding-top: 14px; border-top: 2px solid var(--line);
                 font-size: 8.4px; line-height: 1.6; color: #495059; text-align: justify; font-weight: 300; }
        .legal p { margin: 0 0 8px; }

        .sigs { display: flex; gap: 44px; margin-top: 14px; }
        .sig .sig-img { display: block; height: 44px; width: auto; margin: 0 auto -8px; }
        .sig .sig-at { font-size: 8.5px; color: var(--muted); text-align: center; margin-top: 2px; font-weight: 300; }
        .sig { flex: 1; }
        .sig .line { border-top: 1.4px solid var(--ink); margin-top: 40px; padding-top: 7px;
                     font-size: 10.5px; color: var(--muted); text-align: center; }
        .pickup { margin-top: 24px; padding: 15px 18px 16px; background: var(--soft);
                  border: 1px solid var(--line); border-radius: 14px; }
        .pickup h3 { margin: 0; font-size: 13.5px; font-weight: 800; letter-spacing: -0.01em; }
        .pickup .sub { font-size: 11px; color: var(--sub); margin-top: 3px; }

        .toolbar { text-align: center; margin-bottom: 18px; }
        .toolbar button { padding: 11px 26px; cursor: pointer; background: var(--accent); color: #fff;
                          border: none; border-radius: 11px; font-size: 14px; font-weight: 600;
                          box-shadow: 0 8px 22px rgba(10,132,255,0.28); }

        /* odkazy: na OBRAZOVCE klikací (podtržené), na PAPÍŘE obyčejný text */
        a.doclink { color: inherit; text-decoration: none; }
        @media screen { a.doclink { text-decoration: underline; text-underline-offset: 2px; } }
        /* Vynutit A4 na výšku (bez tohoto se orientace řídila výchozím nastavením tiskárny → tisklo na šířku) */
        @page { size: A4 portrait; margin: 0; }
        @media print {
            body { background: #fff; padding: 0; font-size: 12px; }
            .sheet { box-shadow: none; border-radius: 0; max-width: none; width: 210mm; }
            .pad { padding: 11mm 13mm 10mm; }
            .accent-bar, .panel.client, .pickup, .note { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .legal { font-size: 8px; }
        }
    </style>
</head>
<body>

<?php if (!$__EMAIL_MODE && empty($_GET['plain'])): ?>
<div class="no-print toolbar">
    <button onclick="window.print()"><?php echo _l('print'); ?></button>
</div>
<?php endif; ?>

<div class="sheet">
    <div class="accent-bar"></div>
    <div class="pad">
        <div class="head">
            <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="<?php echo e($co_name); ?>"><?php endif; ?>
            <div class="doc">
                <div class="kick"><?php echo _l('order_sheet'); ?></div>
                <h1><?php echo e($orderCode); ?></h1>
                <div class="date"><?php echo _l('created'); ?>: <?php echo e($receivedAt); ?></div>
            </div>
        </div>

        <div class="head-sep"></div>

        <div class="cols">
            <div class="panel client">
                <div class="title"><?php echo _l('client'); ?></div>
                <div class="name"><?php echo e($custName ?: '—'); ?></div>
                <div class="contact">
                    <?php if (!empty($order['phone'])): ?><div><span class="ic">☎</span> <?php echo e($order['phone']); ?></div><?php endif; ?>
                    <?php if (!empty($order['email'])): ?><div><span class="ic">✉</span> <?php echo e($order['email']); ?></div><?php endif; ?>
                    <?php if (!empty($order['address'])): ?><div><span class="ic">⌂</span> <?php echo nl2br(e($order['address'])); ?></div><?php endif; ?>
                </div>
                <table class="kv" style="margin-top:12px;border-top:1px solid #e8ebf0;border-bottom:none;"><tr>
                    <td class="k" style="padding-top:10px;"><?php echo _l('ord_pickup_by_customer'); ?></td>
                    <td class="v" align="right" style="padding-top:10px;"><?php echo e($pickupMethod); ?></td>
                </tr></table>
            </div>

            <div class="panel">
                <div class="title"><?php echo _l('ord_device_and_repair'); ?></div>
                <table class="kv"><tr><td class="k"><?php echo _l('device'); ?></td><td class="v" align="right"><?php echo e($deviceStr ?: '—'); ?></td></tr></table>
                <table class="kv"><tr><td class="k"><?php echo _l('ord_device_passcode'); ?></td><td class="v mono" align="right"><?php echo e($pin !== '' ? $pin : '—'); ?></td></tr></table>
                <table class="kv"><tr><td class="k"><?php echo _l('ord_requested_repair'); ?></td><td class="v repair" align="right"><?php echo e((string)($order['problem_description'] ?? '') ?: '—'); ?></td></tr></table>
                <?php if (count($priceLines) >= 2): ?>
                    <?php foreach ($priceLines as $pl): ?>
                    <table class="kv"><tr><td class="k"><?php echo e($pl['label']); ?></td><td class="v" align="right"><?php echo e(formatMoney((float)$pl['amount'])); ?></td></tr></table>
                    <?php endforeach; ?>
                    <table class="kv"><tr><td class="k"><?php echo _l('ord_estimated_price_total'); ?></td><td class="v price" align="right"><?php echo $estimated !== null ? e(formatMoney($estimated)) : _l('ord_by_diagnostics'); ?></td></tr></table>
                <?php else: ?>
                    <table class="kv"><tr><td class="k"><?php echo _l('ord_estimated_price'); ?></td><td class="v price" align="right"><?php echo $estimated !== null ? e(formatMoney($estimated)) : _l('ord_by_diagnostics'); ?></td></tr></table>
                <?php endif; ?>
                <table class="kv"><tr><td class="k"><?php echo _l('ord_device_received'); ?></td><td class="v" align="right"><?php echo e($receivedAt); ?></td></tr></table>
                <table class="kv"><tr><td class="k"><?php echo _l('ord_repair_completion_date'); ?></td><td class="v done" align="right"><?php echo e($completedAt !== '' ? $completedAt : '—'); ?></td></tr></table>
                <?php if ($pin === ''): ?>
                    <div class="note"><?php echo _l('ord_note_no_pin'); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="legal">
            <p><?php echo _l('ord_terms_p1'); ?></p>
            <p><?php echo _l('ord_terms_p2'); ?></p>
        </div>

        <div class="sigs">
            <div class="sig"></div>
            <div class="sig">
                <?php if ($__sigs['prijem']): ?><img class="sig-img" src="<?php echo $__sigs['prijem']['img']; ?>" alt="podpis"><?php endif; ?>
                <div class="line"><?php echo _l('ord_customer_signature'); ?></div>
                <?php if ($__sigs['prijem']): ?><div class="sig-at"><?php echo _l('ord_signed_electronically'); ?> <?php echo e(date('j. n. Y H:i', strtotime($__sigs['prijem']['at']))); ?></div><?php endif; ?>
            </div>
        </div>

        <div class="pickup">
            <h3><?php echo _l('sign_pickup'); ?></h3>
            <div class="sub"><?php echo _l('ord_pickup_confirm_sub'); ?></div>
            <div class="sigs">
                <div class="sig"></div>
                <div class="sig">
                    <?php if ($__sigs['vydej']): ?><img class="sig-img" src="<?php echo $__sigs['vydej']['img']; ?>" alt="podpis"><?php endif; ?>
                    <div class="line"><?php echo _l('ord_customer_signature'); ?></div>
                    <?php if ($__sigs['vydej']): ?><div class="sig-at"><?php echo _l('ord_signed_electronically'); ?> <?php echo e(date('j. n. Y H:i', strtotime($__sigs['vydej']['at']))); ?></div><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="foot">
            <div class="foot-name"><?php echo e($co_name); ?></div>
            <div class="foot-line"><?php echo $co_line_html; ?></div>
        </div>
    </div>
</div>

</body>
</html>
