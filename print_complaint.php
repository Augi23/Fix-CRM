<?php
/* Reklamační protokol (A4). Lze vložit i z klientského portálu: includer nastaví
   COMPLAINT_DOC_EMBED a připraví $complaint (s joinem zákazníka) + volitelně
   $complaintPhotos. Jinak běží samostatně se staff přihlášením a ?id. */
if (!defined('COMPLAINT_DOC_EMBED')) {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';

    if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) die(__("unauthorized"));
    if (!isset($_GET['id'])) die("Complaint ID is not specified");

    $cid = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT c.*, cu.first_name, cu.last_name, cu.phone AS cust_phone, cu.email, cu.address
                           FROM complaints c
                           LEFT JOIN customers cu ON cu.id = c.customer_id
                           WHERE c.id = ?");
    $stmt->execute([$cid]);
    $complaint = $stmt->fetch();
    if (!$complaint) die(__("print_not_found"));

    $complaintPhotos = [];
    try {
        $ps = $pdo->prepare("SELECT file_path, file_name FROM complaint_attachments WHERE complaint_id = ? ORDER BY id ASC");
        $ps->execute([$cid]);
        $complaintPhotos = $ps->fetchAll();
    } catch (Throwable $e) { $complaintPhotos = []; }

    $target_lang = $_GET['lang'] ?? 'cs';
}
$complaintPhotos = $complaintPhotos ?? [];
$target_lang = $target_lang ?? 'cs';

$__cust_name = trim(((string)($complaint['first_name'] ?? '')) . ' ' . ((string)($complaint['last_name'] ?? '')));
if ($__cust_name === '') $__cust_name = (string)($complaint['customer_name'] ?? '—');
$__cust_phone = (string)($complaint['cust_phone'] ?? $complaint['phone'] ?? '');
$__cust_email = (string)($complaint['email'] ?? '');
$__created = !empty($complaint['created_at']) ? date('d.m.Y H:i', strtotime((string)$complaint['created_at'])) : date('d.m.Y H:i');

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
    <title>Reklamační protokol <?php echo htmlspecialchars((string)$complaint['complaint_code']); ?></title>
    <style>
        :root { --accent:#f97316; --ink:#1d1d1f; --muted:#6b7280; --line:#e5e7eb; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Arial, sans-serif; font-size: 13.5px; line-height: 1.55;
               color: var(--ink); margin: 0; padding: 24px; background: #f4f5f7; }
        .sheet { max-width: 820px; margin: auto; background: #fff; border-radius: 16px; overflow: hidden;
                 box-shadow: 0 10px 40px rgba(0,0,0,0.08); }
        .doc-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px;
                    padding: 26px 32px; background: linear-gradient(135deg,#2b1406,#3a1e0b); color: #fff; }
        .doc-head .brand { display: flex; align-items: center; gap: 14px; }
        .doc-head .brand img { height: 40px; width: auto; filter: brightness(0) invert(1); }
        .doc-head .company-name { font-size: 20px; font-weight: 800; letter-spacing: -0.01em; margin: 0; }
        .doc-head .company-meta { font-size: 12px; color: rgba(255,255,255,0.72); margin-top: 2px; }
        .doc-head .doc-meta { text-align: right; }
        .doc-head .doc-kicker { font-size: 10.5px; letter-spacing: 0.16em; text-transform: uppercase; color: rgba(255,255,255,0.6); }
        .doc-head .doc-code { font-size: 26px; font-weight: 800; margin: 2px 0; }
        .doc-head .doc-date { font-size: 12px; color: rgba(255,255,255,0.75); }
        .body { padding: 26px 32px 30px; }
        .grid2 { display: flex; gap: 18px; margin-bottom: 18px; }
        .panel { flex: 1; border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; }
        .panel h3 { margin: 0 0 8px; font-size: 10.5px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--muted); }
        .panel .big { font-size: 15px; font-weight: 700; }
        .panel .row { margin-top: 3px; }
        .panel .k { color: var(--muted); }
        .block { margin: 16px 0; }
        .block h3 { font-size: 10.5px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--muted); margin: 0 0 6px; }
        .reason { border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; white-space: pre-wrap; min-height: 60px; }
        .status-chip { display: inline-block; padding: 6px 12px; border-radius: 999px; background: #fff3e6; color: #b4530c;
                       border: 1px solid #f6c48a; font-weight: 700; font-size: 12px; }
        .photos { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
        .photos img { width: 120px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid var(--line); }
        .fineprint { margin-top: 18px; font-size: 10.5px; color: var(--muted); line-height: 1.5; }
        .sign { display: flex; gap: 40px; margin-top: 34px; }
        .sign .slot { flex: 1; border-top: 1px solid #9ca3af; padding-top: 6px; font-size: 11px; color: var(--muted); text-align: center; }
        @media print { body { background: #fff; padding: 0; } .sheet { box-shadow: none; border-radius: 0; max-width: none; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="doc-head">
        <div class="brand">
            <?php if ($__logo_data): ?><img src="<?php echo $__logo_data; ?>" alt="logo"><?php endif; ?>
            <div>
                <p class="company-name"><?php echo htmlspecialchars($__company); ?></p>
                <div class="company-meta">
                    <?php echo htmlspecialchars(trim($__company_addr)); ?>
                    <?php if ($__company_ico): ?> · IČO <?php echo htmlspecialchars($__company_ico); ?><?php endif; ?>
                </div>
                <div class="company-meta">
                    <?php echo htmlspecialchars(trim($__company_phone)); ?>
                    <?php if ($__company_email): ?> · <?php echo htmlspecialchars($__company_email); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="doc-meta">
            <div class="doc-kicker">Reklamační protokol</div>
            <div class="doc-code"><?php echo htmlspecialchars((string)$complaint['complaint_code']); ?></div>
            <div class="doc-date"><?php echo htmlspecialchars($__created); ?></div>
        </div>
    </div>

    <div class="body">
        <div class="grid2">
            <div class="panel">
                <h3>Zákazník</h3>
                <div class="big"><?php echo htmlspecialchars($__cust_name ?: '—'); ?></div>
                <?php if ($__cust_phone): ?><div class="row"><span class="k">Telefon:</span> <?php echo htmlspecialchars($__cust_phone); ?></div><?php endif; ?>
                <?php if ($__cust_email): ?><div class="row"><span class="k">E-mail:</span> <?php echo htmlspecialchars($__cust_email); ?></div><?php endif; ?>
            </div>
            <div class="panel">
                <h3>Zařízení</h3>
                <div class="big"><?php echo htmlspecialchars((string)($complaint['device'] ?? '—') ?: '—'); ?></div>
                <?php if (!empty($complaint['serial_number'])): ?><div class="row"><span class="k">SN/IMEI:</span> <?php echo htmlspecialchars((string)$complaint['serial_number']); ?></div><?php endif; ?>
                <?php if (!empty($complaint['order_code'])): ?><div class="row"><span class="k">Původní zakázka:</span> <?php echo htmlspecialchars((string)$complaint['order_code']); ?></div><?php endif; ?>
            </div>
        </div>

        <div class="block">
            <h3>Stav reklamace</h3>
            <span class="status-chip"><?php echo htmlspecialchars((string)($complaint['complaint_status'] ?? 'Přijato') ?: 'Přijato'); ?></span>
        </div>

        <div class="block">
            <h3>Předmět reklamace</h3>
            <div class="reason"><?php echo htmlspecialchars((string)($complaint['complaint_reason'] ?? '')); ?></div>
        </div>

        <?php if (!empty($complaintPhotos)): ?>
        <div class="block">
            <h3>Fotodokumentace</h3>
            <div class="photos">
                <?php foreach ($complaintPhotos as $ph):
                    $pp = (string)($ph['file_path'] ?? '');
                    if ($pp === '') continue;
                    if (!preg_match('#^https?://#i', $pp)) { $pp = (defined('COMPLAINT_DOC_EMBED') ? '../' : '') . ltrim($pp, '/'); }
                ?>
                    <img src="<?php echo htmlspecialchars($pp); ?>" alt="foto">
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="fineprint">
            Reklamace byla přijata k posouzení. O způsobu a termínu vyřízení bude zákazník informován v souladu s platnými
            obchodními podmínkami a příslušnými ustanoveními občanského zákoníku. Tento protokol slouží jako potvrzení
            o převzetí reklamace.
        </div>

        <div class="sign">
            <div class="slot">Za zákazníka</div>
            <div class="slot">Za servis <?php echo htmlspecialchars($__company); ?></div>
        </div>
    </div>
</div>
</body>
</html>
