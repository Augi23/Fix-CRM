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
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Změna nastavení — Pobočky (adresy / otevírací doba)']);
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
    if (isset($_POST['google_review_url'])) { set_setting('google_review_url', trim($_POST['google_review_url'])); }
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Změna nastavení — Firemní údaje']);
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
    // SMS brána GoSMS
    set_setting('gosms_client_id', trim($_POST['gosms_client_id'] ?? ''));
    if (trim($_POST['gosms_client_secret'] ?? '') !== '') { set_setting('gosms_client_secret', trim($_POST['gosms_client_secret'])); }
    set_setting('gosms_channel', trim($_POST['gosms_channel'] ?? ''));
    set_setting('sms_pickup_enabled', isset($_POST['sms_pickup_enabled']) ? '1' : '0');
    set_setting('gosms_token', '');   // po změně přihlášení vynutit nový token
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
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Změna nastavení — Integrace (API klíče / SMTP / webhooky) — hodnoty se z bezpečnostních důvodů nezaznamenávají']);
    header("Location: settings.php?tab=integrations&updated=1");
    exit;
}

// ── VĚRNOSTNÍ KARTA / PENĚŽENKY ──
if (isset($_POST['update_loyalty']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    set_setting('loyalty_enabled', isset($_POST['loyalty_enabled']) ? '1' : '0');
    set_setting('loyalty_points_per_order', (string) max(0, (int)($_POST['loyalty_points_per_order'] ?? 20)));
    set_setting('loyalty_points_per_100', (string) max(0, (int)($_POST['loyalty_points_per_100'] ?? 5)));

    // Apple Wallet
    set_setting('wallet_apple_enabled', isset($_POST['wallet_apple_enabled']) ? '1' : '0');
    set_setting('wallet_apple_pass_type_id', trim($_POST['wallet_apple_pass_type_id'] ?? ''));
    set_setting('wallet_apple_team_id', trim($_POST['wallet_apple_team_id'] ?? ''));
    if (trim((string)($_POST['wallet_apple_p12_pass'] ?? '')) !== '') {
        set_setting('wallet_apple_p12_pass', trim($_POST['wallet_apple_p12_pass']));
    }
    // Google Wallet
    set_setting('wallet_google_enabled', isset($_POST['wallet_google_enabled']) ? '1' : '0');
    set_setting('wallet_google_issuer_id', trim($_POST['wallet_google_issuer_id'] ?? ''));

    // Nahrání certifikátů do /secure/wallet (mimo web root pro čtení, chráněno .htaccess)
    $secureDir = crmWalletCertDir();
    if (!is_dir($secureDir)) { @mkdir($secureDir, 0700, true); }
    $uploadMap = [
        'wallet_apple_p12'      => 'apple_cert.p12',
        'wallet_apple_wwdr'     => 'apple_wwdr.pem',
        'wallet_google_json'    => 'google_service_account.json',
    ];
    foreach ($uploadMap as $field => $destName) {
        if (!empty($_FILES[$field]['tmp_name']) && is_uploaded_file($_FILES[$field]['tmp_name'])) {
            @move_uploaded_file($_FILES[$field]['tmp_name'], $secureDir . '/' . $destName);
            @chmod($secureDir . '/' . $destName, 0600);
        }
    }
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Změna nastavení — Věrnostní karta / peněženky']);
    header("Location: settings.php?tab=loyalty&updated=1");
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
    $branch_id = (int)(filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: 0);
    // Pobočka je POVINNÁ a musí být platná aktivní pobočka (žádný tichý fallback)
    $__branchOk = false;
    foreach (getBranches() as $__b) { if ((int)$__b['id'] === $branch_id) { $__branchOk = true; break; } }
    if (!$__branchOk) { header("Location: settings.php?tab=staff&error=branch_required"); exit; }
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
    // Brigádník = automaticky odměna z času v systému (hodiny × sazba, ne zakázky)
    ensurePayByTimeColumn();
    $pay_by_time_new = ($role === 'brigadnik') ? 1 : 0;
    $stmt = $pdo->prepare("INSERT INTO technicians (name, email, phone, specialization, role, branch_id, telegram_id, telegram_username, username, password, pay_by_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $phone, $spec, $role, $branch_id, $telegramContact['id'], $telegramContact['username'], $username_val, $hashed_password, $pay_by_time_new]);
    crmAuditLog('staff.create', [
        'entity_type' => 'technician', 'entity_id' => (int)$pdo->lastInsertId(), 'entity_label' => $name,
        'summary' => 'Vytvořen zaměstnanec ' . $name . ' (' . $role . ')' . ($username_val ? ', login @' . $username_val : ''),
    ]);
    header("Location: settings.php?tab=staff&tech_added=1");
    exit;
}

if (isset($_POST['edit_tech'])) {
    settingsDebugLog(['event' => 'edit_tech_hit', 'post' => $_POST, 'time' => date('c')]);
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    ensurePayByTimeColumn();
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
    $branch_id = (int)(filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: 0);
    // Pobočka je POVINNÁ: admin nesmí uložit technika bez platné aktivní pobočky.
    // (Non-adminovi se pobočka stejně přebírá z DB v re-verify bloku níže.)
    if ($is_admin_check) {
        $__branchOk = false;
        foreach (getBranches() as $__b) { if ((int)$__b['id'] === $branch_id) { $__branchOk = true; break; } }
        if (!$__branchOk) { header("Location: settings.php?tab=staff&error=branch_required"); exit; }
    }
    $telegramContact = parseTelegramContactInput($_POST['tech_tg'] ?? '');
    if (!$telegramContact['valid']) {
        header("Location: settings.php?tab=staff&error=telegram_contact_invalid");
        exit;
    }
    $active = isset($_POST['is_active']) ? 1 : 0;
    $username = trim($_POST['tech_username'] ?? '');
    $password = $_POST['tech_password'] ?? '';
    $engineer_rate = floatval($_POST['engineer_rate'] ?? 0);
    // Odměna z času v systému (brigádník) — smí přepnout jen admin
    $pay_by_time = $is_admin_check ? (isset($_POST['pay_by_time']) ? 1 : 0) : null;

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
    // Role Brigádník VŽDY znamená odměnu z času — přepínač se nedá vypnout omylem
    if ($role === 'brigadnik' && $pay_by_time !== null) { $pay_by_time = 1; }
    $pbtSql = $pay_by_time !== null ? ", pay_by_time = " . (int)$pay_by_time : "";
    if (!empty($password)) {
        $sql = "UPDATE technicians SET name = ?, email = ?, phone = ?, specialization = ?, role = ?, branch_id = ?, telegram_id = ?, telegram_username = ?, is_active = ?, username = ?, password = ?, engineer_rate = ?" . $pbtSql . " WHERE id = ?";
        $params = [$name, $email, $phone, $spec, $role, $branch_id, $telegramContact['id'], $telegramContact['username'], $active, $username_val, password_hash($password, PASSWORD_DEFAULT), $engineer_rate, $id];
    } else {
        $sql = "UPDATE technicians SET name = ?, email = ?, phone = ?, specialization = ?, role = ?, branch_id = ?, telegram_id = ?, telegram_username = ?, is_active = ?, username = ?, engineer_rate = ?" . $pbtSql . " WHERE id = ?";
        $params = [$name, $email, $phone, $spec, $role, $branch_id, $telegramContact['id'], $telegramContact['username'], $active, $username_val, $engineer_rate, $id];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($is_admin_check) {
        // Hromadný přesun zakázek technika mezi pobočkami JEN při skutečné změně
        // pobočky — dřív běžel při KAŽDÉM uložení karty zaměstnance (tichý přesun).
        $__prevBranch = null;
        try { $bs = $pdo->prepare('SELECT branch_id FROM technicians WHERE id = ?'); $bs->execute([$id]); $__prevBranch = $bs->fetchColumn(); } catch (Throwable $e) {}
        if ($__prevBranch === null || (int)$__prevBranch !== (int)$branch_id) {
            $pdo->prepare('UPDATE orders SET branch_id = ? WHERE technician_id = ?')->execute([$branch_id, $id]);
        }
    }
    crmAuditLog('staff.update', [
        'entity_type' => 'technician', 'entity_id' => (int)$id, 'entity_label' => $name,
        'summary' => 'Upraven zaměstnanec ' . $name . ' (role: ' . $role . ', ' . ($active ? 'aktivní' : 'neaktivní') . ')',
    ]);
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
    $__tn = '';
    try { $ns = $pdo->prepare("SELECT name FROM technicians WHERE id = ?"); $ns->execute([$_POST['delete_tech']]); $__tn = (string)$ns->fetchColumn(); } catch (Throwable $e) {}
    $stmt = $pdo->prepare("DELETE FROM technicians WHERE id = ?");
    $stmt->execute([$_POST['delete_tech']]);
    crmAuditLog('staff.delete', [
        'entity_type' => 'technician', 'entity_id' => (int)$_POST['delete_tech'], 'entity_label' => $__tn,
        'summary' => 'Smazán zaměstnanec ' . ($__tn !== '' ? $__tn : ('#' . (int)$_POST['delete_tech'])),
    ]);
    header("Location: settings.php?tab=staff");
    exit;
}

if (isset($_POST['save_permissions']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    setTechPermissions($_POST['tech_id'], $_POST['permissions'] ?? []);
    $__pn = ''; try { $ns = $pdo->prepare("SELECT name FROM technicians WHERE id = ?"); $ns->execute([(int)$_POST['tech_id']]); $__pn = (string)$ns->fetchColumn(); } catch (Throwable $e) {}
    crmAuditLog('staff.permissions', [
        'entity_type' => 'technician', 'entity_id' => (int)$_POST['tech_id'], 'entity_label' => $__pn,
        'summary' => 'Změna oprávnění: ' . ($__pn !== '' ? $__pn : ('#' . (int)$_POST['tech_id'])),
        'details' => ['opravneni' => array_values((array)($_POST['permissions'] ?? []))],
    ]);
    header("Location: settings.php?tab=staff&perms_updated=1");
    exit;
}

if (isset($_POST['change_admin_password']) && hasPermission('manage_passwords')) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    if (strlen($_POST['new_password']) >= 8) {
        $hashed = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashed, $_POST['admin_id']]);
        crmAuditLog('admin.password', [
            'entity_type' => 'user', 'entity_id' => (int)$_POST['admin_id'],
            'summary' => 'Změna hesla administrátora (účet #' . (int)$_POST['admin_id'] . ')',
        ]);

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
    crmAuditLog('admin.create', [
        'entity_type' => 'user', 'entity_id' => (int)$pdo->lastInsertId(), 'entity_label' => (string)$staffRow['name'],
        'summary' => 'Zaměstnanec ' . (string)$staffRow['name'] . ' povýšen na administrátora (login @' . $login . ')',
    ]);
    header("Location: settings.php?tab=admins&admin_added=1");
    exit;
}

// Odebrání admin práv zaměstnaneckému účtu (technicians.role='admin' → 'engineer').
// Účet zaměstnance zůstává, jen ztrácí administrátorská práva. Sám sobě nelze.
if (isset($_POST['demote_tech_admin']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $dtId = (int)($_POST['demote_tech_admin'] ?? 0);
    if ($dtId > 0 && (int)($_SESSION['tech_id'] ?? 0) !== $dtId) {
        $ns = $pdo->prepare("SELECT name FROM technicians WHERE id = ? AND role = 'admin'");
        $ns->execute([$dtId]);
        $dtName = (string)$ns->fetchColumn();
        if ($dtName !== '') {
            $pdo->prepare("UPDATE technicians SET role = 'engineer' WHERE id = ?")->execute([$dtId]);
            crmAuditLog('admin.demote', [
                'entity_type' => 'technician', 'entity_id' => $dtId, 'entity_label' => $dtName,
                'summary' => 'Zaměstnanci ' . $dtName . ' odebrána administrátorská práva (role → Technik)',
            ]);
        }
    }
    header("Location: settings.php?tab=admins&admin_deleted=1");
    exit;
}

// Odstranění administrátorského účtu (jen admin). Pojistky: výchozího
// administrátora 'admin' smazat NELZE (záchranný účet) a nikdo nesmí
// smazat sám sebe (aby se nezamkl uprostřed práce).
if (isset($_POST['delete_admin']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    $delId = (int)($_POST['delete_admin'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ?");
    $stmt->execute([$delId]);
    $delAdmin = $stmt->fetch();
    if (!$delAdmin) { header("Location: settings.php?tab=admins"); exit; }
    if (strtolower((string)$delAdmin['username']) === 'admin') {
        header("Location: settings.php?tab=admins&error=admin_protected"); exit;
    }
    if (is_numeric($_SESSION['user_id'] ?? null) && (int)$_SESSION['user_id'] === $delId) {
        header("Location: settings.php?tab=admins&error=admin_self_delete"); exit;
    }
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$delId]);
    crmAuditLog('admin.delete', [
        'entity_type' => 'user', 'entity_id' => $delId, 'entity_label' => (string)($delAdmin['full_name'] ?: $delAdmin['username']),
        'summary' => 'Odstraněn administrátorský účet @' . $delAdmin['username'] . ' (' . ($delAdmin['full_name'] ?: '—') . ')',
    ]);
    header("Location: settings.php?tab=admins&admin_deleted=1");
    exit;
}

if (isset($_POST['clear_logs']) && $is_admin_check) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) { die(__('csrf_invalid')); }
    try { $pdo->query("DELETE FROM system_errors"); } catch (Exception $e) {}
    header("Location: settings.php?tab=system&sub=databaze&logs_cleared=1");
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
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Změna nastavení — Systém']);
    header("Location: settings.php?tab=system&sub=databaze&updated=1");
    exit;
}

$is_admin_user = hasPermission('admin_access');
$can_view_all_staff = $is_admin_user || in_array(getCurrentStaffRole(), ['manager', 'boss'], true) || hasPermission('view_reports_all');

$active_tab = $_GET['tab'] ?? ($is_admin_user ? 'company' : 'staff');

// ── Sloučení do záložky „Systém": Integrace + Databáze + Aktualizace ──────────
// Staré přímé odkazy i POST přesměrování (?tab=integrations|updates|backups)
// mapujeme na system + odpovídající pod-sekci, ať staré URL a záložky fungují dál.
$__sysMerge = ['integrations' => 'integrace', 'updates' => 'aktualizace', 'backups' => 'databaze'];
if (isset($__sysMerge[$active_tab])) {
    if (!isset($_GET['sub'])) { $_GET['sub'] = $__sysMerge[$active_tab]; }
    $active_tab = 'system';
}
// Pod-sekce Systému a kdo je smí: Integrace/Databáze jen admin, Aktualizace i vedení.
$sys_subs_allowed = [];
if ($is_admin_user) { $sys_subs_allowed[] = 'integrace'; $sys_subs_allowed[] = 'databaze'; }
if (crmCanRunUpdates()) { $sys_subs_allowed[] = 'aktualizace'; }
$sys_sub = (string)($_GET['sub'] ?? '');
if (!in_array($sys_sub, $sys_subs_allowed, true)) { $sys_sub = $sys_subs_allowed[0] ?? 'integrace'; }
// Pod-navigace Systému — vykreslí se nahoře v právě aktivní pod-sekci.
$sysSubnav = function (string $cur) use ($sys_subs_allowed) {
    $items = [
        'integrace'   => ['fa-plug', __('integrations_tab')],
        'databaze'    => ['fa-database', __('database_header')],
        'aktualizace' => ['fa-cloud-download-alt', __('updates_tab')],
    ];
    echo '<ul class="nav nav-pills mb-4 gap-2 border-bottom border-secondary pb-3">';
    foreach ($items as $key => $it) {
        if (!in_array($key, $sys_subs_allowed, true)) { continue; }
        $isActive = ($cur === $key);
        echo '<li class="nav-item"><a class="nav-link ' . ($isActive ? 'active' : 'text-white-75')
           . '" href="?tab=system&sub=' . $key . '"><i class="fas ' . $it[0] . ' me-2"></i>'
           . htmlspecialchars($it[1]) . '</a></li>';
    }
    echo '</ul>';
};

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
    // Systém smí manažer/Boss jen kvůli Aktualizacím; jinak je celý admin-only.
    $__blocked = in_array($active_tab, ['company', 'loyalty', 'banka', 'admins'], true)
        || ($active_tab === 'system' && !crmCanRunUpdates());
    if ($__blocked) { $active_tab = 'staff'; }
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
                    'csrf' => 'Form expired, please refresh the page.',
                    'branch_required' => 'Zaměstnanec musí mít vybranou pobočku — vyber ji a ulož znovu.'
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
            <a class="nav-link <?php echo $active_tab == 'loyalty' ? 'active' : 'text-white-75'; ?>" href="?tab=loyalty"><i class="fas fa-id-card me-2"></i>Věrnostní karta</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'banka' ? 'active' : 'text-white-75'; ?>" href="?tab=banka"><i class="fas fa-building-columns me-2"></i>Banka</a>
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
            <a class="nav-link <?php echo $active_tab == 'admins' ? 'active' : 'text-white-75'; ?>" href="?tab=admins"><i class="fas fa-user-shield me-2"></i><?php echo __('admin_tab'); ?></a>
        </li>
        <?php endif; ?>
        <?php /* Systém = Integrace + Databáze + Aktualizace. Admin vidí vše; manažer/Boss jen kvůli Aktualizacím. */ ?>
        <?php if ($is_admin_user || crmCanRunUpdates()): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $active_tab == 'system' ? 'active' : 'text-white-75'; ?>" href="?tab=system" id="updatesNavLink"><i class="fas fa-server me-2"></i><?php echo __('system_tab'); ?> <span id="updateBadgeNav" class="badge bg-warning text-dark ms-1" style="display:none;">!</span></a>
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
                            <div class="col-12">
                                <label class="form-label text-white-75 small"><i class="fab fa-google me-1"></i>Odkaz na Google recenzi (posílá se klientům po vydání zakázky)</label>
                                <input type="text" name="google_review_url" class="form-control" value="<?php echo htmlspecialchars(get_setting('google_review_url', 'https://search.google.com/local/writereview?placeid=ChIJb5zqZRmVC0cR_ysPp2WZD8M')); ?>">
                                <div class="form-text small">Otevře klientovi přímo okno psaní recenze (pobočka Křižíkova 29, Karlín). Vymazáním pole se děkovné e-maily s žádostí o recenzi vypnou.</div>
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

        <!-- SYSTÉM › INTEGRACE -->
        <div class="tab-pane fade <?php echo ($active_tab == 'system' && $sys_sub == 'integrace' && $is_admin_user) ? 'show active' : ''; ?>">
            <?php $sysSubnav('integrace'); ?>
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
                            <div class="form-text small text-white-75 mt-2"><?php echo __('service_id_hint'); ?></div>

                            <hr class="border-secondary my-4">
                            <h6 class="small fw-bold text-info mb-3"><i class="fas fa-comment-sms me-2"></i>SMS brána GoSMS.cz</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small text-white-75">Client ID</label>
                                    <input type="text" name="gosms_client_id" class="form-control" value="<?php echo htmlspecialchars(get_setting('gosms_client_id', '')); ?>" placeholder="z GoSMS → Můj účet → API">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-white-75">Client Secret</label>
                                    <input type="password" name="gosms_client_secret" class="form-control" value="" placeholder="<?php echo get_setting('gosms_client_secret', '') !== '' ? '••••••• (uloženo — vyplň jen při změně)' : 'client secret'; ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-white-75">Kanál (číslo)</label>
                                    <input type="number" name="gosms_channel" class="form-control" value="<?php echo htmlspecialchars(get_setting('gosms_channel', '')); ?>" placeholder="ID kanálu z GoSMS">
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="sms_pickup_enabled" id="smsPickupEnabled" <?php echo get_setting('sms_pickup_enabled', '0') === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="smsPickupEnabled">Posílat klientům SMS, když je zakázka <b>připravena k vyzvednutí</b> <span class="text-white-50 small">(v jazyce klienta, každé zakázce jen jednou)</span></label>
                                    </div>
                                </div>
                                <div class="col-12 d-flex gap-2 align-items-center">
                                    <input type="text" id="smsTestNumber" class="form-control" style="max-width:220px;" placeholder="+420777123456">
                                    <button type="button" class="btn btn-outline-info" onclick="afxSmsTest()"><i class="fas fa-paper-plane me-1"></i>Poslat testovací SMS</button>
                                    <span id="smsTestResult" class="small text-white-75"></span>
                                </div>
                            </div>
                            <script>
                            function afxSmsTest() {
                                var n = document.getElementById('smsTestNumber').value.trim();
                                var out = document.getElementById('smsTestResult');
                                if (!n) { out.textContent = 'Zadej číslo.'; return; }
                                out.textContent = 'Odesílám…';
                                $.post('api/sms_test.php', { phone: n }, function (r) {
                                    out.textContent = r.success ? '✓ Odesláno — zkontroluj telefon.' : ('✗ ' + (r.message || 'Chyba'));
                                }, 'json').fail(function () { out.textContent = '✗ Chyba spojení'; });
                            }
                            </script>
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
                            Z každé nové rezervace se <strong>automaticky založí zakázka „Přijato"</strong> (a nový zákazník, pokud v databázi ještě není) — objeví se rovnou v Zakázkách. Termín z webu, způsob předání i poznámka zákazníka se přenesou do poznámky technikovi. Kdyby se automatické založení nepovedlo (např. bez zařízení), rezervace zůstane nahoře v Zakázkách k ručnímu převzetí.
                        </div>
                        <?php $afxWbLast = (string)get_setting('web_booking_last_payload', ''); ?>
                        <details class="mt-2">
                            <summary class="small text-white-75" style="cursor:pointer;">Diagnostika: poslední přijatý payload z webu <?php echo $afxWbLast === '' ? '(zatím nic nedorazilo)' : ''; ?></summary>
                            <pre class="small text-white-75 mt-2 p-2 border border-secondary rounded" style="max-height:260px; overflow:auto; white-space:pre-wrap;"><?php echo htmlspecialchars($afxWbLast !== '' ? $afxWbLast : '—'); ?></pre>
                        </details>
                    </div>

                    <div class="col-12 border-top border-secondary pt-3">
                        <h5 class="mb-3 text-info"><i class="fas fa-tags me-2"></i>Ceník oprav z applefix.cz</h5>
                        <?php
                            ensureRepairPricelistTable();
                            $plCount = 0; $plModels = 0;
                            try {
                                $plCount = (int)$pdo->query("SELECT COUNT(*) FROM repair_pricelist")->fetchColumn();
                                $plModels = (int)$pdo->query("SELECT COUNT(DISTINCT CONCAT(brand,'|',model)) FROM repair_pricelist")->fetchColumn();
                            } catch (Throwable $e) {}
                            $plLast = get_setting('pricelist_last_sync', '');
                        ?>
                        <div class="small text-white-75 mb-2">
                            Ceny oprav se stahují z objednávkového formuláře na applefix.cz (RepairPlugin) a nabízejí se při zakládání zakázky.
                            Aktuálně v ceníku: <b class="text-white"><?php echo $plCount; ?></b> položek pro <b class="text-white"><?php echo $plModels; ?></b> modelů<?php echo $plLast !== '' ? ' · poslední načtení: ' . e($plLast) : ''; ?>.
                        </div>
                        <button type="button" class="btn btn-outline-info" id="afxSyncPricelistBtn">
                            <i class="fas fa-rotate me-1"></i> Načíst ceník Apple z webu
                        </button>
                        <span class="small text-white-75 ms-2" id="afxSyncPricelistStatus"></span>
                        <script>
                        (function () {
                            var btn = document.getElementById('afxSyncPricelistBtn');
                            if (!btn) return;
                            btn.addEventListener('click', async function () {
                                var st = document.getElementById('afxSyncPricelistStatus');
                                var cats = ['Smartphone', 'Tablet', 'Notebook', 'Stolní počítač'];
                                btn.disabled = true;
                                var total = 0;
                                for (var i = 0; i < cats.length; i++) {
                                    st.textContent = 'Načítám ' + cats[i] + '… (' + (i + 1) + '/' + cats.length + ')';
                                    try {
                                        var fd = new FormData();
                                        fd.append('category', cats[i]);
                                        fd.append('brand', 'Apple');
                                        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
                                        var r = await fetch('api/sync_pricelist.php', { method: 'POST', body: fd });
                                        var j = await r.json();
                                        if (j.ok) { total += j.rows; } else { st.textContent = 'Chyba (' + cats[i] + '): ' + j.error; }
                                    } catch (e) { st.textContent = 'Chyba spojení (' + cats[i] + ')'; }
                                }
                                st.textContent = 'Hotovo — načteno ' + total + ' cenových položek. Obnov stránku pro souhrn.';
                                btn.disabled = false;
                            });
                        })();
                        </script>
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

        <!-- BANKA (KB API) TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'banka' ? 'show active' : ''; ?>">
            <?php if ($active_tab == 'banka'): require_once 'includes/kb_api.php'; ensureBankTables(); ?>
            <div class="glass-panel p-4 border-secondary mb-3">
                <h5 class="mb-1"><i class="fas fa-building-columns me-2 text-info"></i>Napojení na Komerční banku (KB API)</h5>
                <p class="text-white-50 small mb-3">Přímý přístup k účtu (ADAA): CRM stahuje pohyby a automaticky páruje příchozí platby s fakturami podle VS — jako v Money S3. Postup registrace: <a href="https://developers.kb.cz" target="_blank" class="text-info">developers.kb.cz</a> → API klíče (OAuth2 + ADAA); pro produkci kvalifikovaný certifikát I.CA/PostSignum → registrace aplikace → souhlas KB Klíčem (platí 12 měsíců).</p>
                <div class="mb-2">
                    Stav: <?php echo kbApiConfigured()
                        ? '<span class="badge bg-success">nakonfigurováno</span> <span class="text-white-50 small">prostředí: ' . e(kbApiEnv()) . ' · poslední sync: ' . (get_setting('kb_last_sync_at', '') ?: 'nikdy') . '</span>'
                        : '<span class="badge bg-secondary">nepřipojeno</span>'; ?>
                </div>
                <form id="kbSettingsForm" class="row g-3" autocomplete="off">
                    <div class="col-md-3">
                        <label class="form-label small">Prostředí</label>
                        <select name="kb_env" class="form-select">
                            <option value="sandbox" <?php echo kbApiEnv() === 'sandbox' ? 'selected' : ''; ?>>Sandbox (testovací)</option>
                            <option value="prod" <?php echo kbApiEnv() === 'prod' ? 'selected' : ''; ?>>Produkce (ostrý účet)</option>
                        </select>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label small">Účet (accountId z KB — vyplní „Načíst účty")</label>
                        <div class="input-group">
                            <input type="text" name="kb_account_id" class="form-control" value="<?php echo e(get_setting('kb_account_id', '')); ?>">
                            <button type="button" class="btn btn-outline-info" id="kbTestBtn">Načíst účty / otestovat</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">API klíč — ADAA</label>
                        <input type="text" name="kb_api_key_adaa" class="form-control" value="<?php echo e(get_setting('kb_api_key_adaa', '')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">API klíč — OAuth2</label>
                        <input type="text" name="kb_api_key_oauth" class="form-control" value="<?php echo e(get_setting('kb_api_key_oauth', '')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Client ID</label>
                        <input type="text" name="kb_client_id" class="form-control" value="<?php echo e(get_setting('kb_client_id', '')); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Client secret <span class="text-white-50">(prázdné = beze změny)</span></label>
                        <input type="password" name="kb_client_secret" class="form-control" placeholder="<?php echo get_setting('kb_client_secret', '') !== '' ? '••••••• uloženo' : ''; ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Refresh token <span class="text-white-50">(z autorizace KB Klíčem; prázdné = beze změny)</span></label>
                        <input type="password" name="kb_refresh_token" class="form-control" placeholder="<?php echo get_setting('kb_refresh_token', '') !== '' ? '••••••• uloženo' : ''; ?>">
                    </div>
                    <div class="col-12 d-flex gap-2 align-items-center">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Uložit nastavení banky</button>
                        <span id="kbSettingsMsg" class="small text-white-50"></span>
                    </div>
                </form>
            </div>
            <script>
            (function () {
                var CSRF = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
                var form = document.getElementById('kbSettingsForm');
                if (!form) return;
                form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var fd = new FormData(form);
                    fd.append('csrf_token', CSRF);
                    fetch('api/kb_settings.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            document.getElementById('kbSettingsMsg').textContent = d.success ? 'Uloženo.' : ('Chyba: ' + (d.message || ''));
                        })
                        .catch(function () { document.getElementById('kbSettingsMsg').textContent = 'Síťová chyba.'; });
                });
                document.getElementById('kbTestBtn').addEventListener('click', function () {
                    var msg = document.getElementById('kbSettingsMsg');
                    msg.textContent = 'Testuji spojení… (nezapomeň nejdřív Uložit)';
                    var fd = new FormData();
                    fd.append('action', 'test');
                    fd.append('csrf_token', CSRF);
                    fetch('api/kb_sync.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (!d.success) { msg.textContent = 'Chyba: ' + (d.message || ''); return; }
                            if (!d.accounts.length) { msg.textContent = 'Spojení OK, ale žádné účty.'; return; }
                            var inp = form.querySelector('[name=kb_account_id]');
                            if (!inp.value) { inp.value = d.accounts[0].accountId; }
                            msg.textContent = 'Spojení OK — účty: ' + d.accounts.map(function (a) { return a.iban || a.accountId; }).join(', ') + ' (accountId doplněno, ulož).';
                        })
                        .catch(function () { msg.textContent = 'Síťová chyba.'; });
                });
            })();
            </script>
            <?php endif; ?>
        </div>

        <!-- VĚRNOSTNÍ KARTA TAB -->
        <div class="tab-pane fade <?php echo $active_tab == 'loyalty' ? 'show active' : ''; ?>">
            <?php
                $lc = crmLoyaltyConfig();
                $secDir = crmWalletCertDir();
                $hasP12  = is_file($secDir . '/apple_cert.p12');
                $hasWwdr = is_file($secDir . '/apple_wwdr.pem');
                $hasGoog = is_file($secDir . '/google_service_account.json');
                $appleOk = crmWalletAppleReady();
                $googOk  = crmWalletGoogleReady();
                $cardCount = 0;
                try { $cardCount = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE card_token IS NOT NULL AND card_token <> ''")->fetchColumn(); } catch (Throwable $e) {}
            ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 text-white"><i class="fas fa-id-card me-2 text-primary"></i>Věrnostní karta &amp; peněženky</h5>
                <span class="badge bg-secondary"><?php echo $cardCount; ?> vydaných karet</span>
            </div>

            <div class="alert border-info bg-transparent text-white-75 small mb-4" style="border-left:3px solid #0dcaf0;">
                <b class="text-info">Jak to funguje:</b> Každému klientovi se při zadání do systému vygeneruje karta s QR kódem.
                Klient si ji přidá do Apple / Google Peněženky. Při další návštěvě recepce QR naskenuje (firemní iPhone/čtečka)
                a systém okamžitě otevře klienta i jeho zakázky. Za každou vyzvednutou opravu se přičtou věrnostní body.
                <br><span class="text-white-50">Pozn.: skutečné „pípnutí" NFC není běžným obchodníkům dostupné — QR sken je okamžitá a spolehlivá náhrada.</span>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfField(); ?>

                <!-- Body -->
                <div class="glass-panel p-3 border-secondary mb-4">
                    <h6 class="text-info mb-3"><i class="fas fa-star me-2"></i>Věrnostní body</h6>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="loyalty_enabled" name="loyalty_enabled" <?php echo $lc['enabled'] ? 'checked' : ''; ?>>
                        <label class="form-check-label text-white" for="loyalty_enabled">Věrnostní systém zapnutý</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Body za dokončenou zakázku</label>
                            <input type="number" min="0" class="form-control" name="loyalty_points_per_order" value="<?php echo (int)$lc['points_per_order']; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Body za každých 100 Kč ceny opravy</label>
                            <input type="number" min="0" class="form-control" name="loyalty_points_per_100" value="<?php echo (int)$lc['points_per_100']; ?>">
                        </div>
                    </div>
                    <div class="small text-white-50 mt-2">Body se připíší jednou při vyzvednutí zakázky. Interní zakázky se nepočítají.</div>
                </div>

                <!-- Apple Wallet -->
                <div class="glass-panel p-3 border-secondary mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0 text-white"><i class="fab fa-apple me-2"></i>Apple Wallet</h6>
                        <span class="badge <?php echo $appleOk ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $appleOk ? 'Připraveno' : 'Nenakonfigurováno'; ?></span>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="wallet_apple_enabled" name="wallet_apple_enabled" <?php echo get_setting('wallet_apple_enabled','0')==='1' ? 'checked' : ''; ?>>
                        <label class="form-check-label text-white" for="wallet_apple_enabled">Nabízet „Přidat do Apple Wallet"</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Pass Type ID <span class="text-white-50">(pass.type.…)</span></label>
                            <input type="text" class="form-control" name="wallet_apple_pass_type_id" value="<?php echo e(get_setting('wallet_apple_pass_type_id','')); ?>" placeholder="pass.cloud.applefix.loyalty">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Team ID <span class="text-white-50">(10 znaků)</span></label>
                            <input type="text" class="form-control" name="wallet_apple_team_id" value="<?php echo e(get_setting('wallet_apple_team_id','')); ?>" placeholder="ABCDE12345">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Certifikát Pass Type ID (.p12) <?php echo $hasP12 ? '<span class="badge bg-success ms-1">nahráno</span>' : ''; ?></label>
                            <input type="file" class="form-control" name="wallet_apple_p12" accept=".p12">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Heslo k .p12</label>
                            <input type="password" class="form-control" name="wallet_apple_p12_pass" placeholder="<?php echo get_setting('wallet_apple_p12_pass','')!=='' ? '•••••• (uloženo)' : 'heslo z exportu'; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Apple WWDR certifikát (.pem) <?php echo $hasWwdr ? '<span class="badge bg-success ms-1">nahráno</span>' : ''; ?></label>
                            <input type="file" class="form-control" name="wallet_apple_wwdr" accept=".pem,.cer,.crt">
                        </div>
                    </div>
                    <div class="small text-white-50 mt-2">Certifikát vytvoříš v <a href="https://developer.apple.com/account/resources/certificates" target="_blank" rel="noopener" class="text-info">Apple Developer</a> (Pass Type ID + certifikát), exportuješ z Keychain jako .p12. WWDR G4 stáhneš z Apple.</div>
                </div>

                <!-- Google Wallet -->
                <div class="glass-panel p-3 border-secondary mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0 text-white"><i class="fab fa-google me-2"></i>Google Wallet</h6>
                        <span class="badge <?php echo $googOk ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $googOk ? 'Připraveno' : 'Nenakonfigurováno'; ?></span>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="wallet_google_enabled" name="wallet_google_enabled" <?php echo get_setting('wallet_google_enabled','0')==='1' ? 'checked' : ''; ?>>
                        <label class="form-check-label text-white" for="wallet_google_enabled">Nabízet „Přidat do Google Wallet"</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Issuer ID</label>
                            <input type="text" class="form-control" name="wallet_google_issuer_id" value="<?php echo e(get_setting('wallet_google_issuer_id','')); ?>" placeholder="3388000000000000000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white-75">Service-account JSON <?php echo $hasGoog ? '<span class="badge bg-success ms-1">nahráno</span>' : ''; ?></label>
                            <input type="file" class="form-control" name="wallet_google_json" accept=".json,application/json">
                        </div>
                    </div>
                    <div class="small text-white-50 mt-2">Issuer ID a service-account získáš v <a href="https://pay.google.com/business/console" target="_blank" rel="noopener" class="text-info">Google Pay &amp; Wallet Console</a> → API access.</div>
                </div>

                <button type="submit" name="update_loyalty" class="btn btn-primary px-5"><?php echo __('save'); ?></button>
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
                        // Admin účty (Správa administrátorů) mají přednost před rolí na kartě
                        // zaměstnance — jinak povýšený admin dál svítil jako Manažer/Technik.
                        $__adminRows = [];
                        if (function_exists('ensureUsersBranchColumn')) { ensureUsersBranchColumn(); }
                        try { $__adminRows = $pdo->query("SELECT id, username, full_name, branch_id FROM users ORDER BY full_name")->fetchAll(); } catch (Throwable $e) { try { $__adminRows = $pdo->query("SELECT id, username, full_name FROM users ORDER BY full_name")->fetchAll(); } catch (Throwable $e2) {} }
                        $__adminUsernames = array_map('strval', array_column($__adminRows, 'username'));
                        // Admini, kteří mají JEN účet v users (ne technika) — dřív ve výpisu
                        // zaměstnanců chyběli, ačkoliv se níže objevovali u přiřazení zvuků.
                        // Doplníme je, ať výpis sedí a jsou vidět všichni.
                        $__techUsernames = array_map(static fn($x) => strtolower((string)($x['username'] ?? '')), $techs);
                        $__adminOnly = $can_view_all_staff ? array_values(array_filter($__adminRows, static function ($u) use ($__techUsernames) {
                            return trim((string)$u['username']) !== '' && !in_array(strtolower((string)$u['username']), $__techUsernames, true);
                        })) : [];
                        foreach ($techs as $t): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($t['name']); ?></strong></td>
                            <td>@<?php echo htmlspecialchars($t['username'] ?? '-'); ?></td>
                            <td>
                                <?php 
                                $r = $t['role'] ?? 'engineer';
                                if($r == 'admin' || in_array((string)($t['username'] ?? ''), $__adminUsernames, true)) echo '<span class="badge bg-danger">'.__('role_admin').'</span>';
                                elseif($r == 'boss') echo '<span class="badge bg-warning text-dark">Boss</span>';
                                elseif($r == 'manager') echo '<span class="badge bg-primary">'.__('role_manager').'</span>';
                                elseif($r == 'brigadnik') echo '<span class="badge bg-success bg-opacity-75"><i class="far fa-clock me-1"></i>Brigádník</span>';
                                else echo '<span class="badge bg-info-glow">'.__('role_engineer').'</span>';
                                ?>
                            </td>
                            <td><span class="badge bg-dark border border-secondary"><i class="fas fa-store me-1"></i><?php echo e(getBranchLabel((int)($t['branch_id'] ?? 0))); ?></span></td>
                            <td><span class="badge glass-panel text-white border-secondary"><?php echo htmlspecialchars($t['specialization']); ?></span></td>
                            <td>
                                <?php if (!empty($t['telegram_id'])): ?>
                                    <code class="small"><?php echo htmlspecialchars($t['telegram_id']); ?></code>
                                    <button class="btn btn-link btn-sm p-0 ms-1 text-info" title="<?php echo __('test_notification'); ?>" onclick="testTechTG(<?php echo $t['id']; ?>)"><i class="fab fa-telegram-plane"></i></button>
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
                        <?php foreach ($__adminOnly as $au): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($au['full_name'] ?: $au['username']); ?></strong></td>
                            <td>@<?php echo htmlspecialchars($au['username']); ?></td>
                            <td><span class="badge bg-danger"><?php echo __('role_admin'); ?></span></td>
                            <td><span class="badge bg-dark border border-secondary"><i class="fas fa-store me-1"></i><?php echo e(getBranchLabel((int)($au['branch_id'] ?? 0) ?: getDefaultBranchId())); ?></span></td>
                            <td><span class="text-white-50 small">—</span></td>
                            <td><span class="text-white-50 small">—</span></td>
                            <td><span class="badge bg-success"><?php echo __('active_status'); ?></span></td>
                            <td class="text-end"><a href="settings.php?tab=admins" class="btn btn-outline-secondary btn-sm" title="<?php echo __('role_admin'); ?>"><i class="fas fa-user-shield"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php if ($is_admin_user && $active_tab == 'staff'): ?>
        <?php
            $greetingStaff = [];
            try {
                // Bez duplicit podle username: aktivní technik má přednost, pak admini,
                // kteří mají účet jen v users. Dřív se člověk v obou tabulkách počítal 2×.
                $__gSeen = [];
                foreach ($pdo->query('SELECT username, name FROM technicians WHERE is_active = 1 ORDER BY name')->fetchAll() as $gt) {
                    $u = strtolower(trim((string)$gt['username'])); if ($u === '' || isset($__gSeen[$u])) continue;
                    $__gSeen[$u] = true; $greetingStaff[] = $gt;
                }
                foreach ($pdo->query('SELECT username, full_name AS name FROM users ORDER BY full_name')->fetchAll() as $gu) {
                    $u = strtolower(trim((string)$gu['username'])); if ($u === '' || isset($__gSeen[$u])) continue;
                    $__gSeen[$u] = true; $greetingStaff[] = $gu;
                }
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

        <!-- SYSTÉM › DATABÁZE (jazyk + systémová nastavení + logy; serverové zálohy níže) -->
        <div class="tab-pane fade <?php echo ($active_tab == 'system' && $sys_sub == 'databaze' && $is_admin_user) ? 'show active' : ''; ?>">
            <?php $sysSubnav('databaze'); ?>
            <div class="row g-4">
                <div class="col-md-6 border-end border-secondary">
                    <h5 class="mb-3 text-white"><i class="fas fa-globe me-2 text-info"></i><?php echo __('system_langs'); ?></h5>
                    <form method="POST" class="row g-2 align-items-center">
                        <?php echo csrfField(); ?>
                        <div class="col-auto">
                            <select name="lang" class="form-select bg-dark text-white border-secondary">
                                <option value="ru" <?php echo crm_get_language() === 'ru' ? 'selected' : ''; ?>><?php echo __('lang_ru'); ?> (RU)</option>
                                <option value="cs" <?php echo crm_get_language() === 'cs' ? 'selected' : ''; ?>><?php echo __('lang_cs'); ?> (CS)</option>
                                <option value="en" <?php echo crm_get_language() === 'en' ? 'selected' : ''; ?>><?php echo __('lang_en'); ?> (EN)</option>
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
            <?php elseif (($_GET['error'] ?? '') === 'admin_protected'): ?>
                <div class="alert alert-danger py-2"><i class="fas fa-triangle-exclamation me-2"></i>Výchozího administrátora „admin" nelze odstranit — je to záchranný účet.</div>
            <?php elseif (($_GET['error'] ?? '') === 'admin_self_delete'): ?>
                <div class="alert alert-danger py-2"><i class="fas fa-triangle-exclamation me-2"></i>Vlastní administrátorský účet si odstranit nemůžeš — požádej jiného administrátora.</div>
            <?php elseif (!empty($_GET['admin_deleted'])): ?>
                <div class="alert alert-success py-2"><i class="fas fa-check me-2"></i>Administrátorský přístup byl odstraněn.</div>
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
                        // Admini jsou ve DVOU tabulkách: users (samostatné admin účty)
                        // a technicians s role='admin' (zaměstnanecký účet s plnými právy).
                        // Dřív se ukazovali jen ti první → seznam byl neúplný.
                        $techAdmins = [];
                        try { $techAdmins = $pdo->query("SELECT id, name, username, is_active FROM technicians WHERE role = 'admin' ORDER BY name")->fetchAll(); } catch (Throwable $e) {}
                        foreach ($admins as $admin): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($admin['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($admin['full_name']); ?></td>
                            <td><span class="badge bg-danger"><?php echo __('role_admin'); ?></span></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#adminPwdModal<?php echo $admin['id']; ?>"><i class="fas fa-key me-1"></i> <?php echo __('password_btn'); ?></button>
                                    <?php $__isDefaultAdmin = strtolower((string)$admin['username']) === 'admin';
                                          $__isSelf = is_numeric($_SESSION['user_id'] ?? null) && (int)$_SESSION['user_id'] === (int)$admin['id'];
                                          if (!$__isDefaultAdmin && !$__isSelf): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="delete_admin" value="<?php echo (int)$admin['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger" data-confirm="Opravdu odstranit administrátorský přístup @<?php echo e($admin['username']); ?>? Účet zaměstnance (pokud existuje) zůstává."><i class="fas fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php foreach ($techAdmins as $ta): ?>
                        <tr>
                            <td><strong>@<?php echo htmlspecialchars((string)($ta['username'] ?? '-')); ?></strong></td>
                            <td><?php echo htmlspecialchars($ta['name']); ?><?php echo ($ta['is_active'] ?? 1) ? '' : ' <span class="badge bg-secondary ms-1">neaktivní</span>'; ?></td>
                            <td><span class="badge bg-danger"><?php echo __('role_admin'); ?></span> <span class="text-white-50 small">zaměstnanecký účet</span></td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="settings.php?tab=staff" class="btn btn-outline-secondary" title="Heslo a údaje se spravují na kartě zaměstnance"><i class="fas fa-user-edit me-1"></i>Karta zaměstnance</a>
                                    <?php $__isSelfTech = (int)($_SESSION['tech_id'] ?? 0) === (int)$ta['id'];
                                          if (!$__isSelfTech): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
                                        <input type="hidden" name="demote_tech_admin" value="<?php echo (int)$ta['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger" data-confirm="Odebrat administrátorská práva zaměstnanci <?php echo e($ta['name']); ?>? Účet zůstane, role se změní na Technik."><i class="fas fa-user-minus"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
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
                        <h5 class="mb-1 text-white"><i class="fas fa-server me-2 text-success"></i>Tisk štítků přes server (doporučeno)</h5>
                        <div class="small text-white-75 mb-3">Štítky tiskne přímo server CRM na tiskárnu Brother na pobočce — funguje z jakéhokoliv počítače, iPadu i Safari, bez instalací.</div>
                        <div id="srvPrintStatus" class="alert alert-secondary py-2 mb-3"><i class="fas fa-circle-notch fa-spin me-2"></i>Zjišťuji stav…</div>
                        <div class="row g-2 align-items-end mb-2" style="max-width:520px;">
                            <div class="col-7">
                                <label class="form-label small text-white-75">IP tiskárny (Brother QL-810W, Karlín)</label>
                                <input type="text" id="srvPrinterIp" class="form-control font-monospace" value="<?php echo e(get_setting('label_printer_ip', '192.168.1.220')); ?>">
                            </div>
                            <div class="col-5 d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary" onclick="srvSaveIp()">Uložit</button>
                                <button type="button" class="btn btn-outline-success" onclick="srvTestPrint()"><i class="fas fa-print me-1"></i>Testovací štítek</button>
                            </div>
                        </div>
                        <script>
                        function srvStatus() {
                            fetch('api/print_label_server.php?action=status', { cache: 'no-store' })
                                .then(function (r) { return r.json(); })
                                .then(function (d) {
                                    var el = document.getElementById('srvPrintStatus');
                                    if (d.printer_reachable) {
                                        el.className = 'alert alert-success py-2 mb-3';
                                        el.innerHTML = '<i class="fas fa-check-circle me-2"></i>Server vidí tiskárnu <strong>' + d.printer_ip + '</strong>' + (d.env_ready ? '' : ' · první tisk chvíli potrvá (server si připraví prostředí)');
                                    } else {
                                        el.className = 'alert alert-warning py-2 mb-3';
                                        el.innerHTML = '<i class="fas fa-triangle-exclamation me-2"></i>Tiskárna <strong>' + d.printer_ip + '</strong> ze serveru neodpovídá — zkontroluj, že je zapnutá.';
                                    }
                                }).catch(function () {});
                        }
                        function srvSaveIp() {
                            var fd = new FormData();
                            fd.append('action', 'save_ip');
                            fd.append('ip', document.getElementById('srvPrinterIp').value.trim());
                            fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
                            fetch('api/print_label_server.php', { method: 'POST', body: fd })
                                .then(function (r) { return r.json(); })
                                .then(function (j) { if (!j.ok) { alert(j.error || 'Chyba'); } srvStatus(); });
                        }
                        function srvTestPrint() {
                            var fd = new FormData();
                            fd.append('action', 'test');
                            fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
                            var el = document.getElementById('srvPrintStatus');
                            el.className = 'alert alert-secondary py-2 mb-3';
                            el.innerHTML = '<i class="fas fa-circle-notch fa-spin me-2"></i>Tisknu testovací štítek…';
                            fetch('api/print_label_server.php', { method: 'POST', body: fd })
                                .then(function (r) { return r.json(); })
                                .then(function (j) {
                                    el.className = j.ok ? 'alert alert-success py-2 mb-3' : 'alert alert-danger py-2 mb-3';
                                    el.innerHTML = j.ok ? '<i class="fas fa-check-circle me-2"></i>Testovací štítek odeslán na tiskárnu.' : '<i class="fas fa-triangle-exclamation me-2"></i>' + (j.error || 'Chyba');
                                });
                        }
                        document.addEventListener('DOMContentLoaded', srvStatus);
                        </script>
                        <hr class="border-secondary my-4">
                        <h5 class="mb-1 text-white"><i class="fas fa-print me-2 text-info"></i><?php echo __('label_bridge_title'); ?> <span class="badge bg-secondary ms-1">záložní řešení</span></h5>
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
                        var isSafari = /^((?!chrome|android|crios|edg).)*safari/i.test(navigator.userAgent);
                        var extra = isSafari
                            ? '<div class="small mt-2"><b>Používáš Safari</b> — Safari neumí ověřit můstek z HTTPS stránky (blokuje localhost). Otevři CRM v <b>Chrome</b>.</div>'
                            : '<div class="small mt-2">Pokud instalace v Terminálu proběhla, ověř příkazem: <code>curl http://127.0.0.1:9110/health</code>.<br>Když odpoví <code>{"ok": true…}</code>, můstek běží — spusť instalační příkaz ještě jednou (stáhne aktualizovaný můstek s podporou nového zabezpečení Chrome) a klikni Zkontrolovat.</div>';
                        st.innerHTML = '<i class="fas fa-triangle-exclamation me-2"></i><?php echo __('label_bridge_not_running'); ?>' + extra;
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

        <!-- SYSTÉM › AKTUALIZACE — vedení (admin, manažer, Boss) -->
        <?php if (crmCanRunUpdates()): ?>
        <div class="tab-pane fade <?php echo ($active_tab == 'system' && $sys_sub == 'aktualizace') ? 'show active' : ''; ?>">
            <?php $sysSubnav('aktualizace'); ?>
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
                                <div class="glass-panel p-3 text-center border-secondary h-100">
                                    <div class="text-white-75 small"><?php echo __('current_version'); ?></div>
                                    <div class="h4 text-white mb-1" id="localVersion">Verze <?php echo e(crmAppVersion()); ?></div>
                                    <?php if (!empty($gitInfo['local_date'])): ?>
                                        <div class="small text-white-75"><i class="far fa-calendar-check me-1 text-info"></i><?php echo e(crmCzechDateTime((string)$gitInfo['local_date'])); ?></div>
                                    <?php endif; ?>
                                    <div class="text-muted mt-1" style="font-size:.72rem;" title="technické označení sestavení">
                                        <?php echo htmlspecialchars(trim($branchLabel . ' @ ' . $localShort)); ?> · <?php echo !empty($gitInfo['dirty']) ? 'dirty' : 'clean'; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="glass-panel p-3 text-center border-secondary h-100">
                                    <div class="text-white-75 small"><?php echo __('latest_version'); ?></div>
                                    <div class="h4 text-muted mb-1" id="remoteVersion"><?php echo !empty($gitInfo['remote_version']) ? 'Verze ' . e((string)$gitInfo['remote_version']) : htmlspecialchars(trim($branchLabel . ' @ ' . $remoteShort)); ?></div>
                                    <div class="small text-white-75" id="remoteReleaseDate"><?php echo !empty($gitInfo['remote_date']) ? '<i class="far fa-calendar-check me-1 text-info"></i>' . e(crmCzechDateTime((string)$gitInfo['remote_date'])) : ''; ?></div>
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
                                        <?php if (!empty($hz['version'])): ?><span class="badge bg-success me-1">v<?php echo e((string)$hz['version']); ?></span><?php endif; ?>
                                        <span class="badge bg-primary me-2"><?php echo e(date('d.m.Y', strtotime((string)$hz['date']))); ?><?php if (!empty($hz['time'])): ?> · <?php echo e((string)$hz['time']); ?><?php endif; ?></span>
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

        <!-- SYSTÉM › DATABÁZE (serverové zálohy — zobrazí se pod databázovou kartou) -->
        <div class="tab-pane fade <?php echo ($active_tab == 'system' && $sys_sub == 'databaze' && $is_admin_user) ? 'show active' : ''; ?>">
            <?php if ($active_tab == 'system' && $sys_sub == 'databaze' && $is_admin_user):
                $__backups = crmListBackups();
                $__bkLastStatus = get_setting('backup_last_status', '');
                $__bkLastRun = (int)get_setting('backup_last_run', '0');
                $__fmtB = function ($b) { $b = (int)$b; if ($b >= 1048576) return number_format($b / 1048576, 1, ',', ' ') . ' MB'; if ($b >= 1024) return number_format($b / 1024, 0, ',', ' ') . ' kB'; return $b . ' B'; };
            ?>
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <h5 class="mb-1 text-white"><i class="fas fa-database me-2 text-info"></i>Zálohy CRM</h5>
                    <div class="small text-white-75">Automaticky každých 15 minut: celá databáze (zakázky, klienti, faktury, historie) + nahrané soubory + kód. Zálohy starší 48 hodin se mažou samy.</div>
                </div>
                <button type="button" class="btn btn-primary" id="btnBackupNow"><i class="fas fa-plus me-2"></i>Zálohovat teď</button>
            </div>

            <div class="glass-panel p-3 border-secondary mb-3 small">
                <i class="fas fa-heartbeat me-2 <?php echo (time() - $__bkLastRun) < 1200 ? 'text-success' : 'text-warning'; ?>"></i>
                Poslední záloha: <strong><?php echo $__bkLastRun > 0 ? date('d.m.Y H:i:s', $__bkLastRun) : 'zatím žádná'; ?></strong>
                <?php if ($__bkLastStatus !== ''): ?> · stav: <?php echo e($__bkLastStatus); ?><?php endif; ?>
                <?php if ($__bkLastRun > 0 && (time() - $__bkLastRun) > 3600): ?>
                    <span class="text-warning ms-2"><i class="fas fa-exclamation-triangle me-1"></i>Poslední záloha je starší než hodinu — zálohy se spouštějí, jen když CRM někdo používá (nebo přijde webová objednávka).</span>
                <?php endif; ?>
            </div>

            <div class="table-responsive glass-panel border-secondary">
                <table class="table table-dark table-hover align-middle mb-0">
                    <thead><tr><th>Čas zálohy</th><th>Databáze</th><th>Soubory</th><th>Kód</th><th>Obsah</th><th class="text-end">Akce</th></tr></thead>
                    <tbody>
                    <?php if (empty($__backups)): ?>
                        <tr><td colspan="6" class="text-center text-white-50 py-4">Zatím žádné zálohy — první se vytvoří do 15 minut, nebo klikni na „Zálohovat teď".</td></tr>
                    <?php else: foreach ($__backups as $bk): ?>
                        <tr>
                            <td style="white-space:nowrap;">
                                <strong><?php echo date('d.m.Y H:i:s', $bk['time']); ?></strong>
                                <?php if ($bk['prerestore']): ?><span class="badge bg-warning text-dark ms-2" title="Automatická pojistná kopie vytvořená před obnovou">pojistka před obnovou</span><?php endif; ?>
                            </td>
                            <td><?php echo $__fmtB($bk['db_bytes']); ?></td>
                            <td><?php echo $bk['files_bytes'] > 0 ? $__fmtB($bk['files_bytes']) : '—'; ?></td>
                            <td><?php echo $bk['code_bytes'] > 0 ? $__fmtB($bk['code_bytes']) : '<span class="text-white-50" title="Kód se od předchozí zálohy nezměnil — kryje ho starší záloha a git">beze změny</span>'; ?></td>
                            <td class="small text-white-75"><?php echo $bk['orders'] !== null ? ((int)$bk['orders'] . ' zakázek, ' . (int)$bk['customers'] . ' klientů') : '—'; ?><?php echo $bk['git'] !== '' ? ' · ' . e($bk['git']) : ''; ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-warning" onclick="restoreBackup('<?php echo e($bk['name']); ?>', '<?php echo date('d.m.Y H:i:s', $bk['time']); ?>')"><i class="fas fa-history me-1"></i>Obnovit</button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="small text-white-50 mt-2"><i class="fas fa-info-circle me-1"></i>Obnova vrátí databázi a nahrané soubory do stavu zálohy. Před každou obnovou se automaticky uloží pojistná kopie aktuálního stavu. Kód CRM se obnovou nemění (spravuje ho git v záložce Aktualizace).</div>

            <script>
            document.getElementById('btnBackupNow')?.addEventListener('click', function() {
                const btn = this;
                btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Zálohuji…';
                $.post('api/backup_action.php', {op: 'run'}, function(res) {
                    btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus me-2"></i>Zálohovat teď';
                    if (res.success) { location.reload(); } else { showAlert(res.message || 'Záloha selhala'); }
                }, 'json').fail(function() { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus me-2"></i>Zálohovat teď'; showAlert('Chyba spojení'); });
            });
            function restoreBackup(name, timeLabel) {
                showConfirm('Opravdu obnovit CRM do stavu ze ' + timeLabel + '?<br><br>Všechny změny provedené PO tomto čase zmizí (databáze i soubory, včetně účtů založených později). Aktuální stav se před obnovou automaticky zazálohuje.', function() {
                    // druhé potvrzení až po dokončení zavírací animace modalu
                    // (okamžité znovuotevření téže Bootstrap instance by se zahodilo)
                    setTimeout(function() {
                        showConfirm('POSLEDNÍ POTVRZENÍ: obnovit zálohu ' + timeLabel + '? Tato akce přepíše aktuální data.', function() {
                            const html = '<div class="text-center py-3"><span class="spinner-border me-2"></span>Obnovuji zálohu, nezavírej stránku…</div>';
                            document.body.insertAdjacentHTML('beforeend', '<div id="restoreOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:99999;display:flex;align-items:center;justify-content:center;color:#fff;">' + html + '</div>');
                            $.post('api/backup_action.php', {op: 'restore', name: name}, function(res) {
                                document.getElementById('restoreOverlay')?.remove();
                                if (res.success) { showAlert(res.message); setTimeout(() => location.reload(), 1500); }
                                else { showAlert(res.message || 'Obnova selhala'); }
                            }, 'json').fail(function() {
                                document.getElementById('restoreOverlay')?.remove();
                                showAlert('Spojení se přerušilo — obnova ale na serveru může stále běžet. Počkej minutu, obnov stránku a zkontroluj stav v této záložce; neklikej hned znovu na Obnovit.');
                            });
                        });
                    }, 450);
                });
            }
            </script>
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
                        <option value="brigadnik">Brigádník</option>
                        <option value="manager"><?php echo __('role_manager'); ?></option>
                        <option value="boss">Boss</option>
                        <option value="admin"><?php echo __('role_admin'); ?></option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?php echo __('spec_col'); ?></label>
                    <input type="text" name="tech_spec" class="form-control" placeholder="<?php echo __('spec_placeholder'); ?>">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label"><i class="fas fa-store me-1"></i>Pobočka</label>
                    <select name="branch_id" class="form-select" required>
                        <?php $__defB = (int)getDefaultBranchId(); foreach ($branches_settings as $branch): ?>
                            <option value="<?php echo (int)$branch['id']; ?>" <?php echo (int)$branch['id'] === $__defB ? 'selected' : ''; ?>><?php echo e($branch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('email'); ?></label><input type="email" name="tech_email" class="form-control"></div><div class="col-md-6 mb-3"><label class="form-label"><?php echo __('phone_label'); ?></label><input type="text" name="tech_phone" class="form-control"></div></div>
            <div class="mb-3">
                <label class="form-label">Telegram ID nebo @username</label>
                <input type="text" name="tech_tg" class="form-control" value="" placeholder="<?php echo __('tg_placeholder'); ?>">
                <div class="form-text small"><?php echo __('tech_tg_hint'); ?></div>
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
                        <option value="brigadnik" <?php echo ($t['role'] ?? 'engineer') == 'brigadnik' ? 'selected' : ''; ?>>Brigádník</option>
                        <option value="manager" <?php echo ($t['role'] ?? 'engineer') == 'manager' ? 'selected' : ''; ?>><?php echo __('role_manager'); ?></option>
                        <option value="boss" <?php echo ($t['role'] ?? 'engineer') == 'boss' ? 'selected' : ''; ?>>Boss</option>
                        <option value="admin" <?php echo ($t['role'] ?? 'engineer') == 'admin' ? 'selected' : ''; ?>><?php echo __('role_admin'); ?></option>
                    </select>
                    <?php else: ?>
                        <div class="form-control bg-dark bg-opacity-25 border-secondary text-white"><?php echo ($t['role'] ?? 'engineer') == 'admin' ? __('role_admin') : (($t['role'] ?? 'engineer') == 'boss' ? 'Boss' : (($t['role'] ?? 'engineer') == 'manager' ? __('role_manager') : (($t['role'] ?? 'engineer') == 'brigadnik' ? 'Brigádník' : __('role_engineer')))); ?></div>
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
                    <select name="branch_id" class="form-select" required>
                        <?php
                        // PAST „prvního vybraného": technik s NULL/neaktivní pobočkou by bez
                        // selected spadl na první pobočku a uložení by ho tiše přeřadilo
                        // (a hromadně přesunulo i jeho zakázky). Doplníme aktuální hodnotu.
                        $__tb = (int)($t['branch_id'] ?? 0);
                        $__tbInList = false;
                        foreach ($branches_settings as $branch) { if ((int)$branch['id'] === $__tb) { $__tbInList = true; break; } }
                        if (!$__tbInList): ?>
                            <?php if ($__tb > 0): ?>
                                <option value="<?php echo $__tb; ?>" selected><?php echo e(getBranchLabel($__tb)); ?> (neaktivní pobočka)</option>
                            <?php else: ?>
                                <option value="" selected disabled>— vyber pobočku (povinné) —</option>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php foreach ($branches_settings as $branch): ?>
                            <option value="<?php echo (int)$branch['id']; ?>" <?php echo $__tb === (int)$branch['id'] ? 'selected' : ''; ?>><?php echo e($branch['name']); ?></option>
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
                    <label class="form-label"><?php echo __('email'); ?></label>
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
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" name="pay_by_time" id="payByTime<?php echo $t['id']; ?>" <?php echo !empty($t['pay_by_time']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="payByTime<?php echo $t['id']; ?>">Odměna z času v systému <span class="text-white-50 small">(brigádník — počítají se hodiny, ne zakázky)</span></label>
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

            // Update remote version display — stejný formát jako PHP render („Verze 1.6.2");
            // git štítek (main @ hash) se použije jen když číslo verze není k dispozici
            const rv = document.getElementById('remoteVersion');
            if (data.remote_version) {
                rv.textContent = /^\d+\.\d+\.\d+$/.test(data.remote_version) ? ('Verze ' + data.remote_version) : data.remote_version;
            }
            rv.className = data.has_update ? 'h4 text-success mb-0' : 'h4 text-white mb-0';
            
            const rd = document.getElementById('remoteReleaseDate');
            if (rd && data.release_date) {
                rd.innerHTML = '<i class="far fa-calendar-check me-1 text-info"></i>' + escapeHtml(crmCzechDateTimeJS(data.release_date));
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

// Lidsky čitelné datum a čas v češtině — stejný formát jako PHP crmCzechDateTime().
function crmCzechDateTimeJS(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d.getTime())) return String(iso);
    const days = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
    const months = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
    const pad = n => String(n).padStart(2, '0');
    let out = days[d.getDay()] + ' ' + d.getDate() + '. ' + months[d.getMonth()] + ' ' + d.getFullYear()
        + ' · ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const day = new Date(d); day.setHours(0, 0, 0, 0);
    const diff = Math.round((today - day) / 86400000);
    if (diff === 0) out += ' (dnes)';
    else if (diff === 1) out += ' (včera)';
    else if (diff >= 2 && diff <= 6) out += ' (před ' + diff + ' dny)';
    return out;
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
                <div class="mt-2"><a href="settings.php?tab=updates" class="btn btn-sm btn-outline-light"><i class="fas fa-redo me-1"></i> <?php echo __('reload_btn'); ?></a></div>
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

