<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

ensureTechnicianTelegramSchema();

if (!function_exists('settingsDebugLog')) {
    function settingsDebugLog(array $data): void {
        @file_put_contents(__DIR__ . '/temp/settings-debug.log', json_encode($data, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
    }
}

// Processing before header to avoid header already sent
$is_admin_check = (($_SESSION['role'] ?? '') == 'admin') || (hasPermission('admin_access'));

if (isset($_POST['set_lang']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $chosenLang = crm_normalize_language((string)($_POST['lang'] ?? ''));
    if ($chosenLang) {
        crm_set_language($chosenLang);
        set_setting('language', $chosenLang);
    }
    header("Location: settings.php?tab=system");
    exit;
}

if (isset($pdo) && function_exists('ensurePickupReadyColumns')) { ensurePickupReadyColumns($pdo); }

if (isset($_POST['update_branches']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    if (function_exists('ensurePickupReadyColumns')) { ensurePickupReadyColumns($pdo); }
    foreach ((array)($_POST['branch_address'] ?? []) as $bid => $addr) {
        $bid = (int)$bid;
        if ($bid <= 0) continue;
        $hrs = trim((string)($_POST['branch_hours'][$bid] ?? ''));
        $pdo->prepare("UPDATE branches SET address = ?, opening_hours = ? WHERE id = ?")
            ->execute([trim((string)$addr), $hrs, $bid]);
    }
    header("Location: settings.php?tab=company&updated=1");
    exit;
}

if (isset($_POST['update_company']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    set_setting('company_name', $_POST['company_name']);
    set_setting('company_address', $_POST['company_address']);
    set_setting('company_phone', $_POST['company_phone']);
    set_setting('company_ico', trim($_POST['company_ico'] ?? ''));
    set_setting('company_dic', trim($_POST['company_dic'] ?? ''));
    set_setting('company_email', trim($_POST['company_email'] ?? ''));
    set_setting('company_web', trim($_POST['company_web'] ?? ''));
    set_setting('currency', $_POST['currency']);
    header("Location: settings.php?tab=company&updated=1");
    exit;
}

if (isset($_POST['update_integrations']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    set_setting('tg_bot_token', trim($_POST['tg_bot_token']));
    set_setting('fixer_webhook_url', trim($_POST['fixer_webhook_url'] ?? ''));
    set_setting('fixer_webhook_secret', trim($_POST['fixer_webhook_secret'] ?? ''));
    set_setting('fixer_api_token', trim($_POST['fixer_api_token'] ?? ''));
    set_setting('ai_provider', $_POST['ai_provider']);
    set_setting('ai_api_key', trim($_POST['ai_api_key']));
    set_setting('ai_model', $_POST['ai_model']);
    set_setting('ifreeicloud_api_key', trim($_POST['ifreeicloud_api_key'] ?? ''));
    set_setting('ifreeicloud_service_id', (string) intval($_POST['ifreeicloud_service_id'] ?? 0));

    // ── SMTP (odesílání zakázkových listů klientům e-mailem) ──
    set_setting('smtp_host', trim($_POST['smtp_host'] ?? ''));
    set_setting('smtp_port', (string) intval($_POST['smtp_port'] ?? 587));
    set_setting('smtp_secure', in_array($_POST['smtp_secure'] ?? '', ['tls','ssl','none'], true) ? $_POST['smtp_secure'] : 'tls');
    set_setting('smtp_user', trim($_POST['smtp_user'] ?? ''));
    if (trim((string)($_POST['smtp_pass'] ?? '')) !== '') {   // prázdné = nechat stávající heslo
        set_setting('smtp_pass', trim($_POST['smtp_pass']));
    }
    set_setting('smtp_from_email', trim($_POST['smtp_from_email'] ?? ''));
    set_setting('smtp_from_name', trim($_POST['smtp_from_name'] ?? ''));

    // ── Firemní kalendář (CalDAV) — rezervace z webu ──
    set_setting('caldav_booking_enabled', isset($_POST['caldav_booking_enabled']) ? '1' : '0');
    set_setting('caldav_booking_calendar_url', trim($_POST['caldav_booking_calendar_url'] ?? ''));
    set_setting('caldav_booking_user', trim($_POST['caldav_booking_user'] ?? ''));
    if (trim((string)($_POST['caldav_booking_pass'] ?? '')) !== '') {
        set_setting('caldav_booking_pass', trim($_POST['caldav_booking_pass']));
    }
    set_setting('caldav_booking_duration_minutes', (string) max(5, (int)($_POST['caldav_booking_duration_minutes'] ?? 30)));

    if (!empty($_POST['tg_bot_token'])) {
        $token = trim($_POST['tg_bot_token']);
        $webhook_url = rtrim((string)get_setting("fixer_webhook_url", "https://admin.applefix.cloud"), "/") . "/tg_webhook.php";
        $api_url = "https://api.telegram.org/bot" . $token . "/setWebhook?url=" . urlencode($webhook_url) . "&drop_pending_updates=true";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_exec($ch);
        curl_close($ch);
    }
    header("Location: settings.php?tab=integrations&updated=1");
    exit;
}

if (isset($_POST['add_tech']) && $is_admin_check) {
    settingsDebugLog(['event' => 'add_tech_hit', 'post' => $_POST, 'time' => date('c')]);
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $name = $_POST['tech_name'];
    $email = $_POST['tech_email'] ?? '';
    $phone = $_POST['tech_phone'] ?? '';
    $spec = $_POST['tech_spec'];
    $role = $_POST['role'] ?? 'engineer';
    $branch_id = filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: getDefaultBranchId();
    $telegramContact = parseTelegramContactInput($_POST['tech_tg'] ?? '');
    if (!$telegramContact['valid']) {
        header("Location: settings.php?tab=staff&error=telegram_contact_invalid");
        exit;
    }
    $username = trim($_POST['tech_username'] ?? '');
    $password = $_POST['tech_password'] ?? '';

    if (!empty($username)) {
        $stmt = $pdo->prepare("SELECT id FROM technicians WHERE username = ? UNION SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username, $username]);
        if ($stmt->fetch()) { header("Location: settings.php?tab=staff&error=username_taken"); exit; }
    }
    $username_val = !empty($username) ? $username : null;
    $hashed_password = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : '';
    $stmt = $pdo->prepare("INSERT INTO technicians (name, email, phone, specialization, role, branch_id, telegram_id, telegram_username, username, password) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $phone, $spec, $role, $branch_id, $telegramContact['id'], $telegramContact['username'], $username_val, $hashed_password]);
    header("Location: settings.php?tab=staff&tech_added=1");
    exit;
}

if (isset($_POST['edit_tech'])) {
    settingsDebugLog(['event' => 'edit_tech_hit', 'post' => $_POST, 'time' => date('c')]);
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $id = $_POST['tech_id'];
    
    // Security check: technicians can only edit themselves
    if (!$is_admin_check && $id != ($_SESSION['tech_id'] ?? 0)) {
        header("Location: settings.php?tab=staff&error=unauthorized");
        exit;
    }

    $name = $_POST['tech_name'];
    $email = $_POST['tech_email'] ?? '';
    $phone = $_POST['tech_phone'] ?? '';
    $spec = $_POST['tech_spec'];
    $role = $_POST['role'] ?? 'engineer';
    $branch_id = filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: getDefaultBranchId();
    $telegramContact = parseTelegramContactInput($_POST['tech_tg'] ?? '');
    if (!$telegramContact['valid']) {
        header("Location: settings.php?tab=staff&error=telegram_contact_invalid");
        exit;
    }
    $active = isset($_POST['is_active']) ? 1 : 0;
    $username = trim($_POST['tech_username'] ?? '');
    $password = $_POST['tech_password'] ?? '';
    $engineer_rate = floatval($_POST['engineer_rate'] ?? 0);

    // Re-verify important fields if NOT admin
    if (!$is_admin_check) {
        $stmt = $pdo->prepare("SELECT role, branch_id, is_active, username, engineer_rate, name, specialization, email, phone, telegram_id, telegram_username FROM technicians WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch();
        $role = $current['role'];
        $branch_id = (int)($current['branch_id'] ?? getDefaultBranchId());
        $active = $current['is_active'];
        $username = $current['username'];
        $engineer_rate = $current['engineer_rate'];
        $name = $current['name'];
        $spec = $current['specialization'];

        // Non-admin users may update only their own contact fields
        $email = $_POST['tech_email'] ?? ($current['email'] ?? '');
        $phone = $_POST['tech_phone'] ?? ($current['phone'] ?? '');
        if (empty($_POST['tech_tg'])) {
            $telegramContact = [
                'id' => $current['telegram_id'] ?? null,
                'username' => $current['telegram_username'] ?? null,
                'valid' => true,
            ];
        }
    }

    if (!empty($username) && $is_admin_check) { // Only admin can change username or check it
        $stmt = $pdo->prepare("SELECT id FROM technicians WHERE username = ? AND id != ?");
        $stmt->execute([$username, $id]);
        if ($stmt->fetch()) { header("Location: settings.php?tab=staff&error=username_taken"); exit; }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) { header("Location: settings.php?tab=staff&error=username_taken"); exit; }
    }
    
    $username_val = !empty($username) ? $username : null;
    if (!empty($password)) {
        $sql = "UPDATE technicians SET name = ?, email = ?, phone = ?, specialization = ?, role = ?, branch_id = ?, telegram_id = ?, telegram_username = ?, is_active = ?, username = ?, password = ?, engineer_rate = ? WHERE id = ?";
        $params = [$name, $email, $phone, $spec, $role, $branch_id, $telegramContact['id'], $telegramContact['username'], $active, $username_val, password_hash($password, PASSWORD_DEFAULT), $engineer_rate, $id];
    } else {
        $sql = "UPDATE technicians SET name = ?, email = ?, phone = ?, specialization = ?, role = ?, branch_id = ?, telegram_id = ?, telegram_username = ?, is_active = ?, username = ?, engineer_rate = ? WHERE id = ?";
        $params = [$name, $email, $phone, $spec, $role, $branch_id, $telegramContact['id'], $telegramContact['username'], $active, $username_val, $engineer_rate, $id];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($is_admin_check) {
        $pdo->prepare('UPDATE orders SET branch_id = ? WHERE technician_id = ?')->execute([$branch_id, $id]);
    }
    settingsDebugLog([
        'event' => 'edit_tech_saved',
        'tech_id' => $id,
        'email' => $email,
        'phone' => $phone,
        'telegram_id' => $telegramContact['id'] ?? null,
        'telegram_username' => $telegramContact['username'] ?? null,
        'role' => $role,
        'branch_id' => $branch_id,
        'username' => $username_val,
        'time' => date('c')
    ]);
    header("Location: settings.php?tab=staff&updated=1");
    exit;
}

if (isset($_POST['delete_tech']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        header("Location: settings.php?tab=staff&error=csrf");
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM technicians WHERE id = ?");
    $stmt->execute([$_POST['delete_tech']]);
    header("Location: settings.php?tab=staff");
    exit;
}

if (isset($_POST['save_permissions']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    setTechPermissions($_POST['tech_id'], $_POST['permissions'] ?? []);
    header("Location: settings.php?tab=staff&perms_updated=1");
    exit;
}

if (isset($_POST['change_admin_password']) && hasPermission('manage_passwords')) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    if (strlen($_POST['new_password']) >= 8) {
        $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $_POST['admin_id']]);
        
        if ($_POST['admin_id'] == $_SESSION['user_id'] && $_SESSION['role'] === 'admin') {
            session_destroy();
            header("Location: login.php");
            exit;
        }
        
        header("Location: settings.php?tab=admins&admin_pwd_updated=1");
    } else { header("Location: settings.php?tab=admins&error=short_password"); }
    exit;
}

// Povýšení stávajícího zaměstnance na administrátora (vytvoří adminský login v users)
if (isset($_POST['promote_staff_admin']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $techId = (int)($_POST['staff_tech_id'] ?? 0);
    $login  = trim((string)($_POST['staff_admin_username'] ?? ''));
    $pwd    = (string)($_POST['staff_admin_password'] ?? '');

    if ($techId <= 0 || $login === '') {
        header("Location: settings.php?tab=admins&error=promote_missing"); exit;
    }
    if (strlen($pwd) < 8) {
        header("Location: settings.php?tab=admins&error=short_password"); exit;
    }
    $stmt = $pdo->prepare("SELECT id, name FROM technicians WHERE id = ?");
    $stmt->execute([$techId]);
    $staffRow = $stmt->fetch();
    if (!$staffRow) { header("Location: settings.php?tab=admins&error=promote_missing"); exit; }

    // login nesmí kolidovat s existujícím adminem (technik smí mít stejný — rozliší je heslo)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$login]);
    if ($stmt->fetch()) { header("Location: settings.php?tab=admins&error=admin_exists"); exit; }

    $pdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'admin')")
        ->execute([$login, password_hash($pwd, PASSWORD_DEFAULT), (string)$staffRow['name']]);
    header("Location: settings.php?tab=admins&admin_added=1");
    exit;
}

if (isset($_POST['clear_logs']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    try { $pdo->query("DELETE FROM system_errors"); } catch (Exception $e) {}
    header("Location: settings.php?tab=system&logs_cleared=1");
    exit;
}

if (isset($_POST['update_system_settings']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $templates = trim($_POST['order_templates'] ?? '');
    $note_templates = trim($_POST['order_note_templates'] ?? '');
    $sla_new = max(0, (int)($_POST['sla_new_hours'] ?? 24));
    $sla_progress = max(0, (int)($_POST['sla_progress_hours'] ?? 72));
    set_setting('order_templates', $templates);
    set_setting('order_note_templates', $note_templates);
    set_setting('sla_new_hours', $sla_new);
    set_setting('sla_progress_hours', $sla_progress);
    header("Location: settings.php?tab=system&updated=1");
    exit;
}

$is_admin_user = hasPermission('admin_access');
$can_view_all_staff = $is_admin_user || getCurrentStaffRole() === 'manager' || hasPermission('view_reports_all');

$active_tab = $_GET['tab'] ?? ($is_admin_user ? 'company' : 'staff');

// Uvítací zvuky při přihlášení (admin)
if (isset($_POST['upload_greeting']) && hasPermission('admin_access')) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { header('Location: settings.php?tab=staff&error=csrf'); exit; }
    $gUser = preg_replace('/[^a-zA-Z0-9._-]/', '_', trim((string)($_POST['greeting_username'] ?? '')));
    $gDir = __DIR__ . '/uploads/greetings/';
    if ($gUser !== '' && !empty($_FILES['greeting_file']['tmp_name']) && is_uploaded_file($_FILES['greeting_file']['tmp_name'])) {
        $ext = strtolower(pathinfo((string)$_FILES['greeting_file']['name'], PATHINFO_EXTENSION));
        $allowed = ['mp3', 'm4a', 'wav', 'ogg'];
        if (in_array($ext, $allowed, true) && (int)$_FILES['greeting_file']['size'] <= 3 * 1024 * 1024) {
            if (!is_dir($gDir)) { mkdir($gDir, 0755, true); }
            foreach ($allowed as $e) { @unlink($gDir . $gUser . '.' . $e); }
            move_uploaded_file($_FILES['greeting_file']['tmp_name'], $gDir . $gUser . '.' . $ext);
            header('Location: settings.php?tab=staff&greeting_updated=1'); exit;
        }
    }
    header('Location: settings.php?tab=staff&error=greeting_invalid'); exit;
}
if (isset($_POST['delete_greeting']) && hasPermission('admin_access')) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { header('Location: settings.php?tab=staff&error=csrf'); exit; }
    $gUser = preg_replace('/[^a-zA-Z0-9._-]/', '_', trim((string)($_POST['greeting_username'] ?? '')));
    foreach (['mp3', 'm4a', 'wav', 'ogg'] as $e) { @unlink(__DIR__ . '/uploads/greetings/' . $gUser . '.' . $e); }
    header('Location: settings.php?tab=staff&greeting_updated=1'); exit;
}

// Security for technicians
if (!$is_admin_user) {
    if ($active_tab == 'company' || $active_tab == 'integrations' || $active_tab == 'system' || $active_tab == 'admins' || $active_tab == 'updates') {
        $active_tab = 'staff';
    }
}
require_once 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary pb-3">
        <h2 class="mb-0 text-white"><i class="fas fa-cog me-3 text-primary"></i><?php echo __('settings'); ?></h2>
        <?php if (isset($_GET['updated'])): ?>
            <span class="badge bg-success-glow"><?php echo __('updated_success'); ?></span>
        <?php endif; ?>
    </div>

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-warning border-warning mb-4">
            <?php
                $settingsError = (string)$_GET['error'];
                $settingsErrorMessages = [
                    'telegram_id_must_be_numeric' => 'Telegram must be entered as a numeric Telegram ID, not @username.',
                    'telegram_contact_invalid' => 'Enter Telegram as numeric ID or @username.',
                    'username_taken' => 'This username already exists.',
                    'unauthorized' => 'You do not have permission for this edit.',
                    'short_password' => 'Password is too short.',
                    'csrf' => 'Form expired, please refresh the page.'
                ];
                echo htmlspecialchars($settingsErrorMessages[$settingsError] ?? $settingsError, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            ?>
        </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <ul class="nav nav-pills mb-4 glass-panel p-2 border-secondary" id="settingsTabs">
        <?php if ($is_admin_user): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'company' ? 'active' : 'text-white-75'; ?>" href="?tab=company"><i class="fas fa-building me-2"></i><?php echo __('company_data'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'integrations' ? 'active' : 'text-white-75'; ?>" href="?tab=integrations"><i class="fas fa-plug me-2"></i><?php echo __('integrations_tab'); ?></a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'staff' ? 'active' : 'text-white-75'; ?>" href="?tab=staff"><i class="fas fa-users me-2"></i><?php echo __('staff_tab'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'tisk' ? 'active' : 'text-white-75'; ?>" href="?tab=tisk"><i class="fas fa-print me-2"></i><?php echo __('label_bridge_tab'); ?></a>
        </li>
        <?php if ($is_admin_user): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'system' ? 'active' : 'text-white-75'; ?>" href="?tab=system"><i class="fas fa-server me-2"></i><?php echo __('system_db'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'admins' ? 'active' : 'text-white-75'; ?>" href="?tab=admins"><i class="fas fa-user-shield me-2"></i><?php echo __('admin_tab'); ?></a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'updates' ? 'active' : 'text-white-75'; ?>" href="?tab=updates" id="updatesNavLink"><i class="fas fa-cloud-download-alt me-2"></i><?php echo __('updates_tab'); ?> <span id="updateBadgeNav" class="badge bg-warning text-dark ms-1" style="display:none;">!</span></a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="glass-panel p-4 mb-4 tab-content">
        
        <!-- COMPANY DATA TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'company' ? 'show active' : ''; ?>">
            <div class="row">
                <div class="col-md-8">
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label text-white-75 small"><?php echo __('company_name'); ?></label>
                                <input type="text" name="company_name" class="form-control" value="<?php echo htmlspecialchars(get_setting('company_name')); ?>">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label text-white-75 small"><?php echo __('company_address'); ?></label>
                                <textarea name="company_address" class="form-control" rows="3"><?php echo htmlspecialchars(get_setting('company_address')); ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small"><?php echo __('company_phone'); ?></label>
                                <input type="text" name="company_phone" class="form-control" value="<?php echo htmlspecialchars(get_setting('company_phone')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small"><?php echo __('currency'); ?></label>
                                <input type="text" name="currency" class="form-control" value="<?php echo htmlspecialchars(get_setting('currency')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small">IČO</label>
                                <input type="text" name="company_ico" class="form-control" placeholder="24588571" value="<?php echo htmlspecialchars(get_setting('company_ico')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small">DIČ</label>
                                <input type="text" name="company_dic" class="form-control" placeholder="CZ24588571 (neplátce nechte prázdné)" value="<?php echo htmlspecialchars(get_setting('company_dic')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small">E-mail firmy</label>
                                <input type="text" name="company_email" class="form-control" placeholder="info@applefix.cz" value="<?php echo htmlspecialchars(get_setting('company_email')); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small">Web</label>
                                <input type="text" name="company_web" class="form-control" placeholder="www.applefix.cz" value="<?php echo htmlspecialchars(get_setting('company_web')); ?>">
                            </div>
                            <div class="col-12 mt-4 pt-3 border-top border-secondary">
                                <button type="submit" name="update_company" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                            </div>
                        </div>
                    </form>

                    <form method="POST" class="mt-5 pt-4 border-top border-secondary">
                        <?php echo csrfField(); ?>
                        <h6 class="text-white mb-1"><i class="fas fa-store me-2 text-info"></i>Pobočky — adresa a otevírací doba</h6>
                        <div class="small text-white-50 mb-3">Zobrazuje se klientovi v e-mailu „Připraveno k vyzvednutí".</div>
                        <?php
                        $branchesEdit = [];
                        try { $branchesEdit = $pdo->query("SELECT id, name, address, COALESCE(opening_hours,'') AS opening_hours FROM branches WHERE is_active = 1 ORDER BY id")->fetchAll(); } catch (Throwable $e) { $branchesEdit = []; }
                        foreach ($branchesEdit as $b): ?>
                            <div class="glass-panel p-3 mb-3 border-secondary">
                                <div class="fw-semibold text-white mb-2"><i class="fas fa-location-dot me-2 text-info"></i><?php echo htmlspecialchars($b['name']); ?></div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label text-white-75 small">Adresa pobočky</label>
                                        <textarea name="branch_address[<?php echo (int)$b['id']; ?>]" class="form-control" rows="3" placeholder="Ulice 123&#10;186 00 Praha 8 – Karlín"><?php echo htmlspecialchars($b['address']); ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label text-white-75 small">Otevírací doba</label>
                                        <textarea name="branch_hours[<?php echo (int)$b['id']; ?>]" class="form-control" rows="3" placeholder="Po–Pá 10:00–19:00&#10;So 10:00–14:00&#10;Ne zavřeno"><?php echo htmlspecialchars($b['opening_hours']); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" name="update_branches" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                    </form>
                </div>
            </div>
        </div>

        <!-- INTEGRATIONS TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'integrations' ? 'show active' : ''; ?>">
            <form method="POST">
                <?php echo csrfField(); ?>
                <div class="row g-4 settings-integrations-row">
                    <div class="col-md-6 border-end border-secondary">
                        <h5 class="mb-3 text-info"><i class="fab fa-telegram-plane me-2"></i>Telegram Bot</h5>
                        <div class="mb-3">
                            <label class="form-label small text-white-75">API Bot Token</label>
                            <input type="password" name="tg_bot_token" class="form-control" value="<?php echo htmlspecialchars(get_setting('tg_bot_token')); ?>">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-12"><label class="form-label small text-white-75">Fixer webhook URL</label><input type="text" name="fixer_webhook_url" class="form-control" value="<?php echo htmlspecialchars(get_setting('fixer_webhook_url', '')); ?>" placeholder="https://your-domain.com"></div>
                            <div class="col-12"><label class="form-label small text-white-75">Fixer webhook secret</label><input type="text" name="fixer_webhook_secret" class="form-control" value="<?php echo htmlspecialchars(get_setting('fixer_webhook_secret', '')); ?>" placeholder="random-secret"></div>
                            <div class="col-12"><label class="form-label small text-white-75">Fixer API token</label><input type="text" name="fixer_api_token" class="form-control" value="<?php echo htmlspecialchars(get_setting('fixer_api_token', '')); ?>" placeholder="another-random-secret"></div>
                        </div>
                        <div class="glass-panel webhook-status-panel p-3 border-secondary mb-3">
                            <h6 class="small fw-bold mb-2 text-white"><?php echo __('webhook_status'); ?></h6>
                            <?php 
                            $current_token = get_setting('tg_bot_token');
                            if (!empty($current_token)) {
                                $api_url = "https://api.telegram.org/bot" . $current_token . "/getWebhookInfo";
                                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $api_url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); $webhook_info = curl_exec($ch); curl_close($ch);
                                $info = json_decode($webhook_info, true);
                                if ($info && $info['ok']) {
                                    $url = $info['result']['url'] ?: __('not_set');
                                    echo '<div class="small text-break text-white-75"><strong>URL:</strong> ' . htmlspecialchars($url) . '</div>';
                                } else { echo '<div class="small text-danger">'.__('token_invalid').'</div>'; }
                            } else { echo '<div class="small text-muted">'.__('token_not_set').'</div>'; }
                            ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h5 class="mb-3 text-primary"><i class="fas fa-robot me-2"></i><?php echo __('ai_integration'); ?></h5>
                        <div class="mb-3">
                            <label class="form-label small text-white-75"><?php echo __('provider'); ?></label>
                            <select name="ai_provider" class="form-select">
                                <option value="openrouter" <?php echo get_setting('ai_provider') == 'openrouter' ? 'selected' : ''; ?>>OpenRouter</option>
                                <option value="openai" <?php echo get_setting('ai_provider') == 'openai' ? 'selected' : ''; ?>>OpenAI API</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-white-75">API Key</label>
                            <input type="password" name="ai_api_key" class="form-control" value="<?php echo htmlspecialchars(get_setting('ai_api_key')); ?>" placeholder="sk-...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-white-75">AI Model</label>
                            <input type="text" name="ai_model" class="form-control" value="<?php echo htmlspecialchars(get_setting('ai_model', 'google/gemini-2.0-flash-001')); ?>">
                        </div>
                        <div class="mb-3 pt-2 border-top border-secondary">
                            <h6 class="small fw-bold text-info mb-3"><i class="fas fa-sim-card me-2"></i>iFreeiCloud IMEI API</h6>
                            <label class="form-label small text-white-75">API Key</label>
                            <input type="password" name="ifreeicloud_api_key" class="form-control mb-3" value="<?php echo htmlspecialchars(get_setting_with_fallback('ifreeicloud_api_key', IFREEICLOUD_API_KEY_FALLBACK, 'IFREEICLOUD_API_KEY')); ?>" placeholder="83L-...">
                            <label class="form-label small text-white-75">Service ID z iFreeiCloud dashboardu</label>
                            <input type="number" name="ifreeicloud_service_id" class="form-control" value="<?php echo htmlspecialchars(get_setting_with_fallback('ifreeicloud_service_id', (string) IFREEICLOUD_SERVICE_ID_FALLBACK, 'IFREEICLOUD_SERVICE_ID')); ?>" min="0" step="1">
                            <div class="form-text small text-white-75 mt-2">Used as secondary verification below the Police DB result. Enter the real service ID for the selected iFreeiCloud check (e.g. FMI / model / serial from dashboard).</div>
                        </div>
                        <div class="form-text small text-white-75"><?php echo __('ai_hint'); ?></div>
                    </div>

                    <div class="mb-3 pt-3 border-top border-secondary">
                        <h5 class="mb-3 text-info"><i class="fas fa-envelope me-2"></i>E-mail (SMTP) — odesílání zakázkových listů klientům</h5>
                        <div class="row g-2">
                            <div class="col-md-8"><label class="form-label small text-white-75">SMTP server (host)</label>
                                <input type="text" name="smtp_host" class="form-control" value="<?php echo htmlspecialchars(get_setting('smtp_host')); ?>" placeholder="smtp.seznam.cz / smtp.gmail.com"></div>
                            <div class="col-md-2"><label class="form-label small text-white-75">Port</label>
                                <input type="number" name="smtp_port" class="form-control" value="<?php echo htmlspecialchars(get_setting('smtp_port', '587')); ?>" placeholder="587"></div>
                            <div class="col-md-2"><label class="form-label small text-white-75">Zabezpečení</label>
                                <?php $sec = get_setting('smtp_secure', 'tls'); ?>
                                <select name="smtp_secure" class="form-select">
                                    <option value="tls" <?php echo $sec==='tls'?'selected':''; ?>>TLS (587)</option>
                                    <option value="ssl" <?php echo $sec==='ssl'?'selected':''; ?>>SSL (465)</option>
                                    <option value="none" <?php echo $sec==='none'?'selected':''; ?>>Žádné</option>
                                </select></div>
                            <div class="col-md-6"><label class="form-label small text-white-75">Uživatel (login)</label>
                                <input type="text" name="smtp_user" class="form-control" value="<?php echo htmlspecialchars(get_setting('smtp_user')); ?>" placeholder="servis@applefix.cz" autocomplete="off"></div>
                            <div class="col-md-6"><label class="form-label small text-white-75">Heslo</label>
                                <input type="password" name="smtp_pass" class="form-control" value="" placeholder="<?php echo get_setting('smtp_pass') ? '•••••••• (uloženo — nech prázdné pro zachování)' : 'heslo k e-mailu'; ?>" autocomplete="new-password"></div>
                            <div class="col-md-6"><label class="form-label small text-white-75">Odesílatel — e-mail</label>
                                <input type="email" name="smtp_from_email" class="form-control" value="<?php echo htmlspecialchars(get_setting('smtp_from_email')); ?>" placeholder="servis@applefix.cz"></div>
                            <div class="col-md-6"><label class="form-label small text-white-75">Odesílatel — jméno</label>
                                <input type="text" name="smtp_from_name" class="form-control" value="<?php echo htmlspecialchars(get_setting('smtp_from_name', get_setting('company_name', 'AppleFix'))); ?>" placeholder="AppleFix servis"></div>
                        </div>
                        <div class="form-text small text-white-75 mt-2">
                            Heslo se ukládá jen do vaší databáze a v poli se nikdy nezobrazuje. Po uložení lze e-mail otestovat tlačítkem u kterékoli zakázky.
                        </div>
                    </div>

                    <div class="col-12 border-top border-secondary pt-3">
                        <?php ensureWebBookingsSchema(); $afxWbKey = get_setting('web_booking_key', ''); ?>
                        <h5 class="mb-3 text-info"><i class="fas fa-globe me-2"></i>Rezervace z webu (applefix.cz — RepairPlugin)</h5>
                        <div class="row g-2">
                            <div class="col-md-7"><label class="form-label small text-white-75">Webhook URL (kam WordPress posílá rezervace)</label>
                                <input type="text" class="form-control" readonly onclick="this.select()" value="https://admin.applefix.cloud/api/website_booking.php"></div>
                            <div class="col-md-5"><label class="form-label small text-white-75">Sdílený klíč (X-AFX-KEY)</label>
                                <input type="text" class="form-control" readonly onclick="this.select()" value="<?php echo htmlspecialchars($afxWbKey); ?>"></div>
                            <div class="col-12"><label class="form-label small text-white-75">URL pro RepairPlugin „Trigger Webhooks" (s klíčem — vložit do pole URL ve WordPressu)</label>
                                <input type="text" class="form-control" readonly onclick="this.select()" value="https://admin.applefix.cloud/api/website_booking.php?key=<?php echo htmlspecialchars($afxWbKey); ?>"></div>
                        </div>
                        <div class="form-text small text-white-75 mt-2">
                            Nové rezervace se objevují nahoře v Zakázkách, seřazené dle termínu. Tlačítko „Vytvořit zakázku" předvyplní wizard a rezervaci označí jako převzatou.
                        </div>
                        <?php $afxWbLast = (string)get_setting('web_booking_last_payload', ''); ?>
                        <details class="mt-2">
                            <summary class="small text-white-75" style="cursor:pointer;">Diagnostika: poslední přijatý payload z webu <?php echo $afxWbLast === '' ? '(zatím nic nedorazilo)' : ''; ?></summary>
                            <pre class="small text-white-75 mt-2 p-2 border border-secondary rounded" style="max-height:260px; overflow:auto; white-space:pre-wrap;"><?php echo htmlspecialchars($afxWbLast !== '' ? $afxWbLast : '—'); ?></pre>
                        </details>
                    </div>

                    <div class="col-12 border-top border-secondary pt-3">
                        <h5 class="mb-3 text-info"><i class="fas fa-calendar-alt me-2"></i>Firemní kalendář (CalDAV) — rezervace z webu</h5>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="caldav_booking_enabled" id="caldavBookingEnabled" value="1" <?php echo get_setting('caldav_booking_enabled', '0') === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label text-white-75" for="caldavBookingEnabled">Automaticky přidávat nové rezervace oprav do firemního kalendáře</label>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-8"><label class="form-label small text-white-75">CalDAV URL kalendáře</label>
                                <input type="url" name="caldav_booking_calendar_url" class="form-control" value="<?php echo htmlspecialchars(get_setting('caldav_booking_calendar_url', '')); ?>" placeholder="https://applefix.cz/.../calendars/.../servis/"></div>
                            <div class="col-md-4"><label class="form-label small text-white-75">Délka události v minutách</label>
                                <input type="number" name="caldav_booking_duration_minutes" class="form-control" value="<?php echo htmlspecialchars(get_setting('caldav_booking_duration_minutes', '30')); ?>" min="5" step="5"></div>
                            <div class="col-md-6"><label class="form-label small text-white-75">Uživatel</label>
                                <input type="text" name="caldav_booking_user" class="form-control" value="<?php echo htmlspecialchars(get_setting('caldav_booking_user', '')); ?>" placeholder="servis@applefix.cz" autocomplete="off"></div>
                            <div class="col-md-6"><label class="form-label small text-white-75">Heslo / app password</label>
                                <input type="password" name="caldav_booking_pass" class="form-control" value="" placeholder="<?php echo get_setting('caldav_booking_pass') ? '•••••••• (uloženo — nech prázdné pro zachování)' : 'heslo ke CalDAV'; ?>" autocomplete="new-password"></div>
                        </div>
                        <div class="form-text small text-white-75 mt-2">
                            Každá rezervace z RepairPluginu vytvoří nebo aktualizuje jednu událost. Zrušená rezervace se z kalendáře smaže, pokud už byla propsaná.
                        </div>
                    </div>

                    <div class="col-12 border-top border-secondary pt-3 integrations-save-row">
                        <button type="submit" name="update_integrations" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
                    </div>
                </div>
            </form>
        </div>

        <!-- STAFF TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'staff' ? 'show active' : ''; ?>">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><?php echo __('staff_and_techs'); ?></h5>
                <?php if ($is_admin_user): ?>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addTechModal"><i class="fas fa-plus me-1"></i> <?php echo __('add_btn'); ?></button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle border-secondary">
                    <thead class="table-dark"><tr><th><?php echo __('name_col'); ?></th><th><?php echo __('login_col'); ?></th><th><?php echo __('role_col'); ?></th><th>Pobočka</th><th><?php echo __('spec_col'); ?></th><th>Telegram</th><th><?php echo __('status_col'); ?></th><th class="text-end"><?php echo __('actions_col'); ?></th></tr></thead>
                    <tbody>
                        <?php 
                        $techs_query = $can_view_all_staff ? "SELECT * FROM technicians ORDER BY name ASC" : "SELECT * FROM technicians WHERE id = " . intval($_SESSION['tech_id'] ?? 0);
                        $techs = $pdo->query($techs_query)->fetchAll();
                        foreach ($techs as $t): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($t['name']); ?></strong></td>
                            <td>@<?php echo htmlspecialchars($t['username'] ?? '-'); ?></td>
                            <td>
                                <?php 
                                $r = $t['role'] ?? 'engineer';
                                if($r == 'admin') echo '<span class="badge bg-danger">'.__('role_admin').'</span>';
                                elseif($r == 'manager') echo '<span class="badge bg-primary">'.__('role_manager').'</span>';
                                else echo '<span class="badge bg-info-glow">'.__('role_engineer').'</span>';
                                ?>
                            </td>
                            <td><span class="badge bg-dark border border-secondary"><i class="fas fa-store me-1"></i><?php echo e(getBranchLabel((int)($t['branch_id'] ?? 0))); ?></span></td>
                            <td><span class="badge glass-panel text-white border-secondary"><?php echo htmlspecialchars($t['specialization']); ?></span></td>
                            <td>
                                <?php if (!empty($t['telegram_id'])): ?>
                                    <code class="small"><?php echo htmlspecialchars($t['telegram_id']); ?></code>
                                    <button class="btn btn-link btn-sm p-0 ms-1 text-info" title="Тест уведомления" onclick="testTechTG(<?php echo $t['id']; ?>)"><i class="fab fa-telegram-plane"></i></button>
                                <?php else: ?>
                                    <span class="text-muted small"><?php echo __('not_linked'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ($t['is_active'] ?? 1) ? '<span class="badge bg-success">'.__('active_status').'</span>' : '<span class="badge bg-secondary">'.__('inactive_status').'</span>'; ?></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($is_admin_user): ?>
                                        <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#permModal<?php echo $t['id']; ?>"><i class="fas fa-shield-alt"></i></button>
                                    <?php endif; ?>
                                    <?php if ($is_admin_user || (int)$t['id'] === (int)($_SESSION['tech_id'] ?? 0)): ?>
                                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTechModal<?php echo $t['id']; ?>"><i class="fas fa-edit"></i></button>
                                    <?php endif; ?>
                                    <?php if ($is_admin_user): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                            <input type="hidden" name="delete_tech" value="<?php echo $t['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" data-confirm="<?php echo __('delete_confirm'); ?>"><i class="fas fa-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php if ($is_admin_user && $active_tab == 'staff'): ?>
        <?php
            $greetingStaff = [];
            try {
                foreach ($pdo->query('SELECT username, full_name AS name FROM users ORDER BY full_name')->fetchAll() as $gu) { $greetingStaff[] = $gu; }
                foreach ($pdo->query('SELECT username, name FROM technicians WHERE is_active = 1 ORDER BY name')->fetchAll() as $gt) { $greetingStaff[] = $gt; }
            } catch (Throwable $e) { $greetingStaff = []; }
        ?>
        <?php if ($active_tab == 'staff'): ?>
        <div class="glass-panel p-4 border-secondary mt-4" id="greetingSounds">
            <h5 class="mb-1 text-white"><i class="fas fa-volume-high me-2 text-info"></i><?php echo __('greeting_title'); ?></h5>
            <div class="small text-white-75 mb-3"><?php echo __('greeting_desc'); ?></div>
            <?php if (isset($_GET['greeting_updated'])): ?><div class="alert alert-success py-2"><?php echo __('greeting_saved'); ?></div><?php endif; ?>
            <?php if (($_GET['error'] ?? '') === 'greeting_invalid'): ?><div class="alert alert-danger py-2"><?php echo __('greeting_invalid'); ?></div><?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead><tr><th><?php echo __('presence_employee'); ?></th><th><?php echo __('greeting_state'); ?></th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php foreach ($greetingStaff as $gs): $gUrl = loginGreetingUrl((string)$gs['username']); ?>
                        <tr>
                            <td><?php echo e($gs['name'] ?: $gs['username']); ?> <span class="text-white-50 small">(<?php echo e($gs['username']); ?>)</span></td>
                            <td>
                                <?php if ($gUrl): ?>
                                    <audio controls preload="none" src="<?php echo e($gUrl); ?>" style="height:30px; max-width:220px;"></audio>
                                <?php else: ?>
                                    <span class="text-white-50 small"><?php echo __('greeting_none'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <form method="POST" enctype="multipart/form-data" class="d-inline-flex gap-1 align-items-center justify-content-end">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="greeting_username" value="<?php echo e($gs['username']); ?>">
                                    <input type="file" name="greeting_file" accept=".mp3,.m4a,.wav,.ogg,audio/*" class="form-control form-control-sm" style="max-width:230px;" required>
                                    <button type="submit" name="upload_greeting" value="1" class="btn btn-sm btn-primary"><i class="fas fa-upload"></i></button>
                                </form>
                                <?php if ($gUrl): ?>
                                <form method="POST" class="d-inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="greeting_username" value="<?php echo e($gs['username']); ?>">
                                    <button type="submit" name="delete_greeting" value="1" class="btn btn-sm btn-outline-danger" title="<?php echo e(__('delete')); ?>"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-white-50"><?php echo __('greeting_note'); ?></div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        </div>

        <!-- SYSTEM & DB TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'system' ? 'show active' : ''; ?>">
            <div class="row g-4">
                <div class="col-md-6 border-end border-secondary">
                    <h5 class="mb-3 text-white"><i class="fas fa-database me-2 text-secondary"></i><?php echo __('database_header'); ?></h5>
                    <div class="d-grid gap-2 mb-4">
                        <button type="button" class="btn btn-success" onclick="runBackup(this)"><i class="fas fa-file-download me-2"></i><?php echo __('create_backup'); ?></button>
                        <div id="backupResult" class="small"></div>
                    </div>
                    <h5 class="mb-3 text-white"><i class="fas fa-globe me-2 text-info"></i><?php echo __('system_langs'); ?></h5>
                    <form method="POST" class="row g-2 align-items-center">
                        <?php echo csrfField(); ?>
                        <div class="col-auto">
                            <select name="lang" class="form-select bg-dark text-white border-secondary">
                                <option value="ru" <?php echo crm_get_language() === 'ru' ? 'selected' : ''; ?>>Русский (RU)</option>
                                <option value="cs" <?php echo crm_get_language() === 'cs' ? 'selected' : ''; ?>>Czech (CS)</option>
                                <option value="en" <?php echo crm_get_language() === 'en' ? 'selected' : ''; ?>>English (EN)</option>
                            </select>
                        </div>
                        <div class="col-auto"><button type="submit" name="set_lang" class="btn btn-primary"><?php echo __('save'); ?></button></div>
                    </form>

                    <hr class="my-4 border-secondary">
                    <h5 class="mb-3 text-white"><i class="fas fa-sliders-h me-2 text-primary"></i><?php echo __('system_settings'); ?></h5>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <div class="mb-3">
                            <label class="form-label text-white-75 small"><?php echo __('templates'); ?></label>
                            <textarea name="order_templates" class="form-control" rows="5" placeholder="<?php echo __('templates_help'); ?>"><?php echo htmlspecialchars(get_setting('order_templates', '')); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-white-75 small"><?php echo __('templates_notes'); ?></label>
                            <textarea name="order_note_templates" class="form-control" rows="5" placeholder="<?php echo __('templates_help'); ?>"><?php echo htmlspecialchars(get_setting('order_note_templates', '')); ?></textarea>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small"><?php echo __('sla_new_hours'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="sla_new_hours" class="form-control" min="0" value="<?php echo (int)get_setting('sla_new_hours', 24); ?>">
                                    <span class="input-group-text bg-dark text-white border-secondary"><?php echo __('sla_hours'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-white-75 small"><?php echo __('sla_progress_hours'); ?></label>
                                <div class="input-group">
                                    <input type="number" name="sla_progress_hours" class="form-control" min="0" value="<?php echo (int)get_setting('sla_progress_hours', 72); ?>">
                                    <span class="input-group-text bg-dark text-white border-secondary"><?php echo __('sla_hours'); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="form-text small mt-2"><?php echo __('sla_hint'); ?></div>
                        <div class="mt-3">
                            <button type="submit" name="update_system_settings" class="btn btn-primary"><?php echo __('save'); ?></button>
                        </div>
                    </form>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0 text-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo __('error_logs'); ?></h5>
                        <form method="POST">
                            <?php echo csrfField(); ?>
                            <button type="submit" name="clear_logs" class="btn btn-sm btn-outline-danger" data-confirm="<?php echo __('clear_confirm'); ?>"><?php echo __('clear_btn'); ?></button></form>
                    </div>
                    <div class="overflow-auto border-secondary glass-panel p-2" style="max-height: 480px; font-family: monospace; font-size: 0.75rem;">
                        <?php
                        try {
                            $errors = $pdo->query("SELECT * FROM system_errors ORDER BY created_at DESC LIMIT 50")->fetchAll();
                        } catch (Exception $e) {
                            $errors = [];
                        }
                        if (empty($errors)) echo '<div class="text-success p-2">'.__('no_errors').'</div>';
                        foreach ($errors as $err): ?>
                            <div class="text-white border-bottom border-secondary mb-1 pb-1">
                                <span class="text-danger">[<?php echo $err['created_at']; ?>]</span> <strong><?php echo htmlspecialchars($err['error_type']); ?>:</strong> <?php echo htmlspecialchars($err['message']); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ADMINS TAB -->
        <?php if ($is_admin_user): ?>
        <div class="tab-pane fade <?php echo $active_tab == 'admins' ? 'show active' : ''; ?>">
            <h5 class="mb-3"><?php echo __('admin_management_title'); ?></h5>

            <?php if (isset($_GET['admin_added'])): ?>
                <div class="alert alert-success py-2"><i class="fas fa-check me-2"></i>Administrátor přidán. Může se hned přihlásit.</div>
            <?php elseif (($_GET['error'] ?? '') === 'admin_exists'): ?>
                <div class="alert alert-danger py-2"><i class="fas fa-triangle-exclamation me-2"></i>Toto přihlašovací jméno už mezi administrátory existuje — zvol jiné.</div>
            <?php elseif (($_GET['error'] ?? '') === 'promote_missing'): ?>
                <div class="alert alert-danger py-2"><i class="fas fa-triangle-exclamation me-2"></i>Vyber zaměstnance a vyplň přihlašovací jméno.</div>
            <?php elseif (($_GET['error'] ?? '') === 'short_password'): ?>
                <div class="alert alert-danger py-2"><i class="fas fa-triangle-exclamation me-2"></i>Heslo musí mít alespoň 8 znaků.</div>
            <?php endif; ?>

            <!-- Přidat administrátora z aktuálních zaměstnanců -->
            <?php
            $adminLogins = array_column($pdo->query("SELECT username FROM users")->fetchAll(), 'username');
            $staffCandidates = $pdo->query("SELECT id, name, username FROM technicians ORDER BY name ASC")->fetchAll();
            ?>
            <form method="POST" class="glass-panel p-3 border-secondary mb-4">
                <?php echo csrfField(); ?>
                <div class="small text-white-75 mb-2"><i class="fas fa-user-plus me-2 text-info"></i>Přidat administrátora z aktuálních zaměstnanců</div>
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small text-white-75">Zaměstnanec</label>
                        <select name="staff_tech_id" id="promoteStaffSelect" class="form-select" required>
                            <option value="">— vyber —</option>
                            <?php foreach ($staffCandidates as $sc): ?>
                                <option value="<?php echo (int)$sc['id']; ?>" data-username="<?php echo htmlspecialchars((string)$sc['username']); ?>">
                                    <?php echo htmlspecialchars($sc['name']); ?><?php if (!empty($sc['username'])): ?> (<?php echo htmlspecialchars($sc['username']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-white-75">Přihlašovací jméno (admin)</label>
                        <input type="text" name="staff_admin_username" id="promoteStaffUsername" class="form-control" required autocomplete="off">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-white-75">Heslo administrátora (min. 8 znaků)</label>
                        <input type="password" name="staff_admin_password" class="form-control" minlength="8" required autocomplete="new-password">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="promote_staff_admin" value="1" class="btn btn-primary w-100"><i class="fas fa-user-shield me-1"></i>Přidat</button>
                    </div>
                </div>
                <div class="form-text small text-white-75 mt-2">
                    Zaměstnanci vznikne samostatný adminský přístup (účet zaměstnance zůstává beze změny).
                    Může používat stejné přihlašovací jméno — role se pozná podle hesla.
                </div>
            </form>
            <script>
            (function () {
                var sel = document.getElementById('promoteStaffSelect');
                var usr = document.getElementById('promoteStaffUsername');
                var taken = <?php echo json_encode(array_values($adminLogins), JSON_UNESCAPED_UNICODE); ?>;
                if (!sel || !usr) return;
                sel.addEventListener('change', function () {
                    var opt = sel.options[sel.selectedIndex];
                    var u = (opt && opt.getAttribute('data-username')) || '';
                    if (!u && opt && opt.text) { u = opt.text.trim().toLowerCase().split(/\s+/)[0].normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
                    // koliduje-li s existujícím adminem, nabídni variantu
                    var candidate = u, i = 2;
                    while (candidate && taken.indexOf(candidate) !== -1) { candidate = u + i; i++; }
                    usr.value = candidate;
                });
            })();
            </script>

            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle border-secondary">
                    <thead class="table-dark"><tr><th><?php echo __('login_col'); ?></th><th><?php echo __('name_col'); ?></th><th><?php echo __('role_col'); ?></th><th class="text-end"><?php echo __('actions_col'); ?></th></tr></thead>
                    <tbody>
                        <?php $admins = $pdo->query("SELECT * FROM users ORDER BY role DESC")->fetchAll();
                        foreach ($admins as $admin): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($admin['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($admin['full_name']); ?></td>
                            <td><span class="badge bg-danger">Admin</span></td>
                            <td class="text-end"><button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#adminPwdModal<?php echo $admin['id']; ?>"><i class="fas fa-key me-1"></i> <?php echo __('password_btn'); ?></button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- TISK ŠTÍTKŮ TAB (dostupný všem zaměstnancům) -->
        <div class="tab-pane fade <?php echo $active_tab == 'tisk' ? 'show active' : ''; ?>">
            <?php if ($active_tab == 'tisk'): ?>
            <div class="row">
                <div class="col-lg-7">
                    <div class="glass-panel p-4 border-secondary mb-3">
                        <h5 class="mb-1 text-white"><i class="fas fa-print me-2 text-info"></i><?php echo __('label_bridge_title'); ?></h5>
                        <div class="small text-white-75 mb-3"><?php echo __('label_bridge_desc'); ?></div>

                        <div id="bridgeStatus" class="alert alert-secondary py-2 mb-3">
                            <i class="fas fa-circle-notch fa-spin me-2"></i><?php echo __('label_bridge_checking'); ?>
                        </div>

                        <div id="bridgeInstallBox" style="display:none;">
                            <div class="small text-white-75 mb-2"><?php echo __('label_bridge_install_steps'); ?></div>
                            <div class="input-group mb-2">
                                <input type="text" class="form-control font-monospace" id="bridgeCmd" readonly
                                       value="curl -fsSL https://admin.applefix.cloud/print-bridge/bootstrap.sh | bash">
                                <button class="btn btn-primary" type="button" onclick="bridgeCopyCmd()"><i class="fas fa-copy me-1"></i><?php echo __('label_bridge_copy'); ?></button>
                            </div>
                            <div class="small text-white-50"><?php echo __('label_bridge_once_note'); ?></div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button class="btn btn-outline-secondary btn-sm" type="button" onclick="bridgeCheck()"><i class="fas fa-rotate me-1"></i><?php echo __('label_bridge_recheck'); ?></button>
                            <button class="btn btn-outline-info btn-sm" type="button" id="bridgePreviewBtn" style="display:none;" onclick="bridgePreview()"><i class="fas fa-eye me-1"></i><?php echo __('label_bridge_preview'); ?></button>
                            <button class="btn btn-outline-success btn-sm" type="button" id="bridgeTestBtn" style="display:none;" onclick="bridgeTestPrint()"><i class="fas fa-print me-1"></i><?php echo __('label_bridge_test'); ?></button>
                        </div>
                        <div id="bridgePreviewArea" class="mt-3" style="display:none;">
                            <img id="bridgePreviewImg" src="" alt="náhled štítku" style="max-width:100%; background:#fff; border-radius:8px; padding:6px;">
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="glass-panel p-4 border-secondary">
                        <h6 class="text-uppercase small text-muted mb-3"><?php echo __('label_bridge_how_title'); ?></h6>
                        <ul class="small text-white-75 mb-0">
                            <li class="mb-2"><?php echo __('label_bridge_how_1'); ?></li>
                            <li class="mb-2"><?php echo __('label_bridge_how_2'); ?></li>
                            <li class="mb-2"><?php echo __('label_bridge_how_3'); ?></li>
                            <li><?php echo __('label_bridge_how_4'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
            <script>
            var BRIDGE = 'http://127.0.0.1:9110';
            function bridgeCheck() {
                var st = document.getElementById('bridgeStatus');
                var box = document.getElementById('bridgeInstallBox');
                st.className = 'alert alert-secondary py-2 mb-3';
                st.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i><?php echo __('label_bridge_checking'); ?>';
                var ctrl = new AbortController();
                var t = setTimeout(function(){ ctrl.abort(); }, 1800);
                fetch(BRIDGE + '/health', { signal: ctrl.signal })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        clearTimeout(t);
                        st.className = 'alert alert-success py-2 mb-3';
                        st.innerHTML = '<i class="fas fa-check-circle me-2"></i><?php echo __('label_bridge_running'); ?> <strong>' + (d.printer_ip || '?') + '</strong>';
                        box.style.display = 'none';
                        document.getElementById('bridgePreviewBtn').style.display = '';
                        document.getElementById('bridgeTestBtn').style.display = '';
                    })
                    .catch(function(){
                        clearTimeout(t);
                        st.className = 'alert alert-warning py-2 mb-3';
                        st.innerHTML = '<i class="fas fa-triangle-exclamation me-2"></i><?php echo __('label_bridge_not_running'); ?>';
                        box.style.display = '';
                        document.getElementById('bridgePreviewBtn').style.display = 'none';
                        document.getElementById('bridgeTestBtn').style.display = 'none';
                    });
            }
            function bridgeCopyCmd() {
                var inp = document.getElementById('bridgeCmd');
                inp.select(); inp.setSelectionRange(0, 999);
                navigator.clipboard.writeText(inp.value).then(function(){
                    window.afxLabelToast && window.afxLabelToast('📋 <?php echo __('label_bridge_copied'); ?>', true);
                });
            }
            function bridgePreview() {
                var area = document.getElementById('bridgePreviewArea');
                document.getElementById('bridgePreviewImg').src = BRIDGE + '/preview?code=APFAZ0000000&client=' + encodeURIComponent('Jan Novák') + '&defect=' + encodeURIComponent('Ukázkový štítek — náhled') + '&date=<?php echo date('d.m.Y'); ?>&_=' + Date.now();
                area.style.display = '';
            }
            function bridgeTestPrint() {
                if (!confirm('<?php echo __('label_bridge_test_confirm'); ?>')) return;
                fetch(BRIDGE + '/print', { method: 'POST', headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ code: 'APFAZ0000000', client: 'Zkušební tisk', defect: 'Testovací štítek z Nastavení', date: '<?php echo date('d.m.Y'); ?>' }) })
                    .then(function(r){ return r.json(); })
                    .then(function(d){ window.afxLabelToast && window.afxLabelToast(d.ok ? '🏷️ <?php echo __('label_bridge_test_ok'); ?>' : ('⚠️ ' + (d.error || 'chyba')), !!d.ok); })
                    .catch(function(e){ window.afxLabelToast && window.afxLabelToast('⚠️ ' + e.message, false); });
            }
            document.addEventListener('DOMContentLoaded', bridgeCheck);
            </script>
            <?php endif; ?>
        </div>

        <!-- UPDATES TAB -->
        <?php if ($is_admin_user): ?>
        <div class="tab-pane fade <?php echo $active_tab == 'updates' ? 'show active' : ''; ?>">
            <div class="row g-4">
                <!-- Left: Version & Update -->
                <div class="col-md-6">
                    <div class="glass-panel p-4 mb-4 border-secondary">
                        <?php
                        $gitInfo = function_exists('getGitRepoInfo') ? getGitRepoInfo(__DIR__) : [];
                        $branchLabel = $gitInfo['branch'] ?? 'main';
                        $localShort = $gitInfo['local_short'] ?? '—';
                        $remoteShort = $gitInfo['remote_short'] ?? '—';
                        $remoteLabel = ($gitInfo['remote_slug'] ?? '') ?: (($gitInfo['remote_name'] ?? '') ?: 'origin');
                        $remoteUrl = (string)($gitInfo['remote_url'] ?? '');
                        ?>
                        <div class="d-flex align-items-center mb-3">
                            <div class="rounded-circle bg-primary bg-opacity-25 p-3 me-3">
                                <i class="fas fa-code-branch fa-lg text-primary"></i>
                            </div>
                            <div>
                                <h5 class="mb-1 text-white"><?php echo __('updates_title'); ?></h5>
                                <div class="text-white-75 small"><?php echo __('update_server_hint'); ?></div>
                                <div class="text-white-50 small mt-1">
                                    <?php echo htmlspecialchars($remoteLabel . ' · ' . ($gitInfo['branch'] ?? 'main')); ?>
                                </div>
                                <?php if ($remoteUrl !== ''): ?>
                                    <div class="text-white-50 small" style="word-break: break-all;"><?php echo htmlspecialchars($remoteUrl); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (!empty($gitInfo['error'])): ?>
                            <div class="alert alert-warning border-0 bg-warning bg-opacity-10 small mb-3">
                                <i class="fas fa-exclamation-triangle me-2 text-warning"></i><?php echo htmlspecialchars((string)$gitInfo['error']); ?>
                                <?php if (!empty($gitInfo['error_detail'])): ?>
                                    <div class="mt-1 text-white-50" style="font-family:monospace;font-size:.72rem;word-break:break-all;"><?php echo htmlspecialchars((string)$gitInfo['error_detail']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="row g-3 mb-4">
                            <div class="col-6">
                                <div class="glass-panel p-3 text-center border-secondary">
                                    <div class="text-white-75 small"><?php echo __('current_version'); ?></div>
                                    <div class="h4 text-white mb-0" id="localVersion"><?php echo htmlspecialchars(trim($branchLabel . ' @ ' . $localShort)); ?></div>
                                    <div class="small text-muted">
                                        <?php echo !empty($gitInfo['dirty']) ? 'dirty' : 'clean'; ?> · ahead <?php echo (int)($gitInfo['ahead_by'] ?? 0); ?> · behind <?php echo (int)($gitInfo['behind_by'] ?? 0); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="glass-panel p-3 text-center border-secondary">
                                    <div class="text-white-75 small"><?php echo __('latest_version'); ?></div>
                                    <div class="h4 text-muted mb-0" id="remoteVersion"><?php echo htmlspecialchars(trim($branchLabel . ' @ ' . $remoteShort)); ?></div>
                                    <div class="small text-muted" id="remoteReleaseDate"><?php echo !empty($gitInfo['remote_date']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($gitInfo['remote_date']))) : ''; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="d-grid gap-2 mb-3">
                            <button type="button" class="btn btn-primary" id="btnCheckUpdates" onclick="checkForUpdates(true)">
                                <i class="fas fa-sync-alt me-2"></i><?php echo __('check_updates'); ?>
                            </button>
                            <button type="button" class="btn btn-success" id="btnInstallUpdate" onclick="installUpdate()" style="display:none;">
                                <i class="fas fa-cloud-download-alt me-2"></i><?php echo __('install_update'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="btnUpdateDiag" onclick="runUpdateDiagnostics()">
                                <i class="fas fa-stethoscope me-2"></i>Diagnostika serveru
                            </button>
                        </div>
                        <div id="updateDiagArea" class="mb-3" style="display:none;"></div>

                        <div id="updateStatusArea" class="mb-4" style="display:none;"></div>
                        <div class="small text-white-75 mt-2"><?php echo __('update_server_hint'); ?></div>
                        <div class="alert alert-warning border-0 bg-warning bg-opacity-10 mt-3 mb-0 small">
                            <i class="fas fa-exclamation-triangle me-2 text-warning"></i><?php echo __('update_warning'); ?>
                        </div>
                    </div>
                </div>
                <!-- Right: Changelog -->
                <div class="col-md-6">
                    <div class="glass-panel p-4 border-secondary">
                        <h5 class="mb-3 text-white"><i class="fas fa-list-ul me-2 text-info"></i><?php echo __('changelog_title'); ?></h5>
                        <div id="changelogArea" class="overflow-auto" style="max-height: 480px;">
                            <div class="text-muted small"><?php echo __('no_changelog'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Historie úprav (trvalý, ručně vedený přehled) -->
            <?php $crm_history = @include __DIR__ . '/includes/changelog.php'; ?>
            <?php if (is_array($crm_history) && $crm_history): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="glass-panel p-4 border-secondary">
                        <h5 class="mb-1 text-white"><i class="fas fa-rocket me-2 text-success"></i>Historie úprav</h5>
                        <div class="small text-white-75 mb-3">Přehled dokončených vylepšení (posledních 50) — jak systém krok za krokem posouváme.</div>
                        <div class="overflow-auto" style="max-height: 440px;">
                            <?php foreach (array_slice($crm_history, 0, 50) as $hz): ?>
                                <div class="mb-3 pb-2 border-bottom border-secondary">
                                    <div class="fw-bold text-white">
                                        <span class="badge bg-primary me-2"><?php echo e(date('d.m.Y', strtotime((string)$hz['date']))); ?></span>
                                        <?php echo e((string)$hz['title']); ?>
                                    </div>
                                    <?php if (!empty($hz['items'])): ?>
                                        <ul class="small text-white-75 mb-0 mt-2">
                                            <?php foreach ((array)$hz['items'] as $hi): ?>
                                                <li><?php echo e((string)$hi); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODALS -->
<?php $branches_settings = getBranches(); ?>
<div class="modal fade" id="addTechModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <div class="modal-header border-secondary"><h5 class="modal-title"><?php echo __('add_employee_title'); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label text-white-75 small"><?php echo __('full_name_label'); ?></label><input type="text" name="tech_name" class="form-control" required></div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('crm_role_label'); ?></label>
                    <select name="role" class="form-select">
                        <option value="engineer"><?php echo __('role_engineer'); ?></option>
                        <option value="manager"><?php echo __('role_manager'); ?></option>
                        <option value="admin"><?php echo __('role_admin'); ?></option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('spec_col'); ?></label>
                    <input type="text" name="tech_spec" class="form-control" placeholder="<?php echo __('spec_placeholder'); ?>">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label"><i class="fas fa-store me-1"></i>Pobočka</label>
                    <select name="branch_id" class="form-select">
                        <?php foreach ($branches_settings as $branch): ?>
                            <option value="<?php echo (int)$branch['id']; ?>"><?php echo e($branch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="tech_email" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('phone_label'); ?></label><input type="text" name="tech_phone" class="form-control"></div></div>
            <div class="mb-3">
                <label class="form-label">Telegram ID nebo @username</label>
                <input type="text" name="tech_tg" class="form-control" value="" placeholder="<?php echo __('tg_placeholder'); ?>">
                <div class="form-text small">You can enter numeric ID or @username. If you enter username, the employee must message the bot first and CRM will auto-pair the ID.</div>
            </div>
            <hr><h6 class="mb-3"><?php echo __('system_access_header'); ?></h6>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('login_col'); ?></label><input type="text" name="tech_username" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('password_btn'); ?></label><input type="password" name="tech_password" class="form-control"></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel_btn'); ?></button><button type="submit" name="add_tech" class="btn btn-primary"><?php echo __('create_btn'); ?></button></div>
    </form></div></div>
</div>

<?php foreach ($techs as $t): ?>
<div class="modal fade" id="editTechModal<?php echo $t['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content border-secondary text-white"><form method="POST" id="editTechForm<?php echo $t['id']; ?>">
        <?php echo csrfField(); ?>
        <input type="hidden" name="edit_tech" value="1">
        <input type="hidden" name="tech_id" value="<?php echo $t['id']; ?>">
        <div class="modal-header border-secondary"><h5 class="modal-title"><?php echo __('edit_title'); ?><?php echo htmlspecialchars($t['name']); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php if ($is_admin_user): ?>
            <div class="mb-3">
                <label class="form-label"><?php echo __('full_name_label'); ?></label>
                <input type="text" name="tech_name" class="form-control" value="<?php echo htmlspecialchars($t['name']); ?>" required>
            </div>
            <?php else: ?>
                <input type="hidden" name="tech_name" value="<?php echo htmlspecialchars($t['name']); ?>">
            <?php endif; ?>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('crm_role_label'); ?></label>
                    <?php if ($is_admin_user): ?>
                    <select name="role" class="form-select">
                        <option value="engineer" <?php echo ($t['role'] ?? 'engineer') == 'engineer' ? 'selected' : ''; ?>><?php echo __('role_engineer'); ?></option>
                        <option value="manager" <?php echo ($t['role'] ?? 'engineer') == 'manager' ? 'selected' : ''; ?>><?php echo __('role_manager'); ?></option>
                        <option value="admin" <?php echo ($t['role'] ?? 'engineer') == 'admin' ? 'selected' : ''; ?>><?php echo __('role_admin'); ?></option>
                    </select>
                    <?php else: ?>
                        <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo ($t['role'] ?? 'engineer') == 'admin' ? __('role_admin') : (($t['role'] ?? 'engineer') == 'manager' ? __('role_manager') : __('role_engineer')); ?></div>
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($t['role'] ?? 'engineer'); ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('spec_col'); ?></label>
                    <?php if ($is_admin_user): ?>
                        <input type="text" name="tech_spec" class="form-control" value="<?php echo htmlspecialchars($t['specialization'] ?? ''); ?>">
                    <?php else: ?>
                        <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo htmlspecialchars($t['specialization'] ?? ''); ?></div>
                        <input type="hidden" name="tech_spec" value="<?php echo htmlspecialchars($t['specialization'] ?? ''); ?>">
                    <?php endif; ?>
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label"><i class="fas fa-store me-1"></i>Pobočka</label>
                    <?php if ($is_admin_user): ?>
                    <select name="branch_id" class="form-select">
                        <?php foreach ($branches_settings as $branch): ?>
                            <option value="<?php echo (int)$branch['id']; ?>" <?php echo (int)($t['branch_id'] ?? 0) === (int)$branch['id'] ? 'selected' : ''; ?>><?php echo e($branch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                        <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo e(getBranchLabel((int)($t['branch_id'] ?? 0))); ?></div>
                        <input type="hidden" name="branch_id" value="<?php echo (int)($t['branch_id'] ?? getCurrentStaffBranchId()); ?>">
                    <?php endif; ?>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label"><?php echo __('login_col'); ?></label>
                <?php if ($is_admin_user): ?>
                    <input type="text" name="tech_username" class="form-control" value="<?php echo htmlspecialchars($t['username'] ?? ''); ?>">
                <?php else: ?>
                    <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo htmlspecialchars($t['username'] ?? ''); ?></div>
                    <input type="hidden" name="tech_username" value="<?php echo htmlspecialchars($t['username'] ?? ''); ?>">
                <?php endif; ?>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="tech_email" class="form-control" value="<?php echo htmlspecialchars($t['email'] ?? ''); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('phone_label'); ?></label>
                    <input type="text" name="tech_phone" class="form-control" value="<?php echo htmlspecialchars($t['phone'] ?? ''); ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Telegram ID nebo @username</label>
                <div class="input-group">
                    <input type="text" name="tech_tg" class="form-control" value="<?php echo htmlspecialchars(telegramContactDisplayValue($t['telegram_id'] ?? '', $t['telegram_username'] ?? '')); ?>" placeholder="123456789 nebo @uzivatel">
                    <?php if ($is_admin_user): ?>
                    <button class="btn btn-outline-info" type="button" onclick="testTechTG(<?php echo $t['id']; ?>)"><i class="fab fa-telegram-plane"></i></button>
                    <?php endif; ?>
                </div>
                <div class="form-text small"><?php echo __('tg_notification_hint'); ?></div>
            </div>
            <div class="mb-3"><label class="form-label"><?php echo __('new_password_label'); ?></label><input type="password" name="tech_password" class="form-control" placeholder="<?php echo __('password_placeholder'); ?>"></div>
            <?php if ($is_admin_user): ?>
            <div class="mb-3">
                <label class="form-label"><i class="fas fa-money-bill-wave me-1 text-success"></i><?php echo __('engineer_rate_label'); ?></label>
                <div class="input-group">
                    <input type="number" name="engineer_rate" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($t['engineer_rate'] ?? 0); ?>">
                    <span class="input-group-text"><?php echo get_setting('currency', 'Kč'); ?>/h</span>
                </div>
                <div class="form-text small"><?php echo __('rate_hint'); ?></div>
            </div>
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="isActive<?php echo $t['id']; ?>" <?php echo ($t['is_active'] ?? 1) ? 'checked' : ''; ?>><label class="form-check-label" for="isActive<?php echo $t['id']; ?>"><?php echo __('active_status'); ?></label></div>
            <?php else: ?>
                <input type="hidden" name="engineer_rate" value="<?php echo htmlspecialchars($t['engineer_rate'] ?? 0); ?>">
                <input type="hidden" name="is_active" value="1">
            <?php endif; ?>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary"><?php echo __('save'); ?></button></div>
    </form></div></div>
</div>

<div class="modal fade" id="permModal<?php echo $t['id']; ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="tech_id" value="<?php echo $t['id']; ?>">
        <div class="modal-header border-secondary bg-warning bg-opacity-10"><h5 class="modal-title"><?php echo __('permissions_title'); ?><?php echo htmlspecialchars($t['name']); ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php $tech_perms = getTechPermissions($t['id']); foreach (getAvailablePermissions() as $pk => $pi): ?>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="permissions[]" value="<?php echo $pk; ?>" id="p_<?php echo $t['id'].$pk; ?>" <?php echo in_array($pk, $tech_perms) ? 'checked' : ''; ?>><label class="form-check-label" for="p_<?php echo $t['id'].$pk; ?>"><strong><?php echo $pi['name']; ?></strong><div class="text-white-75 small"><?php echo $pi['desc']; ?></div></label></div>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer border-secondary"><button type="submit" name="save_permissions" class="btn btn-warning"><?php echo __('save_permissions_btn'); ?></button></div>
    </form></div></div>
</div>
<?php endforeach; ?>

<?php if ($is_admin_user): ?>
<?php foreach ($admins as $admin): ?>
<div class="modal fade" id="adminPwdModal<?php echo $admin['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-sm"><div class="modal-content border-secondary text-white"><form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
        <div class="modal-header border-secondary bg-danger bg-opacity-25 text-white"><h6 class="modal-title"><?php echo __('change_password_title'); ?></h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label small text-white-75"><?php echo __('new_password_for'); ?> <?php echo htmlspecialchars($admin['username']); ?></label>
            <input type="password" name="new_password" class="form-control" required minlength="6">
        </div>
        <div class="modal-footer border-secondary"><button type="submit" name="change_admin_password" class="btn btn-danger btn-sm"><?php echo __('save'); ?></button></div>
    </form></div></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
function testTechTG(id) { if (!id) return; $.post('api/test_tech_tg.php', {id: id, csrf_token: $('meta[name="csrf-token"]').attr('content')}, function(res) { if (res.success) { showAlert('OK'); } else { showAlert(res.message); } }); }
function triggerDownload(path, filename) {
    if (!path) return;
    const a = document.createElement('a');
    a.href = path;
    a.setAttribute('download', filename || 'backup.sql');
    document.body.appendChild(a);
    a.click();
    a.remove();
}

function triggerDownloadBase64(base64Content, filename) {
    if (!base64Content) return;
    const binary = atob(base64Content);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    const blob = new Blob([bytes], { type: 'application/sql;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    triggerDownload(url, filename || 'backup.sql');
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function runBackup(btnEl) {
    const btn = btnEl && btnEl.closest ? btnEl.closest('button') : document.querySelector('button[onclick*="runBackup"]');
    const resultDiv = document.getElementById('backupResult');
    if (!btn || !resultDiv) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo __('create_backup'); ?>';

    $.post('api/backup_db.php', { csrf_token: $('meta[name="csrf-token"]').attr('content') }, function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-download me-2"></i><?php echo __('create_backup'); ?>';

        if (res && res.success) {
            const filenameRaw = String(res.filename || 'backup.sql');
            const safeName = escapeHtml(filenameRaw);
            const safeFilenameJs = filenameRaw.replace(/'/g, "\\'");

            if (res.inline && res.content_base64) {
                resultDiv.innerHTML = `<div class="alert alert-success p-2 mt-2"><?php echo __('done_js'); ?> ${safeName}</div>`;
                triggerDownloadBase64(res.content_base64, filenameRaw);
                return;
            }

            const safePath = String(res.path || '').replace(/'/g, "\\'");
            resultDiv.innerHTML = `<div class="alert alert-success p-2 mt-2"><?php echo __('done_js'); ?> <a href="javascript:void(0)" onclick="triggerDownload('${safePath}', '${safeFilenameJs}')">${safeName}</a></div>`;
            if (res.path) triggerDownload(res.path, filenameRaw);
            return;
        }

        const msg = (res && res.message) ? escapeHtml(res.message) : '<?php echo __('error_js'); ?>';
        resultDiv.innerHTML = `<div class="alert alert-danger p-2 mt-2">${msg}</div>`;
    }, 'json').fail(function(xhr) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-file-download me-2"></i><?php echo __('create_backup'); ?>';
        const raw = (xhr && xhr.responseJSON && xhr.responseJSON.message)
            ? xhr.responseJSON.message
            : ((xhr && xhr.responseText) ? xhr.responseText : '<?php echo __('error_js'); ?>');
        resultDiv.innerHTML = `<div class="alert alert-danger p-2 mt-2">${escapeHtml(String(raw))}</div>`;
    });
}
</script>

<?php if ($is_admin_user): ?>
<script>
const UPDATE_TRANSLATIONS = {
    check_updates:     '<?php echo __('check_updates'); ?>',
    checking_updates:  '<?php echo __('checking_updates'); ?>',
    install_update:    '<?php echo __('install_update'); ?>',
    installing_update: '<?php echo __('installing_update'); ?>',
    update_available:  '<?php echo __('update_available'); ?>',
    update_available_desc: '<?php echo __('update_available_desc'); ?>',
    up_to_date:        '<?php echo __('up_to_date'); ?>',
    up_to_date_desc:   '<?php echo __('up_to_date_desc'); ?>',
    update_success:    '<?php echo __('update_success'); ?>',
    update_error:      '<?php echo __('update_error'); ?>',
    no_changelog:      '<?php echo __('no_changelog'); ?>',
    last_check:        '<?php echo __('last_check'); ?>',
    minutes_ago:       '<?php echo __('minutes_ago'); ?>',
    migrations_ran:    '<?php echo __('migrations_ran'); ?>',
    release_date:      '<?php echo __('release_date'); ?>',
    build:             '<?php echo __('build'); ?>'
};

function checkForUpdates(force = false) {
    const btn = document.getElementById('btnCheckUpdates');
    const installBtn = document.getElementById('btnInstallUpdate');
    const statusArea = document.getElementById('updateStatusArea');
    
    if (!btn || !statusArea) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + UPDATE_TRANSLATIONS.checking_updates;
    statusArea.style.display = 'none';

    if (installBtn) {
        installBtn.style.display = 'none';
        installBtn.disabled = false;
        installBtn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>' + UPDATE_TRANSLATIONS.install_update;
    }

    const url = 'api/check_updates.php' + (force ? '?force=1' : '');
    
    fetch(url)
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>' + UPDATE_TRANSLATIONS.check_updates;
            
            if (!data.success) {
                statusArea.style.display = 'block';
                statusArea.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">
                    <i class="fas fa-exclamation-circle me-2"></i>${escapeHtml(data.message || UPDATE_TRANSLATIONS.update_error)}
                    ${data.detail ? `<div class="mt-1 text-white-50" style="font-family:monospace;font-size:.72rem;word-break:break-all;">${escapeHtml(data.detail)}</div>` : ''}
                </div>`;
                if (installBtn) installBtn.style.display = 'none';
                return;
            }

            // Update remote version display
            const rv = document.getElementById('remoteVersion');
            rv.textContent = data.remote_version || rv.textContent;
            rv.className = data.has_update ? 'h4 text-success mb-0' : 'h4 text-white mb-0';
            
            const rd = document.getElementById('remoteReleaseDate');
            if (rd && data.release_date) {
                rd.textContent = UPDATE_TRANSLATIONS.release_date + ': ' + data.release_date;
            }

            // Status message
            statusArea.style.display = 'block';
            if (data.has_update) {
                statusArea.innerHTML = `<div class="alert alert-info border-0 bg-info bg-opacity-10 small mb-0">
                    <i class="fas fa-arrow-circle-up me-2 text-info"></i>
                    <strong>${UPDATE_TRANSLATIONS.update_available}</strong> v${data.local_version} → v${data.remote_version}
                    <div class="mt-1 text-white-75">${UPDATE_TRANSLATIONS.update_available_desc}</div>
                    <div class="mt-1 text-muted small"><i class="fas fa-code-branch me-1"></i>${UPDATE_TRANSLATIONS.build}: ${escapeHtml(data.build || '')}</div>
                </div>`;
                if (installBtn) installBtn.style.display = 'block';
                // Show badge
                const badge = document.getElementById('updateBadgeNav');
                if (badge) badge.style.display = 'inline';
            } else {
                statusArea.innerHTML = `<div class="alert alert-success border-0 bg-success bg-opacity-10 small mb-0">
                    <i class="fas fa-check-circle me-2 text-success"></i>
                    <strong>${UPDATE_TRANSLATIONS.up_to_date}</strong> (v${data.local_version})
                    <div class="mt-1 text-white-75">${UPDATE_TRANSLATIONS.up_to_date_desc}</div>
                    <div class="mt-1 text-muted small"><i class="fas fa-code-branch me-1"></i>${UPDATE_TRANSLATIONS.build}: ${escapeHtml(data.build || '')}</div>
                </div>`;
                if (installBtn) installBtn.style.display = 'none';
                const badge = document.getElementById('updateBadgeNav');
                if (badge) badge.style.display = 'none';
            }

            // Cache info
            if (data.from_cache && data.cache_age) {
                const mins = Math.round(data.cache_age / 60);
                statusArea.innerHTML += `<div class="text-muted small mt-2"><i class="fas fa-clock me-1"></i>${UPDATE_TRANSLATIONS.last_check}: ${mins} ${UPDATE_TRANSLATIONS.minutes_ago}</div>`;
            }

            // Changelog
            renderChangelog(data.changelog || []);
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync-alt me-2"></i>' + UPDATE_TRANSLATIONS.check_updates;
            statusArea.style.display = 'block';
            statusArea.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">
                <i class="fas fa-exclamation-circle me-2"></i>${err.message || UPDATE_TRANSLATIONS.update_error}
            </div>`;
            if (installBtn) installBtn.style.display = 'none';
        });
}

function renderChangelog(commits) {
    const area = document.getElementById('changelogArea');
    if (!area) return;

    if (!commits || commits.length === 0) {
        area.innerHTML = `<div class="text-muted small">${UPDATE_TRANSLATIONS.no_changelog}</div>`;
        return;
    }

    let html = '';
    commits.forEach(c => {
        const sha = c.sha || c.version || '';
        const rawDate = c.date || c.release_date || '';
        const date = rawDate ? new Date(rawDate).toLocaleString() : '';
        const msgRaw = c.message || c.description || '';
        const msg = String(msgRaw).split('\n')[0];
        const shortMsg = msg.length > 120 ? msg.slice(0, 117) + '…' : msg;

        html += `<div class="d-flex align-items-start mb-2 pb-2 border-bottom border-secondary">
            <code class="text-info me-2 flex-shrink-0" style="font-size:0.75rem;">${escapeHtml(sha)}</code>
            <div class="flex-grow-1">
                <div class="text-white small">${escapeHtml(shortMsg)}</div>
                <div class="text-muted" style="font-size:0.7rem;">${escapeHtml(date)}${c.author ? ' · ' + escapeHtml(c.author) : ''}</div>
            </div>
        </div>`;
    });
    area.innerHTML = html;
}

function escapeHtml(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

function runUpdateDiagnostics() {
    const btn = document.getElementById('btnUpdateDiag');
    const area = document.getElementById('updateDiagArea');
    if (!btn || !area) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Diagnostika…';
    fetch('api/update_diagnostics.php')
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-stethoscope me-2"></i>Diagnostika serveru';
            if (!data.success) {
                area.style.display = 'block';
                area.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">${escapeHtml(data.message || 'Chyba')}</div>`;
                return;
            }
            const c = data.checks || {};
            const yn = v => v ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗</span>';
            const blocked = /^BLOCKED/.test(data.verdict || '');
            let rows = '';
            const add = (label, val) => { rows += `<tr><td class="text-white-75 pe-3">${escapeHtml(label)}</td><td class="text-white" style="font-family:monospace;word-break:break-all;">${val}</td></tr>`; };
            add('exec() povolen', yn(c.exec_available));
            add('git nalezen', yn(c.git_found) + ' <span class="text-muted">' + escapeHtml(c.git_version || '') + '</span>');
            add('je git repo (.git)', yn(c.is_git_repo));
            add('PHP uživatel', escapeHtml(c.php_user || '?'));
            add('vlastník repa', escapeHtml(c.repo_owner || '?') + (c.ownership_match ? '' : ' <span class="text-muted">(jiný než PHP — řešeno přes safe.directory)</span>'));
            add('lokální commit', escapeHtml(c.local_commit || '—'));
            add('remote', escapeHtml(c.remote_url || '—'));
            add('token v .env', yn(c.token_present));
            add('fetch funguje', yn(c.fetch_ok));
            add('stav vůči GitHubu', c.update_available
                ? '<span class="text-info">aktualizace dostupná (behind ' + escapeHtml(String(c.behind_by)) + ')</span>'
                : '<span class="text-success">aktuální</span>');
            add('lokální změny', c.dirty
                ? '<span class="text-warning">' + escapeHtml(c.dirty_files || 'ano') + '</span> <span class="text-muted">(neblokuje aktualizaci)</span>'
                : '<span class="text-success">žádné</span>');
            if (c.error_detail) add('detail chyby', '<span class="text-warning">' + escapeHtml(c.error_detail) + '</span>');
            area.style.display = 'block';
            area.innerHTML = `
                <div class="alert ${blocked ? 'alert-warning bg-warning' : 'alert-success bg-success'} border-0 bg-opacity-10 small mb-2">
                    <i class="fas ${blocked ? 'fa-triangle-exclamation' : 'fa-circle-check'} me-2"></i><strong>${escapeHtml(data.verdict || '')}</strong>
                </div>
                <div class="glass-panel p-3 border-secondary"><table class="table table-sm table-borderless mb-0 small">${rows}</table></div>`;
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-stethoscope me-2"></i>Diagnostika serveru';
            area.style.display = 'block';
            area.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">${escapeHtml(err.message || 'Chyba')}</div>`;
        });
}

function installUpdate() {
    if (!confirm('<?php echo __('update_warning'); ?>  \n\nContinue?')) return;
    
    const btn = document.getElementById('btnInstallUpdate');
    const statusArea = document.getElementById('updateStatusArea');
    
    if (!btn || !statusArea) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>' + UPDATE_TRANSLATIONS.installing_update;

    const csrf = $('meta[name="csrf-token"]').attr('content') || '<?php echo generateCsrfToken(); ?>';
    
    fetch('api/run_update.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(csrf)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>' + UPDATE_TRANSLATIONS.install_update;
        
        statusArea.style.display = 'block';
        if (data.success) {
            let migrationsHtml = '';
            if (data.migrations && data.migrations.length > 0) {
                migrationsHtml = `<div class="mt-2"><strong>${UPDATE_TRANSLATIONS.migrations_ran}:</strong><ul class="mb-0">`;
                data.migrations.forEach(m => {
                    migrationsHtml += `<li>${escapeHtml(m.file || '')} — ${m.status}</li>`;
                });
                migrationsHtml += '</ul></div>';
            }
            // Robust version labels (older builds may omit some keys → no "undefined")
            const prevVer = (data.previous_version || '').toString().trim();
            const newVer  = (data.new_version || data.current_version || '').toString().trim();
            const updated = (data.updated !== false);
            const verLine = newVer
                ? `<div class="mt-1 fw-semibold">${(prevVer && prevVer !== newVer) ? escapeHtml(prevVer) + ' → ' : ''}${escapeHtml(newVer)}</div>`
                : '';
            const headline = updated ? UPDATE_TRANSLATIONS.update_success : (UPDATE_TRANSLATIONS.up_to_date || UPDATE_TRANSLATIONS.update_success);
            statusArea.innerHTML = `<div class="alert alert-success border-0 bg-success bg-opacity-10 mb-0">
                <i class="fas fa-check-circle me-2 text-success"></i>
                <strong>${escapeHtml(headline)}</strong>
                ${verLine}
                ${migrationsHtml}
                <div class="mt-2"><a href="settings.php?tab=updates" class="btn btn-sm btn-outline-light"><i class="fas fa-redo me-1"></i> Reload</a></div>
            </div>`;
            const badge = document.getElementById('updateBadgeNav');
            if (badge) badge.style.display = 'none';
            const installBtn = document.getElementById('btnInstallUpdate');
            if (installBtn) installBtn.style.display = 'none';
            // Update the "current version" label only if we actually got a version (never blank it)
            if (newVer) {
                const lv = document.getElementById('localVersion');
                if (lv) lv.textContent = newVer;
            }
        } else {
            statusArea.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>${UPDATE_TRANSLATIONS.update_error}</strong>
                <div class="mt-1">${escapeHtml(data.message || '')}</div>
                ${data.output ? '<pre class="mt-2 mb-0 text-white-75 small">' + escapeHtml(data.output) + '</pre>' : ''}
                ${data.hint ? '<div class="mt-2 text-info"><i class="fas fa-lightbulb me-1"></i>' + escapeHtml(data.hint) + '</div>' : ''}
            </div>`;
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-cloud-download-alt me-2"></i>' + UPDATE_TRANSLATIONS.install_update;
        statusArea.style.display = 'block';
        statusArea.innerHTML = `<div class="alert alert-danger border-0 bg-danger bg-opacity-10 small mb-0">
            <i class="fas fa-exclamation-circle me-2"></i>${err.message || UPDATE_TRANSLATIONS.update_error}
        </div>`;
    });
}

// Auto-check on page load (use cache, no force)
document.addEventListener('DOMContentLoaded', () => checkForUpdates(false));
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

