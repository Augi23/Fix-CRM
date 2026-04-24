<?php
/**
 * Helper Functions for CRM
 */

/**
 * Return the effective internal staff role for the current employee session.
 */
function getCurrentStaffRole(): string {
    if (($_SESSION['role'] ?? '') === 'admin') {
        return 'admin';
    }

    if (($_SESSION['role'] ?? '') === 'technician') {
        return (string)($_SESSION['internal_role'] ?? 'engineer');
    }

    return (string)($_SESSION['role'] ?? '');
}

/**
 * Check if current user has a specific permission.
 * All permissions are loaded from the DB once per session and cached in $_SESSION['_perms'].
 * Call invalidatePermissionsCache() whenever permissions or the session is updated.
 */
function hasPermission($permission) {
    global $pdo;

    // Admins always have all permissions
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }

    // Technicians/Staff – use session-level cache
    if (($_SESSION['role'] ?? '') === 'technician' && isset($_SESSION['tech_id'])) {
        if (!isset($_SESSION['_perms'])) {
            $stmt = $pdo->prepare('SELECT permission FROM tech_permissions WHERE technician_id = ?');
            $stmt->execute([$_SESSION['tech_id']]);
            $_SESSION['_perms'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // admin_access grants everything
        if (in_array('admin_access', $_SESSION['_perms'], true)) {
            return true;
        }

        $implicitPermissions = [];
        if (getCurrentStaffRole() === 'manager') {
            $implicitPermissions = [
                'view_all_orders',
                'edit_orders',
                'edit_customers',
                'manage_inventory',
                'procurement_manage',
                'view_reports_all',
            ];
        }

        return in_array($permission, $_SESSION['_perms'], true)
            || in_array($permission, $implicitPermissions, true);
    }

    return false;
}

/**
 * Invalidate the in-session permissions cache.
 * Call after setTechPermissions() or on logout.
 */
function invalidatePermissionsCache(): void {
    unset($_SESSION['_perms']);
}

/**
 * Return all active technicians.
 * Result is statically cached for the lifetime of the current PHP request.
 */
function getActiveTechnicians(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $cache = $pdo->query(
            'SELECT id, name FROM technicians WHERE is_active = 1 ORDER BY name ASC'
        )->fetchAll();
    } catch (Exception $e) {
        $cache = [];
    }
    return $cache;
}


/**
 * Get all permissions for a technician
 */
function getTechPermissions($tech_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT permission FROM tech_permissions WHERE technician_id = ?");
    $stmt->execute([$tech_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Set permissions for a technician (replaces all existing)
 */
function setTechPermissions($tech_id, $permissions) {
    global $pdo;
    
    // Delete existing
    $stmt = $pdo->prepare("DELETE FROM tech_permissions WHERE technician_id = ?");
    $stmt->execute([$tech_id]);
    
    // Insert new
    if (!empty($permissions)) {
        $stmt = $pdo->prepare("INSERT INTO tech_permissions (technician_id, permission) VALUES (?, ?)");
        foreach ($permissions as $perm) {
            $stmt->execute([$tech_id, $perm]);
        }
    }

    // Invalidate session permission cache so changes take effect immediately
    invalidatePermissionsCache();
}

/**
 * Available permissions list with descriptions
 */
function getAvailablePermissions() {
    return [
        'admin_access' => ['name' => __('perm_admin_access'), 'desc' => __('perm_admin_access_desc'), 'icon' => 'fas fa-crown text-warning'],
        'view_all_orders' => ['name' => __('perm_view_all_orders'), 'desc' => __('perm_view_all_orders_desc'), 'icon' => 'fas fa-eye text-info'],
        'edit_orders' => ['name' => __('perm_edit_orders'), 'desc' => __('perm_edit_orders_desc'), 'icon' => 'fas fa-edit text-primary'],
        'edit_customers' => ['name' => __('perm_edit_customers'), 'desc' => __('perm_edit_customers_desc'), 'icon' => 'fas fa-user-edit text-success'],
        'manage_inventory' => ['name' => 'Inventory management', 'desc' => 'Can edit inventory, adjust stock quantity, and work with the parts catalog.', 'icon' => 'fas fa-boxes text-info'],
        'procurement_manage' => ['name' => 'Procurement management', 'desc' => 'Can manage the procurement queue, mark parts as ordered/received, and delete requests.', 'icon' => 'fas fa-truck-loading text-warning'],
        'view_reports_all' => ['name' => 'Reports for all employees', 'desc' => 'Can view summary and individual reports for all technicians.', 'icon' => 'fas fa-chart-line text-success'],
        'manage_passwords' => ['name' => __('perm_manage_passwords'), 'desc' => __('perm_manage_passwords_desc'), 'icon' => 'fas fa-key text-danger'],
    ];
}

function getDeviceIcon($type) {
    switch ($type) {
        case 'Phone': return '📱';
        case 'Notebook': return '💻';
        case 'PC': return '🖥️';
        case 'Tablet': return '📟';
        case 'HDD': return '💾';
        case 'Computer': return '🖥️';
        default: return '🛠️';
    }
}

function normalizePhoneForTel(?string $phone): string {
    $phone = trim((string)$phone);
    if ($phone === '') {
        return '';
    }

    $hasPlus = strpos($phone, '+') === 0;
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === null || $digits === '') {
        return '';
    }

    return $hasPlus ? ('+' . $digits) : $digits;
}

function normalizeEmailForMailto(?string $email): string {
    $email = trim((string)$email);
    if ($email === '') {
        return '';
    }

    return str_replace(["\r", "\n"], '', $email);
}

function getOrderStatusAliases(): array {
    return [
        'new' => ['New', 'Новый', 'Nová'],
        'pending_approval' => ['Pending Approval', 'На согласовании', 'K odsouhlasení', 'Čeká na schválení'],
        'in_progress' => ['In Progress', 'В работе', 'V práci', 'V procesu', 'Provádí se'],
        'waiting_parts' => ['Waiting for Parts', 'Ожидание запчастей', 'Čeká na díly'],
        'completed' => ['Completed', 'Готов', 'Hotovo'],
        'collected' => ['Collected', 'Выдан', 'Vydáno'],
        'cancelled' => ['Cancelled', 'Отменен', 'Zrušeno'],
        'done' => ['Completed', 'Collected', 'Готов', 'Выдан', 'Hotovo', 'Vydáno'],
    ];
}

function getOrderStatusList(string $key): array {
    $aliases = getOrderStatusAliases();
    return $aliases[$key] ?? [$key];
}

function sqlPlaceholders(array $values): string {
    return implode(',', array_fill(0, count($values), '?'));
}

function getStatusBadge($status) {
    switch ($status) {
        case 'New':
        case 'Новый':
        case 'Nová':
            return '<span class="badge bg-primary">'.__('new').'</span>';
        case 'Pending Approval':
        case 'На согласовании':
        case 'K odsouhlasení':
        case 'Čeká na schválení':
            return '<span class="badge bg-info text-dark">'.__('pending_approval').'</span>';
        case 'In Progress':
        case 'В работе':
        case 'V práci':
        case 'V procesu':
        case 'Provádí se':
            return '<span class="badge bg-warning">'.__('in_progress').'</span>';
        case 'Waiting for Parts':
        case 'Ожидание запчастей':
        case 'Čeká na díly':
            return '<span class="badge bg-secondary">'.__('waiting_parts').'</span>';
        case 'Completed':
        case 'Готов':
        case 'Hotovo':
            return '<span class="badge bg-success">'.__('status_completed').'</span>';
        case 'Collected':
        case 'Выдан':
        case 'Vydáno':
            return '<span class="badge bg-info text-dark">'.__('status_collected').'</span>';
        case 'Cancelled':
        case 'Отменен':
        case 'Zrušeno':
            return '<span class="badge bg-danger">'.__('status_cancelled').'</span>';
        default:
            return '<span class="badge bg-dark">' . $status . '</span>';
    }
}

function formatMoney($amount) {
    global $pdo;
    $currency = get_setting('currency', 'Kč');
    return number_format($amount, 2, '.', ' ') . ' ' . $currency;
}

function get_setting($key, $default = '') {
    global $pdo;
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false) {
            $cache[$key] = $val;
            return $val;
        }
    } catch (Exception $e) {
        $cache[$key] = $default;
        return $default;
    }
    $cache[$key] = $default;
    return $default;
}

function get_setting_with_fallback($key, $default = '', ?string $envKey = null) {
    $value = trim((string) get_setting($key, ''));
    if ($value !== '') {
        return $value;
    }

    if ($envKey) {
        $envValue = trim((string) getenv($envKey));
        if ($envValue !== '') {
            return $envValue;
        }
    }

    return $default;
}

function set_setting($key, $value) {
    global $pdo;
    $stmt = $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    return $stmt->execute([$key, $value]);
}

function getDeviceBrands() {
    global $pdo;
    try {
        return $pdo->query("SELECT brand_name FROM device_brands ORDER BY brand_name ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return ['Apple', 'Samsung', 'Xiaomi', 'Other'];
    }
}

function getSupplierCatalogs(): array {
    return [
        'mobilnidily' => [
            'name' => 'Mobilnidily.cz',
            'host' => 'mobilnidily.cz',
            'default_url' => 'https://www.mobilnidily.cz/nahradni-dily-apple/',
        ],
        'refurb-zone' => [
            'name' => 'refurb.zone',
            'host' => 'refurb.zone',
            'default_url' => 'https://refurb.zone/',
        ],
        'fixshop' => [
            'name' => 'fixshop.cz',
            'host' => 'fixshop.cz',
            'default_url' => 'https://fixshop.cz/',
        ],
    ];
}

function supplierKeyFromUrl(string $url): string {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return '';
    }

    foreach (getSupplierCatalogs() as $key => $supplier) {
        $supplierHost = strtolower((string)($supplier['host'] ?? ''));
        if ($supplierHost !== '' && ($host === $supplierHost || str_ends_with($host, '.' . $supplierHost))) {
            return $key;
        }
    }

    return '';
}

function supplierLabel(string $supplierKey): string {
    $catalogs = getSupplierCatalogs();
    return (string)($catalogs[$supplierKey]['name'] ?? $supplierKey);
}

function ensureProcurementSchema(): bool {
    global $pdo;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NULL,
            supplier_key VARCHAR(50) NOT NULL,
            inventory_id INT NULL,
            item_name VARCHAR(255) NOT NULL,
            sku VARCHAR(80) DEFAULT NULL,
            quantity INT NOT NULL DEFAULT 1,
            priority ENUM('today','this_week','later') NOT NULL DEFAULT 'this_week',
            status ENUM('pending','ordered','received','cancelled') NOT NULL DEFAULT 'pending',
            notes TEXT NULL,
            requested_by INT NULL,
            ordered_by INT NULL,
            ordered_at TIMESTAMP NULL DEFAULT NULL,
            received_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_supplier (supplier_key),
            INDEX idx_order (order_id),
            INDEX idx_inventory (inventory_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("ALTER TABLE `inventory` ADD COLUMN `source_supplier` VARCHAR(50) DEFAULT NULL");
    } catch (Throwable $e) {
        // ignore duplicate column/table errors below
    }

    try {
        $pdo->exec("ALTER TABLE `inventory` ADD COLUMN `source_url` VARCHAR(255) DEFAULT NULL");
    } catch (Throwable $e) {
        // ignore duplicate column/table errors below
    }

    return true;
}

function queueProcurementRequestFromOrder(int $orderId, int $inventoryId, int $quantity, string $notes = ''): bool {
    global $pdo;

    if ($orderId <= 0 || $inventoryId <= 0 || $quantity <= 0) {
        return false;
    }

    ensureProcurementSchema();

    $stmt = $pdo->prepare("SELECT i.id, i.part_name, i.sku, i.source_supplier, i.source_url FROM inventory i WHERE i.id = ? LIMIT 1");
    $stmt->execute([$inventoryId]);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inventory) {
        return false;
    }

    $supplierKey = trim((string)($inventory['source_supplier'] ?? ''));
    if ($supplierKey === '') {
        $supplierKey = supplierKeyFromUrl((string)($inventory['source_url'] ?? ''));
    }
    if ($supplierKey === '') {
        $catalogUrl = trim((string)get_setting('inventory_catalog_url', ''));
        $supplierKey = supplierKeyFromUrl($catalogUrl);
    }
    if ($supplierKey === '') {
        $suppliers = array_keys(getSupplierCatalogs());
        $supplierKey = $suppliers[0] ?? '';
    }
    if ($supplierKey === '') {
        return false;
    }

    $itemName = trim((string)($inventory['part_name'] ?? ''));
    if ($itemName === '') {
        return false;
    }

    $sku = trim((string)($inventory['sku'] ?? ''));
    $requestedBy = $_SESSION['user_id'] ?? ($_SESSION['tech_id'] ?? null);

    $stmt = $pdo->prepare("SELECT id, quantity FROM purchase_requests WHERE order_id = ? AND inventory_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$orderId, $inventoryId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $upd = $pdo->prepare("UPDATE purchase_requests SET quantity = quantity + ?, notes = CASE WHEN notes IS NULL OR notes = '' THEN ? ELSE notes END WHERE id = ?");
        $upd->execute([$quantity, $notes !== '' ? $notes : null, $existing['id']]);
        return true;
    }

    $stmt = $pdo->prepare("INSERT INTO purchase_requests (order_id, supplier_key, inventory_id, item_name, sku, quantity, priority, status, notes, requested_by) VALUES (?, ?, ?, ?, ?, ?, 'this_week', 'pending', ?, ?)");
    $stmt->execute([
        $orderId,
        $supplierKey,
        $inventoryId,
        $itemName,
        $sku !== '' ? $sku : null,
        $quantity,
        $notes !== '' ? $notes : null,
        $requestedBy,
    ]);

    return true;
}

/**
 * Log System Error
 */
function log_error($message, $type = 'system', $details = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO system_errors (error_type, message, details) VALUES (?, ?, ?)");
        $stmt->execute([$type, $message, $details]);
    } catch (Exception $e) {
        // Fallback to file if DB fails
        error_log("DB Log Failed: " . $message . " | " . $details);
    }
}

function ensureTechnicianTelegramSchema(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    $done = true;

    try {
        $hasUsername = !empty($pdo->query("SHOW COLUMNS FROM technicians LIKE 'telegram_username'")->fetchAll());
        if (!$hasUsername) {
            $pdo->exec("ALTER TABLE technicians ADD COLUMN telegram_username VARCHAR(64) DEFAULT NULL AFTER telegram_id");
        }
        try {
            $pdo->exec("CREATE INDEX idx_technicians_telegram_username ON technicians (telegram_username)");
        } catch (Exception $e) {
        }
    } catch (Exception $e) {
        error_log('ensureTechnicianTelegramSchema failed: ' . $e->getMessage());
    }
}

function parseTelegramContactInput($value): array {
    $raw = trim((string)$value);
    if ($raw === '') {
        return ['id' => null, 'username' => null, 'valid' => true, 'raw' => ''];
    }

    $compact = preg_replace('/\s+/u', '', $raw);
    if ($compact === null) $compact = $raw;

    if (preg_match('/^\+?\d+$/', $compact)) {
        return [
            'id' => ltrim($compact, '+'),
            'username' => null,
            'valid' => true,
            'raw' => $raw,
        ];
    }

    $username = ltrim($compact, '@');
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $username)) {
        return [
            'id' => null,
            'username' => strtolower($username),
            'valid' => true,
            'raw' => $raw,
        ];
    }

    return ['id' => null, 'username' => null, 'valid' => false, 'raw' => $raw];
}

function telegramContactDisplayValue($telegramId, $telegramUsername = null): string {
    $telegramId = trim((string)$telegramId);
    if ($telegramId !== '') return $telegramId;
    $telegramUsername = trim((string)$telegramUsername);
    if ($telegramUsername !== '') return '@' . ltrim($telegramUsername, '@');
    return '';
}

function runGitCommand(string $repoRoot, string $command, ?int &$exitCode = null): string {
    $cmd = 'git -C ' . escapeshellarg($repoRoot) . ' ' . $command . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $exitCode = $code;
    return trim(implode("\n", $output));
}

function gitRemoteSlug(string $repoRoot): ?string {
    $code = 0;
    $url = runGitCommand($repoRoot, 'config --get remote.origin.url', $code);
    if ($code !== 0 || $url === '') {
        return null;
    }
    if (preg_match('~github\.com[:/](.+?)(?:\.git)?$~', $url, $m)) {
        return trim($m[1]);
    }
    return null;
}

function getGitRepoInfo(string $repoRoot): array {
    $repoRoot = rtrim($repoRoot, '/');
    $info = [
        'repo_root' => $repoRoot,
        'branch' => 'unknown',
        'local_commit' => '',
        'local_short' => '',
        'local_date' => '',
        'dirty' => false,
        'remote_commit' => '',
        'remote_short' => '',
        'remote_date' => '',
        'ahead_by' => null,
        'behind_by' => null,
        'update_available' => null,
        'changelog' => [],
        'remote_slug' => gitRemoteSlug($repoRoot),
        'error' => null,
    ];

    if (!is_dir($repoRoot . '/.git')) {
        $info['error'] = 'Repository not found';
        return $info;
    }

    $code = 0;
    $branch = runGitCommand($repoRoot, 'rev-parse --abbrev-ref HEAD', $code);
    if ($code === 0 && $branch !== '') {
        $info['branch'] = $branch;
    }

    $head = runGitCommand($repoRoot, 'rev-parse HEAD', $code);
    if ($code === 0 && $head !== '') {
        $info['local_commit'] = $head;
        $info['local_short'] = substr($head, 0, 7);
    }

    $localDate = runGitCommand($repoRoot, "show -s --format=%cI HEAD", $code);
    if ($code === 0 && $localDate !== '') {
        $info['local_date'] = $localDate;
    }

    $dirty = runGitCommand($repoRoot, 'status --porcelain', $code);
    $info['dirty'] = ($code === 0 && $dirty !== '');

    $remoteSlug = $info['remote_slug'];
    if ($remoteSlug) {
        $remoteUrl = 'https://github.com/' . $remoteSlug . '.git';
        $fetchCode = 0;
        $remoteBranch = $branch !== '' ? $branch : 'main';
        runGitCommand($repoRoot, 'fetch --quiet ' . escapeshellarg($remoteUrl) . ' ' . escapeshellarg($remoteBranch), $fetchCode);
        if ($fetchCode === 0) {
            $remote = runGitCommand($repoRoot, 'rev-parse FETCH_HEAD', $code);
            if ($code === 0 && $remote !== '') {
                $info['remote_commit'] = $remote;
                $info['remote_short'] = substr($remote, 0, 7);
            }
            $remoteDate = runGitCommand($repoRoot, 'show -s --format=%cI FETCH_HEAD', $code);
            if ($code === 0 && $remoteDate !== '') {
                $info['remote_date'] = $remoteDate;
            }
            $counts = runGitCommand($repoRoot, 'rev-list --left-right --count HEAD...FETCH_HEAD', $code);
            if ($code === 0 && preg_match('/^(\d+)\s+(\d+)$/', $counts, $m)) {
                $info['ahead_by'] = (int)$m[1];
                $info['behind_by'] = (int)$m[2];
                $info['update_available'] = ((int)$m[2]) > 0;
            }
            $log = runGitCommand($repoRoot, "log --format='%H|%h|%cI|%s' -n 8 HEAD", $code);
            if ($code === 0 && $log !== '') {
                foreach (explode("\n", $log) as $row) {
                    $parts = explode('|', $row, 4);
                    if (count($parts) === 4) {
                        $info['changelog'][] = [
                            'hash' => $parts[0],
                            'short' => $parts[1],
                            'date' => $parts[2],
                            'message' => $parts[3],
                        ];
                    }
                }
            }
        } else {
            $info['error'] = $fetchCode === 128 ? 'GitHub access denied or repository unavailable' : 'Git fetch failed';
        }
    } else {
        $info['error'] = 'Origin remote is not a GitHub repository';
    }

    return $info;
}

function crmNormalizeTelegramChatId($chatId): ?string {
    $chatId = trim((string)$chatId);
    if ($chatId === '') {
        return null;
    }

    if ($chatId[0] === '+') {
        $chatId = substr($chatId, 1);
    }

    return preg_match('/^-?\d+$/', $chatId) ? $chatId : null;
}

function crmTelegramEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function crmBuildOrderViewLink(int $orderId): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim(dirname($scriptName), '/');

    if (substr($basePath, -4) === '/api') {
        $basePath = substr($basePath, 0, -4);
    }

    $basePath = rtrim($basePath === '.' ? '' : $basePath, '/');
    $path = ($basePath !== '' ? $basePath : '') . '/view_order.php?id=' . $orderId;
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }

    if ($host === '') {
        return $path;
    }

    return $scheme . $host . $path;
}

function crmGetTechnicianById(int $technicianId): ?array {
    global $pdo;
    if ($technicianId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, name, role, telegram_id, is_active FROM technicians WHERE id = ? LIMIT 1');
    $stmt->execute([$technicianId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['telegram_id'] = crmNormalizeTelegramChatId($row['telegram_id'] ?? null);
    return $row;
}

function crmGetOrderNotificationContext(int $orderId): ?array {
    global $pdo;
    if ($orderId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT o.id, o.status, o.technician_id, o.device_brand, o.device_model, o.problem_description,
                o.final_cost, o.estimated_cost,
                TRIM(CONCAT(COALESCE(c.last_name, ''), ' ', COALESCE(c.first_name, ''))) AS customer_name,
                COALESCE(t.name, '') AS technician_name
         FROM orders o
         LEFT JOIN customers c ON c.id = o.customer_id
         LEFT JOIN technicians t ON t.id = o.technician_id
         WHERE o.id = ?
         LIMIT 1"
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return $row;
}

function crmGetOversightRecipients($excludeTechnicianIds = []): array {
    global $pdo;
    $stmt = $pdo->query("SELECT id, name, role, telegram_id FROM technicians WHERE is_active = 1 AND role IN ('admin', 'manager')");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!is_array($excludeTechnicianIds)) {
        $excludeTechnicianIds = [$excludeTechnicianIds];
    }

    $excludeMap = [];
    foreach ($excludeTechnicianIds as $excludeId) {
        $excludeId = (int)$excludeId;
        if ($excludeId > 0) {
            $excludeMap[$excludeId] = true;
        }
    }

    $result = [];
    foreach ($rows as $row) {
        $techId = (int)($row['id'] ?? 0);
        if ($techId > 0 && isset($excludeMap[$techId])) {
            continue;
        }

        $chatId = crmNormalizeTelegramChatId($row['telegram_id'] ?? null);
        if (!$chatId) {
            continue;
        }

        if (!isset($result[$chatId])) {
            $result[$chatId] = [
                'id' => $techId,
                'name' => (string)($row['name'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
                'telegram_id' => $chatId,
            ];
        }
    }

    return array_values($result);
}

function crmFormatOrderDeviceLabel($brand, $model): string {
    $brand = trim((string)$brand);
    $model = trim((string)$model);
    $device = trim($brand . ' ' . $model);
    return $device !== '' ? $device : '-';
}

function crmFormatOrderProblemSnippet($problem): string {
    $problem = trim((string)$problem);
    if ($problem === '') {
        return '-';
    }
    return function_exists('mb_substr') ? mb_substr($problem, 0, 180) : substr($problem, 0, 180);
}

function crmNotifyOrderLifecycleEvent(array $event): void {
    $type = (string)($event['type'] ?? '');
    $orderId = (int)($event['order_id'] ?? 0);
    if ($type === '' || $orderId <= 0) {
        return;
    }

    $ctx = crmGetOrderNotificationContext($orderId);
    if (!$ctx) {
        return;
    }

    $oldStatus = trim((string)($event['old_status'] ?? ''));
    $newStatus = trim((string)($event['new_status'] ?? ($ctx['status'] ?? '')));
    $statusChanged = ($oldStatus !== '' && $newStatus !== '' && $oldStatus !== $newStatus);

    $assignedTechId = isset($event['technician_id'])
        ? (int)$event['technician_id']
        : (int)($ctx['technician_id'] ?? 0);
    $previousTechId = isset($event['previous_technician_id']) ? (int)$event['previous_technician_id'] : 0;
    $technicianChanged = ($previousTechId > 0 && $assignedTechId > 0 && $previousTechId !== $assignedTechId);
    $technicianAssigned = ($assignedTechId > 0 && $previousTechId !== $assignedTechId);

    $actorRole = (string)($event['actor_role'] ?? ($_SESSION['role'] ?? ''));
    $actorTechId = (int)($event['actor_tech_id'] ?? ($_SESSION['tech_id'] ?? 0));
    $actorName = trim((string)($event['actor_name'] ?? ($_SESSION['full_name'] ?? '')));
    if ($actorName === '' && $actorTechId > 0) {
        $actor = crmGetTechnicianById($actorTechId);
        $actorName = trim((string)($actor['name'] ?? ''));
    }
    if ($actorName === '') {
        $actorName = $actorTechId > 0 ? ('Technik #' . $actorTechId) : 'Systém';
    }

    $assignedTech = $assignedTechId > 0 ? crmGetTechnicianById($assignedTechId) : null;

    $deviceLabel = crmTelegramEscape(crmFormatOrderDeviceLabel($ctx['device_brand'] ?? '', $ctx['device_model'] ?? ''));
    $customerLabel = crmTelegramEscape(trim((string)($ctx['customer_name'] ?? '')) ?: '-');
    $problemSnippet = crmTelegramEscape(crmFormatOrderProblemSnippet($ctx['problem_description'] ?? ''));
    $link = crmBuildOrderViewLink($orderId);

    if ($assignedTechId > 0) {
        $assignedChatId = $assignedTech['telegram_id'] ?? null;
        if ($assignedChatId) {
            $statusLabel = $newStatus !== '' ? crmTelegramEscape($newStatus) : crmTelegramEscape((string)($ctx['status'] ?? '-'));
            $techMsg = '';

            if ($type === 'order_created') {
                $techMsg = "🆕 <b>Nová zakázka #{$orderId}</b>
"
                    . "👤 Klient: <b>{$customerLabel}</b>
"
                    . "📱 Zařízení: <b>{$deviceLabel}</b>
"
                    . "📝 Problém: {$problemSnippet}
"
                    . "📍 Stav: <b>{$statusLabel}</b>
"
                    . "🔗 <a href=\"{$link}\">Otevřít zakázku</a>";
            }

            if ($type === 'order_status_changed' && ($statusChanged || $technicianChanged)) {
                $statusText = $statusChanged
                    ? crmTelegramEscape($oldStatus) . ' → ' . crmTelegramEscape($newStatus)
                    : $statusLabel;
                $techMsg = "🛠️ <b>Aktualizace zakázky #{$orderId}</b>
"
                    . "📍 Stav: <b>{$statusText}</b>
"
                    . "📱 Zařízení: <b>{$deviceLabel}</b>
"
                    . "👤 Klient: <b>{$customerLabel}</b>
";

                $finalCost = $event['final_cost'] ?? ($ctx['final_cost'] ?? null);
                if ($finalCost !== null && $finalCost !== '') {
                    $techMsg .= '💰 Cena: <b>' . crmTelegramEscape(formatMoney((float)$finalCost)) . "</b>
";
                }

                if ($technicianChanged) {
                    $techMsg .= "👨‍🔧 Zakázka byla přiřazena právě vám.
";
                }

                $techMsg .= "🔗 <a href=\"{$link}\">Otevřít zakázku</a>";
            }

            if ($techMsg !== '') {
                sendTelegramNotification($assignedChatId, $techMsg);
            }
        }
    }

    if ($type === 'order_created') {
        $statusLabel = $newStatus !== '' ? crmTelegramEscape($newStatus) : crmTelegramEscape((string)($ctx['status'] ?? '-'));
        $assignedTechName = trim((string)($assignedTech['name'] ?? ($ctx['technician_name'] ?? '')));
        if ($assignedTechName === '') {
            $assignedTechName = 'Nepřiřazeno';
        }

        $oversightRecipients = crmGetOversightRecipients([$actorTechId, $assignedTechId]);
        if (!empty($oversightRecipients)) {
            $oversightMsg = "🆕 <b>Nová zakázka #{$orderId}</b>
"
                . "👤 Klient: <b>{$customerLabel}</b>
"
                . "📱 Zařízení: <b>{$deviceLabel}</b>
"
                . "📝 Problém: {$problemSnippet}
"
                . "📍 Stav: <b>{$statusLabel}</b>
"
                . "🛠 Přiřazený technik: <b>" . crmTelegramEscape($assignedTechName) . "</b>
"
                . "👨‍💼 Založil: <b>" . crmTelegramEscape($actorName) . "</b>
"
                . "🔗 <a href=\"{$link}\">Otevřít zakázku</a>";

            foreach ($oversightRecipients as $recipient) {
                sendTelegramNotification($recipient['telegram_id'], $oversightMsg);
            }
        }
    }

    if ($type === 'order_status_changed' && $technicianAssigned) {
        $assignedTechName = trim((string)($assignedTech['name'] ?? ($ctx['technician_name'] ?? '')));
        if ($assignedTechName === '') {
            $assignedTechName = 'Nepřiřazeno';
        }

        $previousTechName = 'Nepřiřazeno';
        if ($previousTechId > 0) {
            $previousTech = crmGetTechnicianById($previousTechId);
            $previousTechName = trim((string)($previousTech['name'] ?? ''));
            if ($previousTechName === '') {
                $previousTechName = 'Technik #' . $previousTechId;
            }
        }

        $statusLabel = $newStatus !== '' ? crmTelegramEscape($newStatus) : crmTelegramEscape((string)($ctx['status'] ?? '-'));
        $oversightRecipients = crmGetOversightRecipients([$actorTechId, $assignedTechId]);
        if (!empty($oversightRecipients)) {
            $assignmentMsg = "👨‍🔧 <b>Přiřazení technika na zakázce #{$orderId}</b>
"
                . "👤 Klient: <b>{$customerLabel}</b>
"
                . "📱 Zařízení: <b>{$deviceLabel}</b>
"
                . "🧑‍🔧 Technik: <b>" . crmTelegramEscape($previousTechName) . " → " . crmTelegramEscape($assignedTechName) . "</b>
"
                . "📍 Stav: <b>{$statusLabel}</b>
"
                . "👨‍💼 Změnil: <b>" . crmTelegramEscape($actorName) . "</b>
"
                . "🔗 <a href=\"{$link}\">Otevřít zakázku</a>";

            foreach ($oversightRecipients as $recipient) {
                sendTelegramNotification($recipient['telegram_id'], $assignmentMsg);
            }
        }
    }

    if ($type === 'order_status_changed' && $statusChanged && $actorRole === 'technician') {
        $assignedTechName = trim((string)($ctx['technician_name'] ?? ''));
        if ($assignedTechName === '' && $assignedTechId > 0) {
            $assignedTechName = trim((string)($assignedTech['name'] ?? ''));
        }
        if ($assignedTechName === '') {
            $assignedTechName = 'Nepřiřazeno';
        }

        $oversightRecipients = crmGetOversightRecipients($actorTechId);
        if (!empty($oversightRecipients)) {
            $oversightMsg = "👀 <b>Dohled nad změnou stavu</b>
"
                . "🧾 Zakázka: <b>#{$orderId}</b>
"
                . "👨‍🔧 Změnil: <b>" . crmTelegramEscape($actorName) . "</b>
"
                . "📍 Stav: <b>" . crmTelegramEscape($oldStatus) . ' → ' . crmTelegramEscape($newStatus) . "</b>
"
                . "🛠 Přiřazený technik: <b>" . crmTelegramEscape($assignedTechName) . "</b>
"
                . "📱 Zařízení: <b>{$deviceLabel}</b>
"
                . "🔗 <a href=\"{$link}\">Otevřít zakázku</a>";

            foreach ($oversightRecipients as $recipient) {
                sendTelegramNotification($recipient['telegram_id'], $oversightMsg);
            }
        }
    }
}

function sendTelegramNotification($chatId, $message) {
    $chatId = crmNormalizeTelegramChatId($chatId);
    if (!defined('TG_BOT_TOKEN') || TG_BOT_TOKEN === '' || !$chatId) return false;

    $url = "https://api.telegram.org/bot" . TG_BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Telegram sendMessage curl failed: ' . $err);
        return false;
    }

    $result = json_decode($response, true);
    if (!is_array($result) || !isset($result['ok']) || !$result['ok']) {
        error_log('Telegram sendMessage failed: ' . $response);
        return false;
    }
    return true;
}

function ensureOrderStatusLogTable() {
    global $pdo;
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS order_status_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            old_status VARCHAR(50) NOT NULL,
            new_status VARCHAR(50) NOT NULL,
            changed_by INT NULL,
            changed_role VARCHAR(20) NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function logOrderStatusChange($order_id, $old_status, $new_status) {
    if ($old_status === $new_status && $old_status !== '') return;
    global $pdo;
    try {
        if (!$pdo->inTransaction()) {
            ensureOrderStatusLogTable();
        }
        $changed_by = $_SESSION['user_id'] ?? ($_SESSION['tech_id'] ?? null);
        $changed_role = $_SESSION['role'] ?? null;
        $stmt = $pdo->prepare(
            "INSERT INTO order_status_log (order_id, old_status, new_status, changed_by, changed_role)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$order_id, $old_status, $new_status, $changed_by, $changed_role]);
    } catch (Exception $e) {
        // ignore logging errors
    }
}

function ensureOrderWorkTrackingSchema() {
    global $pdo;
    foreach ([
        "ALTER TABLE `orders` ADD COLUMN `work_started_at` DATETIME NULL AFTER `updated_at`",
        "ALTER TABLE `orders` ADD COLUMN `work_finished_at` DATETIME NULL AFTER `work_started_at`",
        "ALTER TABLE `orders` ADD COLUMN `work_duration_seconds` INT NOT NULL DEFAULT 0 AFTER `work_finished_at`",
        "ALTER TABLE `orders` ADD COLUMN `work_started_by` INT NULL AFTER `work_duration_seconds`",
        "ALTER TABLE `orders` ADD COLUMN `work_finished_by` INT NULL AFTER `work_started_by`",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Ignore duplicate-column errors and keep bootstrapping resilient.
        }
    }

    // Legacy compatibility: older builds stored this field in seconds.
    // Current behavior stores cumulative minutes in the same column.
    try {
        if (get_setting('work_duration_unit', 'seconds') !== 'minutes') {
            $pdo->exec("UPDATE orders SET work_duration_seconds = ROUND(COALESCE(work_duration_seconds, 0) / 60) WHERE COALESCE(work_duration_seconds, 0) > 0");
            set_setting('work_duration_unit', 'minutes');
        }
    } catch (Throwable $e) {
        // Keep schema bootstrap resilient.
    }

    return true;
}

function getTechnicianInProgressCount($technicianId, $excludeOrderId = null) {
    global $pdo;
    if (!$technicianId) return 0;
    $sql = "SELECT COUNT(*) FROM orders WHERE technician_id = ? AND status = 'In Progress'";
    $params = [$technicianId];
    if ($excludeOrderId) {
        $sql .= " AND id <> ?";
        $params[] = $excludeOrderId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function formatWorkDuration($minutesTotal) {
    $minutesTotal = (int)($minutesTotal ?? 0);
    if ($minutesTotal <= 0) {
        return '—';
    }

    $days = intdiv($minutesTotal, 1440);
    $minutesTotal %= 1440;
    $hours = intdiv($minutesTotal, 60);
    $minutes = $minutesTotal % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ' d';
    }
    if ($hours > 0 || $days > 0) {
        $parts[] = $hours . ' h';
    }
    $parts[] = $minutes . ' min';

    return implode(' ', $parts);
}

/**
 * Change inventory quantity safely, preventing negative stock.
 */
function changeInventoryQuantity($inventory_id, $change) {
    global $pdo;
    if (!$inventory_id) return true;
    
    $stmt = $pdo->prepare("SELECT quantity, part_name FROM inventory WHERE id = ? FOR UPDATE");
    $stmt->execute([$inventory_id]);
    $item = $stmt->fetch();
    
    if (!$item) {
        throw new Exception("Inventory item #{$inventory_id} not found.");
    }
    
    $new_quantity = $item['quantity'] + $change;
    
    if ($new_quantity < 0) {
        throw new Exception("Not enough stock for item '{$item['part_name']}'. Available: {$item['quantity']}, Required: " . abs($change));
    }
    
    $upd = $pdo->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
    return $upd->execute([$new_quantity, $inventory_id]);
}

/**
 * Process inventory changes when an order status changes.
 */
function processOrderInventoryChange($order_id, $is_finishing, $was_finished) {
    global $pdo;
    
    if (!$was_finished && $is_finishing) {
        $stmt = $pdo->prepare('SELECT inventory_id, quantity FROM order_items WHERE order_id = ? AND inventory_id IS NOT NULL');
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll();
        foreach ($items as $item) {
            changeInventoryQuantity($item['inventory_id'], -$item['quantity']);
        }
    } elseif ($was_finished && !$is_finishing) {
        $stmt = $pdo->prepare('SELECT inventory_id, quantity FROM order_items WHERE order_id = ? AND inventory_id IS NOT NULL');
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll();
        foreach ($items as $item) {
            changeInventoryQuantity($item['inventory_id'], $item['quantity']);
        }
    }
}
?>
