<?php
/**
 * KLIENTSKÁ VĚRNOSTNÍ KARTA (klientský portál).
 * Klient vidí svou kartu, body, počet zařízení a QR pro recepci —
 * a přidá si ji do Apple / Google Peněženky (pokud je nakonfigurováno).
 */
require_once 'includes/config.php';
require_once 'includes/auth.php';

clientRequireAuth();

$customerId = (int)($_SESSION['client_customer_id'] ?? 0);
if ($customerId <= 0) { header('Location: index.php'); exit; }

$card = crmClientCardData($customerId);
$company = get_setting('company_name', 'AppleFix');
$appleReady  = function_exists('crmWalletAppleReady') && crmWalletAppleReady();
$googleReady = function_exists('crmWalletGoogleReady') && crmWalletGoogleReady();
$qr = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' . urlencode(crmCardScanUrl($card['token'] ?? ''));
?><!doctype html>
<html lang="<?php echo e(crm_get_language()); ?>" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?php echo e(__('client_loyalty_card')); ?> — <?php echo e($company); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    body { background:#07080d; color:#eef2f8; min-height:100dvh; font-family:-apple-system,'SF Pro Text','Segoe UI',sans-serif; padding:20px 14px; }
    .wrap { max-width:440px; margin:0 auto; }
    .loyalty-card {
        border-radius:22px; padding:22px; color:#fff; position:relative; overflow:hidden;
        /* stejná paleta jako pass v Apple Wallet (strip gradient) */
        background:linear-gradient(135deg,#0F111C 0%,#182042 55%,#2E265C 100%);
        border:1px solid rgba(142,160,208,.22);
        box-shadow:0 18px 44px rgba(46,38,92,.45);
    }
    .loyalty-card::after { content:""; position:absolute; right:-40px; top:-40px; width:200px; height:200px; border-radius:50%; background:radial-gradient(circle,rgba(94,92,230,.28),transparent 70%); }
    .lc-brand { font-weight:800; letter-spacing:.5px; font-size:1.1rem; }
    .lc-name { font-size:1.35rem; font-weight:700; margin-top:22px; }
    .lc-stats { display:flex; gap:10px; margin-top:16px; }
    .lc-stat { flex:1; background:rgba(255,255,255,.15); border-radius:14px; padding:12px; text-align:center; backdrop-filter:blur(6px); }
    .lc-stat b { font-size:1.6rem; display:block; line-height:1; }
    .lc-stat span { font-size:.72rem; opacity:.9; }
    .qr-box { background:#fff; border-radius:18px; padding:16px; text-align:center; margin-top:18px; }
    .qr-box img { width:200px; height:200px; }
    .wallet-btn { display:flex; align-items:center; justify-content:center; gap:10px; width:100%; padding:14px; border-radius:14px; font-weight:600; text-decoration:none; margin-top:12px; border:0; }
    .wallet-apple { background:#000; color:#fff; border:1px solid #333; }
    .wallet-google { background:#fff; color:#3c4043; }
    .muted { color:rgba(255,255,255,.55); font-size:.82rem; }
</style>
</head>
<body>
<div class="wrap">
    <a href="dashboard.php" class="text-white-50 text-decoration-none small"><i class="fas fa-arrow-left me-1"></i> <?php echo e(__('back')); ?></a>
    <?php if (!$card): ?>
        <div class="text-center mt-5"><i class="fas fa-id-card fa-3x mb-3 text-secondary"></i><p><?php echo e(__('cl_card_load_failed')); ?></p></div>
    <?php else: ?>
    <div class="loyalty-card mt-3">
        <div class="lc-brand"><i class="fas fa-id-card me-2"></i><?php echo e($company); ?> · <?php echo e(__('cl_card_club')); ?></div>
        <div class="lc-name"><?php echo e($card['name']); ?></div>
        <div class="lc-stats">
            <div class="lc-stat"><b><?php echo (int)$card['points']; ?></b><span><?php echo e(__('cl_card_points_label')); ?></span></div>
            <div class="lc-stat"><b><?php echo (int)$card['devices_total']; ?></b><span><?php echo e(__('cl_card_devices_total')); ?></span></div>
            <div class="lc-stat"><b><?php echo (int)$card['devices_active']; ?></b><span><?php echo e(__('cl_card_devices_active')); ?></span></div>
        </div>
        <div class="muted mt-3" style="color:rgba(255,255,255,.8)"><i class="fas fa-hashtag me-1"></i><?php echo e($card['token']); ?> · <?php echo e(__('cl_card_member_since')); ?> <?php echo e($card['since']); ?></div>
    </div>

    <div class="qr-box">
        <img src="<?php echo e($qr); ?>" alt="<?php echo e(__('cl_card_qr_alt')); ?>">
        <div class="text-dark small mt-1"><?php echo e(__('cl_card_show_reception')); ?></div>
    </div>

    <?php if ($appleReady): ?>
        <a class="wallet-btn wallet-apple" href="../wallet/apple_pass.php?t=<?php echo e($card['token']); ?>"><i class="fab fa-apple fa-lg"></i> <?php echo e(__('cl_card_add_apple_wallet')); ?></a>
    <?php endif; ?>
    <?php if ($googleReady): ?>
        <a class="wallet-btn wallet-google" href="../wallet/google_pass.php?t=<?php echo e($card['token']); ?>"><i class="fab fa-google fa-lg"></i> <?php echo e(__('cl_card_add_google_wallet')); ?></a>
    <?php endif; ?>
    <?php if (!$appleReady && !$googleReady): ?>
        <p class="muted text-center mt-3"><?php echo e(__('cl_card_wallet_soon')); ?></p>
    <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
