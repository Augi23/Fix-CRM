<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

clientRequireAuth();

$customerId = (int)($_SESSION['client_customer_id'] ?? 0);
$selectedOrderId = isset($_GET['order']) ? (int)$_GET['order'] : (int)($_SESSION['client_order_id'] ?? 0);

$customer = null;
$orders = [];

if (isset($pdo) && $customerId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$customerId]);
        $orders = $stmt->fetchAll();

        if (!$orders) {
            $selectedOrderId = 0;
        } elseif ($selectedOrderId <= 0) {
            $selectedOrderId = (int)$orders[0]['id'];
        } else {
            $belongsToCustomer = false;
            foreach ($orders as $o) {
                if ((int)$o['id'] === $selectedOrderId) {
                    $belongsToCustomer = true;
                    break;
                }
            }
            if (!$belongsToCustomer) {
                $selectedOrderId = (int)$orders[0]['id'];
            }
        }
    } catch (Exception $e) {
        $customer = null;
        $orders = [];
    }
}

$selectedOrder = null;
foreach ($orders as $order) {
    if ((int)$order['id'] === $selectedOrderId) {
        $selectedOrder = $order;
        break;
    }
}
if (!$selectedOrder && !empty($orders)) {
    $selectedOrder = $orders[0];
}

$orderMedia = [];
if (isset($pdo) && $selectedOrder) {
    try {
        $stmt = $pdo->prepare("SELECT file_path, file_type, file_name FROM order_attachments WHERE order_id = ? ORDER BY id DESC");
        $stmt->execute([(int)$selectedOrder['id']]);
        $orderMedia = [];
        foreach ($stmt->fetchAll() as $item) {
            $path = trim((string)($item['file_path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $type = strtolower(trim((string)($item['file_type'] ?? '')));
            if ($type === '') {
                $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                    $type = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
                } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'webm'], true)) {
                    $type = 'video/' . $ext;
                }
            }

            if (!str_starts_with($type, 'image/') && !str_starts_with($type, 'video/')) {
                continue;
            }

            $item['file_type'] = $type;
            $orderMedia[] = $item;
        }
    } catch (Exception $e) {
        $orderMedia = [];
    }
}

$clientStatusHistory = [];
if (!empty($selectedOrder['id'])) {
    try {
        $hstmt = $pdo->prepare(
            "SELECT l.new_status, l.changed_at, st.name AS status_tech_name
             FROM order_status_log l
             LEFT JOIN technicians st ON st.id = l.technician_id
             WHERE l.order_id = ?
             ORDER BY l.changed_at ASC"
        );
        $hstmt->execute([(int)$selectedOrder['id']]);
        $clientStatusHistory = $hstmt->fetchAll();
    } catch (Throwable $e) {
        $clientStatusHistory = [];
    }
}

function clientStatusMeta(string $status): array {
    if (isOrderStatusIn($status, 'uncollected')) {
        return [__('client_status_uncollected'), 'warning'];
    }

    $group = getOrderStatusGroup($status);
    $map = [
        'new' => ['client_status_new', 'primary'],
        'pending_approval' => ['client_status_pending_approval', 'info'],
        'in_progress' => ['client_status_in_progress', 'warning'],
        'waiting_parts' => ['client_status_waiting_parts', 'secondary'],
        'completed' => ['client_status_completed', 'success'],
        'collected' => ['client_status_collected', 'dark'],
        'cancelled' => ['client_status_cancelled', 'danger'],
    ];

    if (!$group || !isset($map[$group])) {
        return [trim((string)$status), 'light'];
    }

    [$labelKey, $class] = $map[$group];
    return [__($labelKey), $class];
}


function clientMediaUrl(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $normalized = ltrim($path, '/');
    while (str_starts_with($normalized, '../')) {
        $normalized = substr($normalized, 3);
    }
    return '../' . ltrim($normalized, '/');
}

function clientMoney($amount): string {
    if ($amount === null || $amount === '') {
        return '—';
    }

    $lang = crm_get_language();
    $decimal = $lang === 'en' ? '.' : ',';
    $thousands = $lang === 'en' ? ',' : ' ';
    return number_format((float)$amount, 0, $decimal, $thousands) . ' ' . get_setting('currency', 'Kč');
}

function clientOrderAmount(array $order): ?float {
    if (isset($order['final_cost']) && $order['final_cost'] !== null && $order['final_cost'] !== '') {
        return (float)$order['final_cost'];
    }
    if (isset($order['estimated_cost']) && $order['estimated_cost'] !== null && $order['estimated_cost'] !== '') {
        return (float)$order['estimated_cost'];
    }
    return null;
}

function clientOrderAmountLabel(array $order): string {
    if (isset($order['final_cost']) && $order['final_cost'] !== null && $order['final_cost'] !== '') {
        return __('client_amount_final');
    }
    if (isset($order['estimated_cost']) && $order['estimated_cost'] !== null && $order['estimated_cost'] !== '') {
        return __('client_amount_estimated');
    }
    return __('client_amount_generic');
}

$customerDisplayName = trim((string)($_SESSION['client_full_name'] ?? ''));
if ($customerDisplayName === '' && $customer) {
    $customerDisplayName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
}
if ($customerDisplayName === '' && !empty($customer['company'])) {
    $customerDisplayName = $customer['company'];
}

$today = date('d.m.Y');

/* ---- Dokumenty dostupné klientovi + stav reklamace k vybrané zakázce ---- */
$clientDocs = [];
$orderComplaint = null;
$canComplain = false;
$selectedOrderCode = '';
if ($selectedOrder && isset($pdo)) {
    $oid = (int)$selectedOrder['id'];
    // Jednotné označení zakázky = order_code (převzato z importu, pokračuje v číselné řadě).
    // Interní ID klientovi nezobrazujeme.
    $selectedOrderCode = trim((string)($selectedOrder['order_code'] ?? ''));

    // Zakázkový list — vždy
    $clientDocs[] = ['type' => 'order_sheet', 'label' => __('client_doc_order_sheet'), 'icon' => 'fa-file-lines'];

    // Faktura — jen pokud byla vystavena
    try {
        $q = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND customer_id = ? AND status IN ('issued','paid') AND invoice_type = 'invoice' ORDER BY created_at DESC LIMIT 1");
        $q->execute([$oid, $customerId]);
        if ($q->fetchColumn()) {
            $clientDocs[] = ['type' => 'invoice', 'label' => __('client_doc_invoice'), 'icon' => 'fa-file-invoice-dollar'];
        }
    } catch (Throwable $e) { /* faktura nedostupná */ }

    // Reklamace k této zakázce
    if (function_exists('ensureComplaintsClientColumns')) { ensureComplaintsClientColumns($pdo); }
    try {
        $q = $pdo->prepare("SELECT * FROM complaints WHERE order_id = ? AND customer_id = ? ORDER BY id DESC LIMIT 1");
        $q->execute([$oid, $customerId]);
        $orderComplaint = $q->fetch() ?: null;
    } catch (Throwable $e) { $orderComplaint = null; }

    if ($orderComplaint) {
        $clientDocs[] = ['type' => 'complaint', 'label' => __('client_doc_complaint_protocol'), 'icon' => 'fa-clipboard-check', 'complaint' => (int)$orderComplaint['id']];
    }

    $canComplain = isOrderStatusIn((string)$selectedOrder['status'], 'collected') && !$orderComplaint;
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(crm_get_language()); ?>" data-bs-theme="dark">
<head>
    <script>(function(){try{var t=localStorage.getItem('lg-theme')||'dark';document.documentElement.setAttribute('data-lg-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(__('client_section_title')); ?> - AppleFix</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0d1512">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Fix-CRM">
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js').catch(function () {}); }</script>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/sf-pro.css'); ?>">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/liquid-glass.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/liquid-glass.css'); ?>">
    <script src="../assets/js/liquid-glass.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/liquid-glass.js'); ?>" defer></script>
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #05070b 0%, #090d12 48%, #12161b 100%);
            color: #eef4ff;
            font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
        }

        .client-shell {
            width: min(100%, 1440px);
            margin: 0 auto;
            padding: 24px 18px 32px;
        }

        .client-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 22px;
            padding: 18px 20px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.045);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .client-topbar-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .client-topbar-brand img {
            width: 150px;
            height: auto;
            object-fit: contain;
        }

        .client-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: flex-end;
            color: rgba(243,247,255,0.84);
            font-size: 0.92rem;
        }

        .client-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.055);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 600;
        }

        .client-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.8fr);
            gap: 20px;
        }

        .client-card {
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,0.08);
            background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.045));
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 18px 54px rgba(0,0,0,0.24);
            overflow: hidden;
        }

        .client-card-inner {
            padding: 24px;
        }

        .client-hero h1 {
            margin: 0;
            font-size: clamp(2rem, 3vw, 3.2rem);
            line-height: 0.95;
            letter-spacing: -0.06em;
            font-weight: 800;
            color: #f4f7fb;
        }

        .client-hero p {
            margin: 14px 0 0;
            color: rgba(243,247,255,0.76);
            max-width: 640px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 22px;
        }

        .stat-box {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .stat-box .label {
            font-size: 0.76rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(243,247,255,0.62);
            font-weight: 800;
        }

        .stat-box .value {
            margin-top: 8px;
            font-size: 1.25rem;
            font-weight: 800;
            color: #fff;
        }

        .repair-status {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .status-badge-large {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 16px;
            font-weight: 800;
            border: 1px solid transparent;
        }

        .status-badge-large.primary { background: rgba(13,110,253,0.15); border-color: rgba(13,110,253,0.25); color: #7ab2ff; }
        .status-badge-large.info { background: rgba(13,202,240,0.15); border-color: rgba(13,202,240,0.25); color: #82e8ff; }
        .status-badge-large.warning { background: rgba(255,193,7,0.15); border-color: rgba(255,193,7,0.25); color: #ffd86a; }
        .status-badge-large.secondary { background: rgba(108,117,125,0.15); border-color: rgba(108,117,125,0.25); color: #c8d1da; }
        .status-badge-large.success { background: rgba(25,135,84,0.15); border-color: rgba(25,135,84,0.25); color: #7fe6ad; }
        .status-badge-large.danger { background: rgba(220,53,69,0.15); border-color: rgba(220,53,69,0.25); color: #ff8d99; }
        .status-badge-large.dark { background: rgba(52,58,64,0.3); border-color: rgba(255,255,255,0.08); color: #f1f4f8; }
        .status-badge-large.light { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.08); color: #fff; }

        .repair-list {
            display: grid;
            gap: 12px;
        }

        .repair-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(243,247,255,0.86);
            text-decoration: none;
            transition: transform .15s ease, background .15s ease, border-color .15s ease;
        }

        .repair-item:hover {
            transform: translateY(-1px);
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.12);
            color: #fff;
        }

        .repair-item.active {
            border-color: rgba(25,167,255,0.3);
            background: rgba(25,167,255,0.09);
        }

        .repair-item .title {
            font-weight: 800;
            color: #fff;
        }

        .repair-item .sub {
            font-size: 0.88rem;
            color: rgba(243,247,255,0.66);
        }

        .repair-details-grid {
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .detail-row .label {
            color: rgba(243,247,255,0.68);
            font-size: 0.85rem;
            font-weight: 700;
        }

        .detail-row .value {
            color: #fff;
            font-weight: 700;
            text-align: right;
        }

        .order-notice {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(25,135,84,0.12);
            border: 1px solid rgba(25,135,84,0.25);
            color: #b4f4d1;
            font-weight: 600;
        }

        .media-section {
            margin-top: 18px;
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .media-section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .media-section-title h4 {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
            color: #fff;
        }

        .media-section-title p {
            margin: 0;
            font-size: 0.9rem;
            color: rgba(243,247,255,0.68);
        }

        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }

        .media-card {
            display: block;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.035);
            text-decoration: none;
            transition: transform .15s ease, border-color .15s ease, background .15s ease;
        }

        .media-card:hover {
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.05);
        }

        .media-card img {
            width: 100%;
            height: 150px;
            object-fit: cover;
            display: block;
            background: rgba(255,255,255,0.02);
        }

        .media-card-caption {
            padding: 10px 12px;
            color: rgba(243,247,255,0.78);
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .empty-state {
            padding: 28px;
            text-align: center;
            color: rgba(243,247,255,0.68);
        }

        .doc-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .doc-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 15px;
            border-radius: 14px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.09);
            color: #eef4ff;
            text-decoration: none;
            font-weight: 600;
            transition: transform .15s ease, background .15s ease, border-color .15s ease;
        }

        .doc-link:hover {
            transform: translateY(-1px);
            background: rgba(255,255,255,0.07);
            border-color: rgba(25,167,255,0.35);
            color: #fff;
        }

        .doc-link-ico {
            width: 34px; height: 34px; flex: 0 0 34px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 10px;
            background: rgba(25,167,255,0.14);
            color: #7ab2ff;
        }

        .doc-link-label { flex: 1; min-width: 0; }
        .doc-link-ext { color: rgba(243,247,255,0.4); font-size: 0.82rem; }

        .claim-box {
            margin-top: 18px;
            padding: 18px;
            border-radius: 18px;
            background: rgba(249,115,22,0.09);
            border: 1px solid rgba(249,115,22,0.24);
        }

        .claim-head { font-weight: 800; color: #ffd0a8; font-size: 1.02rem; }
        .claim-sub { margin-top: 6px; color: rgba(243,247,255,0.72); font-size: 0.92rem; }

        .btn-claim {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 11px 18px; border: none; border-radius: 12px; cursor: pointer;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: #fff; font-weight: 700; font-size: 0.95rem;
            transition: filter .15s ease, transform .15s ease;
        }
        .btn-claim:hover { filter: brightness(1.06); transform: translateY(-1px); }
        .btn-claim:disabled { opacity: 0.6; cursor: default; transform: none; }

        .btn-claim-ghost {
            padding: 11px 18px; border-radius: 12px; cursor: pointer;
            background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12);
            color: #eef4ff; font-weight: 600;
        }

        .claim-modal {
            position: fixed; inset: 0; z-index: 9999;
            display: none; align-items: center; justify-content: center;
            padding: 18px;
            background: rgba(4,6,10,0.6);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        }

        .claim-modal-card {
            width: min(100%, 520px);
            border-radius: 22px;
            background: linear-gradient(180deg, #12171e, #0c1015);
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 24px 70px rgba(0,0,0,0.5);
            overflow: hidden;
        }

        .claim-modal-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 20px; border-bottom: 1px solid rgba(255,255,255,0.08);
        }
        .claim-modal-head h3 { margin: 0; font-size: 1.15rem; font-weight: 800; color: #fff; }
        .claim-x { background: none; border: none; color: rgba(255,255,255,0.6); font-size: 1.6rem; line-height: 1; cursor: pointer; }
        .claim-modal-body { padding: 20px; display: grid; gap: 8px; }
        .claim-modal-body label { font-size: 0.82rem; font-weight: 700; color: rgba(243,247,255,0.7); margin-top: 6px; }
        .claim-modal-body textarea,
        .claim-modal-body input {
            width: 100%; padding: 11px 13px; border-radius: 12px;
            background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.12);
            color: #fff; font-size: 0.95rem; font-family: inherit;
        }
        .claim-modal-body textarea:focus,
        .claim-modal-body input:focus { outline: none; border-color: rgba(249,115,22,0.5); }
        .claim-order { color: rgba(243,247,255,0.72); font-size: 0.9rem; }
        .claim-error { color: #ff9aa5; font-size: 0.88rem; }
        .claim-modal-foot {
            display: flex; justify-content: flex-end; gap: 10px;
            padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08);
        }

        @media (max-width: 1100px) {
            .client-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .client-topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .client-meta {
                justify-content: flex-start;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/../includes/liquid_glass_svg.php'; ?>
    <div class="client-shell">
        <div class="client-topbar">
            <div class="client-topbar-brand">
                <img src="../assets/img/applefix-logo.png" alt="AppleFix logo">
                <div>
                    <div class="client-chip mb-2"><i class="fas fa-user-shield"></i> <?php echo __('client_section_title'); ?></div>
                </div>
            </div>
            <div class="client-meta">
                <button type="button" class="btn btn-sm btn-outline-light lg-theme-toggle" title="Light / Dark" aria-label="Light / Dark"><i class="fas fa-sun"></i></button>
                <?php $currentLang = crm_get_language(); ?>
                <div class="d-flex gap-1" title="<?php echo e(__('language_switch')); ?>">
                    <a class="btn btn-sm rounded-pill px-3 <?php echo $currentLang === 'cs' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="../set_language.php?lang=cs&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'klient/dashboard.php'); ?>">CS</a>
                    <a class="btn btn-sm rounded-pill px-3 <?php echo $currentLang === 'en' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="../set_language.php?lang=en&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'klient/dashboard.php'); ?>">EN</a>
                    <a class="btn btn-sm rounded-pill px-3 <?php echo $currentLang === 'ru' ? 'btn-light text-dark' : 'btn-outline-light'; ?>" href="../set_language.php?lang=ru&amp;redirect=<?php echo rawurlencode($_SERVER['REQUEST_URI'] ?? 'klient/dashboard.php'); ?>">RU</a>
                </div>
                <span class="client-chip"><i class="fas fa-calendar-day"></i> <?php echo e($today); ?></span>
                <span class="client-chip"><i class="fas fa-user"></i> <?php echo e($customerDisplayName ?: __('client_default_name')); ?></span>
                <a href="karta.php" class="btn btn-sm rounded-pill px-3" style="background:linear-gradient(135deg,#0A84FF,#BF5AF2);color:#fff;border:0;"><i class="fas fa-id-card me-2"></i><?php echo __('client_loyalty_card'); ?></a>
                <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fas fa-right-from-bracket me-2"></i><?php echo __('logout'); ?></a>
            </div>
        </div>

        <div class="client-grid">
            <section class="client-card client-hero">
                <div class="client-card-inner">

                    <?php if ($selectedOrder): ?>
                        <?php [$statusLabel, $statusClass] = clientStatusMeta((string)$selectedOrder['status']); ?>
                        <div class="repair-status">
                            <span class="status-badge-large <?php echo e($statusClass); ?>">
                                <i class="fas fa-circle-info"></i>
                                <?php echo e($statusLabel); ?>
                            </span>
                            <span class="status-badge-large light">
                                <i class="fas fa-hashtag"></i>
                                <?php echo __('client_order_label'); ?> <?php echo e($selectedOrderCode . orderLegacySuffix($selectedOrder)); ?>
                            </span>
                        </div>

                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="label"><?php echo __('client_label_device'); ?></div>
                                <div class="value"><?php echo e(trim(($selectedOrder['device_brand'] ?? '') . ' ' . ($selectedOrder['device_model'] ?? '')) ?: '—'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="label"><?php echo e(clientOrderAmountLabel($selectedOrder)); ?></div>
                                <div class="value"><?php echo e(clientMoney(clientOrderAmount($selectedOrder))); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="label"><?php echo __('client_label_last_change'); ?></div>
                                <div class="value"><?php echo e(date('d.m.Y H:i', strtotime((string)($selectedOrder['updated_at'] ?? $selectedOrder['created_at'] ?? 'now')))); ?></div>
                            </div>
                        </div>

                        <div class="repair-details-grid">
                            <div class="detail-row">
                                <div class="label"><?php echo __('client_label_problem_desc'); ?></div>
                                <div class="value"><?php echo e($selectedOrder['problem_description'] ?: '—'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="label"><?php echo __('client_label_serial_number'); ?></div>
                                <div class="value"><?php echo e($selectedOrder['serial_number'] ?: '—'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="label"><?php echo __('client_label_serial_number_2'); ?></div>
                                <div class="value"><?php echo e($selectedOrder['serial_number_2'] ?: '—'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="label"><?php echo __('client_label_received'); ?></div>
                                <div class="value"><?php echo e(date('d.m.Y', strtotime((string)($selectedOrder['created_at'] ?? 'now')))); ?></div>
                            </div>
                        </div>

                        <div class="media-section">
                            <div class="media-section-title">
                                <div>
                                    <h4><i class="fas fa-camera me-2"></i><?php echo __('client_media_title'); ?></h4>
                                    <p><?php echo __('client_media_desc'); ?></p>
                                </div>
                            </div>

                            <?php if (!empty($orderMedia)): ?>
                                <div class="media-grid">
                                    <?php foreach ($orderMedia as $media): ?>
                                        <?php
                                            $mediaType = strtolower((string)($media['file_type'] ?? ''));
                                            $isVideo = str_starts_with($mediaType, 'video/');
                                            $mediaUrl = clientMediaUrl((string)($media['file_path'] ?? ''));
                                        ?>
                                        <a class="media-card" href="<?php echo e($mediaUrl); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php if ($isVideo): ?>
                                                <div class="d-flex align-items-center justify-content-center" style="height:150px;background:rgba(255,255,255,0.04);">
                                                    <i class="fas fa-video fa-lg"></i>
                                                </div>
                                            <?php else: ?>
                                                <img src="<?php echo e($mediaUrl); ?>" alt="<?php echo e(__('client_media_alt')); ?>">
                                            <?php endif; ?>
                                            <div class="media-card-caption"><?php echo e($media['file_name'] ?: ($isVideo ? 'Video' : __('client_media_caption_default'))); ?></div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state py-3 px-0 text-start">
                                    <?php echo __('client_media_empty'); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($clientStatusHistory)): ?>
                            <div class="media-section-title mt-4 mb-2">
                                <h4><i class="fas fa-clock-rotate-left me-2"></i><?php echo __('client_status_history'); ?></h4>
                            </div>
                            <div class="repair-details-grid" style="display:block;">
                                <?php foreach ($clientStatusHistory as $ch): ?>
                                    <div class="detail-row d-flex justify-content-between py-1" style="border-bottom: 1px solid rgba(255,255,255,0.06);">
                                        <span class="label"><?php echo date('d.m.Y H:i', strtotime($ch['changed_at'])); ?></span>
                                        <span class="value"><?php echo htmlspecialchars(orderStatusHistoryLabel((string)$ch['new_status'], $ch['status_tech_name'] ?? null)); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (in_array($selectedOrder['status'], getOrderStatusList('done'), true)): ?>
                            <div class="order-notice">
                                <i class="fas fa-box-open me-2"></i>
                                <?php echo __('client_notice_ready'); ?>
                            </div>
                        <?php elseif (in_array($selectedOrder['status'], getOrderStatusList('waiting_parts'), true)): ?>
                            <div class="order-notice" style="background: rgba(255,193,7,0.12); border-color: rgba(255,193,7,0.25); color: #ffe08a;">
                                <i class="fas fa-screwdriver-wrench me-2"></i>
                                <?php echo __('client_notice_waiting_parts'); ?>
                            </div>
                        <?php elseif (in_array($selectedOrder['status'], getOrderStatusList('in_progress'), true)): ?>
                            <div class="order-notice" style="background: rgba(13,202,240,0.12); border-color: rgba(13,202,240,0.25); color: #8be8ff;">
                                <i class="fas fa-gears me-2"></i>
                                <?php echo __('client_notice_in_progress'); ?>
                            </div>
                        <?php endif; ?>

                        <div class="media-section">
                            <div class="media-section-title">
                                <div>
                                    <h4><i class="fas fa-folder-open me-2"></i><?php echo __('client_documents_title'); ?></h4>
                                    <p><?php echo __('client_documents_desc'); ?></p>
                                </div>
                            </div>
                            <div class="doc-links">
                                <?php foreach ($clientDocs as $d): ?>
                                    <?php $href = 'document.php?type=' . rawurlencode($d['type']) . '&order=' . (int)$selectedOrder['id']
                                        . (isset($d['complaint']) ? '&complaint=' . (int)$d['complaint'] : ''); ?>
                                    <a class="doc-link" href="<?php echo e($href); ?>" target="_blank" rel="noopener noreferrer">
                                        <span class="doc-link-ico"><i class="fas <?php echo e($d['icon']); ?>"></i></span>
                                        <span class="doc-link-label"><?php echo e($d['label']); ?></span>
                                        <i class="fas fa-arrow-up-right-from-square doc-link-ext"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($orderComplaint): ?>
                            <div class="order-notice" style="background: rgba(249,115,22,0.12); border-color: rgba(249,115,22,0.28); color: #ffcfa1;">
                                <i class="fas fa-rotate-left me-2"></i>
                                <?php echo __('client_complaint_existing'); ?>
                                <strong><?php echo e($orderComplaint['complaint_code']); ?></strong>
                                — <?php echo e($orderComplaint['complaint_status'] ?? ''); ?>
                            </div>
                        <?php elseif ($canComplain): ?>
                            <div class="claim-box">
                                <div class="claim-head"><i class="fas fa-rotate-left me-2"></i><?php echo __('client_complaint_cta_title'); ?></div>
                                <p class="claim-sub mb-0"><?php echo __('client_complaint_cta_desc'); ?></p>
                                <button type="button" class="btn-claim mt-3" onclick="afxOpenClaim()">
                                    <i class="fas fa-rotate-left me-2"></i><?php echo __('client_complaint_cta'); ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open fa-2x mb-3"></i>
                            <div><?php echo __('client_no_active_order'); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="client-card">
                <div class="client-card-inner">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="client-chip mb-2"><i class="fas fa-list-check"></i> <?php echo __('client_my_orders'); ?></div>
                            <h3 class="mb-0 text-white fw-bold" style="letter-spacing: -0.04em;"><?php echo __('client_repairs_overview'); ?></h3>
                        </div>
                    </div>

                    <div class="repair-list">
                        <?php if (empty($orders)): ?>
                            <div class="empty-state"><?php echo __('client_no_orders'); ?></div>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php [$statusLabel, $statusClass] = clientStatusMeta((string)$order['status']); ?>
                                <a class="repair-item <?php echo (int)$order['id'] === (int)$selectedOrderId ? 'active' : ''; ?>" href="?order=<?php echo (int)$order['id']; ?>">
                                    <div>
                                        <div class="title"><?php echo htmlspecialchars(trim((string)($order['order_code'] ?? '')) . orderLegacySuffix($order), ENT_QUOTES); ?> · <?php echo e(trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? '')) ?: __('client_device_fallback')); ?></div>
                                        <div class="sub">
                                            <?php echo e($statusLabel); ?> · <?php echo e(clientMoney(clientOrderAmount($order))); ?>
                                        </div>
                                    </div>
                                    <span class="status-badge-large <?php echo e($statusClass); ?> py-2 px-3">
                                        <?php echo e($statusLabel); ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <?php if ($canComplain): ?>
    <div id="claimModal" class="claim-modal" role="dialog" aria-modal="true">
        <div class="claim-modal-card">
            <div class="claim-modal-head">
                <h3><i class="fas fa-rotate-left me-2" style="color:#f97316"></i><?php echo __('client_complaint_title'); ?></h3>
                <button type="button" class="claim-x" onclick="afxCloseClaim()" aria-label="<?php echo e(__('close')); ?>">&times;</button>
            </div>
            <div class="claim-modal-body">
                <div class="claim-order"><?php echo __('client_complaint_for_order'); ?> <strong><?php echo e($selectedOrderCode); ?></strong> · <?php echo e(trim(($selectedOrder['device_brand'] ?? '') . ' ' . ($selectedOrder['device_model'] ?? ''))); ?></div>
                <label for="claimReason"><?php echo __('client_complaint_reason_label'); ?></label>
                <textarea id="claimReason" rows="4" placeholder="<?php echo e(__('client_complaint_reason_ph')); ?>"></textarea>
                <label for="claimResolution"><?php echo __('client_complaint_resolution_label'); ?></label>
                <input id="claimResolution" type="text" placeholder="<?php echo e(__('client_complaint_resolution_ph')); ?>">
                <div id="claimError" class="claim-error" style="display:none;"></div>
            </div>
            <div class="claim-modal-foot">
                <button type="button" class="btn-claim-ghost" onclick="afxCloseClaim()"><?php echo __('client_complaint_cancel'); ?></button>
                <button type="button" id="claimSubmit" class="btn-claim" onclick="afxSubmitClaim()"><i class="fas fa-paper-plane me-2"></i><?php echo __('client_complaint_submit'); ?></button>
            </div>
        </div>
    </div>
    <script>
        var AFX_CSRF = <?php echo json_encode(generateCsrfToken()); ?>;
        var AFX_ORDER_ID = <?php echo (int)$selectedOrder['id']; ?>;
        function afxOpenClaim(){ document.getElementById('claimModal').style.display='flex'; }
        function afxCloseClaim(){ document.getElementById('claimModal').style.display='none'; }
        (function(){
            var m = document.getElementById('claimModal');
            if (m) m.addEventListener('click', function(e){ if (e.target === m) afxCloseClaim(); });
        })();
        function afxSubmitClaim(){
            var btn = document.getElementById('claimSubmit');
            var reason = document.getElementById('claimReason').value.trim();
            var resolution = document.getElementById('claimResolution').value.trim();
            var err = document.getElementById('claimError');
            err.style.display = 'none';
            if (!reason){ err.textContent = <?php echo json_encode(__('client_complaint_reason_required')); ?>; err.style.display='block'; return; }
            btn.disabled = true;
            var fd = new FormData();
            fd.append('csrf_token', AFX_CSRF);
            fd.append('order_id', AFX_ORDER_ID);
            fd.append('reason', reason);
            fd.append('resolution', resolution);
            fetch('api/create_complaint.php', { method:'POST', body: fd, credentials:'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.ok){ afxCloseClaim(); alert(d.message || <?php echo json_encode(__('client_complaint_sent')); ?>); location.reload(); }
                    else { err.textContent = d.error || <?php echo json_encode(__('error'), JSON_UNESCAPED_UNICODE); ?>; err.style.display='block'; btn.disabled=false; }
                })
                .catch(function(){ err.textContent = <?php echo json_encode(__('client_complaint_conn_error')); ?>; err.style.display='block'; btn.disabled=false; });
        }
    </script>
    <?php endif; ?>
</body>
</html>
