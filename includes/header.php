<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../klient/includes/auth.php';

// Keep client users inside the client portal
if (clientIsLoggedIn()) {
    header('Location: klient/dashboard.php');
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'login.php') {
    header("Location: login.php");
    exit;
}

// Access Control based on permissions
$page = basename($_SERVER['PHP_SELF']);

// Pages that require specific permissions
$permission_pages = [
    'customers.php' => 'edit_customers',
    'edit_customer.php' => 'edit_customers',
    'inventory.php' => 'manage_inventory',
    'edit_inventory.php' => 'manage_inventory',
    // 'reports.php' => 'admin_access', // Handled specially below
];

if ($page == 'reports.php') {
    if (!hasPermission('view_reports_all') && !hasPermission('admin_access') && (($_SESSION['role'] ?? '') != 'technician')) {
        header("Location: index.php");
        exit;
    }
} elseif (isset($permission_pages[$page]) && !hasPermission($permission_pages[$page])) {
    header("Location: index.php");
    exit;
}

$current_page = basename($_SERVER['PHP_SELF']);
$search_action = 'index.php';
$search_placeholder = __('search_placeholder');
$show_search = true;

if ($current_page == 'orders.php') {
    $search_action = 'orders.php';
    $search_placeholder = __('orders') . ' (ID, ' . __('client') . ', ' . __('device_model') . '...)';
} elseif ($current_page == 'customers.php') {
    $search_action = 'customers.php';
    $search_placeholder = __('customers') . ' (ID, ' . __('client') . ', ' . __('phone') . ', ' . __('ico') . '...)';
} elseif ($current_page == 'inventory.php') {
    $search_action = 'inventory.php';
    $search_placeholder = __('inventory') . ' (ID, ' . __('part_name') . ', ' . __('sku') . '...)';
} elseif ($current_page == 'settings.php') {
    if (($_SESSION['role'] ?? '') == 'admin') {
        $search_action = 'settings.php';
        $search_placeholder = __('technicians') . '...';
    } else {
        $show_search = false;
    }
}

$pageTitleMap = [
    'index.php' => __('dashboard'),
    'orders.php' => __('orders'),
    'customers.php' => __('customers'),
    'inventory.php' => __('inventory'),
    'reports.php' => __('reports'),
    'accounting.php' => __('accounting'),
    'settings.php' => __('settings'),
    'edit_order.php' => __('orders'),
    'view_order.php' => __('orders'),
];
$topbarTitle = $pageTitleMap[$current_page] ?? get_setting('company_name', 'Repair CRM');

$roleLabel = match (getCurrentStaffRole()) {
    'admin' => __('role_admin'),
    'manager' => __('role_manager'),
    'engineer' => __('role_engineer'),
    default => ucfirst((string)getCurrentStaffRole()),
};

$currentLang = crm_get_language();
$langRedirect = $_SERVER['REQUEST_URI'] ?? basename($_SERVER['PHP_SELF']);

$ordersBadgeCount = 0;
try {
    $ordersBadgeCount = (int)($pdo->query("SELECT COUNT(*) FROM orders WHERE status IN (" . orderStatusSqlIn($pdo, 'active') . ")" . orderBranchScopeSql('branch_id'))->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $ordersBadgeCount = 0;
}

// Počet aktivních reklamací (badge v menu) — vše mimo Vyřízeno/Zamítnuto
$complaintsBadgeCount = 0;
try {
    $complaintsBadgeCount = (int)($pdo->query("SELECT COUNT(*) FROM complaints WHERE complaint_status NOT IN ('Vyřízeno','Zamítnuto')")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $complaintsBadgeCount = 0;
}

// Počet aktivních položek nákupů (badge v menu) — čeká na objednání nebo doručení
$procurementBadgeCount = 0;
try {
    $procurementBadgeCount = (int)($pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('pending','ordered')")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $procurementBadgeCount = 0;
}

// Počet čekajících položek nákupního seznamu (badge v menu)
$shoppingListBadgeCount = 0;
try {
    $shoppingListBadgeCount = (int)($pdo->query("SELECT COUNT(*) FROM purchase_requests WHERE status = 'pending'")->fetchColumn() ?: 0);
} catch (Throwable $e) {
    $shoppingListBadgeCount = 0;
}
?>
<!DOCTYPE html>
<html lang="<?php echo e(crm_get_language()); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(get_setting('company_name', 'Repair CRM')); ?> - <?php echo e(__('dashboard')); ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#0d1512">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Fix-CRM">
    <script>if ('serviceWorker' in navigator) { navigator.serviceWorker.register('/sw.js').catch(function () {}); }</script>
    <meta name="csrf-token" content="<?php echo e($_SESSION['csrf_token'] ?? ''); ?>">
    <script>(function(){try{var t=localStorage.getItem('lg-theme')||'dark';document.documentElement.setAttribute('data-lg-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>

    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/sf-pro.css'); ?>">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/fix-crm-v2.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/fix-crm-v2.css'); ?>">
    <link rel="stylesheet" href="assets/css/liquid-glass.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/liquid-glass.css'); ?>">
    <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/responsive.css'); ?>">
    <link rel="stylesheet" href="assets/css/crm-shell.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/crm-shell.css'); ?>">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>
    <script src="assets/js/liquid-glass.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/liquid-glass.js'); ?>" defer></script>
    <script src="assets/js/liquid-glass-engine.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/liquid-glass-engine.js'); ?>" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.umd.js"></script>
    <script>
    $(function() {
        var csrfToken = $('meta[name="csrf-token"]').attr('content');
        $.ajaxSetup({
            beforeSend: function(xhr, settings) {
                if (settings.type === 'POST' || settings.type === 'post') {
                    if (typeof settings.data === 'string') {
                        settings.data += '&csrf_token=' + encodeURIComponent(csrfToken);
                    } else if (settings.data instanceof FormData) {
                        settings.data.append('csrf_token', csrfToken);
                    }
                }
            }
        });
    });
    window.LANG_NOTICE = '<?php echo __("notice_title"); ?>';
    window.LANG_CONFIRM = '<?php echo __("confirm_title"); ?>';
    window.LANG_PREVIEW = '<?php echo __("preview_btn"); ?>';
    window.LANG_HIGH = '<?php echo __("high"); ?>';
    window.LANG_NORMAL = '<?php echo __("normal"); ?>';
    window.LANG_PRIORITY_LOW = '<?php echo __("priority_low"); ?>';
    window.LANG_PRIORITY_NORMAL = '<?php echo __("priority_normal"); ?>';
    window.LANG_PRIORITY_HIGH = '<?php echo __("priority_high"); ?>';
    window.LANG_SEARCH_CLIENT = '<?php echo __("search_client_placeholder"); ?>';
    window.LANG_BRAND = '<?php echo __("brand"); ?>';
    window.LANG_MODEL = '<?php echo __("model_placeholder"); ?>';
    window.LANG_SCAN_NOT_FOUND = '<?php echo __("scan_not_found"); ?>';
    window.LANG_SCAN_CAMERA_ERROR = '<?php echo __("scan_camera_error"); ?>';
    window.LANG_SCAN_CAMERA_DENIED = '<?php echo __("scan_camera_denied"); ?>';
    </script>
</head>
<body>
<?php require __DIR__ . '/liquid_glass_svg.php'; ?>

<?php
// Iniciály přihlášeného uživatele (avatar v servisním řádku)
$afxFn = trim((string)($_SESSION['full_name'] ?? 'U'));
$afxFp = array_values(array_filter(explode(' ', $afxFn)));
$afxInitials = mb_strtoupper(count($afxFp) >= 2 ? mb_substr($afxFp[0],0,1).mb_substr($afxFp[1],0,1) : mb_substr($afxFn,0,2));
$afxIsManager = hasPermission('admin_access') || in_array(getCurrentStaffRole(), ['manager', 'boss'], true);
?>
<!-- Saténové pozadí (grafit × lesní zelená) — struktura pro Liquid Glass refrakci -->
<svg class="crm-bgfx" preserveAspectRatio="xMidYMid slice" viewBox="0 0 1440 900" aria-hidden="true">
  <defs>
    <linearGradient id="afx-sg1" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#121517"/><stop offset=".5" stop-color="#23282c"/><stop offset="1" stop-color="#0e1113"/>
    </linearGradient>
    <linearGradient id="afx-sg2" x1="0" y1="1" x2="1" y2="0">
      <stop offset="0" stop-color="#0a110d"/><stop offset=".55" stop-color="#172c21"/><stop offset="1" stop-color="#0b130f"/>
    </linearGradient>
    <linearGradient id="afx-sg3" x1="0" y1="0" x2="1" y2=".3">
      <stop offset="0" stop-color="#10150f"/><stop offset=".5" stop-color="#22372c"/><stop offset="1" stop-color="#0e130f"/>
    </linearGradient>
    <linearGradient id="afx-sheen" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#6f9483" stop-opacity="0"/><stop offset=".5" stop-color="#9cc4ae" stop-opacity=".42"/><stop offset="1" stop-color="#6f9483" stop-opacity="0"/>
    </linearGradient>
    <filter id="afx-soft" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="7"/></filter>
  </defs>
  <rect width="1440" height="900" fill="#070a09"/>
  <path d="M-100,240 C 240,380 560,120 900,260 C 1180,375 1360,240 1540,330 L1540,-60 L-100,-60 Z" fill="url(#afx-sg3)" opacity=".62"/>
  <path d="M-100,130 C 320,240 680,40 1020,170 C 1280,268 1420,150 1540,210 L1540,-60 L-100,-60 Z" fill="url(#afx-sg1)" opacity=".8"/>
  <path d="M-100,560 C 300,470 640,660 980,545 C 1240,458 1400,540 1540,480 L1540,960 L-100,960 Z" fill="url(#afx-sg2)" opacity=".38"/>
  <path d="M-100,690 C 260,540 520,820 830,660 C 1120,510 1300,640 1540,520 L1540,960 L-100,960 Z" fill="url(#afx-sg1)"/>
  <path d="M-100,780 C 300,660 620,880 940,740 C 1220,620 1380,720 1540,650 L1540,960 L-100,960 Z" fill="url(#afx-sg2)" opacity=".6"/>
  <path d="M-100,240 C 240,380 560,120 900,260 C 1180,375 1360,240 1540,330" stroke="url(#afx-sheen)" stroke-width="2.8" fill="none" filter="url(#afx-soft)" opacity=".9"/>
  <path d="M-100,130 C 320,240 680,40 1020,170 C 1280,268 1420,150 1540,210" stroke="url(#afx-sheen)" stroke-width="2" fill="none" filter="url(#afx-soft)" opacity=".6"/>
  <path d="M-100,560 C 300,470 640,660 980,545 C 1240,458 1400,540 1540,480" stroke="url(#afx-sheen)" stroke-width="2.4" fill="none" filter="url(#afx-soft)" opacity=".7"/>
  <path d="M-100,690 C 260,540 520,820 830,660 C 1120,510 1300,640 1540,520" stroke="url(#afx-sheen)" stroke-width="3.2" fill="none" filter="url(#afx-soft)"/>
  <path d="M-100,780 C 300,660 620,880 940,740 C 1220,620 1380,720 1540,650" stroke="url(#afx-sheen)" stroke-width="2.2" fill="none" filter="url(#afx-soft)" opacity=".75"/>
</svg>

<!-- Tenký servisní řádek -->
<header class="afx-utility">
    <a class="afx-brand" href="index.php">
        <img src="assets/img/logo-black.png" alt="AppleFix">
    </a>
    <span class="afx-page-title"><?php echo e($topbarTitle); ?></span>
    <span class="afx-utility-sep"></span>

    <?php if ($show_search): ?>
    <form action="<?php echo $search_action; ?>" method="GET" class="afx-search crm-navbar-search">
        <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
            <span class="input-group-text crm-kbd-hint">⌘K</span>
        </div>
    </form>
    <?php endif; ?>

    <button class="btn btn-sm crm-v2-icon-btn" type="button" id="scanOrderBtn" title="<?php echo e(__('scan_order_title')); ?>" aria-label="<?php echo e(__('scan_order_title')); ?>">
        <i class="fas fa-qrcode"></i>
    </button>
    <button class="btn btn-sm crm-v2-icon-btn" type="button" id="notificationsToggle" aria-label="<?php echo e(__('notifications')); ?>">
        <i class="fas fa-bell"></i>
        <span class="crm-v2-alert-dot"></span>
    </button>
    <a class="btn btn-sm crm-v2-icon-btn" href="sign_station.php" title="<?php echo e(__('sign_station_link')); ?>" aria-label="<?php echo e(__('sign_station_link')); ?>"><i class="fas fa-pen-nib"></i></a>
    <button class="btn btn-sm crm-v2-icon-btn lg-theme-toggle afx-hide-m" type="button" title="<?php echo e(__('theme_toggle')); ?>" aria-label="<?php echo e(__('theme_toggle')); ?>">
        <i class="fas fa-sun"></i>
    </button>
    <div class="dropdown afx-hide-m">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle crm-lang-switch" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e(__('language_switch')); ?>">
            <i class="fas fa-language me-1"></i><?php echo strtoupper($currentLang); ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item <?php echo $currentLang === 'cs' ? 'active' : ''; ?>" href="set_language.php?lang=cs&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_cs'); ?> (CS)</a></li>
            <li><a class="dropdown-item <?php echo $currentLang === 'en' ? 'active' : ''; ?>" href="set_language.php?lang=en&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_en'); ?> (EN)</a></li>
            <li><a class="dropdown-item <?php echo $currentLang === 'ru' ? 'active' : ''; ?>" href="set_language.php?lang=ru&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_ru'); ?> (RU)</a></li>
        </ul>
    </div>
    <a href="logout.php" class="btn btn-outline-danger btn-sm afx-logout afx-hide-m" title="<?php echo __('logout'); ?>"><i class="fas fa-sign-out-alt"></i></a>
    <span class="afx-user" title="<?php echo e($afxFn); ?> · <?php echo e($roleLabel ?: __('role_employee')); ?>">
        <span class="afx-avatar"><?php echo e($afxInitials); ?></span>
        <b><?php echo e($afxFp[0] ?? $afxFn); ?></b>
    </span>
</header>

<!-- Plovoucí dok z buněk (Liquid Glass) -->
<div class="afx-dockwrap">
    <nav class="afx-dock" id="afxDock" data-afx-glass="dock">
        <a class="afx-cell <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php">
            <i class="fas fa-home"></i><small><?php echo __('dashboard'); ?></small>
        </a>
        <a class="afx-cell <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
            <?php if ($ordersBadgeCount > 0): ?><span class="afx-badge"><?php echo $ordersBadgeCount; ?></span><?php endif; ?>
            <i class="fas fa-tools"></i><small><?php echo __('orders'); ?></small>
        </a>
        <a class="afx-cell <?php echo $current_page == 'reklamace.php' ? 'active' : ''; ?>" href="reklamace.php">
            <?php if ($complaintsBadgeCount > 0): ?><span class="afx-badge afx-badge--warn"><?php echo $complaintsBadgeCount; ?></span><?php endif; ?>
            <i class="fas fa-rotate-left"></i><small><?php echo __('complaints'); ?></small>
        </a>
        <a class="afx-cell <?php echo $current_page == 'procurement.php' ? 'active' : ''; ?>" href="procurement.php">
            <?php if ($procurementBadgeCount > 0): ?><span class="afx-badge"><?php echo $procurementBadgeCount; ?></span><?php endif; ?>
            <i class="fas fa-truck-loading"></i><small><?php echo __('procurement'); ?></small>
        </a>
        <?php if (hasPermission('edit_customers')): ?>
        <a class="afx-cell <?php echo $current_page == 'customers.php' ? 'active' : ''; ?>" href="customers.php">
            <i class="fas fa-users"></i><small><?php echo __('customers'); ?></small>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('manage_inventory')): ?>
        <a class="afx-cell <?php echo $current_page == 'inventory.php' ? 'active' : ''; ?>" href="inventory.php">
            <i class="fas fa-boxes"></i><small><?php echo __('inventory'); ?></small>
        </a>
        <?php endif; ?>
        <a class="afx-cell <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
            <i class="fas fa-chart-line"></i><small><?php echo __('reports'); ?></small>
        </a>
        <?php if ($afxIsManager): ?>
        <a class="afx-cell <?php echo $current_page == 'accounting.php' ? 'active' : ''; ?>" href="accounting.php">
            <i class="fas fa-file-invoice-dollar"></i><small><?php echo __('accounting'); ?></small>
        </a>
        <a class="afx-cell <?php echo $current_page == 'fixer_chat.php' ? 'active' : ''; ?>" href="fixer_chat.php">
            <i class="fab fa-telegram-plane"></i><small><?php echo __('fixer_chat'); ?></small>
        </a>
        <?php endif; ?>
        <?php if (hasPermission('admin_access')): ?>
        <a class="afx-cell <?php echo $current_page == 'history.php' ? 'active' : ''; ?>" href="history.php">
            <i class="fas fa-clock-rotate-left"></i><small>Historie</small>
        </a>
        <?php endif; ?>
        <a class="afx-cell <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
            <i class="fas <?php echo $afxIsManager ? 'fa-cog' : 'fa-user-circle'; ?>"></i><small><?php echo __('settings'); ?></small>
        </a>
        <span class="afx-div"></span>
        <a class="afx-cell act-blue" href="#" data-bs-toggle="modal" data-bs-target="#newOrderModal">
            <i class="fas fa-plus"></i><small><?php echo __('new_order'); ?></small>
        </a>
        <a class="afx-cell act-orange" href="#" data-bs-toggle="modal" data-bs-target="#newComplaintModal">
            <i class="fas fa-rotate-left"></i><small><?php echo __('complaints'); ?></small>
        </a>
        <a class="afx-cell act-green <?php echo $current_page == 'nakupni-seznam.php' ? 'active' : ''; ?>" href="nakupni-seznam.php">
            <?php if (!empty($shoppingListBadgeCount) && $shoppingListBadgeCount > 0): ?><span class="afx-badge"><?php echo (int)$shoppingListBadgeCount; ?></span><?php endif; ?>
            <i class="fas fa-cart-shopping"></i><small><?php echo __('shopping_list'); ?></small>
        </a>
    </nav>
</div>

<!-- Mobil/tablet: spodní tab bar -->
<nav class="afx-tabbar" aria-label="<?php echo e(__('mobile_navigation')); ?>">
    <a class="afx-tb <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php"><i class="fas fa-home"></i><?php echo __('dashboard'); ?></a>
    <a class="afx-tb <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
        <?php if ($ordersBadgeCount > 0): ?><span class="afx-badge"><?php echo $ordersBadgeCount; ?></span><?php endif; ?>
        <i class="fas fa-tools"></i><?php echo __('orders'); ?>
    </a>
    <button class="afx-tb-plus" type="button" data-bs-toggle="modal" data-bs-target="#newOrderModal" aria-label="<?php echo __('new_order'); ?>"><i class="fas fa-plus"></i></button>
    <a class="afx-tb <?php echo $current_page == 'reklamace.php' ? 'active' : ''; ?>" href="reklamace.php"><i class="fas fa-rotate-left"></i><?php echo __('complaints'); ?></a>
    <button class="afx-tb" type="button" id="afxSheetOpen"><i class="fas fa-bars"></i><?php echo __('menu'); ?></button>
</nav>

<!-- Mobil/tablet: sheet menu s hledáním -->
<div class="afx-sheet" id="afxSheet" aria-hidden="true">
    <div class="afx-scrim" id="afxSheetScrim"></div>
    <div class="afx-panel">
        <div class="afx-grab"></div>
        <?php if ($show_search): ?>
        <form action="<?php echo $search_action; ?>" method="GET" class="afx-sheet-search">
            <div class="input-group">
                <span class="input-group-text"><i class="fas fa-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
            </div>
        </form>
        <?php endif; ?>
        <div class="afx-sheet-acts">
            <a class="afx-sheet-act b" href="#" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus"></i><?php echo __('new_order'); ?></a>
            <a class="afx-sheet-act o" href="#" data-bs-toggle="modal" data-bs-target="#newComplaintModal"><i class="fas fa-rotate-left"></i><?php echo __('complaints'); ?></a>
            <a class="afx-sheet-act" href="sign_station.php"><i class="fas fa-pen-nib"></i><?php echo __('sign_station_link'); ?></a>
            <a class="afx-sheet-act g <?php echo $current_page == 'nakupni-seznam.php' ? 'active' : ''; ?>" href="nakupni-seznam.php">
                <?php if (!empty($shoppingListBadgeCount) && $shoppingListBadgeCount > 0): ?><span class="afx-badge"><?php echo (int)$shoppingListBadgeCount; ?></span><?php endif; ?>
                <i class="fas fa-cart-shopping"></i><?php echo __('shopping_list'); ?>
            </a>
        </div>
        <div class="afx-sheet-grid">
            <a class="afx-sheet-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php"><i class="fas fa-home"></i><?php echo __('dashboard'); ?></a>
            <a class="afx-sheet-link <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php"><i class="fas fa-tools"></i><?php echo __('orders'); ?></a>
            <a class="afx-sheet-link <?php echo $current_page == 'reklamace.php' ? 'active' : ''; ?>" href="reklamace.php"><i class="fas fa-rotate-left"></i><?php echo __('complaints'); ?></a>
            <a class="afx-sheet-link <?php echo $current_page == 'procurement.php' ? 'active' : ''; ?>" href="procurement.php"><i class="fas fa-truck-loading"></i><?php echo __('procurement'); ?></a>
            <?php if (hasPermission('edit_customers')): ?>
            <a class="afx-sheet-link <?php echo $current_page == 'customers.php' ? 'active' : ''; ?>" href="customers.php"><i class="fas fa-users"></i><?php echo __('customers'); ?></a>
            <?php endif; ?>
            <?php if (hasPermission('manage_inventory')): ?>
            <a class="afx-sheet-link <?php echo $current_page == 'inventory.php' ? 'active' : ''; ?>" href="inventory.php"><i class="fas fa-boxes"></i><?php echo __('inventory'); ?></a>
            <?php endif; ?>
            <a class="afx-sheet-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php"><i class="fas fa-chart-line"></i><?php echo __('reports'); ?></a>
            <?php if ($afxIsManager): ?>
            <a class="afx-sheet-link <?php echo $current_page == 'accounting.php' ? 'active' : ''; ?>" href="accounting.php"><i class="fas fa-file-invoice-dollar"></i><?php echo __('accounting'); ?></a>
            <a class="afx-sheet-link <?php echo $current_page == 'fixer_chat.php' ? 'active' : ''; ?>" href="fixer_chat.php"><i class="fab fa-telegram-plane"></i><?php echo __('fixer_chat'); ?></a>
            <?php endif; ?>
            <?php if (hasPermission('admin_access')): ?>
            <a class="afx-sheet-link <?php echo $current_page == 'history.php' ? 'active' : ''; ?>" href="history.php"><i class="fas fa-clock-rotate-left"></i>Historie</a>
            <?php endif; ?>
            <a class="afx-sheet-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>" href="settings.php"><i class="fas <?php echo $afxIsManager ? 'fa-cog' : 'fa-user-circle'; ?>"></i><?php echo __('settings'); ?></a>
        </div>
        <div class="afx-sheet-user">
            <span class="afx-avatar"><?php echo e($afxInitials); ?></span>
            <span><?php echo e($afxFn); ?><small><?php echo e($roleLabel ?: __('role_employee')); ?></small></span>
        </div>
        <div class="afx-sheet-foot">
            <button class="btn btn-outline-secondary btn-sm lg-theme-toggle" type="button"><i class="fas fa-sun me-1"></i><?php echo __('theme'); ?></button>
            <span class="afx-sheet-langs">
                <a class="btn btn-outline-secondary btn-sm <?php echo $currentLang === 'cs' ? 'active' : ''; ?>" href="set_language.php?lang=cs&amp;redirect=<?php echo rawurlencode($langRedirect); ?>">CS</a>
                <a class="btn btn-outline-secondary btn-sm <?php echo $currentLang === 'en' ? 'active' : ''; ?>" href="set_language.php?lang=en&amp;redirect=<?php echo rawurlencode($langRedirect); ?>">EN</a>
                <a class="btn btn-outline-secondary btn-sm <?php echo $currentLang === 'ru' ? 'active' : ''; ?>" href="set_language.php?lang=ru&amp;redirect=<?php echo rawurlencode($langRedirect); ?>">RU</a>
            </span>
            <a class="btn btn-outline-danger btn-sm" href="logout.php"><i class="fas fa-sign-out-alt me-1"></i><?php echo __('logout'); ?></a>
        </div>
    </div>
</div>
<script>
(function () {
    var sheet = document.getElementById('afxSheet');
    var openBtn = document.getElementById('afxSheetOpen');
    var scrim = document.getElementById('afxSheetScrim');
    if (!sheet || !openBtn) return;
    function close() { document.body.classList.remove('afx-sheet-open'); sheet.setAttribute('aria-hidden', 'true'); }
    openBtn.addEventListener('click', function () {
        document.body.classList.add('afx-sheet-open');
        sheet.setAttribute('aria-hidden', 'false');
    });
    if (scrim) scrim.addEventListener('click', close);
    sheet.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('a') : null;
        if (t) close();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
})();
</script>

<div id="content" class="crm-v2-content">
    <div class="crm-main-content">
