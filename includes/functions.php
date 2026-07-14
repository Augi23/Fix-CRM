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
        // Boss = tatáž práva jako manažer (o stupeň výš jen u přiřazování techniků,
        // což řeší isBranchGlobalViewer + edit_orders níže).
        if (in_array(getCurrentStaffRole(), ['manager', 'boss'], true)) {
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
function getBranches(bool $activeOnly = true): array {
    global $pdo;
    static $cache = [];
    $key = $activeOnly ? 'active' : 'all';
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $where = $activeOnly ? ' WHERE is_active = 1' : '';
        $cache[$key] = $pdo->query('SELECT id, code, name, address, is_active FROM branches' . $where . ' ORDER BY id ASC')->fetchAll();
    } catch (Throwable $e) {
        $cache[$key] = [];
    }
    return $cache[$key];
}

function getBranchLabel(?int $branchId): string {
    foreach (getBranches(false) as $branch) {
        if ((int)$branch['id'] === (int)$branchId) {
            return (string)$branch['name'];
        }
    }
    return '';
}

function getDefaultBranchId(): int {
    foreach (getBranches(false) as $branch) {
        if (($branch['code'] ?? '') === 'karlin') {
            return (int)$branch['id'];
        }
    }
    $branches = getBranches(false);
    return (int)($branches[0]['id'] ?? 0);
}

function getCurrentStaffBranchId(): int {
    global $pdo;
    if (!empty($_SESSION['branch_id'])) {
        return (int)$_SESSION['branch_id'];
    }
    if (!empty($_SESSION['tech_id'])) {
        try {
            $stmt = $pdo->prepare('SELECT branch_id FROM technicians WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$_SESSION['tech_id']]);
            $branchId = (int)$stmt->fetchColumn();
            if ($branchId > 0) {
                $_SESSION['branch_id'] = $branchId;
                return $branchId;
            }
        } catch (Throwable $e) {}
    }
    return getDefaultBranchId();
}

function isBranchGlobalViewer(): bool {
    // Boss vidí a přiřazuje napříč pobočkami stejně jako manažer/admin
    // → smí určit jakéhokoliv technika k jakékoliv zakázce (canAssignTechnicianToOrder).
    return hasPermission('admin_access') || in_array(getCurrentStaffRole(), ['manager', 'boss'], true);
}

function addOrderBranchScope(array &$whereClauses, array &$params, string $orderAlias = 'o'): void {
    if (isBranchGlobalViewer()) {
        return;
    }
    $branchId = getCurrentStaffBranchId();
    if ($branchId > 0) {
        $whereClauses[] = $orderAlias . '.branch_id = ?';
        $params[] = $branchId;
    }
}

function orderBranchScopeSql(string $column = 'branch_id'): string {
    if (isBranchGlobalViewer()) {
        return '';
    }
    $branchId = getCurrentStaffBranchId();
    return $branchId > 0 ? ' AND ' . $column . ' = ' . (int)$branchId : '';
}

function canAccessOrderBranch(array $order): bool {
    if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
        return false;
    }
    if (isBranchGlobalViewer()) {
        return true;
    }
    $orderBranchId = (int)($order['branch_id'] ?? 0);
    return $orderBranchId > 0 && $orderBranchId === getCurrentStaffBranchId();
}

function canAssignTechnicianToOrder(?int $technicianId, ?int $branchId = null): bool {
    global $pdo;
    if (!$technicianId) {
        return true;
    }
    if (isBranchGlobalViewer()) {
        return true;
    }
    try {
        $stmt = $pdo->prepare('SELECT branch_id FROM technicians WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([(int)$technicianId]);
        $techBranchId = (int)$stmt->fetchColumn();
        return $techBranchId > 0 && $techBranchId === ($branchId ?: getCurrentStaffBranchId());
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Return active technicians visible for the current staff member.
 * Managers/admins see all branches; branch staff only see their branch colleagues.
 */
function getActiveTechnicians(): array {
    global $pdo;
    static $cache = [];
    $key = isBranchGlobalViewer() ? 'all' : ('branch_' . getCurrentStaffBranchId());
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        if (isBranchGlobalViewer()) {
            $cache[$key] = $pdo->query(
                'SELECT t.id, t.name, t.branch_id, b.name AS branch_name FROM technicians t LEFT JOIN branches b ON b.id = t.branch_id WHERE t.is_active = 1 ORDER BY b.id ASC, t.name ASC'
            )->fetchAll();
        } else {
            $stmt = $pdo->prepare(
                'SELECT t.id, t.name, t.branch_id, b.name AS branch_name FROM technicians t LEFT JOIN branches b ON b.id = t.branch_id WHERE t.is_active = 1 AND t.branch_id = ? ORDER BY t.name ASC'
            );
            $stmt->execute([getCurrentStaffBranchId()]);
            $cache[$key] = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        $cache[$key] = [];
    }
    return $cache[$key];
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

function getOrderStatusDefinitions(): array {
    return [
        // ── Aktuální stavový model (od 7/2026) — jen tyto lze nově vybrat ──
        'Přijato' => ['group' => 'new', 'badge' => 'new'],
        'Přijato z RepairPluginu' => ['group' => 'new', 'badge' => 'repairplugin'],
        'Čeká na technika' => ['group' => 'new', 'badge' => 'handoff'],
        'V opravě' => ['group' => 'in_progress', 'badge' => 'progress'],
        'V opravě - v externím servisu' => ['group' => 'in_progress', 'badge' => 'progress'],
        'V opravě - v autorizovaném servisu' => ['group' => 'in_progress', 'badge' => 'progress'],
        'Čeká na díl' => ['group' => 'waiting_parts', 'badge' => 'waiting'],
        'Připraveno k převzetí' => ['group' => 'completed', 'badge' => 'completed'],
        'Vydáno - čeká na platbu' => ['group' => 'collected', 'badge' => 'uncollected'],
        'Vydáno' => ['group' => 'collected', 'badge' => 'collected'],
        'Nevyzvednuto' => ['group' => 'uncollected', 'badge' => 'uncollected'],
        'Stornováno' => ['group' => 'cancelled', 'badge' => 'cancelled'],
        // ── Legacy stavy: jen pro zobrazení starších/importovaných zakázek ──
        'Zakládá se' => ['group' => 'new', 'badge' => 'new', 'legacy' => true],
        'V opravě zák. desky' => ['group' => 'in_progress', 'badge' => 'progress', 'legacy' => true],
        'V externím servisu' => ['group' => 'in_progress', 'badge' => 'progress', 'legacy' => true],
        'V aut. servisu' => ['group' => 'in_progress', 'badge' => 'progress', 'legacy' => true],
        'Čeká na zákazníka' => ['group' => 'pending_approval', 'badge' => 'pending', 'legacy' => true],
        'Čeká na platbu' => ['group' => 'pending_approval', 'badge' => 'pending', 'legacy' => true],
        'Vydáno - ČR' => ['group' => 'collected', 'badge' => 'collected', 'legacy' => true],
    ];
}

function getLegacyOrderStatusAliases(): array {
    return [
        'new' => ['New', 'Новый', 'Nová', 'Černá růže'],
        'pending_approval' => ['Pending Approval', 'На согласовании', 'K odsouhlasení', 'Čeká na schválení'],
        'in_progress' => ['In Progress', 'В работе', 'V práci', 'V procesu', 'Provádí se'],
        'waiting_parts' => ['Waiting for Parts', 'Ожидание запчастей', 'Čeká na díly', 'Čeká na díl', 'Ceka na dil', 'Čeká na diel'],
        'completed' => ['Completed', 'Готов', 'Hotovo'],
        'uncollected' => ['Uncollected'],
        'collected' => ['Collected', 'Выдан', 'Vydáno'],
        'cancelled' => ['Cancelled', 'Отменен', 'Zrušeno'],
    ];
}

function getOrderStatusAliases(): array {
    $aliases = getLegacyOrderStatusAliases();
    foreach (getOrderStatusDefinitions() as $status => $meta) {
        $aliases[$meta['group']][] = $status;
        if (($meta['badge'] ?? '') === 'uncollected') {
            $aliases['uncollected'][] = $status;
        }
    }
    foreach ($aliases as $key => $values) {
        $aliases[$key] = array_values(array_unique($values));
    }
    $aliases['done'] = array_values(array_unique(array_merge($aliases['completed'], $aliases['uncollected'], $aliases['collected'])));
    $aliases['active'] = array_values(array_unique(array_merge($aliases['new'], $aliases['pending_approval'], $aliases['in_progress'], $aliases['waiting_parts'])));
    $aliases['terminal'] = array_values(array_unique(array_merge($aliases['done'], $aliases['cancelled'])));
    return $aliases;
}

function getOrderStatusList(string $key): array {
    $aliases = getOrderStatusAliases();
    return $aliases[$key] ?? [$key];
}

function getOrderStatusOptions(bool $includeLegacy = false, ?string $ensureStatus = null): array {
    $options = [];
    foreach (getOrderStatusDefinitions() as $status => $meta) {
        if (!$includeLegacy && !empty($meta['legacy'])) { continue; }
        $options[$status] = getOrderStatusLabel($status);   // value česky, popisek dle jazyka
    }
    // u starší zakázky nabídnout i její aktuální (legacy) stav, ať se formulářem nezmění omylem
    if ($ensureStatus !== null && $ensureStatus !== '' && !isset($options[$ensureStatus])) {
        $options = [$ensureStatus => getOrderStatusLabel($ensureStatus)] + $options;
    }
    return $options;
}

function getOrderCanonicalStatuses(): array {
    return array_keys(getOrderStatusDefinitions());
}

function getAllowedOrderFilterStatuses(): array {
    $statuses = getOrderCanonicalStatuses();

    foreach (getOrderStatusAliases() as $items) {
        foreach ($items as $status) {
            if (is_string($status) && trim($status) !== '') {
                $statuses[] = $status;
            }
        }
    }

    return array_values(array_unique($statuses));
}

function getDefaultOrderStatus(): string {
    return 'Přijato';
}

function getCanonicalOrderStatusByGroup(string $group): ?string {
    return [
        'new' => 'Přijato',
        'pending_approval' => 'Čeká na zákazníka',
        'in_progress' => 'V opravě',
        'waiting_parts' => 'Čeká na díl',
        'completed' => 'Připraveno k převzetí',
        'uncollected' => 'Nevyzvednuto',
        'collected' => 'Vydáno',
        'cancelled' => 'Stornováno',
    ][$group] ?? null;
}

function normalizeOrderStatus(?string $status): string {
    $status = trim((string)$status);
    if ($status === '') {
        return getDefaultOrderStatus();
    }
    if (isset(getOrderStatusDefinitions()[$status])) {
        return $status;
    }
    if (isOrderStatusIn($status, 'uncollected')) {
        return getCanonicalOrderStatusByGroup('uncollected') ?? getDefaultOrderStatus();
    }
    $group = getOrderStatusGroup($status);
    return $group ? (getCanonicalOrderStatusByGroup($group) ?? $status) : $status;
}

function getOrderStatusGroup(string $status): ?string {
    foreach (getOrderStatusAliases() as $group => $statuses) {
        if (in_array($group, ['done', 'active', 'terminal'], true)) {
            continue;
        }
        if (in_array($status, $statuses, true)) {
            return $group;
        }
    }
    return null;
}

function isOrderStatusIn(?string $status, string $key): bool {
    return in_array((string)$status, getOrderStatusList($key), true);
}

function orderStatusSqlIn(PDO $pdo, string $key): string {
    return implode(',', array_map(static fn($status) => $pdo->quote($status), getOrderStatusList($key)));
}

/**
 * Překlady kanonických stavů zakázek (DB hodnota zůstává VŽDY česky —
 * překládá se jen zobrazený popisek dle zvoleného jazyka).
 */
function getOrderStatusTranslations(): array {
    return [
        'en' => [
            'Přijato' => 'Received',
            'Přijato z RepairPluginu' => 'Received from RepairPlugin',
            'Čeká na technika' => 'Waiting for technician',
            'V opravě' => 'In Repair',
            'V opravě - v externím servisu' => 'In Repair — External Service',
            'V opravě - v autorizovaném servisu' => 'In Repair — Authorized Service',
            'Čeká na díl' => 'Waiting for Part',
            'Připraveno k převzetí' => 'Ready for Pickup',
            'Vydáno - čeká na platbu' => 'Collected — Awaiting Payment',
            'Vydáno' => 'Collected',
            'Nevyzvednuto' => 'Not Collected',
            'Stornováno' => 'Cancelled',
            'Zakládá se' => 'Being Created',
            'V opravě zák. desky' => 'In Repair — Logic Board',
            'V externím servisu' => 'External Service',
            'V aut. servisu' => 'Authorized Service',
            'Čeká na zákazníka' => 'Waiting for Customer',
            'Čeká na platbu' => 'Awaiting Payment',
            'Vydáno - ČR' => 'Collected (CZ)',
        ],
        'ru' => [
            'Přijato' => 'Принято',
            'Přijato z RepairPluginu' => 'Принято из RepairPlugin',
            'Čeká na technika' => 'Ожидает техника',
            'V opravě' => 'В ремонте',
            'V opravě - v externím servisu' => 'В ремонте — внешний сервис',
            'V opravě - v autorizovaném servisu' => 'В ремонте — авторизованный сервис',
            'Čeká na díl' => 'Ожидает запчасть',
            'Připraveno k převzetí' => 'Готово к выдаче',
            'Vydáno - čeká na platbu' => 'Выдано — ждёт оплаты',
            'Vydáno' => 'Выдано',
            'Nevyzvednuto' => 'Не забрано',
            'Stornováno' => 'Отменено',
            'Zakládá se' => 'Создаётся',
            'V opravě zák. desky' => 'Ремонт платы',
            'V externím servisu' => 'Внешний сервис',
            'V aut. servisu' => 'Авторизованный сервис',
            'Čeká na zákazníka' => 'Ожидание клиента',
            'Čeká na platbu' => 'Ожидание оплаты',
            'Vydáno - ČR' => 'Выдано (CZ)',
        ],
    ];
}

function getOrderStatusLabel(string $status): string {
    if (isset(getOrderStatusDefinitions()[$status])) {
        $lang = crm_get_language();
        if ($lang !== 'cs') {
            $map = getOrderStatusTranslations();
            return $map[$lang][$status] ?? $status;
        }
        return $status;
    }
    $legacyLabels = [
        'New' => __('new'),
        'Новый' => __('new'),
        'Nová' => __('new'),
        'Pending Approval' => __('pending_approval'),
        'На согласовании' => __('pending_approval'),
        'K odsouhlasení' => __('pending_approval'),
        'Čeká na schválení' => __('pending_approval'),
        'In Progress' => __('in_progress'),
        'В работе' => __('in_progress'),
        'V práci' => __('in_progress'),
        'V procesu' => __('in_progress'),
        'Provádí se' => __('in_progress'),
        'Waiting for Parts' => __('waiting_parts'),
        'Ожидание запчастей' => __('waiting_parts'),
        'Čeká na díly' => __('waiting_parts'),
        'Čeká na díl' => __('waiting_parts'),
        'Ceka na dil' => __('waiting_parts'),
        'Čeká na diel' => __('waiting_parts'),
        'Completed' => __('status_completed'),
        'Готов' => __('status_completed'),
        'Hotovo' => __('status_completed'),
        'Uncollected' => __('status_uncollected'),
        'Collected' => __('status_collected'),
        'Выдан' => __('status_collected'),
        'Vydáno' => __('status_collected'),
        'Cancelled' => __('status_cancelled'),
        'Отменен' => __('status_cancelled'),
        'Zrušeno' => __('status_cancelled'),
    ];
    return $legacyLabels[$status] ?? $status;
}

function orderDisplayCode(array $order): string
{
    $orderCode = trim((string)($order['order_code'] ?? ''));
    return $orderCode !== '' ? $orderCode : '#' . (int)($order['id'] ?? 0);
}

/**
 * Vygeneruje další číslo zakázky navazující na importovanou řadu
 * (tvar PREFIX+číslo, např. APFAZ2600485 -> APFAZ2600486).
 * Vrací null, pokud v DB žádný kód v tomto tvaru není (fallback = bez kódu).
 */
function generateNextOrderCode(PDO $pdo): ?string
{
    try {
        $row = $pdo->query(
            "SELECT order_code FROM orders
             WHERE order_code REGEXP '^[A-Za-z]+[0-9]+$'
             ORDER BY CAST(REGEXP_REPLACE(order_code, '[^0-9]', '') AS UNSIGNED) DESC
             LIMIT 1"
        )->fetch();
    } catch (Throwable $e) {
        // REGEXP/REGEXP_REPLACE nemusí být k dispozici → jednodušší dotaz (řada APFAZ…)
        try {
            $row = $pdo->query(
                "SELECT order_code FROM orders
                 WHERE order_code LIKE 'APFAZ%'
                 ORDER BY LENGTH(order_code) DESC, order_code DESC
                 LIMIT 1"
            )->fetch();
        } catch (Throwable $e2) {
            return null;   // DB nedostupná — INSERT by stejně selhal
        }
    }
    if (!$row || !preg_match('/^([A-Za-z]+)([0-9]+)$/', (string)$row['order_code'], $m)) {
        // V DB zatím žádný kód v očekávaném tvaru → založit novou řadu APFAZ<rr>00001,
        // ať zakázka NIKDY nezůstane bez kódu (jinak by se uživateli ukazovalo interní #ID).
        $m = [null, 'APFAZ', date('y') . '00000'];
    }
    $prefix = $m[1];
    $digits = $m[2];
    $len = strlen($digits);
    $next = (int)$digits + 1;
    // pojistka proti kolizi (souběžné založení)
    for ($i = 0; $i < 50; $i++) {
        $candidate = $prefix . str_pad((string)$next, $len, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_code = ?');
        $chk->execute([$candidate]);
        if ((int)$chk->fetchColumn() === 0) {
            return $candidate;
        }
        $next++;
    }
    return null;
}

function orderSortSql(string $alias = 'o', ?string $exactIdExpression = null): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        $alias = 'o';
    }

    $parts = [];
    if ($exactIdExpression !== null && trim($exactIdExpression) !== '') {
        $parts[] = '(CASE WHEN ' . $exactIdExpression . ' THEN 0 ELSE 1 END)';
    }
    $parts[] = "(CASE WHEN {$alias}.order_code REGEXP '^[A-Za-z]+[0-9]+$' THEN 0 ELSE 1 END)";
    $parts[] = "CAST(NULLIF(REGEXP_REPLACE(COALESCE({$alias}.order_code, ''), '[^0-9]', ''), '') AS UNSIGNED) DESC";
    $parts[] = "{$alias}.created_at DESC";
    $parts[] = "{$alias}.id DESC";

    return implode(",\n                ", $parts);
}

function sqlPlaceholders(array $values): string {
    return implode(',', array_fill(0, count($values), '?'));
}

/** Returns the colour token for a status (new|pending|progress|waiting|completed|uncollected|collected|cancelled|default). */
function getOrderStatusBadgeToken($status): string {
    $status = preg_replace('/\s+/u', ' ', trim((string)$status));
    $definitions = getOrderStatusDefinitions();
    $badge = $definitions[$status]['badge'] ?? null;
    if ($badge === null) {
        // dohledání bez ohledu na velikost písmen (importy / starší DB hodnoty)
        static $lcMap = null;
        if ($lcMap === null) {
            $lcMap = [];
            foreach ($definitions as $key => $meta) { $lcMap[mb_strtolower($key)] = $meta['badge']; }
        }
        $badge = $lcMap[mb_strtolower($status)] ?? null;
    }
    if ($badge === null) {
        $group = getOrderStatusGroup($status);
        $badge = [
            'new' => 'new',
            'pending_approval' => 'pending',
            'in_progress' => 'progress',
            'waiting_parts' => 'waiting',
            'completed' => isOrderStatusIn($status, 'uncollected') ? 'uncollected' : 'completed',
            'uncollected' => 'uncollected',
            'collected' => 'collected',
            'cancelled' => 'cancelled',
        ][$group] ?? 'default';
    }
    return (string)$badge;
}

function getStatusBadge($status) {
    $badge = getOrderStatusBadgeToken($status);
    return '<span class="badge status-pill status-pill--'.$badge.'">' . e(getOrderStatusLabel((string)$status)) . '</span>';
}

// ── Priorita zakázky (Low = Klidná · Normal = Normální · High = Urgentní) ────

/** Povolené hodnoty priority (DB hodnoty zůstávají anglicky, popisky se překládají). */
function getOrderPriorityValues(): array {
    return ['Low', 'Normal', 'High'];
}

function normalizeOrderPriority($priority): string {
    $priority = trim((string)$priority);
    return in_array($priority, getOrderPriorityValues(), true) ? $priority : 'Normal';
}

function getOrderPriorityLabel($priority): string {
    return match (normalizeOrderPriority($priority)) {
        'Low' => __('priority_low'),
        'High' => __('priority_high'),
        default => __('priority_normal'),
    };
}

/** Volby pro dropdown priority — vzestupně: Klidná, Normální, Urgentní. */
function getOrderPriorityOptions(): array {
    return [
        'Low' => __('priority_low'),
        'Normal' => __('priority_normal'),
        'High' => __('priority_high'),
    ];
}

function getOrderPriorityBadge($priority, ?string $orderStatus = null): string {
    $p = normalizeOrderPriority($priority);
    $token = strtolower($p);
    $icon = $p === 'High' ? '🔥 ' : '';
    // Vydaná zakázka: urgence už nehoří — šedý badge bez ohně, ať seznam nekřičí
    if ($p === 'High' && $orderStatus !== null && $orderStatus !== '' && isOrderStatusIn($orderStatus, 'collected')) {
        return '<span class="badge priority-pill priority-pill--muted">' . e(getOrderPriorityLabel($p)) . '</span>';
    }
    return '<span class="badge priority-pill priority-pill--' . $token . '">' . $icon . e(getOrderPriorityLabel($p)) . '</span>';
}


function formatMoney($amount) {
    global $pdo;
    $currency = get_setting('currency', 'Kč');
    // Ceny v Kč zobrazujeme bez desetinných míst (zaokrouhleno na celé koruny).
    return number_format((float)$amount, 0, ',', ' ') . ' ' . $currency;
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

/**
 * Odeslání HTML e-mailu přes SMTP (bez externí knihovny — socket klient,
 * AUTH LOGIN, STARTTLS/SSL). Nastavení z system_settings (smtp_*).
 * Vrací [bool ok, string error]. Volitelně příloha [filename, mime, data].
 */
function smtpSendMail(string $to, string $subject, string $htmlBody, ?array $attachment = null): array
{
    $host = trim((string) get_setting('smtp_host'));
    $port = (int) (get_setting('smtp_port', '587') ?: 587);
    $secure = get_setting('smtp_secure', 'tls');           // tls | ssl | none
    $user = trim((string) get_setting('smtp_user'));
    $pass = (string) get_setting('smtp_pass');
    $fromEmail = trim((string) get_setting('smtp_from_email')) ?: $user;
    $fromName  = trim((string) get_setting('smtp_from_name')) ?: get_setting('company_name', 'AppleFix');

    if ($host === '' || $fromEmail === '') {
        return [false, 'SMTP není nastavené (Nastavení → Integrace → E-mail).'];
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Neplatná e-mailová adresa klienta.'];
    }

    $eol = "\r\n";
    $transport = ($secure === 'ssl') ? "ssl://$host" : $host;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client("$transport:$port", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { return [false, "Spojení k SMTP selhalo: $errstr ($errno)"]; }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
    $code = fn($r) => (int) substr(trim((string)$r), 0, 3);

    $err = null;
    try {
        if ($code($read()) !== 220) throw new Exception('SMTP nepozdravil (220).');
        $ehloHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost');
        $r = $cmd("EHLO $ehloHost");
        if ($secure === 'tls') {
            if ($code($cmd('STARTTLS')) !== 220) throw new Exception('STARTTLS odmítnut.');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new Exception('Nepodařilo se navázat TLS.');
            }
            $cmd("EHLO $ehloHost");
        }
        if ($user !== '') {
            if ($code($cmd('AUTH LOGIN')) !== 334) throw new Exception('AUTH LOGIN odmítnut.');
            if ($code($cmd(base64_encode($user))) !== 334) throw new Exception('SMTP nepřijal uživatele.');
            if ($code($cmd(base64_encode($pass))) !== 235) throw new Exception('Přihlášení k SMTP selhalo (heslo?).');
        }
        if ($code($cmd("MAIL FROM:<$fromEmail>")) !== 250) throw new Exception('MAIL FROM odmítnut.');
        if ($code($cmd("RCPT TO:<$to>")) !== 250) throw new Exception('Příjemce odmítnut.');
        if ($code($cmd('DATA')) !== 354) throw new Exception('DATA odmítnuto.');

        $boundary = 'afx_' . bin2hex(random_bytes(8));
        $headers  = 'From: ' . mb_encode_mimeheader($fromName) . " <$fromEmail>" . $eol;
        $headers .= "To: <$to>" . $eol;
        $headers .= 'Subject: ' . mb_encode_mimeheader($subject) . $eol;
        $headers .= 'MIME-Version: 1.0' . $eol;
        $headers .= 'Date: ' . date('r') . $eol;
        if ($attachment) {
            $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"" . $eol . $eol;
            $body  = "--$boundary" . $eol;
            $body .= 'Content-Type: text/html; charset=UTF-8' . $eol;
            $body .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
            $body .= chunk_split(base64_encode($htmlBody)) . $eol;
            $body .= "--$boundary" . $eol;
            $body .= 'Content-Type: ' . $attachment['mime'] . '; name="' . $attachment['filename'] . '"' . $eol;
            $body .= 'Content-Transfer-Encoding: base64' . $eol;
            $body .= 'Content-Disposition: attachment; filename="' . $attachment['filename'] . '"' . $eol . $eol;
            $body .= chunk_split(base64_encode($attachment['data'])) . $eol;
            $body .= "--$boundary--" . $eol;
        } else {
            $headers .= 'Content-Type: text/html; charset=UTF-8' . $eol;
            $headers .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
            $body = chunk_split(base64_encode($htmlBody));
        }
        // tečkování řádků začínajících tečkou (RFC 5321)
        $data = preg_replace('/^\./m', '..', $headers . $body);
        fwrite($fp, $data . $eol . '.' . $eol);
        if ($code($read()) !== 250) throw new Exception('Server nepřijal zprávu.');
        $cmd('QUIT');
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
    fclose($fp);
    return $err ? [false, $err] : [true, ''];
}

function getDeviceBrands() {
    global $pdo;
    try {
        return $pdo->query("SELECT brand_name FROM device_brands ORDER BY brand_name ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return ['Apple', 'Samsung', 'Xiaomi', 'Other'];
    }
}

/** Výchozí (původně natvrdo zadané) katalogy — slouží jako seed a záložní seznam. */
function getDefaultSupplierCatalogs(): array {
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

/** Katalogy dodavatelů žijí v DB (tlačítko „Přidat katalog" na Nákupech). */
function ensureSupplierCatalogsTable(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_catalogs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            skey VARCHAR(40) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            host VARCHAR(120) NOT NULL,
            default_url VARCHAR(255) NOT NULL,
            is_active TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if ((int)$pdo->query("SELECT COUNT(*) FROM supplier_catalogs")->fetchColumn() === 0) {
            $ins = $pdo->prepare("INSERT INTO supplier_catalogs (skey, name, host, default_url) VALUES (?, ?, ?, ?)");
            foreach (getDefaultSupplierCatalogs() as $key => $c) {
                $ins->execute([$key, $c['name'], $c['host'], $c['default_url']]);
            }
        }
    } catch (Throwable $e) { /* best-effort */ }
}

function getSupplierCatalogs(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) { return $cache; }
    try {
        ensureSupplierCatalogsTable();
        $out = [];
        foreach ($pdo->query("SELECT skey, name, host, default_url FROM supplier_catalogs WHERE is_active = 1 ORDER BY name") as $r) {
            $out[(string)$r['skey']] = ['name' => (string)$r['name'], 'host' => (string)$r['host'], 'default_url' => (string)$r['default_url']];
        }
        if ($out) { return $cache = $out; }
    } catch (Throwable $e) { /* fallback níže */ }
    return $cache = getDefaultSupplierCatalogs();
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

/**
 * Ensures the inventory.is_stocked flag exists and runs a one-time backfill.
 *
 * Model:
 *  - Sklad (warehouse)  = real stock: parts manually added OR received via procurement.
 *                         Query rule: is_stocked = 1 OR quantity > 0.
 *  - Nákupy (catalog)   = supplier catalog parts you can order (source_supplier set).
 * Backfill marks manually-added items (no supplier source) as stocked; catalog items stay
 * is_stocked = 0 and only surface in Sklad while they actually have quantity > 0.
 */
function ensureInventoryStockedSchema(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $col = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'is_stocked'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN is_stocked TINYINT(1) NOT NULL DEFAULT 0");
            try { $pdo->exec("ALTER TABLE inventory ADD INDEX idx_inventory_stocked (is_stocked)"); } catch (Throwable $e) {}
        }
        if (get_setting('inv_stocked_backfilled', '') !== '1') {
            // Manually-added parts (no catalog source) are real stock from the start.
            // Guard against the catalog column not existing yet (don't depend on migration order):
            // if source_supplier is absent, treat every existing row as real stock.
            $hasSupplierCol = (bool)$pdo->query("SHOW COLUMNS FROM inventory LIKE 'source_supplier'")->fetch();
            $where = $hasSupplierCol ? "(source_supplier IS NULL OR source_supplier = '')" : "1=1";
            $pdo->exec("UPDATE inventory SET is_stocked = 1 WHERE is_stocked = 0 AND " . $where);
            set_setting('inv_stocked_backfilled', '1');
        }
    } catch (Throwable $e) {
        // best-effort schema guard
    }
}

/** SQL fragment for "this part belongs in the warehouse view" (real stock). */
function inventoryStockedWhereSql(): string {
    return '(is_stocked = 1 OR quantity > 0)';
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
    // exec() is frequently disabled on shared hosting via disable_functions.
    if (!function_exists('exec')) {
        $exitCode = 127;
        return 'exec() is disabled on this server (cannot run git)';
    }
    // Bypass git's "dubious ownership" refusal (exit 128) when the repo is owned by a
    // different user than the PHP/web process. safe.directory=* covers git 2.36+; the explicit
    // path also covers git 2.35.2 (where "*" is not yet honored). Per-invocation only — never persisted.
    $cmd = 'git -c ' . escapeshellarg('safe.directory=*')
         . ' -c ' . escapeshellarg('safe.directory=' . $repoRoot)
         . ' -C ' . escapeshellarg($repoRoot) . ' ' . $command . ' 2>&1';
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $exitCode = $code;
    return trim(implode("\n", $output));
}

/**
 * GitHub access token for private-repo fetch/pull.
 * Source order: .env GITHUB_TOKEN → system_settings 'github_token'. Never persisted to git config.
 */
function githubAccessToken(): string {
    $tok = trim((string)(getenv('GITHUB_TOKEN') ?: ''));
    if ($tok === '' && function_exists('get_setting') && !empty($GLOBALS['pdo'])) {
        $tok = trim((string)get_setting('github_token', ''));
    }
    return $tok;
}

/**
 * Activates token auth for the NEXT git fetch/pull by injecting an HTTP Authorization
 * header via GIT_CONFIG_* environment variables (git 2.31+). This keeps the token OUT of
 * the command line (argv / process listing) — unlike embedding it in the remote URL, which
 * is readable by other local users via `ps`/`/proc` on shared hosting. Pair with gitEndAuth().
 * Returns true if auth was activated (token present + HTTPS github remote).
 */
function gitBeginAuth(string $repoRoot, string $remoteName = 'origin'): bool {
    $tok = githubAccessToken();
    if ($tok === '') {
        return false;
    }
    $url = (string)(gitRemoteUrl($repoRoot, $remoteName) ?? '');
    if (!preg_match('~^https://github\.com/~i', $url)) {
        return false; // SSH / non-github remote → rely on normal credential resolution
    }
    putenv('GIT_CONFIG_COUNT=1');
    putenv('GIT_CONFIG_KEY_0=http.https://github.com/.extraHeader');
    putenv('GIT_CONFIG_VALUE_0=Authorization: Basic ' . base64_encode('x-access-token:' . $tok));
    return true;
}

function gitEndAuth(): void {
    putenv('GIT_CONFIG_COUNT');
    putenv('GIT_CONFIG_KEY_0');
    putenv('GIT_CONFIG_VALUE_0');
}

/** Strip any embedded HTTPS credentials before surfacing git output to UI/logs (defense in depth). */
function sanitizeGitText(string $text): string {
    return (string)preg_replace('~https://[^@/\s]+@~i', 'https://***@', $text);
}

function gitRemoteUrl(string $repoRoot, string $remoteName = 'origin'): ?string {
    $remoteName = trim($remoteName);
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $remoteName)) {
        $remoteName = 'origin';
    }

    $code = 0;
    $url = runGitCommand($repoRoot, 'config --get remote.' . $remoteName . '.url', $code);
    if ($code !== 0 || $url === '') {
        return null;
    }
    return trim($url);
}

function gitRemoteSlugFromUrl(?string $url): ?string {
    $url = trim((string)$url);
    if ($url === '') {
        return null;
    }
    if (preg_match('~github\.com[:/](.+?)(?:\.git)?$~', $url, $m)) {
        return trim($m[1]);
    }
    return null;
}

function gitRemoteSlug(string $repoRoot): ?string {
    return gitRemoteSlugFromUrl(gitRemoteUrl($repoRoot, 'origin'));
}

function getGitRepoInfo(string $repoRoot): array {
    $repoRoot = rtrim($repoRoot, '/');
    $remoteName = 'origin';
    $remoteUrl = gitRemoteUrl($repoRoot, $remoteName);

    $info = [
        'repo_root' => $repoRoot,
        'branch' => 'unknown',
        'local_commit' => '',
        'local_short' => '',
        'local_date' => '',
        'dirty' => false,
        'remote_name' => $remoteName,
        'remote_url' => $remoteUrl,
        'remote_commit' => '',
        'remote_short' => '',
        'remote_date' => '',
        'ahead_by' => null,
        'behind_by' => null,
        'update_available' => null,
        'changelog' => [],
        'remote_slug' => gitRemoteSlugFromUrl($remoteUrl),
        'token_present' => githubAccessToken() !== '',
        'error' => null,
        'error_detail' => null,
    ];

    if (!function_exists('exec')) {
        $info['error'] = 'exec() is disabled on this server';
        $info['error_detail'] = 'PHP exec() is blocked (disable_functions). Git-based self-update cannot run on this host.';
        return $info;
    }

    if (!is_dir($repoRoot . '/.git')) {
        $info['error'] = 'Repository not found';
        $info['error_detail'] = 'No .git directory at ' . $repoRoot . ' — the code was likely deployed without git (FTP/zip). Self-update needs a git clone.';
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
    } else {
        // Local rev-parse failing means git itself can't read the repo (dubious ownership,
        // broken repo, git missing). Capture the real reason for diagnostics.
        $info['error'] = 'Git cannot read local repository';
        $info['error_detail'] = sanitizeGitText($head !== '' ? $head : 'git rev-parse HEAD returned no output (exit ' . $code . ')');
        return $info;
    }

    $localDate = runGitCommand($repoRoot, "show -s --format=%cI HEAD", $code);
    if ($code === 0 && $localDate !== '') {
        $info['local_date'] = $localDate;
    }

    $dirty = runGitCommand($repoRoot, 'status --porcelain', $code);
    $info['dirty'] = ($code === 0 && $dirty !== '');
    // Keep the actual changed paths so the updater/diagnostics can show *what* is dirty
    // (a non-conflicting local change should never be an opaque, permanent blocker).
    $info['dirty_files'] = ($code === 0 && $dirty !== '') ? trim($dirty) : '';

    $remoteName = $info['remote_name'] ?: 'origin';
    $remoteBranch = $branch !== '' ? $branch : 'main';
    $fetchCode = 0;
    // Fetch by remote NAME with an explicit refspec so refs/remotes/<remote>/<branch> is
    // actually updated (a bare `fetch <url> <branch>` only moves FETCH_HEAD, leaving the
    // tracking ref stale → ahead/behind would be computed from outdated data). Token auth,
    // when configured, is injected via env header (gitBeginAuth) — never in the URL/argv.
    $refspec = '+' . $remoteBranch . ':refs/remotes/' . $remoteName . '/' . $remoteBranch;
    $authActive = gitBeginAuth($repoRoot, $remoteName);
    $fetchOut = runGitCommand($repoRoot, 'fetch --quiet ' . escapeshellarg($remoteName) . ' ' . escapeshellarg($refspec), $fetchCode);
    if ($authActive) { gitEndAuth(); }

    if ($fetchCode === 0) {
        $remoteRef = $remoteName . '/' . $remoteBranch;

        $remote = runGitCommand($repoRoot, 'rev-parse ' . escapeshellarg($remoteRef), $code);
        if ($code !== 0 || $remote === '') {
            $remote = runGitCommand($repoRoot, 'rev-parse FETCH_HEAD', $code);
        }

        if ($code === 0 && $remote !== '') {
            $info['remote_commit'] = $remote;
            $info['remote_short'] = substr($remote, 0, 7);
        }

        $remoteDate = runGitCommand($repoRoot, 'show -s --format=%cI ' . escapeshellarg($remoteRef), $code);
        if ($code !== 0 || $remoteDate === '') {
            $remoteDate = runGitCommand($repoRoot, 'show -s --format=%cI FETCH_HEAD', $code);
        }
        if ($code === 0 && $remoteDate !== '') {
            $info['remote_date'] = $remoteDate;
        }

        $counts = runGitCommand($repoRoot, 'rev-list --left-right --count HEAD...' . escapeshellarg($remoteRef), $code);
        if ($code !== 0 || !preg_match('/^(\d+)\s+(\d+)$/', $counts, $m)) {
            $counts = runGitCommand($repoRoot, 'rev-list --left-right --count HEAD...FETCH_HEAD', $code);
        }
        if ($code === 0 && preg_match('/^(\d+)\s+(\d+)$/', $counts, $m)) {
            $info['ahead_by'] = (int)$m[1];
            $info['behind_by'] = (int)$m[2];
            $info['update_available'] = ((int)$m[2]) > 0;
        }

        $log = runGitCommand($repoRoot, "log --format='%H|%h|%cI|%an|%s' -n 50 HEAD", $code);
        if ($code === 0 && $log !== '') {
            foreach (explode("\n", $log) as $row) {
                $parts = explode('|', $row, 5);
                if (count($parts) === 5) {
                    $info['changelog'][] = [
                        'hash' => $parts[0],
                        'short' => $parts[1],
                        'date' => $parts[2],
                        'author' => $parts[3],
                        'message' => $parts[4],
                    ];
                }
            }
        }
    } else {
        $detail = sanitizeGitText($fetchOut !== '' ? $fetchOut : 'git fetch exited with code ' . $fetchCode);
        // Filesystem-permission failures (web user can't write to .git) also exit 128 — detect them
        // first so we don't mislabel them as a missing token.
        $looksLikePerm = (bool)preg_match('~permission denied|insufficient permission|cannot lock|unable to create|operation not permitted|failed to write|unpack-objects failed~i', $fetchOut);
        if ($looksLikePerm) {
            $info['error'] = 'Souborová práva: webový uživatel nemá zápis do .git (sjednoťte vlastníka/práva repozitáře na serveru)';
        } elseif ($info['token_present']) {
            $info['error'] = 'Git fetch failed (check the GITHUB_TOKEN scope/validity)';
        } elseif ($fetchCode === 128) {
            $info['error'] = 'Git access denied — private repo needs a GITHUB_TOKEN in .env';
        } else {
            $info['error'] = 'Git fetch failed';
        }
        $info['error_detail'] = $detail;
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
                TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS customer_name,
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
    $stmt = $pdo->query("SELECT id, name, role, telegram_id FROM technicians WHERE is_active = 1 AND role IN ('admin', 'manager', 'boss')");
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

    // Klientský e-mail „připraveno k vyzvednutí" — jen při přechodu DO skupiny 'completed'.
    if ($type === 'order_status_changed' && $newStatus !== '' && isOrderStatusIn($newStatus, 'completed')
        && !($oldStatus !== '' && isOrderStatusIn($oldStatus, 'completed'))) {
        crmSendPickupReadyEmail($orderId);
    }

    // Poděkování + žádost o Google recenzi — jen při přechodu DO skupiny 'collected' (vydáno).
    if ($type === 'order_status_changed' && $newStatus !== '' && isOrderStatusIn($newStatus, 'collected')
        && !($oldStatus !== '' && isOrderStatusIn($oldStatus, 'collected'))) {
        crmSendOrderReviewEmail($orderId);
    }

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

    // Popup „nová přidělená zakázka" pro technika (na zařízení s otevřeným CRM),
    // ale NE pokud si zakázku přidělil sám sobě.
    if ($technicianAssigned && $assignedTechId > 0 && $assignedTechId !== $actorTechId) {
        crmEnqueueTechAssignmentPopup($assignedTechId, $orderId);
    }

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
            technician_id INT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
    try { $pdo->exec("ALTER TABLE order_status_log ADD COLUMN technician_id INT NULL AFTER changed_role"); } catch (Throwable $e) { /* už existuje */ }
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
        // snímek přiděleného technika v okamžiku změny (pro „V opravě: <jméno>" v historii)
        $log_tech_id = null;
        try {
            $ts = $pdo->prepare('SELECT technician_id FROM orders WHERE id = ?');
            $ts->execute([$order_id]);
            $log_tech_id = $ts->fetchColumn() ?: null;
        } catch (Throwable $e) { /* ignore */ }
        $stmt = $pdo->prepare(
            "INSERT INTO order_status_log (order_id, old_status, new_status, changed_by, changed_role, technician_id)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$order_id, $old_status, $new_status, $changed_by, $changed_role, $log_tech_id]);
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

/**
 * Per-technician work segments.
 *
 * orders.work_duration_seconds keeps the order's cumulative minutes (for the order detail view),
 * but it is attributed wholly to the order's CURRENT technician — which mis-credits time when a
 * job is reassigned. order_work_log records each continuous work segment (technician + minutes),
 * so reports can credit time/earnings to whoever actually did each segment.
 *
 * Transitions (mirror the orders.work_* logic):
 *   start    -> workSegmentOpen(order, tech)
 *   reassign -> workSegmentOpen(order, newTech)   (closes the previous tech's open segment first)
 *   finish   -> workSegmentClose(order)
 */
function ensureOrderWorkLogSchema(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_work_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            technician_id INT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL,
            duration_minutes INT NOT NULL DEFAULT 0,
            rate_snapshot DECIMAL(10,2) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_owl_order (order_id),
            INDEX idx_owl_tech (technician_id),
            INDEX idx_owl_ended (ended_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // best-effort schema guard
    }
    // Add rate_snapshot to an already-existing table (earnings are priced at the rate in effect
    // when the segment was worked, so editing a rate later does not re-price past payroll).
    try { $pdo->exec("ALTER TABLE order_work_log ADD COLUMN rate_snapshot DECIMAL(10,2) NULL DEFAULT NULL"); } catch (Throwable $e) {}

    // One-time backfill: seed a single segment per historical order from its cumulative total,
    // credited to its (current) technician, so existing reports keep their numbers. Going forward,
    // reassigned jobs are split correctly via live segments.
    try {
        if (get_setting('work_log_backfilled', '') !== '1') {
            // NOT EXISTS makes the backfill idempotent (safe against a concurrent double-run).
            $pdo->exec("INSERT INTO order_work_log (order_id, technician_id, started_at, ended_at, duration_minutes, rate_snapshot)
                SELECT o.id, o.technician_id,
                       COALESCE(o.work_started_at, o.work_finished_at, o.updated_at, o.created_at),
                       COALESCE(o.work_finished_at, o.updated_at, o.created_at),
                       COALESCE(o.work_duration_seconds, 0),
                       COALESCE((SELECT t.engineer_rate FROM technicians t WHERE t.id = o.technician_id), 0)
                FROM orders o
                WHERE COALESCE(o.work_duration_seconds, 0) > 0
                  AND NOT EXISTS (SELECT 1 FROM order_work_log w2 WHERE w2.order_id = o.id)");
            set_setting('work_log_backfilled', '1');
        }
    } catch (Throwable $e) {
        // columns may not exist yet on a fresh DB → retry on a later call
    }
}

/**
 * Close the order's currently open work segment, crediting its elapsed minutes to its technician.
 * NOTE: callers must have run ensureOrderWorkLogSchema() OUTSIDE any open transaction first — the
 * CREATE TABLE there is DDL (implicit commit in MySQL) and must not run inside a transaction.
 */
function workSegmentClose(int $orderId): void {
    global $pdo;
    if ($orderId <= 0) return;
    try {
        // Snapshot the technician's rate in effect right now, so this segment's pay is fixed
        // even if the rate is edited later.
        $pdo->prepare("UPDATE order_work_log owl
            LEFT JOIN technicians t ON t.id = owl.technician_id
            SET owl.ended_at = NOW(),
                owl.duration_minutes = GREATEST(0, TIMESTAMPDIFF(MINUTE, owl.started_at, NOW())),
                owl.rate_snapshot = COALESCE(t.engineer_rate, owl.rate_snapshot, 0)
            WHERE owl.order_id = ? AND owl.ended_at IS NULL")->execute([$orderId]);
    } catch (Throwable $e) {
        // best-effort
    }
}

/** Open a new work segment for the given technician (closing any previous open one first). */
function workSegmentOpen(int $orderId, ?int $technicianId): void {
    global $pdo;
    if ($orderId <= 0) return;
    workSegmentClose($orderId);
    try {
        $pdo->prepare("INSERT INTO order_work_log (order_id, technician_id, started_at, ended_at, duration_minutes)
            VALUES (?, ?, NOW(), NULL, 0)")->execute([$orderId, ($technicianId ?: null)]);
    } catch (Throwable $e) {
        // best-effort
    }
}

function getTechnicianInProgressCount($technicianId, $excludeOrderId = null) {
    global $pdo;
    if (!$technicianId) return 0;
    $sql = "SELECT COUNT(*) FROM orders WHERE technician_id = ? AND status IN (" . orderStatusSqlIn($pdo, 'in_progress') . ")";
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

/** URL uvítacího zvuku zaměstnance (uploads/greetings/<username>.<ext>), null pokud není nahraný. */
function loginGreetingUrl(string $username): ?string
{
    $slug = preg_replace('/[^a-zA-Z0-9._-]/', '_', trim($username));
    if ($slug === '') { return null; }
    foreach (['mp3', 'm4a', 'wav', 'ogg'] as $ext) {
        if (is_file(__DIR__ . '/../uploads/greetings/' . $slug . '.' . $ext)) {
            return 'uploads/greetings/' . $slug . '.' . $ext;
        }
    }
    return null;
}

/* ============================================================
   HLÍDÁNÍ ZAKÁZEK BEZ POHYBU (dim efekt řádků v seznamech)
   „Bez pohybu" = od poslední změny stavu NEBO jakékoli úpravy
   zakázky (updated_at) — cokoli novějšího. Limity v hodinách:
   [pomalé dýchání, rychlé dýchání|null].
   ============================================================ */
function getOrderStaleThresholds(): array
{
    return [
        'Přijato' => [1, 2],
        'V opravě' => [1, 2],
        'V opravě - v externím servisu' => [24, null],
        'V opravě - v autorizovaném servisu' => [48, null],
        'Čeká na díl' => [1, 12],
        'Vydáno - čeká na platbu' => [24, null],
        // legacy ekvivalenty (staré zakázky si zaslouží stejnou pozornost)
        'V opravě zák. desky' => [1, 2],
        'V externím servisu' => [24, null],
        'V aut. servisu' => [48, null],
    ];
}

/** Vrátí 'fast' | 'slow' | null podle stavu a doby bez pohybu. */
function getOrderStaleLevel(string $status, int $secondsSinceActivity): ?string
{
    $thresholds = getOrderStaleThresholds()[$status] ?? null;
    if (!$thresholds || $secondsSinceActivity <= 0) { return null; }
    [$slowH, $fastH] = $thresholds;
    $hours = $secondsSinceActivity / 3600;
    if ($fastH !== null && $hours >= $fastH) { return 'fast'; }
    if ($hours >= $slowH) { return 'slow'; }
    return null;
}

/** Sekundy bez pohybu z řádku zakázky (potřebuje last_status_change z dotazu, jinak updated_at/created_at). */
function orderStaleSeconds(array $order): int
{
    $candidates = [];
    foreach (['last_status_change', 'updated_at', 'created_at'] as $col) {
        if (!empty($order[$col])) { $candidates[] = strtotime((string)$order[$col]); }
    }
    $last = $candidates ? max($candidates) : null;
    return $last ? max(0, time() - $last) : 0;
}

/** Doplní do tabulky `complaints` sloupce pro klientský portál (idempotentně,
 *  bez migrace — funguje i na starších instalacích). Volá se před prací s reklamacemi.
 *  - order_id / order_code : napojení reklamace na konkrétní zakázku
 *  - source                : 'client' = založeno z klientské sekce, jinak 'staff'
 *  - updated_at            : bump při editaci
 *  - staff_ack_at          : kdy technik/manažer poprvé zareagoval (uvolní „připíchnutí" nahoře) */
function ensureComplaintsClientColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM complaints")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) return;
        $add = [];
        if (!in_array('order_id', $cols, true))     $add[] = "ADD COLUMN `order_id` INT(11) NULL";
        if (!in_array('order_code', $cols, true))   $add[] = "ADD COLUMN `order_code` VARCHAR(30) NULL";
        if (!in_array('source', $cols, true))       $add[] = "ADD COLUMN `source` VARCHAR(20) NOT NULL DEFAULT 'staff'";
        if (!in_array('updated_at', $cols, true))   $add[] = "ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP";
        if (!in_array('staff_ack_at', $cols, true)) $add[] = "ADD COLUMN `staff_ack_at` TIMESTAMP NULL DEFAULT NULL";
        if ($add) {
            $pdo->exec("ALTER TABLE `complaints` " . implode(', ', $add));
        }
    } catch (Throwable $e) {
        // starší DB / chybějící oprávnění — reklamace fungují i bez těchto sloupců
    }
}

/** True = reklamace založená klientem, na kterou zatím nikdo ze servisu nereagoval
 *  (drží se nahoře v seznamu + pulzuje, dokud technik/manažer nezmění stav). */
function complaintIsNewFromClient(array $row): bool
{
    return ($row['source'] ?? '') === 'client' && empty($row['staff_ack_at']);
}

/** Tabulka pro popup „nová přidělená zakázka" (per technik, doručí se pollingem). */
function ensureTechPopupTable(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `tech_assignment_popups` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `technician_id` INT(11) NOT NULL,
                `order_id` INT(11) NOT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `seen_at` TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `tech_unseen` (`technician_id`, `seen_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) { /* best-effort */ }
}

/** Zařadí technikovi popup o nově přidělené zakázce (bez duplicit, dokud si ho nepřevezme). */
function crmEnqueueTechAssignmentPopup(int $techId, int $orderId): void
{
    global $pdo;
    if ($techId <= 0 || $orderId <= 0 || !isset($pdo)) return;
    try {
        ensureTechPopupTable($pdo);
        $chk = $pdo->prepare("SELECT id FROM tech_assignment_popups WHERE technician_id = ? AND order_id = ? AND seen_at IS NULL LIMIT 1");
        $chk->execute([$techId, $orderId]);
        if ($chk->fetchColumn()) return; // už čeká nezobrazený popup
        $pdo->prepare("INSERT INTO tech_assignment_popups (technician_id, order_id) VALUES (?, ?)")->execute([$techId, $orderId]);
    } catch (Throwable $e) { /* best-effort — nesmí shodit změnu zakázky */ }
}

/** Naskenovaný kód → kandidáti (řeší CZ QWERTZ: číslice↔háčky a Y↔Z, i nekonzistentní přepis).
 *  Vrací unikátní varianty VELKÝMI písmeny, jen znaky [0-9A-Z-]. */
function scanNormalizeCandidates(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') return [];
    $mapDigits = ['+'=>'1','ě'=>'2','š'=>'3','č'=>'4','ř'=>'5','ž'=>'6','ý'=>'7','á'=>'8','í'=>'9','é'=>'0',
                  'Ě'=>'2','Š'=>'3','Č'=>'4','Ř'=>'5','Ž'=>'6','Ý'=>'7','Á'=>'8','Í'=>'9','É'=>'0'];
    $mapYZ     = ['y'=>'z','z'=>'y','Y'=>'Z','Z'=>'Y'];
    $variants = [
        $raw,                                 // jak přišlo
        strtr($raw, $mapDigits),              // háčky→číslice
        strtr($raw, $mapYZ),                  // jen Y↔Z
        strtr($raw, $mapDigits + $mapYZ),     // obojí (plný demangle)
    ];
    $out = [];
    foreach ($variants as $v) {
        $v = strtoupper(preg_replace('/[^0-9A-Za-z\-]/u', '', $v));
        if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
    }
    return $out;
}

/** Přeloží naskenovaný kód na ID zakázky (zkusí víc variant demanglingu + holé číselné ID). null = nenalezeno. */
function resolveScannedOrderId(PDO $pdo, string $raw): ?int
{
    $cands = scanNormalizeCandidates($raw);
    if (!$cands) return null;
    try {
        $in = implode(',', array_fill(0, count($cands), '?'));
        $st = $pdo->prepare("SELECT id FROM orders WHERE order_code IN ($in) ORDER BY id DESC LIMIT 1");
        $st->execute($cands);
        $id = $st->fetchColumn();
        if ($id) return (int)$id;
    } catch (Throwable $e) { /* ignore */ }
    foreach ($cands as $c) {
        if (ctype_digit($c)) {
            try {
                $st = $pdo->prepare("SELECT id FROM orders WHERE id = ? LIMIT 1");
                $st->execute([(int)$c]);
                $id = $st->fetchColumn();
                if ($id) return (int)$id;
            } catch (Throwable $e) { /* ignore */ }
        }
    }
    return null;
}

/** Doplní sloupce pro notifikaci „připraveno k vyzvednutí" (idempotentně, bez migrace):
 *  orders.pickup_notified_at (guard proti opakovanému odeslání) + branches.opening_hours. */
function ensurePickupReadyColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $oc = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if ($oc && !in_array('pickup_notified_at', $oc, true)) {
            $pdo->exec("ALTER TABLE `orders` ADD COLUMN `pickup_notified_at` TIMESTAMP NULL DEFAULT NULL");
        }
        $bc = $pdo->query("SHOW COLUMNS FROM branches")->fetchAll(PDO::FETCH_COLUMN);
        if ($bc && !in_array('opening_hours', $bc, true)) {
            $pdo->exec("ALTER TABLE `branches` ADD COLUMN `opening_hours` VARCHAR(255) NULL DEFAULT NULL");
        }
        if ($bc && !in_array('contact_phone', $bc, true)) {
            $pdo->exec("ALTER TABLE `branches` ADD COLUMN `contact_phone` VARCHAR(32) NULL DEFAULT NULL");
        }
        if ($bc && !in_array('contact_email', $bc, true)) {
            $pdo->exec("ALTER TABLE `branches` ADD COLUMN `contact_email` VARCHAR(128) NULL DEFAULT NULL");
        }

        // Předvyplnit kontakty poboček dle applefix.cz (jen PRÁZDNÁ pole — ruční úpravy se nepřepisují)
        $defaultHours = "Po – Út: 10:00 – 20:00\nSt – Pá: 10:00 – 18:00\nSo – Ne: po domluvě";
        foreach ($pdo->query("SELECT id, code, name, address, opening_hours, contact_phone, contact_email FROM branches")->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $key = mb_strtolower(($b['name'] ?? '') . ' ' . ($b['code'] ?? ''), 'UTF-8');
            $def = null;
            if (str_contains($key, 'karl')) {
                $def = ['phone' => '+420 704 011 939', 'email' => 'info@applefix.cz',
                        'addr' => "Křižíkova 177/29\n186 00 Praha 8 – Karlín"];
            } elseif (str_contains($key, 'růže') || str_contains($key, 'ruze') || str_contains($key, 'příkop') || str_contains($key, 'prikop') || str_contains($key, 'václav') || str_contains($key, 'vaclav')) {
                $def = ['phone' => '+420 705 926 236', 'email' => 'cerna-ruze@applefix.cz',
                        'addr' => "Na Příkopě 853\n110 00 Praha 1 – Nové Město"];
            }
            if (!$def) { continue; }
            $upd = [];
            $par = [];
            if (trim((string)$b['contact_phone']) === '') { $upd[] = 'contact_phone = ?'; $par[] = $def['phone']; }
            if (trim((string)$b['contact_email']) === '') { $upd[] = 'contact_email = ?'; $par[] = $def['email']; }
            if (trim((string)$b['address']) === '')       { $upd[] = 'address = ?';       $par[] = $def['addr']; }
            if (trim((string)$b['opening_hours']) === '') { $upd[] = 'opening_hours = ?'; $par[] = $defaultHours; }
            if ($upd) {
                $par[] = (int)$b['id'];
                $pdo->prepare('UPDATE branches SET ' . implode(', ', $upd) . ' WHERE id = ?')->execute($par);
            }
        }
    } catch (Throwable $e) { /* starší DB / bez oprávnění — funguje i bez těchto sloupců */ }
}

/** Jazyk komunikace zákazníka: customers.preferred_language (cs/en/ru, default cs).
 *  Volí se při zakládání zakázky u údajů klienta; e-maily klientovi odcházejí v tomto jazyce. */
function ensureCustomerLanguageColumn(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        if ($cols && !in_array('preferred_language', $cols, true)) {
            $pdo->exec("ALTER TABLE `customers` ADD COLUMN `preferred_language` VARCHAR(5) NOT NULL DEFAULT 'cs'");
        }
    } catch (Throwable $e) { /* best-effort */ }
}

/** ── Ceník oprav z applefix.cz (RepairPlugin) ─────────────────────────────
 *  Sync přes veřejné AJAX endpointy objednávkového formuláře (rp_fe_get_models /
 *  rp_fe_get_repairs). Ceny jsou u variant oprav (Originál / Repas…); oprava bez
 *  varianty s cenou 0 = „na dotaz" (price NULL). Sync: Nastavení → Integrace. */
function ensureRepairPricelistTable(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS repair_pricelist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(60) NOT NULL,
            brand VARCHAR(60) NOT NULL,
            model VARCHAR(120) NOT NULL,
            model_code VARCHAR(190) NULL,
            repair_name VARCHAR(150) NOT NULL,
            variant VARCHAR(150) NULL,
            price DECIMAL(10,2) NULL,
            duration_min INT NULL,
            synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rpl_model (model),
            INDEX idx_rpl_brand (brand, model)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* best-effort */ }
}

/** POST na admin-ajax.php applefix.cz; vrací dekódované JSON pole nebo null. */
function crmPricelistFetch(array $post): ?array {
    $ch = curl_init('https://applefix.cz/wp-admin/admin-ajax.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'Fix-CRM pricelist sync (admin.applefix.cloud)',
    ]);
    $body = curl_exec($ch);
    if (!is_string($body) || $body === '') { return null; }
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

/** Stáhne ceník jedné kategorie+značky (všechny modely) a nahradí ji v tabulce.
 *  Vrací ['models' => n, 'rows' => m] nebo vyhazuje výjimku s popisem. */
function crmSyncRepairPricelist(string $category, string $brand): array {
    global $pdo;
    ensureRepairPricelistTable();

    $models = crmPricelistFetch(['action' => 'rp_fe_get_models', 'c_name' => $category, 'b_name' => $brand]);
    $list = $models['results'] ?? null;
    if (!is_array($list)) { throw new RuntimeException('Nepodařilo se načíst modely (' . $category . ' / ' . $brand . ').'); }

    $rows = [];
    foreach ($list as $m) {
        // klíče odpovědi: A=id, B=název modelu, D=kódy modelu (A-čísla)
        $mName = trim((string)($m['B'] ?? $m['m_name'] ?? ''));
        $mCode = trim((string)($m['D'] ?? $m['m_code'] ?? ''));
        if ($mName === '') { continue; }

        $rep = crmPricelistFetch(['action' => 'rp_fe_get_repairs', 'b_name' => $brand, 'm_name' => $mName]);
        if (!is_array($rep)) { continue; }
        $repairs = array_merge((array)($rep['limited_repairs'] ?? []), (array)($rep['remaining_repairs'] ?? []));
        $attrs = (array)($rep['repair_attrs_from_db'] ?? []);

        foreach ($repairs as $r) {
            $rName = trim((string)($r['r_name'] ?? ''));
            if ($rName === '' || (string)($r['is_active'] ?? '1') !== '1') { continue; }
            $rId = (string)($r['r_id'] ?? '');
            $variants = (isset($attrs[$rId]) && is_array($attrs[$rId])) ? $attrs[$rId] : null;
            if ($variants) {
                foreach ($variants as $v) {
                    // klíče: B=název varianty, D=cena, E=aktivní, H=doba trvání
                    if ((string)($v['E'] ?? '1') !== '1') { continue; }
                    $price = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string)($v['D'] ?? '')));
                    $rows[] = [$category, $brand, $mName, $mCode, $rName, trim((string)($v['B'] ?? '')), $price > 0 ? $price : null, (int)($v['H'] ?? 0) ?: null];
                }
            } else {
                $price = (float)($r['r_price'] ?? 0);
                $rows[] = [$category, $brand, $mName, $mCode, $rName, null, $price > 0 ? $price : null, (int)($r['r_duration'] ?? 0) ?: null];
            }
        }
        usleep(120000);   // šetrné tempo vůči webu
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM repair_pricelist WHERE category = ? AND brand = ?")->execute([$category, $brand]);
        $ins = $pdo->prepare("INSERT INTO repair_pricelist (category, brand, model, model_code, repair_name, variant, price, duration_min) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows as $row) { $ins->execute($row); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    set_setting('pricelist_last_sync', date('Y-m-d H:i') . ' — ' . $category . ' / ' . $brand);
    return ['models' => count($list), 'rows' => count($rows)];
}

/** ── Podpisy klienta (iPad/tablet) ────────────────────────────────────────
 *  order_signatures: podpis při PŘÍJMU (souhlas s podmínkami) a při VÝDEJI
 *  (převzetí hotové zakázky). PNG s průhledným pozadím v uploads/signatures/,
 *  tiskne se na zakázkovém listu nad podpisovou čarou. */
function ensureOrderSignaturesTable(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_signatures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            sig_type VARCHAR(20) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            signed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            requested_by VARCHAR(100) NULL,
            INDEX idx_os_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* best-effort */ }
}

/** Pošle klientovi zakázkový list e-mailem (HTML = print_order.php v embed módu,
 *  vč. případných podpisů). Vrací [ok, chyba]. Používá api/send_order_email.php
 *  i automatika po podpisu na tabletu. */
function crmSendOrderSheetEmail(int $orderId, ?string $toOverride = null): array {
    global $pdo;
    $st = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.address, c.email, c.preferred_language
                         FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?");
    $st->execute([$orderId]);
    $order = $st->fetch();
    if (!$order) { return [false, 'Zakázka nenalezena']; }

    $to = trim((string)($toOverride ?? $order['email'] ?? ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return [false, 'Klient nemá platný e-mail.'];
    }

    $it = $pdo->prepare("SELECT oi.*, i.part_name FROM order_items oi JOIN inventory i ON oi.inventory_id = i.id WHERE oi.order_id = ?");
    $it->execute([$orderId]);
    $items = $it->fetchAll();

    $target_lang = crmCustomerDocLang($order['preferred_language'] ?? 'cs');
    $__EMAIL_MODE = true;
    if (!defined('ORDER_DOC_EMBED')) { define('ORDER_DOC_EMBED', true); }
    ob_start();
    include __DIR__ . '/../print_order.php';
    $html = ob_get_clean();

    $subject = (get_setting('company_name', 'AppleFix')) . ' — zakázkový list ' . orderDisplayCode($order);
    return smtpSendMail($to, $subject, $html);
}

/** Fronta požadavků pro podpisovou stanici (iPad na pultu): zaměstnanec pošle
 *  žádost z detailu zakázky, stanice si ji stáhne (poll) a po podpisu označí done. */
function ensureSignatureRequestsTable(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS signature_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            sig_type VARCHAR(20) NOT NULL,
            branch_id INT NULL,
            requested_by VARCHAR(100) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            email_after TINYINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sr_branch (branch_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $pdo->exec("ALTER TABLE signature_requests ADD COLUMN email_after TINYINT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* už existuje */ }
    } catch (Throwable $e) { /* best-effort */ }
}

/** Podpisy zakázky jako mapa [sig_type => řádek]. */
function crmGetOrderSignatures(int $orderId): array {
    global $pdo;
    try {
        ensureOrderSignaturesTable();
        $st = $pdo->prepare("SELECT sig_type, file_path, signed_at FROM order_signatures WHERE order_id = ? ORDER BY id ASC");
        $st->execute([$orderId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(string)$r['sig_type']] = $r; }
        return $out;
    } catch (Throwable $e) { return []; }
}

/** Cenové řádky zakázky — rozpis ceny na zakázkovém listu (oprava, expresní
 *  příplatek, slevy…). Plní je webhook z RepairPluginu (items[]) a wizard CRM
 *  (příplatek/sleva dle priority). Rozpis se tiskne, když jsou aspoň 2 řádky. */
function ensureOrderPriceLinesTable(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_price_lines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            label VARCHAR(190) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            sort INT NOT NULL DEFAULT 0,
            INDEX idx_opl_order (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* best-effort */ }
}

function crmAddOrderPriceLine(int $orderId, string $label, float $amount, int $sort = 0): void {
    global $pdo;
    ensureOrderPriceLinesTable();
    $pdo->prepare("INSERT INTO order_price_lines (order_id, label, amount, sort) VALUES (?, ?, ?, ?)")
        ->execute([$orderId, mb_substr(trim($label), 0, 190), round($amount, 2), $sort]);
}

function crmGetOrderPriceLines(int $orderId): array {
    global $pdo;
    try {
        ensureOrderPriceLinesTable();
        $st = $pdo->prepare("SELECT label, amount FROM order_price_lines WHERE order_id = ? ORDER BY sort ASC, id ASC");
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** Jazyk pro DOKUMENTY klienta (tisk): cs/en přímo; ukrajinsky zatím dokumenty
 *  přeložené nejsou → uk klientům se tisknou anglicky (e-maily jdou plně ukrajinsky). */
function crmCustomerDocLang($preferredLanguage): string {
    $lang = normalizeCustomerLanguage($preferredLanguage);
    return $lang === 'uk' ? 'en' : $lang;
}

/** Sloupec orders.created_by_name — jméno zaměstnance, který zakázku vytvořil
 *  (natvrdo, přežije smazání účtu). Starší zakázky ho nemají (NULL) — spolehlivá
 *  zpětná rekonstrukce není možná, tak raději nic než špatné jméno. */
function ensureOrderCreatedByColumn(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    try {
        $col = $pdo->query("SHOW COLUMNS FROM orders LIKE 'created_by_name'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN created_by_name VARCHAR(190) NULL AFTER created_at");
        }
        $done = true;
    } catch (Throwable $e) { error_log('ensureOrderCreatedByColumn: ' . $e->getMessage()); }
}

/* ─────────────────────────────  ZÁLOHOVÁNÍ CRM  ───────────────────────────────
 * Kompletní záloha každých ~15 minut: DB dump (gzip) + uploads (tar.gz) + kód
 * (tar.gz jen při změně git verze). Spouští se samo z notify_poll/webhooku
 * (poor-man's cron — žádný systémový cron není potřeba). Retence 48 hodin.
 * Obnova ze Nastavení → Zálohy (jen admin), před obnovou pojistná záloha.
 * ---------------------------------------------------------------------------- */

/** Adresář záloh — PŘEDNOSTNĚ mimo webroot (../crm-backups vedle aplikace),
 *  fallback backups/ v aplikaci chráněný .htaccess. */
function crmBackupBaseDir(): string {
    $root = dirname(__DIR__);                    // kořen aplikace
    $protect = static function (string $d): void {
        // ochrana pro případ, že by adresář byl v dosahu webu: Apache deny +
        // prázdný index (nginx directory listing) — defense-in-depth
        if (!file_exists($d . '/.htaccess')) { @file_put_contents($d . '/.htaccess', "Require all denied\n"); }
        if (!file_exists($d . '/index.html')) { @file_put_contents($d . '/index.html', ''); }
    };
    $pick = static function (string $base) use ($protect): ?string {
        if (!is_dir($base) && !@mkdir($base, 0750, true)) return null;
        if (!is_writable($base)) return null;
        $protect($base);
        // vlastní úložiště s náhodným jménem — i kdyby byl adresář omylem
        // veřejně dostupný (nginx ignoruje .htaccess), cesta není uhodnutelná
        $tokenFile = $base . '/.token';
        $token = trim((string)@file_get_contents($tokenFile));
        if (!preg_match('/^[a-f0-9]{24}\z/', $token)) {
            $token = bin2hex(random_bytes(12));
            @file_put_contents($tokenFile, $token);
            @chmod($tokenFile, 0600);
        }
        $store = $base . '/store-' . $token;
        if (!is_dir($store) && !@mkdir($store, 0750, true)) return null;
        $protect($store);
        return $store;
    };
    return $pick(dirname($root) . '/crm-backups')   // primárně mimo webroot
        ?? $pick($root . '/backups')                // fallback v aplikaci
        ?? sys_get_temp_dir() . '/crm-backups';     // nouzově (nemělo by nastat)
}

/** Dočasný my.cnf s přihlášením k DB — heslo nesmí do příkazové řádky (ps). */
function crmBackupMysqlCnf(): string {
    $f = crmBackupBaseDir() . '/.my.cnf';
    if (!is_file($f)) { @touch($f); }
    @chmod($f, 0600);                                // práva PŘED zápisem hesla
    $esc = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    @file_put_contents($f, "[client]\nhost=" . $esc(DB_HOST) . "\nuser=" . $esc(DB_USER) . "\npassword=" . $esc(DB_PASS) . "\n");
    return $f;
}

/** Najde binárku (mysqldump/mariadb-dump apod.) — první existující vyhraje. */
function crmBackupFindBin(array $candidates): ?string {
    foreach ($candidates as $c) {
        $p = trim((string)@shell_exec('command -v ' . escapeshellarg($c) . ' 2>/dev/null'));
        if ($p !== '') return $p;
    }
    return null;
}

/**
 * Provede kompletní zálohu HNED (blokující, řádově sekundy).
 * @return array{0:bool,1:string} [ok, zpráva]
 */
function crmRunBackupNow(string $reason = 'auto'): array {
    if (!function_exists('exec')) { return [false, 'exec() není na serveru povolen']; }
    $base = crmBackupBaseDir();

    // probíhající OBNOVA má přednost — běžná záloha počká na další cyklus
    // (pojistná 'prerestore' záloha je součástí obnovy, ta projít musí)
    if ($reason !== 'prerestore') {
        $rl = @fopen($base . '/.restore.lock', 'c');
        if ($rl) {
            if (!flock($rl, LOCK_EX | LOCK_NB)) { fclose($rl); return [false, 'Právě probíhá obnova zálohy']; }
            flock($rl, LOCK_UN); fclose($rl);
        }
    }

    $lock = @fopen($base . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return [false, 'Záloha už právě běží']; }
    $dir = null;
    try {
        $root = dirname(__DIR__);

        // binárka musí existovat DŘÍV, než cokoli vytvoříme
        $dump = crmBackupFindBin(['mysqldump', 'mariadb-dump']);
        if ($dump === null) { return [false, 'mysqldump není k dispozici']; }

        $stamp = date('Ymd_His');
        $prefix = ($reason === 'prerestore') ? 'prerestore_' : 'backup_';
        $dir = $base . '/' . $prefix . $stamp;
        if (!@mkdir($dir, 0750, true)) { $dir = null; return [false, 'Nelze vytvořit adresář zálohy']; }

        // ── 1) databáze (kompletní: zakázky, klienti, faktury, historie…) ──
        $cnf = crmBackupMysqlCnf();
        $dbFile = $dir . '/db.sql.gz';
        $pipeline = escapeshellarg($dump) . ' --defaults-extra-file=' . escapeshellarg($cnf)
             . ' --single-transaction --quick --routines --triggers ' . escapeshellarg(DB_NAME)
             . ' 2>' . escapeshellarg($dir . '/db.err') . ' | gzip > ' . escapeshellarg($dbFile);
        // pipefail: jinak by exit kód patřil gzipu a selhání dumpu by se maskovalo
        exec('bash -c ' . escapeshellarg('set -o pipefail; ' . $pipeline), $o1, $rc1);
        $gzOk = is_file($dbFile) && filesize($dbFile) >= 1024;
        if ($gzOk) { exec('gunzip -t ' . escapeshellarg($dbFile) . ' 2>/dev/null', $oT, $rcT); $gzOk = ($rcT === 0); }
        if ($rc1 !== 0 || !$gzOk) {
            $err = trim((string)@file_get_contents($dir . '/db.err'));
            return [false, 'Dump databáze selhal' . ($err !== '' ? ': ' . mb_substr($err, 0, 200) : '')];
        }
        @unlink($dir . '/db.err');
        $dirDone = false;   // od teď máme validní dump

        // ── 2) nahrané soubory (fotky zakázek, podpisy, přílohy reklamací) ──
        if (is_dir($root . '/uploads')) {
            exec('tar -czf ' . escapeshellarg($dir . '/files.tar.gz') . ' -C ' . escapeshellarg($root) . ' uploads 2>/dev/null', $o2, $rc2);
        }

        // ── 3) kód CRM — jen když se změnila git verze od poslední zálohy ──
        $gitHash = trim((string)@shell_exec('cd ' . escapeshellarg($root) . ' && git rev-parse HEAD 2>/dev/null'));
        $lastHash = (string)@file_get_contents($base . '/.last_code_hash');
        if ($gitHash === '' || $gitHash !== $lastHash) {
            exec('tar -czf ' . escapeshellarg($dir . '/code.tar.gz')
               . ' --exclude=./.git --exclude=./uploads --exclude=./backups --exclude=./print-bridge/.venv'
               . ' -C ' . escapeshellarg($root) . ' . 2>/dev/null', $o3, $rc3);
            if ($gitHash !== '') { @file_put_contents($base . '/.last_code_hash', $gitHash); }
        }

        // ── 4) metadata (pro přehled v UI) ──
        global $pdo;
        $counts = ['orders' => null, 'customers' => null];
        try {
            $counts['orders'] = (int)$pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $counts['customers'] = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();
        } catch (Throwable $e) {}
        @file_put_contents($dir . '/meta.json', json_encode([
            'time' => date('c'), 'reason' => $reason, 'git' => substr($gitHash, 0, 10),
            'db_bytes' => @filesize($dbFile) ?: 0,
            'files_bytes' => @filesize($dir . '/files.tar.gz') ?: 0,
            'code_bytes' => @filesize($dir . '/code.tar.gz') ?: 0,
            'orders' => $counts['orders'], 'customers' => $counts['customers'],
        ], JSON_UNESCAPED_UNICODE));

        // ── 5) retence: starší 48 h pryč (vždy ale ponechat 10 nejnovějších) ──
        $all = array_merge(glob($base . '/backup_*', GLOB_ONLYDIR) ?: [], glob($base . '/prerestore_*', GLOB_ONLYDIR) ?: []);
        usort($all, static fn($a, $b) => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));   // dle času, ne názvu
        foreach (array_slice($all, 10) as $oldDir) {
            if (@filemtime($oldDir) < time() - 48 * 3600) {
                exec('rm -rf ' . escapeshellarg($oldDir));
            }
        }

        set_setting('backup_last_run', (string)time());
        set_setting('backup_last_status', 'OK ' . date('d.m.Y H:i:s') . ' (' . basename($dir) . ')');
        $dirDone = true;
        return [true, basename($dir)];
    } finally {
        // neúspěch nesmí nechat na disku rozbitou/prázdnou zálohu
        if ($dir !== null && empty($dirDone)) { exec('rm -rf ' . escapeshellarg($dir)); }
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** Seznam záloh pro UI (nejnovější první). */
function crmListBackups(): array {
    $base = crmBackupBaseDir();
    $out = [];
    foreach (array_merge(glob($base . '/backup_*', GLOB_ONLYDIR) ?: [], glob($base . '/prerestore_*', GLOB_ONLYDIR) ?: []) as $d) {
        $meta = json_decode((string)@file_get_contents($d . '/meta.json'), true) ?: [];
        $out[] = [
            'name' => basename($d),
            'time' => @filemtime($d) ?: 0,
            'db_bytes' => (int)($meta['db_bytes'] ?? (@filesize($d . '/db.sql.gz') ?: 0)),
            'files_bytes' => (int)($meta['files_bytes'] ?? 0),
            'code_bytes' => (int)($meta['code_bytes'] ?? 0),
            'git' => (string)($meta['git'] ?? ''),
            'orders' => $meta['orders'] ?? null,
            'customers' => $meta['customers'] ?? null,
            'prerestore' => str_starts_with(basename($d), 'prerestore_'),
        ];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/**
 * Obnoví zálohu: nejdřív POJISTNÁ záloha aktuálního stavu, pak import DB
 * a rozbalení uploads. Kód se automaticky NEobnovuje (řeší git) — jen upozorní.
 * @return array{0:bool,1:string}
 */
function crmRestoreBackup(string $name): array {
    if (!preg_match('/^(backup|prerestore)_\d{8}_\d{6}\z/', $name)) { return [false, 'Neplatný název zálohy']; }
    $base = crmBackupBaseDir();
    $dir = $base . '/' . $name;
    $dbFile = $dir . '/db.sql.gz';
    if (!is_file($dbFile)) { return [false, 'Záloha neobsahuje databázi']; }

    // zámek přes CELOU obnovu — druhé kliknutí ani auto-záloha nesmí běžet souběžně
    $rlock = @fopen($base . '/.restore.lock', 'c');
    if (!$rlock || !flock($rlock, LOCK_EX | LOCK_NB)) { return [false, 'Obnova už právě probíhá — vydržte, může trvat i minuty.']; }
    try {
        // pojistka: záloha AKTUÁLNÍHO stavu, kdyby obnova byla omyl
        [$okPre, $msgPre] = crmRunBackupNow('prerestore');
        if (!$okPre) { return [false, 'Pojistná záloha selhala (' . $msgPre . ') — obnova přerušena']; }

        $mysql = crmBackupFindBin(['mysql', 'mariadb']);
        if ($mysql === null) { return [false, 'mysql klient není k dispozici']; }
        $cnf = crmBackupMysqlCnf();
        $errF = $base . '/.restore.err';
        exec('bash -c ' . escapeshellarg('set -o pipefail; gunzip -c ' . escapeshellarg($dbFile) . ' | ' . escapeshellarg($mysql)
           . ' --defaults-extra-file=' . escapeshellarg($cnf) . ' ' . escapeshellarg(DB_NAME)
           . ' 2>' . escapeshellarg($errF)), $o, $rc);
        if ($rc !== 0) {
            $err = trim((string)@file_get_contents($errF));
            return [false, 'Import databáze selhal' . ($err !== '' ? ': ' . mb_substr($err, 0, 200) : '') . '. Aktuální stav je v pojistné záloze.'];
        }
        @unlink($errF);

        $root = dirname(__DIR__);
        if (is_file($dir . '/files.tar.gz')) {
            exec('tar -xzf ' . escapeshellarg($dir . '/files.tar.gz') . ' -C ' . escapeshellarg($root) . ' 2>/dev/null');
        }

        // audit AŽ PO importu — záznam o obnově přežije vrácení databáze zpět
        crmAuditLog('system.backup_restore', [
            'entity_type' => 'system',
            'summary' => 'Obnovena záloha ' . $name . ' (pojistná kopie stavu před obnovou: ano)',
        ]);
        set_setting('backup_last_status', 'OBNOVENO z ' . $name . ' — ' . date('d.m.Y H:i:s'));
        return [true, 'Obnoveno ze zálohy ' . $name];
    } finally {
        flock($rlock, LOCK_UN); fclose($rlock);
    }
}

/** Poor-man's cron: zavolat z častých requestů (poll/webhook). Levný check —
 *  když od poslední zálohy uplynulo 15+ minut, spustí zálohu na pozadí. */
function crmBackupMaybeSchedule(): void {
    try {
        $last = (int)get_setting('backup_last_attempt', '0');
        if (time() - $last < 900) return;                       // 15 minut
        if (!function_exists('exec')) return;
        set_setting('backup_last_attempt', (string)time());     // claim (proti souběhu)
        $php = crmBackupFindBin(['php', 'php8.3', 'php8.2', 'php8.1']);
        $script = dirname(__DIR__) . '/scripts/backup_crm.php';
        if ($php !== null && is_file($script)) {
            exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
        } else {
            // ať je problém vidět v UI Zálohy, ne aby zálohy tiše neběžely
            set_setting('backup_last_status', 'CHYBA: nelze spustit zálohu (' . ($php === null ? 'php-cli nenalezen' : 'chybí scripts/backup_crm.php') . ')');
        }
    } catch (Throwable $e) { /* záloha nesmí nikdy shodit request */ }
}

/* ─────────────────────────────  AUDITNÍ HISTORIE  ─────────────────────────────
 * Spolehlivý záznam „kdo — kdy — co udělal" napříč CRM. Jméno aktéra se ukládá
 * NATVRDO do řádku (ne jen ID), aby historie zůstala čitelná i po smazání účtu.
 * Zápis do historie NIKDY neshodí samotnou akci (vše v try/catch).
 * ---------------------------------------------------------------------------- */

/** Vytvoří tabulku audit_log (jednou za request). NIKDY nevolat uvnitř transakce
 *  — DDL by transakci implicitně potvrdilo. crmAuditLog to hlídá sám. */
function ensureAuditLogTable(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actor_type VARCHAR(20) NOT NULL DEFAULT 'system',
            actor_id INT NULL,
            actor_name VARCHAR(190) NOT NULL DEFAULT '',
            actor_role VARCHAR(30) NULL,
            action VARCHAR(60) NOT NULL,
            entity_type VARCHAR(30) NULL,
            entity_id INT NULL,
            entity_label VARCHAR(190) NULL,
            summary VARCHAR(255) NULL,
            details MEDIUMTEXT NULL,
            ip_address VARCHAR(45) NULL,
            branch_id INT NULL,
            PRIMARY KEY (id),
            KEY idx_created (created_at),
            KEY idx_actor (actor_type, actor_id),
            KEY idx_entity (entity_type, entity_id),
            KEY idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) { error_log('ensureAuditLogTable: ' . $e->getMessage()); }
}

/** Zjistí aktéra akce ze session (nebo z explicitního override v $opts). */
function crmAuditResolveActor(array $opts): array {
    if (isset($opts['actor_type'])) {
        return [
            (string)$opts['actor_type'],
            isset($opts['actor_id']) ? (int)$opts['actor_id'] : null,
            trim((string)($opts['actor_name'] ?? '')),
            isset($opts['actor_role']) ? (string)$opts['actor_role'] : null,
        ];
    }
    $name = trim((string)($_SESSION['full_name'] ?? ''));
    if (!empty($_SESSION['tech_id'])) {                 // technik (i Boss/manažer)
        $role = ($_SESSION['role'] ?? '') === 'admin' ? 'admin' : (string)($_SESSION['internal_role'] ?? $_SESSION['role'] ?? 'technician');
        return ['technician', (int)$_SESSION['tech_id'], $name !== '' ? $name : ('Technik #' . (int)$_SESSION['tech_id']), $role];
    }
    if (!empty($_SESSION['user_id'])) {                 // admin účet (users)
        return ['user', is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null, $name !== '' ? $name : (string)($_SESSION['username'] ?? 'Administrátor'), 'admin'];
    }
    if (!empty($_SESSION['client_authenticated'])) {    // klientský portál
        return ['client', (int)($_SESSION['client_customer_id'] ?? 0), trim((string)($_SESSION['client_full_name'] ?? 'Klient')), 'client'];
    }
    return ['system', null, 'Systém', null];            // webhook / automatika
}

/**
 * Zapíše jednu položku do auditní historie. Best-effort: chyba zápisu nikdy
 * neshodí volající akci. $opts: entity_type, entity_id, entity_label, summary,
 * details (pole → JSON | řetězec), branch_id, a případný override aktéra
 * actor_type/actor_id/actor_name/actor_role (např. u přihlášení).
 */
function crmAuditLog(string $action, array $opts = []): void {
    global $pdo;
    if ($action === '' || !isset($pdo)) return;
    try {
        // DDL (vytvoření tabulky) jen mimo transakci — jinak by ji implicitně potvrdilo.
        if (!$pdo->inTransaction()) { ensureAuditLogTable(); }
        [$aType, $aId, $aName, $aRole] = crmAuditResolveActor($opts);
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip !== '') { $ip = substr(trim(explode(',', (string)$ip)[0]), 0, 45); }
        $details = $opts['details'] ?? null;
        if (is_array($details)) { $details = json_encode($details, JSON_UNESCAPED_UNICODE); }
        $stmt = $pdo->prepare("INSERT INTO audit_log
            (actor_type, actor_id, actor_name, actor_role, action, entity_type, entity_id, entity_label, summary, details, ip_address, branch_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $aType, $aId, ($aName !== '' ? $aName : 'Neznámý'), $aRole, $action,
            $opts['entity_type'] ?? null,
            isset($opts['entity_id']) ? (int)$opts['entity_id'] : null,
            $opts['entity_label'] ?? null,
            $opts['summary'] ?? null,
            ($details !== null && $details !== '') ? $details : null,
            ($ip !== '' ? $ip : null),
            isset($opts['branch_id']) ? (int)$opts['branch_id'] : null,
        ]);
    } catch (Throwable $e) {
        error_log('crmAuditLog selhal (' . $action . '): ' . $e->getMessage());
    }
}

/** Lidský český název úkonu pro zobrazení v historii. */
function crmAuditActionLabel(string $action): string {
    static $map = [
        'auth.login' => 'Přihlášení', 'auth.logout' => 'Odhlášení',
        'order.create' => 'Vytvoření zakázky', 'order.update' => 'Úprava zakázky',
        'order.status_change' => 'Změna stavu zakázky', 'order.delete' => 'Smazání zakázky',
        'customer.create' => 'Vytvoření klienta', 'customer.update' => 'Úprava klienta',
        'customer.delete' => 'Smazání klienta',
        'staff.create' => 'Vytvoření zaměstnance', 'staff.update' => 'Úprava zaměstnance',
        'staff.delete' => 'Smazání zaměstnance', 'staff.permissions' => 'Změna oprávnění',
        'admin.create' => 'Povýšení na administrátora', 'admin.password' => 'Změna hesla administrátora',
        'admin.delete' => 'Odstranění administrátora', 'admin.demote' => 'Odebrání admin práv',
        'order.signature_add' => 'Podpis klienta', 'order.dates_change' => 'Zpětná změna datumů',
        'order.item_add' => 'Přidání dílu k zakázce', 'order.item_update' => 'Úprava dílu zakázky',
        'order.item_delete' => 'Odebrání dílu ze zakázky',
        'invoice.create' => 'Vystavení faktury', 'invoice.update' => 'Úprava faktury',
        'invoice.delete' => 'Smazání faktury', 'invoice.status_change' => 'Změna stavu faktury',
        'invoice.credit_note' => 'Vystavení dobropisu',
        'complaint.create' => 'Vytvoření reklamace', 'complaint.status_change' => 'Změna stavu reklamace',
        'procurement.create' => 'Požadavek na díl', 'procurement.status_change' => 'Změna stavu nákupu',
        'procurement.assign_order' => 'Přiřazení dílu k zakázce', 'procurement.delete' => 'Smazání požadavku na díl',
        'inventory.create' => 'Naskladnění dílu', 'inventory.update' => 'Úprava skladového dílu',
        'inventory.delete' => 'Smazání skladového dílu',
        'supplier_catalog.create' => 'Přidání katalogu dodavatele',
        'settings.update' => 'Změna nastavení', 'system.update' => 'Aktualizace systému',
    ];
    return $map[$action] ?? $action;
}

/**
 * Ochrana identity klienta: jednou vyplněné jméno, příjmení, telefon a e-mail
 * smí PŘEPSAT jen administrátor (hasPermission('admin_access')). Prázdný údaj
 * (nebo jen „-"/„–"/„—") smí kdokoli DOPLNIT. Zabraňuje tomu, aby zaměstnanec
 * omylem/záměrně přepsal kontaktní údaje zakázky na někoho jiného.
 * @param array $existing původní řádek customers (aktuální hodnoty)
 * @param array $posted   odeslané hodnoty (klíče first_name/last_name/phone/email)
 * @return array [hodnoty_k_uložení(assoc), zablokovaná_pole(list), je_admin(bool)]
 */
function crmGuardCustomerIdentity(array $existing, array $posted): array {
    $isAdmin = hasPermission('admin_access');
    $fields = ['first_name', 'last_name', 'phone', 'email'];
    $isBlank = static function ($v): bool {
        $v = trim((string)$v);
        return $v === '' || in_array($v, ['-', '–', '—'], true);
    };
    $values = [];
    $blocked = [];
    foreach ($fields as $f) {
        $old = trim((string)($existing[$f] ?? ''));
        $new = array_key_exists($f, $posted) ? trim((string)$posted[$f]) : $old;
        if ($isAdmin || $isBlank($old) || $new === $old) {
            $values[$f] = $new;   // admin / doplnění prázdného / beze změny → povoleno
        } else {
            $values[$f] = $old;   // zaměstnanec nesmí přepsat už vyplněný údaj
            $blocked[] = $f;
        }
    }
    return [$values, $blocked, $isAdmin];
}

/** Je vyplněný údaj identity klienta zamčený pro aktuálního uživatele? (pro UI: readonly) */
function crmCustomerFieldLocked($value): bool {
    if (hasPermission('admin_access')) return false;
    $v = trim((string)$value);
    return $v !== '' && !in_array($v, ['-', '–', '—'], true);
}

/**
 * Je klient zakázky jen ZÁSTUPNÝ/nevyplněný? (prázdné/„-" příjmení a jméno prázdné
 * nebo typu „Neznámý"). Pak smí klienta k zakázce PŘIDAT/přiřadit i zaměstnanec —
 * typicky u starší zakázky, kde se skutečný klient do systému vložil až později.
 * Skutečného vyplněného klienta smí přepsat jen administrátor (ochrana proti záměně).
 */
function crmCustomerIsPlaceholder(?array $cust): bool {
    if (!$cust) return true;
    $blank = static function ($v): bool {
        $v = trim((string)$v);
        return $v === '' || in_array($v, ['-', '–', '—'], true);
    };
    $first = trim((string)($cust['first_name'] ?? ''));
    $firstLower = function_exists('mb_strtolower') ? mb_strtolower($first, 'UTF-8') : strtolower($first);
    $placeholderFirst = $blank($first) || in_array($firstLower,
        ['neznámý', 'neznamy', 'neznámá', 'neznama', 'unknown', 'zákazník', 'zakaznik', 'klient', 'customer', 'walkin', 'walk-in'], true);
    return $blank($cust['last_name'] ?? '') && $placeholderFirst;
}

/** '' / null → NULL, jinak float (čárka i tečka). Pro číselné sloupce ve strict SQL. */
function crmNumOrNull($v): ?float {
    $v = trim((string)($v ?? ''));
    if ($v === '') { return null; }
    return (float)str_replace(',', '.', $v);
}

function normalizeCustomerLanguage($lang): string {
    $lang = strtolower(trim((string)$lang));
    return in_array($lang, ['cs', 'en', 'uk'], true) ? $lang : 'cs';
}

/** Kontakt POBOČKY zakázky pro všechny dokumenty (zakázkový list, účtenky, reklamační
 *  protokol, e-maily): zakázka vystavená pobočkou nese JEJÍ adresu/telefon/e-mail.
 *  Prázdná pole pobočky = fallback na globální nastavení firmy. */
function crmOrderBranchContact($branchId): array {
    global $pdo;
    $out = [
        'label'   => '',
        'address' => trim((string)get_setting('company_address', '')),
        'phone'   => trim((string)get_setting('company_phone', '')),
        'email'   => trim((string)get_setting('company_email', '')) ?: 'info@applefix.cz',
        'hours'   => '',
    ];
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            ensurePickupReadyColumns($pdo);
            if ((int)$branchId > 0) {
                $st = $pdo->prepare('SELECT name, address, opening_hours, contact_phone, contact_email FROM branches WHERE id = ? LIMIT 1');
                $st->execute([(int)$branchId]);
                if ($b = $st->fetch(PDO::FETCH_ASSOC)) {
                    $out['label'] = trim((string)($b['name'] ?? ''));
                    if (trim((string)($b['address'] ?? '')) !== '')       { $out['address'] = trim((string)$b['address']); }
                    if (trim((string)($b['contact_phone'] ?? '')) !== '') { $out['phone'] = trim((string)$b['contact_phone']); }
                    if (trim((string)($b['contact_email'] ?? '')) !== '') { $out['email'] = trim((string)$b['contact_email']); }
                    $out['hours'] = trim((string)($b['opening_hours'] ?? ''));
                }
            }
        }
    } catch (Throwable $e) { /* fallback = globální firemní údaje */ }
    // jednořádková varianta adresy pro hlavičky dokumentů
    $out['address_inline'] = trim(preg_replace('/\s*\R\s*/u', ', ', $out['address']));
    return $out;
}

/** Odešle klientovi e-mail, že je zakázka připravena k vyzvednutí (jen jednou, přes guard
 *  pickup_notified_at). Volá se při přechodu stavu do skupiny 'completed'. */
function crmSendPickupReadyEmail(int $orderId): void
{
    global $pdo;
    if ($orderId <= 0 || !isset($pdo)) return;
    try {
        ensurePickupReadyColumns($pdo);
        ensureCustomerLanguageColumn();
        $st = $pdo->prepare(
            "SELECT o.id, o.order_code, o.status, o.device_brand, o.device_model, o.pin_code,
                    o.problem_description, o.final_cost, o.estimated_cost,
                    o.pickup_notified_at, o.branch_id,
                    c.first_name, c.last_name, c.email, c.preferred_language AS cust_lang,
                    b.name AS branch_name, b.address AS branch_address, b.opening_hours AS branch_hours,
                    b.contact_phone AS branch_phone, b.contact_email AS branch_email
             FROM orders o
             JOIN customers c ON c.id = o.customer_id
             LEFT JOIN branches b ON b.id = o.branch_id
             WHERE o.id = ? LIMIT 1"
        );
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) return;
        if (!empty($o['pickup_notified_at'])) return;                      // už odesláno
        if (!isOrderStatusIn((string)$o['status'], 'completed')) return;   // není připraveno
        $to = trim((string)($o['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return; // není kam poslat
        if (!function_exists('smtpSendMail')) return;

        $company = get_setting('company_name', 'AppleFix');
        $code = trim((string)($o['order_code'] ?? '')) !== '' ? (string)$o['order_code'] : ('#' . $orderId);
        $subject = $company . ' — ' . [
            'cs' => 'vaše zakázka ' . $code . ' je připravena k vyzvednutí',
            'en' => 'your order ' . $code . ' is ready for pickup',
            'uk' => 'ваше замовлення ' . $code . ' готове до видачі',
        ][normalizeCustomerLanguage($o['cust_lang'] ?? 'cs')];
        $html = crmPickupReadyEmailHtml($o);

        [$ok, $err] = smtpSendMail($to, $subject, $html);
        if ($ok) {
            $pdo->prepare("UPDATE orders SET pickup_notified_at = NOW() WHERE id = ?")->execute([$orderId]);
        }
    } catch (Throwable $e) { /* best-effort — nesmí shodit změnu stavu */ }
}

/** Sloupec orders.review_email_sent_at — žádost o recenzi se každé zakázce pošle max. jednou. */
function ensureReviewEmailColumn(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM orders LIKE 'review_email_sent_at'")->fetch()) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN review_email_sent_at TIMESTAMP NULL DEFAULT NULL");
        }
        $done = true;
    } catch (Throwable $e) { error_log('ensureReviewEmailColumn: ' . $e->getMessage()); }
}

/** Odkaz přímo do okna psaní Google recenze — pobočka Křižíkova 29 (Karlín).
 *  Place ID ChIJb5zqZRmVC0cR_ysPp2WZD8M ověřeno přes Google Places API 14.7.2026
 *  (AppleFix s.r.o., Křižíkova 29, Karlín). Přepsatelné settingem google_review_url;
 *  prázdný setting = e-maily s žádostí o recenzi vypnuté. */
function crmGoogleReviewUrl(string $lang = 'cs'): string {
    $url = trim((string)get_setting('google_review_url',
        'https://search.google.com/local/writereview?placeid=ChIJb5zqZRmVC0cR_ysPp2WZD8M'));
    if ($url !== '' && !str_contains($url, 'hl=')) {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'hl=' . ($lang === 'uk' ? 'uk' : ($lang === 'en' ? 'en' : 'cs'));
    }
    return $url;
}

/**
 * Po vydání zakázky: poděkování + nenásilná žádost o hodnocení na Google.
 * Odchází v jazyce klienta, každé zakázce max. jednou (review_email_sent_at).
 * Volá se z crmNotifyOrderLifecycleEvent při přechodu do skupiny 'collected'.
 */
function crmSendOrderReviewEmail(int $orderId): void {
    global $pdo;
    if ($orderId <= 0 || !isset($pdo)) return;
    try {
        ensureReviewEmailColumn();
        ensureCustomerLanguageColumn();
        $st = $pdo->prepare(
            "SELECT o.id, o.order_code, o.status, o.review_email_sent_at,
                    c.first_name, c.last_name, c.email, c.preferred_language AS cust_lang
             FROM orders o JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ? LIMIT 1");
        $st->execute([$orderId]);
        $o = $st->fetch();
        if (!$o) return;
        if (!empty($o['review_email_sent_at'])) return;                    // už odesláno
        if (!isOrderStatusIn((string)$o['status'], 'collected')) return;   // jen po vydání
        if (crmCustomerIsPlaceholder($o)) return;                          // Interní/Neznámý klient
        $to = trim((string)($o['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
        $lang = normalizeCustomerLanguage($o['cust_lang'] ?? 'cs');
        if (crmGoogleReviewUrl($lang) === '') return;                      // vypnuto v nastavení
        if (!function_exists('smtpSendMail')) return;

        $company = get_setting('company_name', 'AppleFix');
        $subject = $company . ' — ' . [
            'cs' => 'děkujeme za Vaši důvěru',
            'en' => 'thank you for your trust',
            'uk' => 'дякуємо за вашу довіру',
        ][$lang];
        [$ok, ] = smtpSendMail($to, $subject, crmReviewRequestEmailHtml($o));
        if ($ok) {
            $pdo->prepare("UPDATE orders SET review_email_sent_at = NOW() WHERE id = ?")->execute([$orderId]);
        }
    } catch (Throwable $e) { /* best-effort — nesmí shodit změnu stavu */ }
}

/** HTML e-mailu s poděkováním a žádostí o Google recenzi — „liquid glass" pojetí:
 *  jemné modravé pozadí, prosvětlená skleněná karta s vrstveným okrajem a měkkým
 *  stínem, zlaté hvězdy, tmavé pill CTA přímo do okna psaní recenze. Tabulkový
 *  layout + inline styly (Mail/Gmail/Outlook safe). Formální, vděčný tón, cs/en/uk. */
function crmReviewRequestEmailHtml(array $o): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $lang = normalizeCustomerLanguage($o['cust_lang'] ?? 'cs');
    $T = [
        'cs' => [
            'order' => 'Zakázka', 'hero' => 'Děkujeme, že jste si vybrali AppleFix',
            'body' => 'Dobrý den,<br>vaše zakázka <b>%s</b> byla dokončena a zařízení předáno. Děkujeme Vám za důvěru, kterou jste nám při opravě svěřili — velmi si jí vážíme.',
            'ask' => 'Pokud jste byli s naší prací a přístupem spokojeni, budeme vděčni, když věnujete minutku hodnocení na&nbsp;Google. Vaše zpětná vazba je pro nás tou nejcennější odměnou a pomáhá nám se dál zlepšovat.',
            'cta' => 'Ohodnotit AppleFix na Google',
            'note' => 'Hodnocení se týká naší pobočky Křižíkova&nbsp;29, Praha&nbsp;8&nbsp;–&nbsp;Karlín. Zabere necelou minutu.',
            'thanks' => 'Ještě jednou děkujeme a přejeme příjemný den.<br><b>Tým AppleFix</b>',
            'footer' => 'Tento e-mail byl odeslán automaticky po předání zakázky %s. Pokud jste s čímkoliv nebyli spokojeni, napište nám prosím na',
            'preheader' => 'Děkujeme za Vaši důvěru — Vaše hodnocení nám moc pomůže.',
        ],
        'en' => [
            'order' => 'Order', 'hero' => 'Thank you for choosing AppleFix',
            'body' => 'Hello,<br>your order <b>%s</b> has been completed and your device handed over. Thank you for the trust you placed in us — we truly appreciate it.',
            'ask' => 'If you were happy with our work and service, we would be grateful if you could take a minute to rate us on&nbsp;Google. Your feedback is the most valuable reward for us and helps us keep improving.',
            'cta' => 'Rate AppleFix on Google',
            'note' => 'The review is for our Křižíkova&nbsp;29 branch, Prague&nbsp;8&nbsp;–&nbsp;Karlín. It takes less than a minute.',
            'thanks' => 'Thank you once again and have a great day.<br><b>The AppleFix team</b>',
            'footer' => 'This e-mail was sent automatically after order %s was handed over. If anything fell short, please let us know at',
            'preheader' => 'Thank you for your trust — your rating would mean a lot to us.',
        ],
        'uk' => [
            'order' => 'Замовлення', 'hero' => 'Дякуємо, що обрали AppleFix',
            'body' => 'Доброго дня!<br>Ваше замовлення <b>%s</b> завершено, а пристрій передано. Дякуємо за довіру, яку ви нам виявили — ми дуже її цінуємо.',
            'ask' => 'Якщо ви задоволені нашою роботою та підходом, будемо вдячні, якщо ви приділите хвилинку та оціните нас у&nbsp;Google. Ваш відгук — найцінніша винагорода для нас і допомагає нам ставати кращими.',
            'cta' => 'Оцінити AppleFix у Google',
            'note' => 'Відгук стосується нашої філії Křižíkova&nbsp;29, Прага&nbsp;8&nbsp;–&nbsp;Карлін. Це займе менше хвилини.',
            'thanks' => 'Ще раз дякуємо та бажаємо гарного дня.<br><b>Команда AppleFix</b>',
            'footer' => 'Цей лист надіслано автоматично після видачі замовлення %s. Якщо щось було не так, напишіть нам на',
            'preheader' => 'Дякуємо за довіру — ваша оцінка нам дуже допоможе.',
        ],
    ];
    $t = fn(string $k): string => $T[$lang][$k] ?? $T['cs'][$k];

    $company = $e(get_setting('company_name', 'AppleFix'));
    $code = trim((string)($o['order_code'] ?? '')) !== '' ? $e($o['order_code']) : ('#' . (int)($o['id'] ?? 0));
    $reviewUrl = $e(crmGoogleReviewUrl($lang));
    $replyTo = $e(get_setting('smtp_from_email', 'servis@applefix.cz'));
    $logo = 'https://admin.applefix.cloud/assets/img/logo-black.png';
    $star = '<span style="color:#f5b942;font-size:26px;line-height:1;">&#9733;</span>';

    return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $company . '</title></head>'
        . '<body style="margin:0;padding:0;background:#e9eef7;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $t('preheader') . '</div>'
        // podklad s jemným studeným gradientem (glass podsvícení)
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e9eef7;background-image:linear-gradient(165deg,#eef2fa 0%,#e6ecf8 55%,#e9eef4 100%);padding:36px 12px;"><tr><td align="center">'
        // „skleněná" karta: prosvětlená bílá, vrstvený okraj, měkký modravý stín
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;background-image:linear-gradient(180deg,rgba(255,255,255,.96),rgba(250,252,255,.92));border:1px solid rgba(255,255,255,.9);outline:1px solid rgba(160,180,215,.28);border-radius:24px;overflow:hidden;box-shadow:0 18px 50px rgba(70,100,160,.16),0 2px 8px rgba(70,100,160,.08);font-family:-apple-system,BlinkMacSystemFont,\'SF Pro Text\',\'Segoe UI\',Arial,sans-serif;">'

        // hlavička: logo + číslo zakázky
        . '<tr><td style="padding:26px 36px 20px;border-bottom:1px solid rgba(160,180,215,.18);">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle;"><img src="' . $logo . '" width="160" alt="' . $company . '" style="display:block;border:0;height:auto;"></td>'
        . '<td align="right" style="vertical-align:middle;font-size:12px;color:#9aa4b5;">' . $t('order') . ' <span style="font-family:ui-monospace,Menlo,monospace;font-weight:700;color:#111;">' . $code . '</span></td>'
        . '</tr></table></td></tr>'

        // hvězdy + titulek
        . '<tr><td align="center" style="padding:34px 36px 6px;">'
        . '<div style="letter-spacing:6px;">' . $star . $star . $star . $star . $star . '</div>'
        . '<div style="font-size:22px;font-weight:800;color:#16202e;margin-top:14px;letter-spacing:-.01em;">' . $t('hero') . '</div>'
        . '</td></tr>'

        // poděkování + prosba
        . '<tr><td style="padding:18px 44px 4px;"><div style="font-size:15px;line-height:1.7;color:#39445a;">'
        . sprintf($t('body'), $code) . '</div></td></tr>'
        . '<tr><td style="padding:14px 44px 4px;"><div style="font-size:15px;line-height:1.7;color:#39445a;">' . $t('ask') . '</div></td></tr>'

        // vnitřní skleněná buňka s CTA
        . '<tr><td align="center" style="padding:26px 36px 8px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:rgba(240,245,252,.85);background-image:linear-gradient(180deg,rgba(255,255,255,.75),rgba(233,240,250,.7));border:1px solid rgba(160,180,215,.32);border-radius:18px;">'
        . '<tr><td align="center" style="padding:26px 24px 24px;">'
        . '<a href="' . $reviewUrl . '" style="display:inline-block;background:#111111;color:#ffffff;text-decoration:none;font-size:16px;font-weight:700;padding:15px 40px;border-radius:14px;box-shadow:0 6px 18px rgba(17,17,17,.22);">&#9733;&nbsp;&nbsp;' . $t('cta') . '</a>'
        . '<div style="font-size:12px;color:#8b96aa;margin-top:14px;line-height:1.6;">' . $t('note') . '</div>'
        . '</td></tr></table></td></tr>'

        // podpis
        . '<tr><td align="center" style="padding:24px 44px 30px;"><div style="font-size:14px;line-height:1.7;color:#39445a;">' . $t('thanks') . '</div></td></tr>'

        // patička
        . '<tr><td style="padding:18px 36px 22px;border-top:1px solid rgba(160,180,215,.18);background:rgba(244,247,252,.7);">'
        . '<div style="font-size:11px;line-height:1.7;color:#9aa4b5;text-align:center;">' . sprintf($t('footer'), $code)
        . ' <a href="mailto:' . $replyTo . '" style="color:#5b7bb0;text-decoration:none;">' . $replyTo . '</a></div>'
        . '</td></tr>'

        . '</table></td></tr></table></body></html>';
}

/** HTML e-mailu „připraveno k vyzvednutí" — světlý AppleFix design (schváleno 13.7.2026):
 *  bílá karta, zelený stavový pruh, souhrn zakázky, CTA na klientský portál s PINem
 *  a kontaktní blok POBOČKY zakázky (Karlín / Černá růže — adresa, telefon, hodiny).
 *  Tabulkový layout + inline styly, snese Mail/Gmail/Outlook. */
function crmPickupReadyEmailHtml(array $o): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    // Texty ve TŘECH jazycích — e-mail odchází v jazyce zákazníka (customers.preferred_language)
    $lang = normalizeCustomerLanguage($o['cust_lang'] ?? 'cs');
    $T = [
        'cs' => [
            'order' => 'Zakázka', 'hero' => 'Vaše zařízení je opravené a připravené k&nbsp;vyzvednutí',
            'body' => 'Dobrý den,<br>dokončili jsme opravu vašeho zařízení <b>%s</b>. Můžete se pro něj kdykoliv zastavit v&nbsp;otevírací době naší prodejny.',
            'summary' => 'SOUHRN ZAK&Aacute;ZKY', 'device' => 'Zařízení', 'repair' => 'Provedená oprava', 'price' => 'Cena opravy',
            'cta' => 'Sledovat zakázku online', 'pin' => 'Přihlásíte se svým e-mailem nebo telefonem a PINem zakázky:',
            'where' => 'KDE N&Aacute;S NAJDETE', 'hours' => 'OTEV&Iacute;RAC&Iacute; DOBA', 'map' => 'Ukázat na mapě',
            'hours_web' => 'Aktuální otevírací dobu najdete na', 'device_fallback' => 'vaše zařízení',
            'footer' => 'Tento e-mail byl odeslán automaticky po dokončení vaší zakázky. K&nbsp;vyzvednutí prosím uveďte číslo zakázky',
            'preheader' => 'Vaše zařízení %s je opravené a připravené k vyzvednutí.',
        ],
        'en' => [
            'order' => 'Order', 'hero' => 'Your device is repaired and ready for pickup',
            'body' => 'Hello,<br>we have finished repairing your <b>%s</b>. You are welcome to pick it up any time during our opening hours.',
            'summary' => 'ORDER SUMMARY', 'device' => 'Device', 'repair' => 'Repair performed', 'price' => 'Repair price',
            'cta' => 'Track your order online', 'pin' => 'Sign in with your e-mail or phone number and the order PIN:',
            'where' => 'WHERE TO FIND US', 'hours' => 'OPENING HOURS', 'map' => 'Show on map',
            'hours_web' => 'Current opening hours at', 'device_fallback' => 'your device',
            'footer' => 'This e-mail was sent automatically after your repair was completed. Please state your order number when picking up:',
            'preheader' => 'Your device %s is repaired and ready for pickup.',
        ],
        'uk' => [
            'order' => 'Замовлення', 'hero' => 'Ваш пристрій відремонтовано і готовий до видачі',
            'body' => 'Доброго дня!<br>Ми завершили ремонт вашого пристрою <b>%s</b>. Ви можете забрати його в будь-який час у години роботи нашого сервісу.',
            'summary' => 'ПІДСУМОК ЗАМОВЛЕННЯ', 'device' => 'Пристрій', 'repair' => 'Виконаний ремонт', 'price' => 'Вартість ремонту',
            'cta' => 'Відстежувати замовлення онлайн', 'pin' => 'Увійдіть, вказавши e-mail або телефон і PIN замовлення:',
            'where' => 'ДЕ НАС ЗНАЙТИ', 'hours' => 'ГОДИНИ РОБОТИ', 'map' => 'Показати на мапі',
            'hours_web' => 'Актуальні години роботи на', 'device_fallback' => 'ваш пристрій',
            'footer' => 'Цей лист надіслано автоматично після завершення вашого замовлення. Під час отримання, будь ласка, назвіть номер замовлення:',
            'preheader' => 'Ваш пристрій %s відремонтовано і готовий до видачі.',
        ],
    ];
    $t = fn(string $k): string => $T[$lang][$k] ?? $T['cs'][$k];
    // dny v uložené otevírací době jsou česky → přeložit pro EN/RU
    $dayMap = [
        'en' => ['Po' => 'Mon', 'Út' => 'Tue', 'St' => 'Wed', 'Čt' => 'Thu', 'Pá' => 'Fri', 'So' => 'Sat', 'Ne' => 'Sun', 'po domluvě' => 'by appointment'],
        'uk' => ['Po' => 'Пн', 'Út' => 'Вт', 'St' => 'Ср', 'Čt' => 'Чт', 'Pá' => 'Пт', 'So' => 'Сб', 'Ne' => 'Нд', 'po domluvě' => 'за домовленістю'],
    ];
    $dayTr = fn(string $v): string => isset($dayMap[$lang]) ? strtr($v, $dayMap[$lang]) : $v;

    $company = $e(get_setting('company_name', 'AppleFix'));
    $device  = trim(((string)($o['device_brand'] ?? '')) . ' ' . ((string)($o['device_model'] ?? '')));
    $device  = $device !== '' ? $e($device) : $t('device_fallback');
    $code    = trim((string)($o['order_code'] ?? '')) !== '' ? $e($o['order_code']) : ('#' . (int)($o['id'] ?? 0));
    $repair  = trim((string)($o['problem_description'] ?? ''));
    if ($repair !== '' && function_exists('mb_strimwidth')) { $repair = mb_strimwidth($repair, 0, 90, '…'); }
    $cost    = (float)(($o['final_cost'] ?? null) ?: ($o['estimated_cost'] ?? 0));
    $pin     = trim((string)($o['pin_code'] ?? ''));

    $branchName  = trim((string)($o['branch_name'] ?? '')) ?: $e(get_setting('company_name', 'AppleFix'));
    $branchAddr  = trim((string)($o['branch_address'] ?? '')) ?: trim((string)get_setting('company_address', ''));
    $branchPhone = trim((string)($o['branch_phone'] ?? '')) ?: trim((string)get_setting('company_phone', ''));
    $branchHours = trim((string)($o['branch_hours'] ?? ''));
    $mapUrl      = 'https://mapy.cz/?q=' . rawurlencode(preg_replace('/\s+/u', ' ', $branchAddr));
    $phoneHref   = preg_replace('/[^0-9+]/', '', $branchPhone);

    $logo   = 'https://admin.applefix.cloud/assets/img/logo-black.png';   // originální AppleFix wordmark
    $green  = '#76b82a';
    $portal = 'https://admin.applefix.cloud/login.php';

    $hoursRows = '';
    foreach (array_filter(array_map('trim', preg_split('/\r?\n/', $branchHours))) as $hl) {
        // řádek "Po – Út: 10:00 – 20:00" → popisek + hodnota
        $parts = explode(':', $hl, 2);
        if (count($parts) === 2) {
            $hoursRows .= '<tr><td style="color:#8a918a;padding-right:16px;white-space:nowrap;">' . $e($dayTr(trim($parts[0]))) . '</td><td><b>' . $e($dayTr(trim($parts[1]))) . '</b></td></tr>';
        } else {
            $hoursRows .= '<tr><td colspan="2" style="color:#33382f;">' . $e($dayTr($hl)) . '</td></tr>';
        }
    }

    $summaryRows = '<tr><td style="padding:6px 0;color:#8a918a;">' . $t('device') . '</td><td align="right" style="padding:6px 0;font-weight:700;">' . $device . '</td></tr>';
    if ($repair !== '') {
        $summaryRows .= '<tr><td style="padding:6px 0;color:#8a918a;border-top:1px dashed #e0e5db;">' . $t('repair') . '</td><td align="right" style="padding:6px 0;font-weight:700;border-top:1px dashed #e0e5db;">' . $e($repair) . '</td></tr>';
    }
    if ($cost > 0) {
        $summaryRows .= '<tr><td style="padding:6px 0;color:#8a918a;border-top:1px dashed #e0e5db;">' . $t('price') . '</td><td align="right" style="padding:6px 0;font-weight:800;font-size:16px;border-top:1px dashed #e0e5db;">' . $e(formatMoney($cost)) . '</td></tr>';
    }

    $pinNote = $pin !== ''
        ? '<div style="font-size:12px;color:#9aa19a;margin-top:10px;">' . $t('pin') . ' <b style="color:#33382f;">' . $e($pin) . '</b></div>'
        : '';

    return '<!doctype html><html lang="cs"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $company . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef1ee;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . sprintf($t('preheader'), $device) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1ee;padding:32px 12px;"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 40px rgba(20,30,20,.10);font-family:-apple-system,BlinkMacSystemFont,\'SF Pro Text\',\'Segoe UI\',Arial,sans-serif;">'

        . '<tr><td style="padding:26px 36px 22px;border-bottom:1px solid #eceeec;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td style="vertical-align:middle;"><img src="' . $logo . '" width="168" alt="' . $company . '" style="display:block;border:0;height:auto;"></td>'
        . '<td align="right" style="vertical-align:middle;font-size:12px;color:#9aa19a;">' . $t('order') . ' <span style="font-family:ui-monospace,Menlo,monospace;font-weight:700;color:#111;">' . $code . '</span></td>'
        . '</tr></table></td></tr>'

        . '<tr><td style="background:' . $green . ';padding:18px 36px;">'
        . '<div style="font-size:17px;font-weight:800;color:#ffffff;">&#10003;&nbsp; ' . $t('hero') . '</div></td></tr>'

        . '<tr><td style="padding:30px 36px 8px;"><div style="font-size:15px;line-height:1.65;color:#33382f;">'
        . sprintf($t('body'), $device) . '</div></td></tr>'

        . '<tr><td style="padding:20px 36px 6px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f8f4;border:1px solid #e6ebe1;border-radius:12px;">'
        . '<tr><td style="padding:16px 20px 6px;font-size:11px;font-weight:700;letter-spacing:.08em;color:' . $green . ';">' . $t('summary') . '</td></tr>'
        . '<tr><td style="padding:2px 20px 14px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;color:#33382f;">' . $summaryRows . '</table></td></tr>'
        . '</table></td></tr>'

        . '<tr><td align="center" style="padding:24px 36px 8px;">'
        . '<a href="' . $portal . '" style="display:inline-block;background:#111;color:#fff;text-decoration:none;font-size:15px;font-weight:700;padding:14px 34px;border-radius:12px;">' . $t('cta') . '</a>' . $pinNote . '</td></tr>'

        . '<tr><td style="padding:26px 36px 6px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td width="52%" style="vertical-align:top;">'
        . '<div style="font-size:11px;font-weight:700;letter-spacing:.08em;color:#9aa19a;margin-bottom:8px;">' . $t('where') . '</div>'
        . '<div style="font-size:14px;line-height:1.6;color:#33382f;"><b>' . $e($branchName) . '</b><br>' . nl2br($e($branchAddr)) . '<br>'
        . ($branchPhone !== '' ? '<a href="tel:' . $e($phoneHref) . '" style="color:' . $green . ';text-decoration:none;font-weight:700;">' . $e($branchPhone) . '</a><br>' : '')
        . '<a href="' . $e($mapUrl) . '" style="color:' . $green . ';text-decoration:none;font-size:13px;">' . $t('map') . ' &rarr;</a></div></td>'
        . '<td width="48%" style="vertical-align:top;">'
        . '<div style="font-size:11px;font-weight:700;letter-spacing:.08em;color:#9aa19a;margin-bottom:8px;">' . $t('hours') . '</div>'
        . ($hoursRows !== ''
            ? '<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:13.5px;color:#33382f;line-height:1.75;">' . $hoursRows . '</table>'
            : '<div style="font-size:13.5px;color:#33382f;">' . $t('hours_web') . ' <a href="https://applefix.cz" style="color:' . $green . ';text-decoration:none;">applefix.cz</a>.</div>')
        . '</td></tr></table></td></tr>'

        . '<tr><td style="padding:24px 36px 26px;"><div style="border-top:1px solid #eceeec;padding-top:16px;font-size:11.5px;line-height:1.7;color:#a7ada6;">'
        . $company . ' &middot; <a href="https://applefix.cz" style="color:' . $green . ';text-decoration:none;">applefix.cz</a> &middot; <a href="mailto:info@applefix.cz" style="color:#a7ada6;">info@applefix.cz</a><br>'
        . $t('footer') . ' <b style="color:#33382f;">' . $code . '</b>.</div></td></tr>'

        . '</table></td></tr></table></body></html>';
}

/** Datum spuštění „naostro" — zakázky vytvořené PŘED tímto dnem (import historie)
 *  se nepovažují za zaseknuté a NEPULZUJÍ. Od tohoto dne jede pulzování dle pravidel.
 *  Uloženo v nastavení (klíč pulse_golive_date), výchozí 2026-07-08. */
function crmGoLiveTs(): int
{
    static $ts = null;
    if ($ts !== null) return $ts;
    $d = trim((string) get_setting('pulse_golive_date', '2026-07-08'));
    $t = strtotime($d . ' 00:00:00');
    $ts = $t ?: strtotime('2026-07-08 00:00:00');
    return $ts;
}

/** CSS třída + title pro <tr> zakázky (prázdné, pokud je vše v pořádku).
 *  Importované/historické zakázky (created_at < go-live) nikdy nepulzují. */
function orderStaleRowAttrs(array $order): array
{
    if (!empty($order['created_at']) && strtotime((string)$order['created_at']) < crmGoLiveTs()) {
        return ['', ''];   // historie / import — neblikat
    }
    $sec = orderStaleSeconds($order);
    $level = getOrderStaleLevel((string)($order['status'] ?? ''), $sec);
    if ($level === null) { return ['', '']; }
    $cls = ' order-stale--' . $level;
    $title = __('stale_since') . ' ' . formatPresenceDuration($sec);
    return [$cls, $title];
}

/** Relativní čas „před X" (pro upozornění). */
function crmTimeAgo($when): string
{
    $t = is_numeric($when) ? (int)$when : strtotime((string)$when);
    if (!$t) return '';
    $s = max(0, time() - $t);
    if ($s < 60)    return 'právě teď';
    if ($s < 3600)  return 'před ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'před ' . floor($s / 3600) . ' h';
    $d = floor($s / 86400);
    return 'před ' . $d . ' ' . ($d === 1.0 ? 'dnem' : ($d < 5 ? 'dny' : 'dny'));
}

/** Reálný feed do notifikačního panelu (od go-live dne dál).
 *  Skládá: zaseknuté zakázky (SLA) + poslední změny stavu + nové reklamace. */
function getCrmNotifications(int $limit = 15): array
{
    global $pdo;
    if (!isset($pdo)) return [];
    $golive = date('Y-m-d H:i:s', crmGoLiveTs());
    $items = [];

    // ikona + typ podle skupiny stavu
    $iconFor = function (string $status): array {
        switch (getOrderStatusGroup($status)) {
            case 'waiting_parts':    return ['warning', 'fa-clock'];
            case 'completed':        return ['success', 'fa-check'];
            case 'collected':        return ['success', 'fa-box-open'];
            case 'cancelled':        return ['info',    'fa-ban'];
            case 'new':              return ['info',    'fa-clipboard-list'];
            default:                 return ['info',    'fa-screwdriver-wrench'];
        }
    };

    try {
        // 1) zaseknuté zakázky (jen go-live+) — nahoře, vyžadují pozornost
        $st = $pdo->query(
            "SELECT o.id, o.order_code, o.status, o.device_model, o.created_at,
                    GREATEST(COALESCE(o.updated_at, o.created_at), o.created_at) AS act,
                    c.first_name, c.last_name
             FROM orders o JOIN customers c ON c.id = o.customer_id
             WHERE o.created_at >= " . $pdo->quote($golive) .
            " ORDER BY act ASC LIMIT 60");
        foreach ($st as $o) {
            $sec = orderStaleSeconds(['last_status_change' => $o['act'], 'created_at' => $o['created_at']]);
            $lvl = getOrderStaleLevel((string)$o['status'], $sec);
            if ($lvl === null) continue;
            $items[] = [
                'type' => 'warning', 'icon' => 'fa-triangle-exclamation',
                'title' => 'Bez pohybu: ' . $o['order_code'] . ' — ' . getOrderStatusLabel((string)$o['status']),
                'sub' => trim(($o['device_model'] ?? '') . ' · ' . formatPresenceDuration($sec)),
                'ts' => strtotime((string)$o['act']), 'url' => 'view_order.php?id=' . (int)$o['id'],
            ];
        }
    } catch (Throwable $e) { /* skip */ }

    try {
        // 2) poslední změny stavu z logu (go-live+)
        $st = $pdo->prepare(
            "SELECT l.new_status, l.changed_at, o.id, o.order_code, o.device_model
             FROM order_status_log l JOIN orders o ON o.id = l.order_id
             WHERE l.changed_at >= ? ORDER BY l.changed_at DESC LIMIT 20");
        $st->execute([$golive]);
        foreach ($st as $r) {
            [$type, $icon] = $iconFor((string)$r['new_status']);
            $items[] = [
                'type' => $type, 'icon' => $icon,
                'title' => $r['order_code'] . ' → ' . getOrderStatusLabel((string)$r['new_status']),
                'sub' => (string)($r['device_model'] ?? ''),
                'ts' => strtotime((string)$r['changed_at']), 'url' => 'view_order.php?id=' . (int)$r['id'],
            ];
        }
    } catch (Throwable $e) { /* log nemusí existovat */ }

    try {
        // 3) nové reklamace (go-live+)
        $st = $pdo->prepare(
            "SELECT id, complaint_code, device, created_at FROM complaints
             WHERE created_at >= ? ORDER BY created_at DESC LIMIT 10");
        $st->execute([$golive]);
        foreach ($st as $r) {
            $items[] = [
                'type' => 'warning', 'icon' => 'fa-rotate-left',
                'title' => 'Nová reklamace: ' . $r['complaint_code'],
                'sub' => (string)($r['device'] ?? ''),
                'ts' => strtotime((string)$r['created_at']), 'url' => 'reklamace.php',
            ];
        }
    } catch (Throwable $e) { /* tabulka nemusí existovat */ }

    // seřadit: nejnovější nahoře, deduplikovat podle title+ts, oříznout
    usort($items, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
    $seen = []; $out = [];
    foreach ($items as $it) {
        $k = $it['title'] . '|' . ($it['ts'] ?? 0);
        if (isset($seen[$k])) continue;
        $seen[$k] = 1; $out[] = $it;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/* ============================================================
   EVIDENCE PŘÍTOMNOSTI ZAMĚSTNANCŮ (informativní, sekce Přehledy)
   Každý požadavek přihlášeného zaměstnance posune last_seen;
   mezera do 10 minut se přičítá do denního součtu. Klientský
   portál se neeviduje (klienti nemají $_SESSION['user_id']).
   ============================================================ */
function ensureStaffPresenceSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `staff_presence_daily` (
        `user_id`        INT(11)   NOT NULL,
        `staff_type`     VARCHAR(8) NOT NULL DEFAULT 'user',
        `work_date`      DATE      NOT NULL,
        `seconds_active` INT(11)   NOT NULL DEFAULT 0,
        `first_seen`     DATETIME  DEFAULT NULL,
        `last_seen`      DATETIME  DEFAULT NULL,
        PRIMARY KEY (`staff_type`, `user_id`, `work_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // upgrade starší verze tabulky (bez staff_type)
    try {
        $has = $pdo->query("SHOW COLUMNS FROM staff_presence_daily LIKE 'staff_type'")->fetchAll();
        if (!$has) {
            $pdo->exec("ALTER TABLE staff_presence_daily ADD COLUMN staff_type VARCHAR(8) NOT NULL DEFAULT 'user' AFTER user_id");
            $pdo->exec("ALTER TABLE staff_presence_daily DROP PRIMARY KEY, ADD PRIMARY KEY (staff_type, user_id, work_date)");
        }
    } catch (Throwable $e) { /* best-effort */ }
}

function trackStaffPresence(PDO $pdo, string $staffType, int $staffId): void
{
    static $done = false;
    if ($done || $staffId <= 0 || !in_array($staffType, ['user', 'tech'], true)) { return; }
    $done = true; // max 1x za request

    $maxGapSeconds = 600; // aktivita s mezerou do 10 min se počítá vcelku
    $attempt = function () use ($pdo, $staffType, $staffId, $maxGapSeconds): void {
        $stmt = $pdo->prepare('SELECT seconds_active, UNIX_TIMESTAMP(last_seen) AS ls
                               FROM staff_presence_daily WHERE staff_type = ? AND user_id = ? AND work_date = CURDATE()');
        $stmt->execute([$staffType, $staffId]);
        $row = $stmt->fetch();
        if ($row) {
            $delta = time() - (int)$row['ls'];
            $add = ($delta > 0 && $delta <= $maxGapSeconds) ? $delta : 0;
            $upd = $pdo->prepare('UPDATE staff_presence_daily
                                  SET seconds_active = seconds_active + ?, last_seen = NOW()
                                  WHERE staff_type = ? AND user_id = ? AND work_date = CURDATE()');
            $upd->execute([$add, $staffType, $staffId]);
        } else {
            $ins = $pdo->prepare('INSERT IGNORE INTO staff_presence_daily
                                  (user_id, staff_type, work_date, seconds_active, first_seen, last_seen)
                                  VALUES (?, ?, CURDATE(), 0, NOW(), NOW())');
            $ins->execute([$staffId, $staffType]);
        }
    };
    try {
        $attempt();
    } catch (Throwable $e) {
        try { ensureStaffPresenceSchema($pdo); $attempt(); } catch (Throwable $e2) { /* nikdy neshodit request */ }
    }
}

/** 126 min -> "2h 6min", 42 min -> "42min" */
function formatPresenceDuration(int $seconds): string
{
    $minutes = intdiv(max(0, $seconds), 60);
    if ($minutes < 60) { return $minutes . 'min'; }
    return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'min';
}


/* ============================================================
   SEGMENTY PŘIDĚLENÍ ZAKÁZKY (Přehledy → Čas na zakázkách)
   Od přidělení/přijetí do předání jinému technikovi nebo dokončení.
   Denní zobrazený čas = průnik segmentu s přítomností technika.
   Nezávislé na order_work_log (ten dál řídí odpracováno/výdělky).
   ============================================================ */
function ensureOrderAssignmentLogSchema(): void
{
    global $pdo;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS order_assignment_log (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            order_id      INT NOT NULL,
            technician_id INT NULL,
            started_at    DATETIME NOT NULL,
            ended_at      DATETIME NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_oal_order (order_id),
            INDEX idx_oal_tech (technician_id),
            INDEX idx_oal_ended (ended_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // jednorázově otevřít segmenty pro aktuálně aktivní přidělené zakázky
        if (function_exists('get_setting') && get_setting('assignment_log_backfilled', '') !== '1') {
            $pdo->exec("INSERT INTO order_assignment_log (order_id, technician_id, started_at)
                        SELECT o.id, o.technician_id, NOW()
                        FROM orders o
                        WHERE o.technician_id IS NOT NULL
                          AND o.status IN (" . orderStatusSqlIn($pdo, 'active') . ")
                          AND NOT EXISTS (SELECT 1 FROM order_assignment_log a WHERE a.order_id = o.id AND a.ended_at IS NULL)");
            if (function_exists('set_setting')) { set_setting('assignment_log_backfilled', '1'); }
        }
    } catch (Throwable $e) { /* best-effort */ }
}

/**
 * Srovná segment přidělení se skutečností: aktivní zakázka s technikem má mít
 * otevřený segment tohoto technika; předání zavře starý a otevře nový;
 * dokončení/storno (i odebrání technika) segment zavře.
 */
function assignmentSegmentSync(int $orderId, ?int $technicianId, ?string $status): void
{
    global $pdo;
    if ($orderId <= 0) { return; }
    $attempt = function () use ($pdo, $orderId, $technicianId, $status): void {
        $isActive = $status !== null && $status !== '' && isOrderStatusIn((string)$status, 'active');
        $desiredTech = ($isActive && $technicianId) ? (int)$technicianId : null;

        $stmt = $pdo->prepare('SELECT id, technician_id FROM order_assignment_log
                               WHERE order_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$orderId]);
        $open = $stmt->fetch();

        if ($open && ((int)$open['technician_id'] !== (int)$desiredTech || $desiredTech === null)) {
            $pdo->prepare('UPDATE order_assignment_log SET ended_at = NOW() WHERE id = ?')
                ->execute([(int)$open['id']]);
            $open = false;
        }
        if ($desiredTech !== null && !$open) {
            $pdo->prepare('INSERT INTO order_assignment_log (order_id, technician_id, started_at)
                           VALUES (?, ?, NOW())')->execute([$orderId, $desiredTech]);
        }
    };
    try {
        $attempt();
    } catch (Throwable $e) {
        try { ensureOrderAssignmentLogSchema(); $attempt(); } catch (Throwable $e2) { /* ignore */ }
    }
}

/** Jednorázový import uvítacích zvuků z assets/greetings_import/ — přiřazení podle jmen. */
function importGreetingSounds(): void
{
    global $pdo;
    try {
        if (get_setting('greeting_sounds_imported_v1', '') === '1') { return; }
        $src = __DIR__ . '/../assets/greetings_import';
        $dst = __DIR__ . '/../uploads/greetings';
        if (!is_dir($src)) { return; }
        if (!is_dir($dst)) { mkdir($dst, 0755, true); }

        $translit = ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o','ř'=>'r',
                     'š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z','ä'=>'a','ö'=>'o','ü'=>'u'];
        $slug = function (string $v) use ($translit): string {
            $v = mb_strtolower(trim($v), 'UTF-8');
            $v = strtr($v, $translit);
            return trim(preg_replace('/[^a-z0-9]+/', '_', $v) ?? '', '_');
        };
        // přezdívky v názvech souborů -> křestní jména
        $alias = ['zdenda' => 'zdenek', 'tonda' => 'antonin', 'pepa' => 'josef', 'honza' => 'jan'];

        $people = [];
        foreach ($pdo->query('SELECT username, full_name AS name FROM users')->fetchAll() as $r) { $people[] = $r; }
        foreach ($pdo->query('SELECT username, name FROM technicians WHERE is_active = 1')->fetchAll() as $r) { $people[] = $r; }

        foreach (glob($src . '/*.mp3') ?: [] as $file) {
            $base = $slug(pathinfo($file, PATHINFO_FILENAME));
            $firstTok = explode('_', $base)[0] ?? '';
            $firstTok = $alias[$firstTok] ?? $firstTok;

            $target = null;
            foreach ($people as $pp) {
                $uSlug = $slug((string)$pp['username']);
                $nSlug = $slug((string)($pp['name'] ?? ''));
                $nFirst = explode('_', $nSlug)[0] ?? '';
                if ($base === $uSlug || $base === $nSlug) { $target = $pp; break; }           // přesná shoda
                if ($firstTok !== '' && ($firstTok === $uSlug || $firstTok === $nFirst)) {    // křestní jméno
                    $target = $pp; break;
                }
            }
            if (!$target) { continue; }
            $destName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$target['username']) . '.mp3';
            if (!is_file($dst . '/' . $destName)) {
                copy($file, $dst . '/' . $destName);
            }
        }
        if (function_exists('set_setting')) { set_setting('greeting_sounds_imported_v1', '1'); }
    } catch (Throwable $e) { /* best-effort, zkusí se příště */ }
}

/** Jednorázově (9.7.2026): NOVÝ uvítací zvuk pro Tomáše Zahradníka — na rozdíl od
 *  importGreetingSounds PŘEPÍŠE už nasazený soubor v uploads/greetings. */
function refreshTomasGreeting202607(): void
{
    global $pdo;
    try {
        if (get_setting('greeting_tomas_v2_2026_07', '') === '1') { return; }
        $src = __DIR__ . '/../assets/greetings_import/tomas_zahradnik.mp3';
        $dst = __DIR__ . '/../uploads/greetings';
        if (!is_file($src)) { return; }
        if (!is_dir($dst)) { mkdir($dst, 0755, true); }

        $norm = function (string $v): string {
            $v = mb_strtolower($v, 'UTF-8');
            return strtr($v, ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n',
                              'ó'=>'o','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z']);
        };
        $people = [];
        foreach ($pdo->query('SELECT username, full_name AS name FROM users')->fetchAll() as $r) { $people[] = $r; }
        foreach ($pdo->query('SELECT username, name FROM technicians')->fetchAll() as $r) { $people[] = $r; }

        // kandidáti se jménem tomas; když je mezi nimi zahradník, ber jen jeho
        $matches = [];
        foreach ($people as $pp) {
            $hay = $norm(($pp['username'] ?? '') . ' ' . ($pp['name'] ?? ''));
            if (str_contains($hay, 'tomas')) { $matches[] = $pp + ['hay' => $hay]; }
        }
        $zahradnik = array_values(array_filter($matches, fn($m) => str_contains($m['hay'], 'zahradn')));
        if ($zahradnik) { $matches = $zahradnik; }

        $done = false;
        foreach ($matches as $pp) {
            $destName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$pp['username']) . '.mp3';
            if (@copy($src, $dst . '/' . $destName)) { $done = true; }
        }
        if ($done && function_exists('set_setting')) { set_setting('greeting_tomas_v2_2026_07', '1'); }
    } catch (Throwable $e) { /* best-effort, zkusí se příště */ }
}

/** Jednorázově (9.7.2026): NOVÝ uvítací zvuk pro admina — PŘEPÍŠE už nasazený
 *  uploads/greetings/admin.mp3 (import sám existující soubor nepřepisuje). */
function refreshAdminGreeting202607(): void
{
    global $pdo;
    try {
        if (get_setting('greeting_admin_v2_2026_07', '') === '1') { return; }
        $src = __DIR__ . '/../assets/greetings_import/admin.mp3';
        $dst = __DIR__ . '/../uploads/greetings';
        if (!is_file($src)) { return; }
        if (!is_dir($dst)) { mkdir($dst, 0755, true); }
        if (@copy($src, $dst . '/admin.mp3') && function_exists('set_setting')) {
            set_setting('greeting_admin_v2_2026_07', '1');
        }
    } catch (Throwable $e) { /* best-effort, zkusí se příště */ }
}

/** Jednorázově (9.7.2026): NOVÝ uvítací zvuk pro Zdendu (Victor) — PŘEPÍŠE už
 *  nasazený uploads/greetings/<username>.mp3 (restore/import existující nepřepisují).
 *  Zdroj zdenda_faceid.mp3 je verzovaný v gitu, takže se nasadí i po čistém pullu. */
function refreshZdendaGreetingVictor202607(): void
{
    global $pdo;
    try {
        if (get_setting('greeting_zdenda_victor_2026_07', '') === '1') { return; }
        $src = __DIR__ . '/../assets/greetings_import/zdenda_faceid.mp3';
        $dst = __DIR__ . '/../uploads/greetings';
        if (!is_file($src)) { return; }
        if (!is_dir($dst)) { mkdir($dst, 0755, true); }
        $norm = function (string $v): string {
            return strtr(mb_strtolower($v, 'UTF-8'),
                ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o',
                 'ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z']);
        };
        $done = false;
        foreach ($pdo->query('SELECT username, name FROM technicians')->fetchAll() as $t) {
            $hay = $norm(($t['username'] ?? '') . ' ' . ($t['name'] ?? ''));
            if (str_contains($hay, 'zdend') || str_contains($hay, 'zdenek')) {
                $destName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$t['username']) . '.mp3';
                if (@copy($src, $dst . '/' . $destName)) { $done = true; }
            }
        }
        if ($done && function_exists('set_setting')) { set_setting('greeting_zdenda_victor_2026_07', '1'); }
    } catch (Throwable $e) { /* best-effort, zkusí se příště */ }
}

/** Zdenda: až bude jeho účet zase existovat (byl smazán), automaticky mu vrátí
 *  uvítací zvuk zdenda_faceid.mp3 → uploads/greetings/<username>.mp3.
 *  Guard se nastaví AŽ po úspěšném přiřazení, takže to zkouší, dokud účet není zpět. */
function restoreZdendaGreeting(): void
{
    global $pdo;
    try {
        if (get_setting('greeting_zdenda_restored', '') === '1') { return; }
        $src = __DIR__ . '/../assets/greetings_import/zdenda_faceid.mp3';
        $dst = __DIR__ . '/../uploads/greetings';
        if (!is_file($src)) { return; }
        $norm = function (string $v): string {
            return strtr(mb_strtolower($v, 'UTF-8'),
                ['á'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ň'=>'n','ó'=>'o',
                 'ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ý'=>'y','ž'=>'z']);
        };
        foreach ($pdo->query('SELECT username, name FROM technicians')->fetchAll() as $t) {
            $hay = $norm(($t['username'] ?? '') . ' ' . ($t['name'] ?? ''));
            if (str_contains($hay, 'zdend') || str_contains($hay, 'zdenek') || str_contains($hay, 'zdenek')) {
                if (!is_dir($dst)) { mkdir($dst, 0755, true); }
                $destName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$t['username']) . '.mp3';
                if (@copy($src, $dst . '/' . $destName) && function_exists('set_setting')) {
                    set_setting('greeting_zdenda_restored', '1');
                }
                break;
            }
        }
    } catch (Throwable $e) { /* best-effort, zkusí se příště */ }
}

/** Jednorázově: stav "Černá růže" (historická značka 2. pobočky) -> Přijato + pobočka Na Příkopě. */
function migrateCernaRuzeOrders(): void
{
    global $pdo;
    try {
        if (get_setting('cerna_ruze_migrated', '') === '1') { return; }
        $bid = $pdo->query("SELECT id FROM branches WHERE code = 'prikope' LIMIT 1")->fetchColumn();
        if ($bid) {
            $pdo->prepare("UPDATE orders SET branch_id = ?, status = 'Přijato' WHERE status = 'Černá růže'")
                ->execute([(int)$bid]);
        } else {
            $pdo->exec("UPDATE orders SET status = 'Přijato' WHERE status = 'Černá růže'");
        }
        if (function_exists('set_setting')) { set_setting('cerna_ruze_migrated', '1'); }
    } catch (Throwable $e) { /* best-effort, zkusí se příště */ }
}

/** Label stavu do historie pohybu: u „V opravě" doplní jméno technika (V opravě: Martin). */
function orderStatusHistoryLabel(string $status, ?string $techName): string
{
    $label = function_exists('localizedOrderStatusLabel') ? localizedOrderStatusLabel($status) : getOrderStatusLabel($status);
    if ($techName !== null && trim($techName) !== '' && isOrderStatusIn($status, 'in_progress')) {
        return $label . ': ' . trim($techName);
    }
    return $label;
}

/** Jednorázově: rozšíření ENUM orders.status o nové stavy modelu 7/2026 (staré hodnoty zůstávají). */
function ensureStatusEnum202607(): void
{
    global $pdo;
    try {
        if (get_setting('status_enum_2026_07', '') === '1') { return; }
        $pdo->exec("ALTER TABLE `orders` MODIFY `status` ENUM(
            'New','In Progress','Waiting for Parts','Pending Approval','Completed','Uncollected','Collected','Cancelled',
            'Přijato','Zakládá se','V opravě','V opravě zák. desky','V externím servisu','V aut. servisu',
            'V opravě - v externím servisu','V opravě - v autorizovaném servisu',
            'Čeká na díl','Čeká na zákazníka','Čeká na platbu','Připraveno k převzetí',
            'Vydáno - čeká na platbu','Nevyzvednuto','Vydáno','Vydáno - ČR','Stornováno'
        ) DEFAULT 'Přijato'");
        if (function_exists('set_setting')) { set_setting('status_enum_2026_07', '1'); }
    } catch (Throwable $e) { /* best-effort, zkusí se příště */ }
}

// auto-hook: běží při každém načtení functions.php v kontextu přihlášeného zaměstnance
if (session_status() === PHP_SESSION_ACTIVE
    && !empty($_SESSION['user_id'])
    && isset($pdo) && $pdo instanceof PDO
    && (basename($_SERVER['PHP_SELF'] ?? '') !== 'login.php')) {
    try {
        // technici se přihlašují z tabulky technicians (session user_id = 't<ID>')
        if (!empty($_SESSION['tech_id'])) {
            trackStaffPresence($pdo, 'tech', (int)$_SESSION['tech_id']);
        } elseif (is_numeric($_SESSION['user_id'])) {
            trackStaffPresence($pdo, 'user', (int)$_SESSION['user_id']);
        }
        migrateCernaRuzeOrders();
        ensureStatusEnum202607();
        importGreetingSounds();
        refreshTomasGreeting202607();
        refreshAdminGreeting202607();
        restoreZdendaGreeting();
        refreshZdendaGreetingVictor202607();
    } catch (Throwable $e) { /* ignore */ }
}

/**
 * Rezervace z webu (RepairPlugin Pro na applefix.cz) — runtime schéma.
 * Webhook: api/website_booking.php (klíč v settings 'web_booking_key').
 */
function ensureWebBookingsSchema(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS web_bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            wp_booking_id VARCHAR(64) DEFAULT NULL,
            customer_name VARCHAR(190) NOT NULL DEFAULT '',
            phone VARCHAR(60) DEFAULT NULL,
            email VARCHAR(190) DEFAULT NULL,
            device VARCHAR(190) DEFAULT NULL,
            service VARCHAR(255) DEFAULT NULL,
            notes TEXT NULL,
            appointment_at DATETIME NULL,
            delivery_method VARCHAR(80) DEFAULT NULL,
            status ENUM('new','converted','dismissed') NOT NULL DEFAULT 'new',
            order_id INT NULL,
            caldav_uid VARCHAR(190) DEFAULT NULL,
            caldav_synced_at DATETIME NULL,
            caldav_last_error TEXT NULL,
            raw_payload MEDIUMTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_wp_booking (wp_booking_id),
            INDEX idx_caldav_uid (caldav_uid),
            INDEX idx_status_appt (status, appointment_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* tabulka už existuje */ }

    foreach ([
        "ALTER TABLE web_bookings ADD COLUMN caldav_uid VARCHAR(190) DEFAULT NULL AFTER order_id",
        "ALTER TABLE web_bookings ADD COLUMN caldav_synced_at DATETIME NULL AFTER caldav_uid",
        "ALTER TABLE web_bookings ADD COLUMN caldav_last_error TEXT NULL AFTER caldav_synced_at",
        "ALTER TABLE web_bookings ADD INDEX idx_caldav_uid (caldav_uid)",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* už existuje / nelze přidat */ }
    }

    // sdílený klíč pro webhook z WordPressu (vygeneruje se jednou)
    try {
        if (get_setting('web_booking_key', '') === '') {
            set_setting('web_booking_key', bin2hex(random_bytes(24)));
        }
    } catch (Throwable $e) { /* settings nedostupné */ }
}

/**
 * CalDAV synchronizace webových rezervací do firemního kalendáře.
 * Nastavení: Nastavení → Integrace → Firemní kalendář (CalDAV).
 */
function crmCalDavEscapeText(string $value): string {
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\\;', $value);
    $value = str_replace(',', '\\,', $value);
    return str_replace("\n", '\\n', $value);
}

function crmCalDavFoldLine(string $line): string {
    $out = '';
    while (strlen($line) > 73) {
        $chunk = function_exists('mb_strcut') ? mb_strcut($line, 0, 73, 'UTF-8') : substr($line, 0, 73);
        $out .= $chunk . "\r\n ";
        $line = substr($line, strlen($chunk));
    }
    return $out . $line;
}

function crmCalDavEventUid(array $booking): string {
    $sourceId = trim((string)($booking['wp_booking_id'] ?? ''));
    if ($sourceId === '') {
        $sourceId = (string)($booking['id'] ?? bin2hex(random_bytes(8)));
    }
    $safeId = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $sourceId);
    return 'applefix-web-booking-' . trim($safeId, '-') . '@admin.applefix.cloud';
}

function crmCalDavBuildIcs(array $booking, string $uid): string {
    $tz = new DateTimeZone('Europe/Prague');
    $start = new DateTime((string)$booking['appointment_at'], $tz);
    $duration = max(5, (int)get_setting('caldav_booking_duration_minutes', '30'));
    $end = (clone $start)->modify('+' . $duration . ' minutes');

    $customer = trim((string)($booking['customer_name'] ?? ''));
    $device = trim((string)($booking['device'] ?? ''));
    $service = trim((string)($booking['service'] ?? ''));
    $summaryBits = array_filter(['Rezervace opravy', $customer, $device ?: $service]);
    $summary = implode(' - ', $summaryBits);

    $descriptionParts = array_filter([
        $customer ? 'Zakaznik: ' . $customer : '',
        trim((string)($booking['phone'] ?? '')) ? 'Telefon: ' . trim((string)$booking['phone']) : '',
        trim((string)($booking['email'] ?? '')) ? 'E-mail: ' . trim((string)$booking['email']) : '',
        $device ? 'Zarizeni: ' . $device : '',
        $service ? 'Oprava: ' . $service : '',
        trim((string)($booking['delivery_method'] ?? '')) ? 'Predani: ' . trim((string)$booking['delivery_method']) : '',
        trim((string)($booking['notes'] ?? '')) ? 'Poznamka: ' . trim((string)$booking['notes']) : '',
        'Zdroj: RepairPlugin / applefix.cz',
    ]);

    $stamp = gmdate('Ymd\THis\Z');
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//AppleFix//Fix-CRM Web Bookings//CS',
        'CALSCALE:GREGORIAN',
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . $stamp,
        'DTSTART;TZID=Europe/Prague:' . $start->format('Ymd\THis'),
        'DTEND;TZID=Europe/Prague:' . $end->format('Ymd\THis'),
        'SUMMARY:' . crmCalDavEscapeText($summary),
        'DESCRIPTION:' . crmCalDavEscapeText(implode("\n", $descriptionParts)),
        'END:VEVENT',
        'END:VCALENDAR',
    ];

    return implode("\r\n", array_map('crmCalDavFoldLine', $lines)) . "\r\n";
}

function crmCalDavRequest(string $method, string $url, ?string $body = null): array {
    $user = trim((string)get_setting('caldav_booking_user', ''));
    $pass = (string)get_setting('caldav_booking_pass', '');
    if ($url === '' || $user === '' || $pass === '') {
        return [false, 'CalDAV neni nastaveny.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_HTTPAUTH => CURLAUTH_ANY,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: text/calendar; charset=utf-8'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        return [false, $err ?: 'CalDAV request selhal.'];
    }
    if ($code < 200 || $code >= 300) {
        return [false, 'CalDAV HTTP ' . $code];
    }

    return [true, ''];
}

function crmSyncWebBookingToCalDav(int $bookingId): void {
    global $pdo;
    if (get_setting('caldav_booking_enabled', '0') !== '1') {
        return;
    }

    try {
        ensureWebBookingsSchema();
        $stmt = $pdo->prepare("SELECT * FROM web_bookings WHERE id = ? LIMIT 1");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking || empty($booking['appointment_at']) || ($booking['status'] ?? '') === 'dismissed') {
            return;
        }

        $baseUrl = rtrim(trim((string)get_setting('caldav_booking_calendar_url', '')), '/') . '/';
        if ($baseUrl === '/') {
            return;
        }

        $uid = trim((string)($booking['caldav_uid'] ?? '')) ?: crmCalDavEventUid($booking);
        $eventUrl = $baseUrl . rawurlencode($uid) . '.ics';
        [$ok, $error] = crmCalDavRequest('PUT', $eventUrl, crmCalDavBuildIcs($booking, $uid));

        if ($ok) {
            $pdo->prepare("UPDATE web_bookings SET caldav_uid = ?, caldav_synced_at = NOW(), caldav_last_error = NULL WHERE id = ?")
                ->execute([$uid, $bookingId]);
        } else {
            $pdo->prepare("UPDATE web_bookings SET caldav_uid = ?, caldav_last_error = ? WHERE id = ?")
                ->execute([$uid, $error, $bookingId]);
            error_log('crmSyncWebBookingToCalDav: ' . $error);
        }
    } catch (Throwable $e) {
        error_log('crmSyncWebBookingToCalDav: ' . $e->getMessage());
    }
}

function crmDeleteWebBookingFromCalDav(int $bookingId): void {
    global $pdo;
    if (get_setting('caldav_booking_enabled', '0') !== '1') {
        return;
    }

    try {
        ensureWebBookingsSchema();
        $stmt = $pdo->prepare("SELECT caldav_uid FROM web_bookings WHERE id = ? LIMIT 1");
        $stmt->execute([$bookingId]);
        $uid = trim((string)$stmt->fetchColumn());
        $baseUrl = rtrim(trim((string)get_setting('caldav_booking_calendar_url', '')), '/') . '/';
        if ($uid === '' || $baseUrl === '/') {
            return;
        }

        [$ok, $error] = crmCalDavRequest('DELETE', $baseUrl . rawurlencode($uid) . '.ics');
        if ($ok) {
            $pdo->prepare("UPDATE web_bookings SET caldav_synced_at = NULL, caldav_last_error = NULL WHERE id = ?")
                ->execute([$bookingId]);
        } else {
            $pdo->prepare("UPDATE web_bookings SET caldav_last_error = ? WHERE id = ?")
                ->execute([$error, $bookingId]);
        }
    } catch (Throwable $e) {
        error_log('crmDeleteWebBookingFromCalDav: ' . $e->getMessage());
    }
}

/** Runtime přidá hodnotu 'Přijato z RepairPluginu' do ENUM orders.status (idempotentní). */
function ensureRepairPluginOrderStatus(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $row = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string)($row['Type'] ?? '');
        if ($type === '' || stripos($type, 'enum(') !== 0) return;                 // není ENUM
        if (mb_strpos($type, 'Přijato z RepairPluginu') !== false) return;         // už tam je
        $newType = preg_replace('/\)\s*$/', ",'Přijato z RepairPluginu')", $type, 1);
        $pdo->exec("ALTER TABLE orders MODIFY COLUMN status $newType DEFAULT 'Přijato'");
    } catch (Throwable $e) {
        error_log('ensureRepairPluginOrderStatus: ' . $e->getMessage());
    }
}

/** Pojistka: ENUM orders.status musí znát 'Čeká na technika' (předání mezi techniky). */
function ensureHandoffOrderStatus(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $row = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        $type = (string)($row['Type'] ?? '');
        if ($type === '' || stripos($type, 'enum(') !== 0) return;
        if (mb_strpos($type, 'Čeká na technika') !== false) return;
        $newType = preg_replace('/\)\s*$/', ",'Čeká na technika')", $type, 1);
        $pdo->exec("ALTER TABLE orders MODIFY COLUMN status $newType DEFAULT 'Přijato'");
    } catch (Throwable $e) {
        error_log('ensureHandoffOrderStatus: ' . $e->getMessage());
    }
}

/** Souhrn času techniků na zakázce (order_work_log, běžící segment do teď). */
function crmGetOrderTechTimes(int $orderId): array {
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT COALESCE(t.name, '—') AS tech_name,
                SUM(CASE WHEN owl.ended_at IS NULL THEN GREATEST(0, TIMESTAMPDIFF(MINUTE, owl.started_at, NOW())) ELSE owl.duration_minutes END) AS minutes,
                MAX(owl.ended_at IS NULL) AS running
            FROM order_work_log owl
            LEFT JOIN technicians t ON t.id = owl.technician_id
            WHERE owl.order_id = ?
            GROUP BY owl.technician_id, t.name
            HAVING minutes > 0 OR running = 1
            ORDER BY minutes DESC");
        $st->execute([$orderId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}

/** Pojistka: ENUM orders.priority musí znát hodnotu 'Low' (Klidná). Idempotentní. */
function ensureOrderPriorityLowValue(): void {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $row = $pdo->query("SHOW COLUMNS FROM orders LIKE 'priority'")->fetch(PDO::FETCH_ASSOC);
        $type = (string)($row['Type'] ?? '');
        if ($type === '' || stripos($type, 'enum(') !== 0) return;   // není ENUM → nic netřeba
        if (stripos($type, "'Low'") !== false) return;               // už tam je
        $newType = preg_replace('/\)\s*$/', ",'Low')", $type, 1);
        $pdo->exec("ALTER TABLE orders MODIFY COLUMN priority $newType DEFAULT 'Normal'");
    } catch (Throwable $e) {
        error_log('ensureOrderPriorityLowValue: ' . $e->getMessage());
    }
}

/** Lowercase bez diakritiky — pro tolerantní porovnávání textů z webu. */
function crmFoldText(string $s): string {
    $s = mb_strtolower(trim($s));
    return strtr($s, [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
        'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
    ]);
}

/**
 * Rozpozná prioritu z popisku volby v RepairPluginu (normal / express / nespěchám…).
 * Vrací 'High' | 'Low' | 'Normal', nebo null pokud text prioritu nepřipomíná.
 */
function crmDetectWebPriority(string $label): ?string {
    $t = crmFoldText($label);
    if ($t === '') return null;
    // POZOR na pořadí: „nespěchám" obsahuje „spěch" a „Priorita: normální"
    // obsahuje „priorit" — Low a Normal se musí testovat před High.
    if (preg_match('/nespech|no ?rush|klid|az to bude|pozdeji/', $t)) return 'Low';
    if (preg_match('/normal|standard|bezn|klasick/', $t)) return 'Normal';
    if (preg_match('/expres|urgent|priorit|spech|do ?24|24 ?h/', $t)) return 'High';
    return null;
}

/** Je položka items[] z webhooku skutečná oprava/produkt (patří do popisu závady)? */
function crmWebItemIsService(array $item): bool {
    $type = strtolower(trim((string)($item['type'] ?? '')));
    if (in_array($type, ['repair', 'upsale', 'product'], true)) return true;
    if (in_array($type, ['extra_fee', 'fee', 'priority', 'discount', 'payment', 'shipping'], true)) return false;
    // bez typu: vyřadit jen texty vypadající jako priorita
    $name = (string)($item['name'] ?? '');
    return crmDetectWebPriority($name) === null;
}

/** Najde v items[] webhooku zvolenou prioritu (položky mimo opravy). */
function crmExtractWebPriority(array $payload): string {
    foreach (['items', 'repairs', 'line_items'] as $key) {
        $items = $payload[$key] ?? null;
        if (!is_array($items)) continue;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            if (crmWebItemIsService($item)) continue;
            $detected = crmDetectWebPriority((string)($item['name'] ?? ''));
            if ($detected !== null) return $detected;
        }
    }
    // fallback: samostatné pole v payloadu
    foreach (['priority', 'repair_priority', 'service_priority', 'appointment_priority'] as $key) {
        if (isset($payload[$key]) && is_scalar($payload[$key])) {
            $detected = crmDetectWebPriority((string)$payload[$key]);
            if ($detected !== null) return $detected;
        }
    }
    return 'Normal';
}

/**
 * Přeloží způsob předání / service_method z RepairPluginu (anglicky z pluginu)
 * do jazyka CRM. Neznámou hodnotu vrátí beze změny.
 *   „Come by our store" | „Ship Device" | „Pickup Service"
 */
function crmTranslateWebServiceMethod(string $raw, ?string $lang = null): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $lang = $lang ?: (function_exists('crm_get_language') ? crm_get_language() : 'cs');
    $t = crmFoldText($raw);
    // kanonické klíče → [cs, en, ru]
    $map = [
        'store'  => ['cs' => 'Osobně na prodejně', 'en' => 'Come by our store', 'ru' => 'Лично в магазине'],
        'ship'   => ['cs' => 'Zaslání poštou',      'en' => 'Ship device',       'ru' => 'Отправка почтой'],
        'pickup' => ['cs' => 'Vyzvednutí u zákazníka','en' => 'Pickup service',   'ru' => 'Забор у клиента'],
    ];
    $key = null;
    if (preg_match('/come ?by|our ?store|\bstore\b|osobn|prodejn|na ?prodejn/', $t))      $key = 'store';
    elseif (preg_match('/ship|posta|postou|postou|mail|posli|zasl/', $t))                 $key = 'ship';
    elseif (preg_match('/pick ?up|vyzvednu|svoz|kuryr|courier/', $t))                     $key = 'pickup';
    if ($key === null) return $raw;                       // neznámé → nechat originál
    return $map[$key][$lang] ?? $map[$key]['cs'];
}

/**
 * Zruší rezervaci z webu při cancelled/deleted webhooku z RepairPluginu.
 * - web_booking → 'dismissed' (i když už byla převedena na zakázku)
 * - pokud z ní vznikla zakázka a ta je stále čerstvá (stav „Přijato z RepairPluginu"
 *   nebo skupina new), nastaví ji na „Stornováno" + poznámka; u rozpracované zakázky
 *   status NEmění, jen přidá varovnou poznámku (chrání práci technika).
 * - odstraní událost z firemního CalDAV kalendáře.
 */
function crmCancelWebBooking(int $bookingId): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM web_bookings WHERE id = ? LIMIT 1");
        $stmt->execute([$bookingId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) return;

        $orderId = (int)($b['order_id'] ?? 0);
        if ($orderId > 0) {
            $o = $pdo->prepare("SELECT id, status, technician_notes FROM orders WHERE id = ? LIMIT 1");
            $o->execute([$orderId]);
            $order = $o->fetch(PDO::FETCH_ASSOC);
            if ($order) {
                $curStatus = (string)$order['status'];
                $stamp = date('j.n.Y H:i');
                $isFresh = ($curStatus === 'Přijato z RepairPluginu') || isOrderStatusIn($curStatus, 'new');
                if ($isFresh && !isOrderStatusIn($curStatus, 'cancelled')) {
                    $note = trim((string)$order['technician_notes']);
                    $note = ($note !== '' ? $note . "\n" : '') . '⚠ Rezervace zrušena na webu (RepairPlugin) ' . $stamp . ' → zakázka stornována.';
                    $pdo->prepare("UPDATE orders SET status = 'Stornováno', technician_notes = ? WHERE id = ?")
                        ->execute([$note, $orderId]);
                    if (function_exists('logOrderStatusChange')) { try { logOrderStatusChange($orderId, $curStatus, 'Stornováno'); } catch (Throwable $e) {} }
                    if (function_exists('assignmentSegmentSync')) { try { assignmentSegmentSync($orderId, $curStatus, 'Stornováno'); } catch (Throwable $e) {} }
                } else {
                    // rozpracovaná / hotová zakázka → neměnit stav, jen upozornit
                    $note = trim((string)$order['technician_notes']);
                    $note = ($note !== '' ? $note . "\n" : '') . '⚠ POZOR: zákazník zrušil rezervaci na webu ' . $stamp . ' (zakázka je již rozpracovaná — zkontrolujte).';
                    $pdo->prepare("UPDATE orders SET technician_notes = ? WHERE id = ?")->execute([$note, $orderId]);
                }
            }
        }

        // rezervace pryč z panelu (i converted) + z kalendáře
        $pdo->prepare("UPDATE web_bookings SET status = 'dismissed' WHERE id = ?")->execute([$bookingId]);
        if (function_exists('crmDeleteWebBookingFromCalDav')) { crmDeleteWebBookingFromCalDav($bookingId); }
    } catch (Throwable $e) {
        error_log('crmCancelWebBooking: ' . $e->getMessage());
    }
}

/** Odhad typu zařízení (ENUM orders.device_type) z názvu zařízení. */
function crmGuessDeviceType(string $device): string {
    $d = mb_strtolower($device);
    if (preg_match('/iphone|smartphone|\bphone\b|galaxy|pixel/u', $d)) return 'Phone';
    if (preg_match('/ipad|tablet|\btab\b/u', $d)) return 'Tablet';
    if (preg_match('/macbook|notebook|laptop|thinkpad/u', $d)) return 'Notebook';
    if (preg_match('/imac|mac ?mini|mac ?pro|mac ?studio|desktop|\bpc\b|počítač/u', $d)) return 'Computer';
    return 'Other';
}

/**
 * Z webové rezervace (web_bookings) rovnou založí ZÁKAZNÍKA (pokud neexistuje) a ZAKÁZKU „Přijato".
 * Idempotentní: pokud už rezervace má order_id nebo je dismissed, nic nedělá.
 * Vrací ID vytvořené zakázky, nebo null (nezaložilo se — rezervace zůstane v panelu k ručnímu převzetí).
 */
function crmCreateOrderFromWebBooking(int $bookingId): ?int {
    global $pdo;
    try {
        ensureWebBookingsSchema();
        $stmt = $pdo->prepare("SELECT * FROM web_bookings WHERE id = ? LIMIT 1");
        $stmt->execute([$bookingId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) return null;
        if (!empty($b['order_id'])) return (int)$b['order_id'];        // už převzato
        if (($b['status'] ?? 'new') !== 'new') return null;            // dismissed/converted

        $raw = [];
        if (!empty($b['raw_payload'])) { $raw = json_decode((string)$b['raw_payload'], true) ?: []; }
        $pick = function(array $a, array $keys): string {
            foreach ($keys as $k) { if (isset($a[$k]) && is_scalar($a[$k]) && trim((string)$a[$k]) !== '') return trim((string)$a[$k]); }
            return '';
        };

        // ── Zákazník: najít podle telefonu (číslice) nebo e-mailu, jinak založit ──
        $name  = trim((string)($b['customer_name'] ?? ''));
        $phone = trim((string)($b['phone'] ?? ''));
        $email = trim((string)($b['email'] ?? ''));
        $parts = array_values(array_filter(preg_split('/\s+/', $name)));
        $firstName = $name;
        $lastName  = '';
        if (count($parts) >= 2) { $lastName = array_pop($parts); $firstName = implode(' ', $parts); }
        elseif (count($parts) === 1) { $firstName = $parts[0]; }
        if ($firstName === '') { $firstName = 'Zákazník z webu'; }

        $phoneDigits = preg_replace('/\D/', '', $phone);
        $customerId = 0;
        if ($phoneDigits !== '') {
            try {
                $q = $pdo->prepare("SELECT id FROM customers WHERE REGEXP_REPLACE(COALESCE(phone,''),'[^0-9]','') = ? AND ? <> '' ORDER BY id ASC LIMIT 1");
                $q->execute([$phoneDigits, $phoneDigits]);
                $customerId = (int)($q->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $q = $pdo->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
                $q->execute([$phone]);
                $customerId = (int)($q->fetchColumn() ?: 0);
            }
        }
        if ($customerId === 0 && $email !== '') {
            $q = $pdo->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
            $q->execute([$email]);
            $customerId = (int)($q->fetchColumn() ?: 0);
        }
        $businessName = $pick($raw, ['customer_business_name', 'company']);
        $addressBits = array_filter([
            trim($pick($raw, ['customer_street_address', 'street']) . ' ' . $pick($raw, ['customer_house_no', 'house_no'])),
            trim($pick($raw, ['customer_zipcode', 'zipcode']) . ' ' . $pick($raw, ['customer_city', 'city'])),
            $pick($raw, ['customer_country', 'country']),
        ]);
        if ($customerId === 0) {
            $pdo->prepare("INSERT INTO customers (customer_type, first_name, last_name, phone, email, address, company)
                           VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $businessName !== '' ? 'company' : 'private',
                    mb_substr($firstName, 0, 50), mb_substr($lastName, 0, 50),
                    mb_substr($phone, 0, 20), mb_substr($email, 0, 100),
                    implode(', ', $addressBits) ?: null, $businessName !== '' ? mb_substr($businessName, 0, 100) : null,
                ]);
            $customerId = (int)$pdo->lastInsertId();
        }

        // ── Zařízení / oprava ──
        $device = trim((string)($b['device'] ?? ''));
        $brand  = $pick($raw, ['brand', 'device_brand', 'manufacturer']);
        $model  = $pick($raw, ['model', 'device_model', 'model_name']);
        $color  = $pick($raw, ['color', 'colour']);
        if ($device === '') { $device = trim($brand . ' ' . $model . ' ' . $color); }
        $deviceModel = mb_substr($device !== '' ? $device : 'Neurčeno', 0, 100);
        $service = trim((string)($b['service'] ?? ''));
        $problem = $service !== '' ? $service : 'Objednávka z webu';
        $imei    = $pick($raw, ['customer_imei', 'imei', 'serial']);
        // Passcode zařízení z formuláře → pole PIN/heslo; priorita dle volby zákazníka
        $passcode = $pick($raw, ['custom_device_passcode', 'device_passcode', 'customer_passcode', 'passcode', 'device_pin']);
        $priority = crmExtractWebPriority($raw);

        // Poznámka technikovi: termín z webu + způsob + zákaznická poznámka
        $noteBits = ['Zdroj: web (applefix.cz)'];
        if (!empty($b['wp_booking_id'])) { $noteBits[] = 'Objednávka z webu č. ' . $b['wp_booking_id']; }
        if (!empty($b['appointment_at'])) { $noteBits[] = 'Termín z webu: ' . date('j.n.Y H:i', strtotime((string)$b['appointment_at'])); }
        if (!empty($b['delivery_method'])) { $noteBits[] = 'Způsob: ' . crmTranslateWebServiceMethod((string)$b['delivery_method'], 'cs'); }
        if (!empty($b['notes'])) { $noteBits[] = 'Poznámka zákazníka: ' . $b['notes']; }
        $techNotes = implode("\n", $noteBits);

        $estCost = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', $pick($raw, ['total_price', 'balance_due_on_repair', 'sub_total'])));

        // ── Zakázka „Přijato z RepairPluginu" ──
        ensureRepairPluginOrderStatus();
        ensureOrderPriorityLowValue();
        $status = 'Přijato z RepairPluginu';
        $branchId = getDefaultBranchId();
        $orderCode = function_exists('generateNextOrderCode') ? generateNextOrderCode($pdo) : null;
        $deviceType = crmGuessDeviceType($device);

        ensureOrderCreatedByColumn();
        $pdo->prepare("INSERT INTO orders
            (customer_id, technician_id, branch_id, device_type, order_type, device_brand, device_model,
             problem_description, technician_notes, serial_number, pin_code, priority, estimated_cost, status, order_code, created_by_name)
            VALUES (?, NULL, ?, ?, 'Non-Warranty', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Web (applefix.cz)')")
            ->execute([
                $customerId, $branchId, $deviceType,
                mb_substr($brand !== '' ? $brand : '', 0, 100), $deviceModel,
                mb_substr($problem, 0, 5000), $techNotes,
                mb_substr($imei, 0, 100), $passcode !== '' ? mb_substr($passcode, 0, 50) : null,
                normalizeOrderPriority($priority),
                $estCost > 0 ? $estCost : null, $status, $orderCode,
            ]);
        $orderId = (int)$pdo->lastInsertId();

        if (function_exists('assignmentSegmentSync')) { try { assignmentSegmentSync($orderId, null, (string)$status); } catch (Throwable $e) {} }
        if (function_exists('logOrderStatusChange')) { try { logOrderStatusChange($orderId, '', $status); } catch (Throwable $e) {} }

        crmAuditLog('order.create', [
            'actor_type' => 'system', 'actor_name' => 'Web (RepairPlugin)',
            'entity_type' => 'order', 'entity_id' => $orderId,
            'entity_label' => ($orderCode ?: ('#' . $orderId)),
            'summary' => 'Zakázka ' . ($orderCode ?: ('#' . $orderId)) . ' založena z webu' . ($name !== '' ? ' — ' . $name : ''),
            'branch_id' => $branchId,
        ]);

        // Rozpis ceny z webu (opravy, expresní příplatek, slevy) → zakázkový list
        try {
            $sortI = 0; $linesSum = 0.0;
            foreach (['items', 'repairs', 'line_items'] as $ik) {
                if (!is_array($raw[$ik] ?? null)) { continue; }
                foreach ($raw[$ik] as $it) {
                    if (!is_array($it)) { continue; }
                    $plabel = trim((string)($it['name'] ?? ''));
                    $pamt = $it['item_subtotal'] ?? ($it['price'] ?? null);
                    if ($plabel === '' || $pamt === null || !is_numeric($pamt)) { continue; }
                    $pamt = (float)$pamt;
                    if (abs($pamt) < 0.005) { continue; }
                    crmAddOrderPriceLine($orderId, $plabel, $pamt, $sortI++);
                    $linesSum += $pamt;
                }
                break;   // items[] má přednost, další klíče jsou aliasy
            }
            // sleva mimo položky (kupón/combo) — jen pokud sedí do celkové ceny
            $combo = (float)($raw['combo_discount'] ?? 0);
            if ($combo > 0.005 && $estCost > 0
                && abs($linesSum - $estCost) > 0.01
                && abs(($linesSum - $combo) - $estCost) <= 0.01) {
                $cc = trim((string)($raw['coupon_code'] ?? ''));
                crmAddOrderPriceLine($orderId, 'Sleva' . ($cc !== '' ? ' (' . $cc . ')' : ''), -$combo, $sortI++);
            }
        } catch (Throwable $e) { /* rozpis je bonus, nesmí shodit založení */ }

        $pdo->prepare("UPDATE web_bookings SET status = 'converted', order_id = ? WHERE id = ?")
            ->execute([$orderId, $bookingId]);

        return $orderId;
    } catch (Throwable $e) {
        error_log('crmCreateOrderFromWebBooking: ' . $e->getMessage());
        return null;   // rezervace zůstane v panelu → ruční převzetí
    }
}
