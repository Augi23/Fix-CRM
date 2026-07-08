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
?>
<!DOCTYPE html>
<html lang="<?php echo e(crm_get_language()); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(get_setting('company_name', 'Repair CRM')); ?> - <?php echo e(__('dashboard')); ?></title>
    <meta name="csrf-token" content="<?php echo e($_SESSION['csrf_token'] ?? ''); ?>">
    <script>(function(){try{var t=localStorage.getItem('lg-theme')||'dark';document.documentElement.setAttribute('data-lg-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>

    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5.0/dist/fancybox/fancybox.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Fira+Code:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="assets/css/fix-crm-v2.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/fix-crm-v2.css'); ?>">
    <link rel="stylesheet" href="assets/css/liquid-glass.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/liquid-glass.css'); ?>">
    <link rel="stylesheet" href="assets/css/responsive.css?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/css/responsive.css'); ?>">

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/main.js'); ?>"></script>
    <script src="assets/js/liquid-glass.js?v=<?php echo (int)@filemtime(__DIR__ . '/../assets/js/liquid-glass.js'); ?>" defer></script>
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
    window.LANG_SEARCH_CLIENT = '<?php echo __("search_client_placeholder"); ?>';
    window.LANG_BRAND = '<?php echo __("brand"); ?>';
    window.LANG_MODEL = '<?php echo __("model_placeholder"); ?>';
    window.LANG_SCAN_NOT_FOUND = '<?php echo __("scan_not_found"); ?>';
    window.LANG_SCAN_CAMERA_ERROR = '<?php echo __("scan_camera_error"); ?>';
    </script>
</head>
<body>
<?php require __DIR__ . '/liquid_glass_svg.php'; ?>

<div id="sidebar" class="crm-v2-sidebar">
    <div class="sidebar-brand">
        <img src="assets/img/logo-black.png" alt="AppleFix" class="sidebar-logo">
        <div class="sidebar-brand-kicker">Fix · CRM v2.0</div>
    </div>

    <nav class="nav flex-column crm-v2-nav">
        <a class="nav-link <?php echo $current_page == 'index.php' ? 'active' : ''; ?>" href="index.php"><i class="fas fa-home me-2"></i><span><?php echo __('dashboard'); ?></span></a>

        <div class="crm-nav-group-title"><?php echo __('nav_work'); ?></div>
        <a class="nav-link crm-nav-new-order" href="#" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus me-2"></i><span><?php echo __('new_order'); ?></span></a>
        <a class="nav-link crm-nav-new-complaint" href="#" data-bs-toggle="modal" data-bs-target="#newComplaintModal"><i class="fas fa-rotate-left me-2"></i><span>Nová reklamace</span></a>
        <a class="nav-link <?php echo $current_page == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
            <i class="fas fa-tools me-2"></i><span><?php echo __('orders'); ?></span>
            <?php if ($ordersBadgeCount > 0): ?><span class="crm-nav-pill"><?php echo $ordersBadgeCount; ?></span><?php endif; ?>
        </a>
        <a class="nav-link <?php echo $current_page == 'reklamace.php' ? 'active' : ''; ?>" href="reklamace.php"><i class="fas fa-rotate-left me-2"></i><span>Reklamace</span></a>
        <a class="nav-link <?php echo $current_page == 'procurement.php' ? 'active' : ''; ?>" href="procurement.php"><i class="fas fa-truck-loading me-2"></i><span><?php echo __('procurement'); ?></span></a>

        <div class="crm-nav-group-title"><?php echo __('nav_database'); ?></div>
        <?php if (hasPermission('edit_customers')): ?>
            <a class="nav-link <?php echo $current_page == 'customers.php' ? 'active' : ''; ?>" href="customers.php"><i class="fas fa-users me-2"></i><span><?php echo __('customers'); ?></span></a>
        <?php endif; ?>
        <?php if (hasPermission('manage_inventory')): ?>
            <a class="nav-link <?php echo $current_page == 'inventory.php' ? 'active' : ''; ?>" href="inventory.php"><i class="fas fa-boxes me-2"></i><span><?php echo __('inventory'); ?></span></a>
        <?php endif; ?>

        <div class="crm-nav-group-title"><?php echo __('nav_system'); ?></div>
        <a class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php"><i class="fas fa-chart-line me-2"></i><span><?php echo __('reports'); ?></span></a>
        <?php if (hasPermission('admin_access') || getCurrentStaffRole() === 'manager'): ?>
            <a class="nav-link <?php echo $current_page == 'accounting.php' ? 'active' : ''; ?>" href="accounting.php"><i class="fas fa-file-invoice-dollar me-2"></i><span><?php echo __('accounting'); ?></span></a>
            <a class="nav-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>" href="settings.php"><i class="fas fa-cog me-2"></i><span><?php echo __('settings'); ?></span></a>
            <a class="nav-link" href="fixer_chat.php"><i class="fab fa-telegram-plane me-2"></i><span><?php echo __('fixer_chat'); ?></span></a>
        <?php else: ?>
            <a class="nav-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>" href="settings.php"><i class="fas fa-user-circle me-2"></i><span><?php echo __('settings'); ?></span></a>
        <?php endif; ?>
    </nav>

    <div class="crm-v2-divider"></div>
    <div class="crm-v2-user">
        <div class="crm-v2-avatar"><?php
            $fn = trim((string)($_SESSION['full_name'] ?? 'U'));
            $fp = array_values(array_filter(explode(' ', $fn)));
            echo mb_strtoupper(count($fp) >= 2 ? mb_substr($fp[0],0,1).mb_substr($fp[1],0,1) : mb_substr($fn,0,2));
        ?></div>
        <div class="crm-v2-user-meta">
            <div class="crm-v2-user-name"><?php echo e($_SESSION['full_name'] ?? __('technician')); ?></div>
            <div class="crm-v2-user-role"><?php echo e($roleLabel ?: __('role_employee')); ?></div>
        </div>
        <span class="crm-v2-online-dot"></span>
    </div>
</div>
<div id="sidebarBackdrop" class="sidebar-backdrop" aria-hidden="true"></div>

<div id="content" class="crm-v2-content">
    <nav class="navbar navbar-expand-lg navbar-dark crm-topbar crm-v2-topbar">
        <div class="container-fluid d-flex align-items-center justify-content-between crm-topbar-inner">
            <div class="d-flex align-items-center crm-topbar-left">
                <button class="btn btn-sm btn-outline-secondary me-3 d-lg-none" id="sidebarCollapse" aria-label="<?php echo e(__('toggle_menu')); ?>">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="navbar-brand mb-0 h1 d-none d-sm-inline-block"><?php echo e($topbarTitle); ?></span>
            </div>

            <div class="d-flex align-items-center crm-topbar-center">
                <div class="d-flex align-items-center me-2 crm-topbar-cta-wrap">
                    <button type="button" class="btn btn-sm btn-primary crm-v2-cta-new-order crm-v2-cta-new-order--big" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus me-1"></i><?php echo __('new_order'); ?></button>
                </div>

                <?php if ($show_search): ?>
                <form action="<?php echo $search_action; ?>" method="GET" class="d-flex crm-navbar-search crm-v2-search">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="<?php echo e($search_placeholder); ?>" value="<?php echo e($_GET['search'] ?? ''); ?>">
                        <span class="input-group-text crm-kbd-hint">⌘K</span>
                    </div>
                </form>
                <?php else: ?>
                    <div class="crm-navbar-search crm-navbar-search--placeholder"></div>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-center gap-2 crm-navbar-actions">
                <button class="btn btn-sm crm-v2-icon-btn" type="button" id="scanOrderBtn" title="<?php echo e(__('scan_order_title')); ?>" aria-label="<?php echo e(__('scan_order_title')); ?>">
                    <i class="fas fa-qrcode"></i>
                </button>
                <button class="btn btn-sm crm-v2-icon-btn" type="button" id="notificationsToggle" aria-label="<?php echo e(__('notifications')); ?>">
                    <i class="fas fa-bell"></i>
                    <span class="crm-v2-alert-dot"></span>
                </button>
                <button class="btn btn-sm crm-v2-icon-btn lg-theme-toggle" type="button" title="Light / Dark" aria-label="Light / Dark">
                    <i class="fas fa-sun"></i>
                </button>


                <div class="dropdown me-1">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle crm-lang-switch" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo e(__('language_switch')); ?>">
                        <i class="fas fa-language me-1"></i><?php echo strtoupper($currentLang); ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item <?php echo $currentLang === 'cs' ? 'active' : ''; ?>" href="set_language.php?lang=cs&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_cs'); ?> (CS)</a></li>
                        <li><a class="dropdown-item <?php echo $currentLang === 'en' ? 'active' : ''; ?>" href="set_language.php?lang=en&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_en'); ?> (EN)</a></li>
                        <li><a class="dropdown-item <?php echo $currentLang === 'ru' ? 'active' : ''; ?>" href="set_language.php?lang=ru&amp;redirect=<?php echo rawurlencode($langRedirect); ?>"><?php echo __('lang_ru'); ?> (RU)</a></li>
                    </ul>
                </div>

                <a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="fas fa-sign-out-alt"></i><span class="ms-1 d-none d-sm-inline"><?php echo __('logout'); ?></span></a>
            </div>
        </div>
    </nav>
    <div class="crm-main-content">
