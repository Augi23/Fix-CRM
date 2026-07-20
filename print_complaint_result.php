<?php
/* Vyrozumění o vyřízení reklamace (A4) — dokument pro klienta: číslo reklamace
   + zakázky, klient, PŮVODNÍ popis problému, ZÁVĚR/vyrozumění technika, datum
   a přílohy (fotky jako obrázky, ostatní soubory jako seznam názvů).
   Vzor print_complaint.php. Lze vložit i pro e-mail: includer
   (crmSendComplaintResultEmail) nastaví COMPLAINT_RESULT_EMBED a připraví
   $complaint (s joinem zákazníka), $complaintMedia, $target_lang + $__EMAIL_MODE. */
if (!defined('COMPLAINT_RESULT_EMBED')) {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';

    if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) die(__("unauthorized"));
    if (!isset($_GET['id'])) die("Complaint ID is not specified");

    ensureComplaintsClientColumns($pdo);
    ensureComplaintsWorkflowColumns($pdo);

    $cid = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT c.*, cu.first_name, cu.last_name, cu.phone AS cust_phone, cu.email, cu.address, cu.preferred_language
                           FROM complaints c
                           LEFT JOIN customers cu ON cu.id = c.customer_id
                           WHERE c.id = ?");
    $stmt->execute([$cid]);
    $complaint = $stmt->fetch();
    if (!$complaint) die(__("print_not_found"));

    $complaintMedia = crmGetComplaintMedia($pdo, $cid);

    // bez ?lang → dokument v jazyce KLIENTA (vzor print_order.php)
    $target_lang = $_GET['lang'] ?? crmCustomerDocLang($complaint['preferred_language'] ?? 'cs');
}
$complaintMedia = $complaintMedia ?? [];
$target_lang = $target_lang ?? 'cs';
$__EMAIL_MODE = $__EMAIL_MODE ?? false;
if (!function_exists('_l')) {
    function _l($key) { global $target_lang; return __($key, $target_lang); }
}

$__cust_name = trim(((string)($complaint['first_name'] ?? '')) . ' ' . ((string)($complaint['last_name'] ?? '')));
if ($__cust_name === '') $__cust_name = (string)($complaint['customer_name'] ?? '—');
$__cust_phone = (string)($complaint['cust_phone'] ?? $complaint['phone'] ?? '');
$__cust_email = (string)($complaint['email'] ?? '');
$__created  = !empty($complaint['created_at']) ? date('d.m.Y', strtotime((string)$complaint['created_at'])) : '';
$__resolved = !empty($complaint['resolved_at']) ? date('d.m.Y', strtotime((string)$complaint['resolved_at'])) : date('d.m.Y');
$__resolution = trim((string)($complaint['resolution_text'] ?? ''));
$__resolved_by = trim((string)($complaint['resolved_by'] ?? ''));

$__company = get_setting('company_name', 'AppleFix s.r.o.');
$__company_ico = get_setting('company_ico', '');
// Adresa/telefon/e-mail dle pobočky: přednostně pobočka PŮVODNÍ zakázky,
// jinak pobočka přihlášeného zaměstnance, jinak pobočka poslední zakázky se
// stejným S/N, fallback globální firma (vzor print_complaint.php).
$__doc_branch = 0;
if (!empty($complaint['order_id'])) {
    try {
        $__bq = $pdo->prepare("SELECT branch_id FROM orders WHERE id = ? LIMIT 1");
        $__bq->execute([(int)$complaint['order_id']]);
        $__doc_branch = (int)$__bq->fetchColumn();
    } catch (Throwable $e) { $__doc_branch = 0; }
}
if ($__doc_branch <= 0 && function_exists('getCurrentStaffBranchId')) {
    try { $__doc_branch = (int)getCurrentStaffBranchId(); } catch (Throwable $e) { $__doc_branch = 0; }
}
if ($__doc_branch <= 0 && trim((string)($complaint['serial_number'] ?? '')) !== '') {
    try {
        $__bq = $pdo->prepare("SELECT branch_id FROM orders WHERE serial_number = ? ORDER BY id DESC LIMIT 1");
        $__bq->execute([trim((string)$complaint['serial_number'])]);
        $__doc_branch = (int)$__bq->fetchColumn();
    } catch (Throwable $e) { $__doc_branch = 0; }
}
$__bc = crmOrderBranchContact($__doc_branch);
$__company_addr = $__bc['address_inline'];
$__company_phone = $__bc['phone'];
$__company_email = $__bc['email'] ?: get_setting('smtp_from_email', 'info@applefix.cz');

$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';

/* ---- přílohy: fotky vložit jako obrázky, ostatní jako seznam názvů ----
   E-mail: data: URI s rozpočtem (max 5 fotek, base64 celkem ~7 MB) — při
   překročení se fotky vynechají a vypíše se jen jejich počet, ať mail projde. */
$__img_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$__img_exts  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$__photos = [];        // [['src' =>, 'name' =>], …]
$__other_files = [];   // názvy ostatních souborů (PDF, HEIC, video…)
$__photos_omitted = 0; // fotky vynechané kvůli rozpočtu e-mailu
$__email_budget = 7 * 1024 * 1024;
$__email_used = 0;
$__email_max_photos = 5;
foreach ($complaintMedia as $__m) {
    $__fp = (string)($__m['file_path'] ?? '');
    if ($__fp === '') continue;
    $__name = trim((string)($__m['file_name'] ?? '')) ?: basename($__fp);
    $__mime = strtolower((string)($__m['file_type'] ?? ''));
    $__ext  = strtolower(pathinfo($__fp, PATHINFO_EXTENSION));
    $__is_img = in_array($__mime, $__img_mimes, true) || ($__mime === '' && in_array($__ext, $__img_exts, true));
    if (!$__is_img) { $__other_files[] = $__name; continue; }

    if ($__EMAIL_MODE) {
        $__fs = __DIR__ . '/' . ltrim($__fp, '/');
        if (!is_file($__fs)) { $__photos_omitted++; continue; }
        if (count($__photos) >= $__email_max_photos) { $__photos_omitted++; continue; }
        $__raw = (string)file_get_contents($__fs);
        $__b64 = base64_encode($__raw);
        if ($__email_used + strlen($__b64) > $__email_budget) { $__photos_omitted++; continue; }
        $__email_used += strlen($__b64);
        $__data_mime = $__mime !== '' ? $__mime : ('image/' . ($__ext === 'jpg' ? 'jpeg' : $__ext));
        $__photos[] = ['src' => 'data:' . $__data_mime . ';base64,' . $__b64, 'name' => $__name];
    } else {
        $__photos[] = ['src' => ltrim($__fp, '/'), 'name' => $__name];
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($target_lang); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(_l('cmpl_result_doc_title')); ?> <?php echo htmlspecialchars((string)$complaint['complaint_code']); ?></title>
    <?php if (!$__EMAIL_MODE): ?><link rel="stylesheet" href="assets/css/sf-pro.css"><?php endif; ?>
    <style>
        /* Jednotný vizuál klientských dokumentů (dle reklamačního protokolu).
           Popisek–hodnota jako TABULKA .kv s barvami natvrdo — Gmail zahazuje
           flex justify-content/gap i var(--x) (vzor print_order.php po v2.7.5). */
        :root { --ink:#111318; --sub:#4d5560; --muted:#949aa4; --line:#e8ebf0;
                --accent:#0a84ff; --accent-ink:#0a5bd6; --soft:#f6f8fb; }
        * { box-sizing: border-box; }
        body { font-family: 'SF Pro Display', -apple-system, "Segoe UI", Arial, sans-serif; font-size: 13px; line-height: 1.55;
               color: #111318; margin: 0; padding: 26px 18px; background: #eceff3; -webkit-font-smoothing: antialiased; }
        .sheet { max-width: 840px; margin: auto; background: #fff; border-radius: 18px; overflow: hidden;
                 box-shadow: 0 24px 64px rgba(17,20,24,0.12); }
        .accent-bar { height: 5px; background: linear-gradient(90deg, #0a84ff, #5ac8fa 55%, #64d2ff); }
        .doc-head { padding: 28px 34px 0; }
        .doc-head img { height: 34px; width: auto; }
        .doc-meta { text-align: right; }
        .doc-kicker { font-size: 10px; letter-spacing: 0.22em; text-transform: uppercase; color: #0a5bd6; font-weight: 800; }
        .doc-code { font-size: 24px; font-weight: 800; letter-spacing: -0.03em; margin: 3px 0 0; font-family: ui-monospace, Menlo, monospace; }
        .doc-date { font-size: 11px; color: #949aa4; margin-top: 5px; font-weight: 300; }
        .head-sep { margin: 16px 34px 0; border-bottom: 1px solid #e8ebf0; }
        .body { padding: 18px 34px 30px; }
        .panel { border: 1px solid #e8ebf0; border-radius: 14px; padding: 16px 18px; margin-bottom: 14px; }
        .panel h3 { margin: 0 0 8px; font-size: 9.5px; letter-spacing: 0.14em; text-transform: uppercase; color: #0a5bd6; font-weight: 800; }
        .panel .big { font-size: 17px; font-weight: 800; letter-spacing: -0.01em; }
        table.kv { width: 100%; border-collapse: collapse; }
        .kv td { padding: 4px 0; vertical-align: top; }
        .kv .k { font-size: 11px; color: #949aa4; font-weight: 300; text-align: left; padding-right: 14px; white-space: nowrap; }
        .kv .v { font-size: 12.5px; font-weight: 700; text-align: right; word-break: break-word; }
        .block { margin: 14px 0; }
        .block h3 { font-size: 9.5px; letter-spacing: 0.14em; text-transform: uppercase; color: #0a5bd6; margin: 0 0 6px; font-weight: 800; }
        .reason { border: 1px solid #e8ebf0; border-radius: 12px; padding: 14px 16px; white-space: pre-wrap; min-height: 40px; background: #f6f8fb; }
        .resolution { border: 1px solid #b6d4fb; border-radius: 12px; padding: 14px 16px; white-space: pre-wrap; min-height: 60px; background: #e8f1fe; }
        .resolution-meta { margin-top: 6px; font-size: 10.5px; color: #949aa4; font-weight: 300; text-align: right; }
        .status-chip { display: inline-block; padding: 6px 12px; border-radius: 999px; background: #e8f1fe; color: #0a5bd6;
                       border: 1px solid #b6d4fb; font-weight: 700; font-size: 12px; }
        .photos img { width: 150px; height: 150px; object-fit: cover; border-radius: 10px; border: 1px solid #e8ebf0;
                      margin: 0 6px 6px 0; vertical-align: top; }
        .filelist { margin: 6px 0 0; padding-left: 18px; font-size: 12px; color: #4d5560; }
        .filelist li { margin: 2px 0; }
        .omitted-note { margin-top: 8px; font-size: 11px; color: #8a5a1a; background: #fff7ec; border: 1px solid #f6dcb4;
                        border-radius: 9px; padding: 8px 10px; line-height: 1.5; }
        .fineprint { margin-top: 18px; padding-top: 14px; border-top: 2px solid #e8ebf0;
                     font-size: 10px; color: #495059; line-height: 1.55; font-weight: 300; text-align: justify; }
        .foot { margin-top: 24px; padding-top: 14px; border-top: 1px solid #e8ebf0; text-align: center; }
        .foot .foot-name { font-size: 12px; font-weight: 800; letter-spacing: 0.02em; color: #111318; }
        .foot .foot-line { font-size: 10px; color: #949aa4; font-weight: 300; margin-top: 4px; letter-spacing: 0.02em; }
        a.doclink { color: inherit; text-decoration: none; }
        @media screen { a.doclink { text-decoration: underline; text-underline-offset: 2px; } }
        /* Vynutit A4 na výšku (jinak orientaci určuje tiskárna) */
        @page { size: A4 portrait; margin: 0; }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; border-radius: 0; max-width: none; width: 210mm; }
                       .accent-bar, .resolution, .reason { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="accent-bar"></div>
    <div class="doc-head">
        <?php /* hlavička jako tabulka (ne flex) — v Gmailu by se logo a kód slily pod sebe */ ?>
        <table style="width:100%;border-collapse:collapse;"><tr>
            <td style="vertical-align:top;"><?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="<?php echo htmlspecialchars($__company); ?>"><?php endif; ?></td>
            <td class="doc-meta" style="vertical-align:top;" align="right">
                <div class="doc-kicker"><?php echo htmlspecialchars(_l('cmpl_result_doc_title')); ?></div>
                <div class="doc-code"><?php echo htmlspecialchars((string)$complaint['complaint_code']); ?></div>
                <div class="doc-date"><?php echo htmlspecialchars(_l('cmpl_result_date')); ?>: <?php echo htmlspecialchars($__resolved); ?></div>
            </td>
        </tr></table>
    </div>
    <div class="head-sep"></div>

    <div class="body">
        <div class="panel">
            <h3><?php echo htmlspecialchars(_l('customer_col')); ?></h3>
            <div class="big"><?php echo htmlspecialchars($__cust_name ?: '—'); ?></div>
            <table class="kv" style="margin-top:6px;">
                <?php if ($__cust_phone): ?><tr><td class="k"><?php echo htmlspecialchars(_l('phone')); ?></td><td class="v" align="right"><?php echo htmlspecialchars($__cust_phone); ?></td></tr><?php endif; ?>
                <?php if ($__cust_email): ?><tr><td class="k"><?php echo htmlspecialchars(_l('email')); ?></td><td class="v" align="right"><?php echo htmlspecialchars($__cust_email); ?></td></tr><?php endif; ?>
            </table>
        </div>

        <div class="panel">
            <h3><?php echo htmlspecialchars(_l('device')); ?></h3>
            <div class="big"><?php echo htmlspecialchars((string)($complaint['device'] ?? '—') ?: '—'); ?></div>
            <table class="kv" style="margin-top:6px;">
                <?php if (!empty($complaint['serial_number'])): ?><tr><td class="k">SN/IMEI</td><td class="v" align="right"><?php echo htmlspecialchars((string)$complaint['serial_number']); ?></td></tr><?php endif; ?>
                <tr><td class="k"><?php echo htmlspecialchars(_l('cmpl_number')); ?></td><td class="v" align="right"><?php echo htmlspecialchars((string)$complaint['complaint_code']); ?></td></tr>
                <?php if (!empty($complaint['order_code'])): ?><tr><td class="k"><?php echo htmlspecialchars(_l('cmpl_original_order')); ?></td><td class="v" align="right"><?php echo htmlspecialchars((string)$complaint['order_code']); ?></td></tr><?php endif; ?>
                <?php if ($__created !== ''): ?><tr><td class="k"><?php echo htmlspecialchars(_l('cmpl_received_date')); ?></td><td class="v" align="right"><?php echo htmlspecialchars($__created); ?></td></tr><?php endif; ?>
            </table>
        </div>

        <div class="block">
            <h3><?php echo htmlspecialchars(_l('cmpl_status')); ?></h3>
            <span class="status-chip"><?php echo htmlspecialchars((string)($complaint['complaint_status'] ?? 'Přijato') ?: 'Přijato'); ?></span>
        </div>

        <div class="block">
            <h3><?php echo htmlspecialchars(_l('cmpl_original_issue')); ?></h3>
            <div class="reason"><?php echo htmlspecialchars((string)($complaint['complaint_reason'] ?? '')); ?></div>
        </div>

        <div class="block">
            <h3><?php echo htmlspecialchars(_l('cmpl_resolution_doc')); ?></h3>
            <div class="resolution"><?php echo $__resolution !== '' ? htmlspecialchars($__resolution) : htmlspecialchars(_l('cmpl_no_resolution_yet')); ?></div>
            <?php if ($__resolution !== '' && ($__resolved_by !== '' || $__resolved !== '')): ?>
                <div class="resolution-meta">
                    <?php if ($__resolved_by !== ''): ?><?php echo htmlspecialchars(_l('cmpl_resolved_by_doc')); ?>: <?php echo htmlspecialchars($__resolved_by); ?> · <?php endif; ?><?php echo htmlspecialchars($__resolved); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($__photos) || $__photos_omitted > 0): ?>
        <div class="block">
            <h3><?php echo htmlspecialchars(_l('photo_documentation')); ?></h3>
            <?php if (!empty($__photos)): ?>
            <div class="photos">
                <?php foreach ($__photos as $__ph): ?>
                    <img src="<?php echo htmlspecialchars($__ph['src']); ?>" alt="<?php echo htmlspecialchars($__ph['name']); ?>">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($__photos_omitted > 0): ?>
                <div class="omitted-note"><?php echo htmlspecialchars(sprintf(_l('cmpl_photos_omitted'), $__photos_omitted)); ?></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($__other_files)): ?>
        <div class="block">
            <h3><?php echo htmlspecialchars(_l('cmpl_other_attachments')); ?></h3>
            <ul class="filelist">
                <?php foreach ($__other_files as $__fn): ?>
                    <li><?php echo htmlspecialchars($__fn); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div class="fineprint"><?php echo htmlspecialchars(_l('cmpl_result_fineprint')); ?></div>

        <div class="foot">
            <div class="foot-name"><?php echo htmlspecialchars($__company); ?></div>
            <div class="foot-line">
                <?php echo htmlspecialchars(trim($__company_addr)); ?><?php if ($__company_ico): ?> · IČO: <?php echo htmlspecialchars($__company_ico); ?><?php endif; ?>
                · Tel.: <a class="doclink" href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', (string)$__company_phone)); ?>"><?php echo htmlspecialchars(trim($__company_phone)); ?></a><?php if ($__company_email): ?> · <a class="doclink" href="mailto:<?php echo htmlspecialchars($__company_email); ?>"><?php echo htmlspecialchars($__company_email); ?></a><?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
