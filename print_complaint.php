<?php
/* Reklamační protokol (A4). Lze vložit i z klientského portálu: includer nastaví
   COMPLAINT_DOC_EMBED a připraví $complaint (s joinem zákazníka) + volitelně
   $complaintPhotos. Jinak běží samostatně se staff přihlášením a ?id. */
if (!defined('COMPLAINT_DOC_EMBED')) {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';

    if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) die(__("unauthorized"));
// role účetní: klientská data mimo fakturační rozsah nevidí (hranice role)
if (function_exists('crmIsAccountant') && crmIsAccountant()) { die(__('unauthorized')); }
    if (!isset($_GET['id'])) die("Complaint ID is not specified");

    $cid = (int)$_GET['id'];
    if (function_exists('ensureComplaintsLegalColumns')) { ensureComplaintsLegalColumns($pdo); }
    $stmt = $pdo->prepare("SELECT c.*, cu.first_name, cu.last_name, cu.phone AS cust_phone, cu.email, cu.address, cu.preferred_language,
                                  o.problem_description AS ord_problem, o.repair_solution AS ord_solution
                           FROM complaints c
                           LEFT JOIN customers cu ON cu.id = c.customer_id
                           LEFT JOIN orders o ON o.id = c.order_id
                           WHERE c.id = ?");
    $stmt->execute([$cid]);
    $complaint = $stmt->fetch();
    if (!$complaint) die(__("print_not_found"));

    // Přílohy přes sdílený helper (slévá complaint_media + complaint_attachments);
    // do protokolu jen obrázky, které prohlížeč i tiskárna umí vykreslit (HEIC ne).
    $complaintPhotos = [];
    foreach (crmGetComplaintMedia($pdo, (int)$cid) as $__pr) {
        if (function_exists('crmComplaintMediaIsViewableImage') && !crmComplaintMediaIsViewableImage($__pr)) { continue; }
        $complaintPhotos[] = $__pr;
    }

    $target_lang = $_GET['lang'] ?? crmCustomerDocLang($complaint['preferred_language'] ?? 'cs');
}
$complaintPhotos = $complaintPhotos ?? [];
$target_lang = $target_lang ?? 'cs';
if (!function_exists('_l')) {
    function _l($key) { global $target_lang; return __($key, $target_lang); }
}

$__cust_name = trim(((string)($complaint['first_name'] ?? '')) . ' ' . ((string)($complaint['last_name'] ?? '')));
if ($__cust_name === '') $__cust_name = (string)($complaint['customer_name'] ?? '—');
$__cust_phone = (string)($complaint['cust_phone'] ?? $complaint['phone'] ?? '');
$__cust_email = (string)($complaint['email'] ?? '');
$__created = !empty($complaint['created_at']) ? date('d.m.Y H:i', strtotime((string)$complaint['created_at'])) : date('d.m.Y H:i');

// Elektronický podpis klienta z podpisové stanice (pokud existuje) — vkládá se
// nad podpisovou linku stejně jako u zakázkového listu.
$__cmpl_sig = function_exists('crmGetComplaintSignature') ? crmGetComplaintSignature((int)($complaint['id'] ?? 0)) : null;

$__company = get_setting('company_name', 'AppleFix s.r.o.');
$__company_ico = get_setting('company_ico', '');
// Adresa/telefon/e-mail dle pobočky: reklamaci vystavuje zaměstnanec pobočky;
// fallback = pobočka poslední zakázky se stejným S/N, jinak globální firma.
$__complaint_branch = defined('COMPLAINT_DOC_EMBED') ? 0 : (int)getCurrentStaffBranchId();
if ($__complaint_branch <= 0 && trim((string)($complaint['serial_number'] ?? '')) !== '') {
    try {
        $__bq = $pdo->prepare("SELECT branch_id FROM orders WHERE serial_number = ? ORDER BY id DESC LIMIT 1");
        $__bq->execute([trim((string)$complaint['serial_number'])]);
        $__complaint_branch = (int)$__bq->fetchColumn();
    } catch (Throwable $e) { $__complaint_branch = 0; }
}
$__bc = crmOrderBranchContact($__complaint_branch);
$__company_addr = $__bc['address_inline'];
$__company_phone = $__bc['phone'];
$__company_email = $__bc['email'] ?: get_setting('smtp_from_email', 'info@applefix.cz');

$__logo_fs = __DIR__ . '/assets/img/logo-black.png';
$__logo_data = is_file($__logo_fs) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($__logo_fs)) : '';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($target_lang); ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars(_l('complaint_protocol')); ?> <?php echo htmlspecialchars((string)$complaint['complaint_code']); ?></title>
    <link rel="stylesheet" href="<?php echo defined('COMPLAINT_DOC_EMBED') ? '../' : ''; ?>assets/css/sf-pro.css">
    <style>
        /* Jednotný vizuál klientských dokumentů (dle zakázkového listu): SF Pro,
           modrý akcent, tučné hodnoty × light popisky, adresa v patičce dole. */
        :root { --ink:#111318; --sub:#4d5560; --muted:#949aa4; --line:#e8ebf0;
                --accent:#0a84ff; --accent-ink:#0a5bd6; --soft:#f6f8fb; }
        * { box-sizing: border-box; }
        body { font-family: 'SF Pro Display', -apple-system, "Segoe UI", Arial, sans-serif; font-size: 13px; line-height: 1.55;
               color: var(--ink); margin: 0; padding: 26px 18px; background: #eceff3; -webkit-font-smoothing: antialiased; }
        .sheet { max-width: 840px; margin: auto; background: #fff; border-radius: 18px; overflow: hidden;
                 box-shadow: 0 24px 64px rgba(17,20,24,0.12); }
        .accent-bar { height: 5px; background: linear-gradient(90deg, #0a84ff, #5ac8fa 55%, #64d2ff); }
        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; padding: 28px 34px 0; }
        .doc-head img { height: 34px; width: auto; }
        .doc-meta { text-align: right; }
        .doc-kicker { font-size: 10px; letter-spacing: 0.22em; text-transform: uppercase; color: var(--accent-ink); font-weight: 800; }
        .doc-code { font-size: 24px; font-weight: 800; letter-spacing: -0.03em; margin: 3px 0 0; font-family: ui-monospace, Menlo, monospace; }
        .doc-date { font-size: 11px; color: var(--muted); margin-top: 5px; font-weight: 300; }
        .head-sep { margin: 16px 34px 0; border-bottom: 1px solid var(--line); }
        .body { padding: 18px 34px 30px; }
        .grid2 { display: flex; gap: 16px; margin-bottom: 16px; }
        .panel { flex: 1; border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; }
        .panel h3 { margin: 0 0 8px; font-size: 9.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent-ink); font-weight: 800; }
        .panel .big { font-size: 17px; font-weight: 800; letter-spacing: -0.01em; }
        .panel .row { margin-top: 4px; font-size: 12.5px; }
        .panel .k { color: var(--muted); font-weight: 300; }
        .block { margin: 14px 0; }
        .block h3 { font-size: 9.5px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--accent-ink); margin: 0 0 6px; font-weight: 800; }
        .reason { border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; white-space: pre-wrap; min-height: 60px; background: var(--soft); }
        .status-chip { display: inline-block; padding: 6px 12px; border-radius: 999px; background: #e8f1fe; color: var(--accent-ink);
                       border: 1px solid #b6d4fb; font-weight: 700; font-size: 12px; }
        .photos { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .photos img { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid var(--line); }
        .fineprint { margin-top: 18px; padding-top: 14px; border-top: 2px solid var(--line);
                     font-size: 10px; color: #495059; line-height: 1.55; font-weight: 300; text-align: justify; }
        .sign { display: flex; gap: 40px; margin-top: 38px; }
        .sign .slot { flex: 1; border-top: 1.4px solid var(--ink); padding-top: 7px; font-size: 10.5px; color: var(--muted); text-align: center; }
        /* Elektronický podpis: obrázek NAD linkou (slot bez vlastní linky, linku nese .sigline) */
        .sign .slot.signed { border-top: none; padding-top: 0; }
        .sign .slot.signed .sig-img { display: block; max-height: 54px; margin: 0 auto 2px; }
        .sign .slot.signed .sigline { border-top: 1.4px solid var(--ink); padding-top: 7px; }
        .sign .slot.signed .sig-at { margin-top: 3px; font-size: 9px; color: #8a929c; }
        .foot { margin-top: 24px; padding-top: 14px; border-top: 1px solid var(--line); text-align: center; }
        .foot .foot-name { font-size: 12px; font-weight: 800; letter-spacing: 0.02em; color: var(--ink); }
        .foot .foot-line { font-size: 10px; color: var(--muted); font-weight: 300; margin-top: 4px; letter-spacing: 0.02em; }
        a.doclink { color: inherit; text-decoration: none; }
        @media screen { a.doclink { text-decoration: underline; text-underline-offset: 2px; } }
        /* Vynutit A4 na výšku (jinak orientaci určuje tiskárna) */
        @page { size: A4 portrait; margin: 0; }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; border-radius: 0; max-width: none; width: 210mm; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="accent-bar"></div>
    <div class="doc-head">
        <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="<?php echo htmlspecialchars($__company); ?>"><?php endif; ?>
        <div class="doc-meta">
            <div class="doc-kicker"><?php echo htmlspecialchars(_l('complaint_protocol')); ?></div>
            <div class="doc-code"><?php echo htmlspecialchars((string)$complaint['complaint_code']); ?></div>
            <div class="doc-date"><?php echo htmlspecialchars($__created); ?></div>
        </div>
    </div>
    <div class="head-sep"></div>

    <div class="body">
        <div class="grid2">
            <div class="panel">
                <h3><?php echo htmlspecialchars(_l('customer_col')); ?></h3>
                <div class="big"><?php echo htmlspecialchars($__cust_name ?: '—'); ?></div>
                <?php if ($__cust_phone): ?><div class="row"><span class="k"><?php echo htmlspecialchars(_l('phone')); ?>:</span> <?php echo htmlspecialchars($__cust_phone); ?></div><?php endif; ?>
                <?php if ($__cust_email): ?><div class="row"><span class="k"><?php echo htmlspecialchars(_l('email')); ?>:</span> <?php echo htmlspecialchars($__cust_email); ?></div><?php endif; ?>
            </div>
            <div class="panel">
                <h3><?php echo htmlspecialchars(_l('device')); ?></h3>
                <div class="big"><?php echo htmlspecialchars((string)($complaint['device'] ?? '—') ?: '—'); ?></div>
                <?php if (!empty($complaint['serial_number'])): ?><div class="row"><span class="k">SN/IMEI:</span> <?php echo htmlspecialchars((string)$complaint['serial_number']); ?></div><?php endif; ?>
                <?php if (!empty($complaint['order_code'])): ?><div class="row"><span class="k"><?php echo htmlspecialchars(_l('cmpl_original_order')); ?>:</span> <?php echo htmlspecialchars((string)$complaint['order_code']); ?></div><?php endif; ?>
            </div>
        </div>

        <?php
            // Zákonné náležitosti potvrzení o přijetí (§ 19 z. č. 634/1992 Sb.):
            // datum uplatnění, obsah reklamace, požadovaný způsob vyřízení, kontakt,
            // + lhůta vyřízení. Vše viditelně na protokolu.
            $__req  = trim((string)($complaint['requested_resolution'] ?? ''));
            $__rcvd = trim((string)($complaint['received_by'] ?? ''));
            $__pd   = !empty($complaint['purchase_date']) ? date('d.m.Y', strtotime((string)$complaint['purchase_date'])) : '';
            $__dl   = !empty($complaint['created_at']) ? date('d.m.Y', strtotime('+30 days', strtotime((string)$complaint['created_at']))) : '';
        ?>
        <div class="grid2">
            <div class="panel">
                <h3>Uplatnění reklamace</h3>
                <div class="row"><span class="k">Datum uplatnění:</span> <strong><?php echo htmlspecialchars($__created); ?></strong></div>
                <?php if ($__rcvd !== ''): ?><div class="row"><span class="k">Přijal(a):</span> <?php echo htmlspecialchars($__rcvd); ?></div><?php endif; ?>
                <?php if ($__pd !== ''): ?><div class="row"><span class="k">Datum zakoupení / opravy:</span> <?php echo htmlspecialchars($__pd); ?></div><?php endif; ?>
                <?php if ($__dl !== ''): ?><div class="row"><span class="k">Vyřídíme nejpozději do:</span> <strong><?php echo htmlspecialchars($__dl); ?></strong> (zákonná lhůta 30 dnů)</div><?php endif; ?>
            </div>
            <div class="panel">
                <h3>Požadovaný způsob vyřízení</h3>
                <div class="big"><?php echo htmlspecialchars($__req !== '' ? $__req : 'Posouzení technikem'); ?></div>
                <div class="row"><span class="k">Stav:</span> <?php echo htmlspecialchars((string)($complaint['complaint_status'] ?? 'Přijato') ?: 'Přijato'); ?></div>
            </div>
        </div>

        <div class="block">
            <h3><?php echo htmlspecialchars(_l('cmpl_subject')); ?> (popis vady)</h3>
            <div class="reason"><?php echo htmlspecialchars((string)($complaint['complaint_reason'] ?? '')); ?></div>
        </div>

        <?php if (!empty($complaint['ord_problem']) || !empty($complaint['ord_solution'])): ?>
        <div class="block">
            <h3>Původní zakázka <?php echo htmlspecialchars((string)($complaint['order_code'] ?? '')); ?></h3>
            <div class="reason"><?php
                $__orig = [];
                if (!empty($complaint['ord_problem']))  $__orig[] = 'Původní závada: ' . (string)$complaint['ord_problem'];
                if (!empty($complaint['ord_solution'])) $__orig[] = 'Provedená oprava: ' . (string)$complaint['ord_solution'];
                echo htmlspecialchars(implode("\n", $__orig));
            ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($complaintPhotos)): ?>
        <div class="block">
            <h3><?php echo htmlspecialchars(_l('photo_documentation')); ?></h3>
            <div class="photos">
                <?php foreach ($complaintPhotos as $ph):
                    $pp = (string)($ph['file_path'] ?? '');
                    if ($pp === '') continue;
                    // smazaný soubor = prázdný rámeček na právním dokumentu → radši vynechat
                    if (!preg_match('#^https?://#i', $pp) && !is_file(__DIR__ . '/' . ltrim($pp, '/'))) { continue; }
                    if (!preg_match('#^https?://#i', $pp)) { $pp = (defined('COMPLAINT_DOC_EMBED') ? '../' : '') . ltrim($pp, '/'); }
                ?>
                    <img src="<?php echo htmlspecialchars($pp); ?>" alt="foto">
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="fineprint">
            Toto potvrzení se vydává při uplatnění reklamace dle § 19 zákona č. 634/1992 Sb., o ochraně spotřebitele,
            a obsahuje datum uplatnění reklamace, její obsah (popis vytýkané vady), požadovaný způsob vyřízení
            a kontaktní údaje spotřebitele pro účely poskytnutí informace o vyřízení. Reklamace včetně odstranění vady
            bude vyřízena bez zbytečného odkladu, nejpozději do 30 dnů ode dne uplatnění, pokud se strany nedohodnou
            na lhůtě delší. O vyřízení reklamace bude spotřebitel v této lhůtě informován na uvedené kontaktní údaje
            a obdrží potvrzení o datu a způsobu vyřízení reklamace, včetně případného potvrzení o provedení opravy
            a době jejího trvání, případně písemné odůvodnění zamítnutí reklamace. Marné uplynutí lhůty zakládá
            právo spotřebitele od smlouvy odstoupit nebo požadovat přiměřenou slevu. Zákazník svým podpisem stvrzuje
            správnost uvedených údajů a předání zařízení ve stavu popsaném výše.
        </div>

        <div class="sign">
            <?php if ($__cmpl_sig): ?>
            <div class="slot signed">
                <img class="sig-img" src="<?php echo $__cmpl_sig['img']; ?>" alt="podpis">
                <div class="sigline"><?php echo htmlspecialchars(_l('cmpl_sign_customer')); ?></div>
                <div class="sig-at"><?php echo htmlspecialchars(_l('ord_signed_electronically')); ?> <?php echo htmlspecialchars(date('j. n. Y H:i', strtotime((string)$__cmpl_sig['at']))); ?></div>
            </div>
            <?php else: ?>
            <div class="slot"><?php echo htmlspecialchars(_l('cmpl_sign_customer')); ?></div>
            <?php endif; ?>
            <div class="slot"><?php echo htmlspecialchars(_l('cmpl_sign_service')); ?> <?php echo htmlspecialchars($__company); ?></div>
        </div>

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
