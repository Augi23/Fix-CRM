<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Historie je citlivá (kdo co provedl) → jen administrátor.
$__isAdmin = (($_SESSION['role'] ?? '') === 'admin') || hasPermission('admin_access');
if (!isset($_SESSION['user_id']) || !$__isAdmin) {
    header('Location: index.php');
    exit;
}

ensureAuditLogTable();

// ── Filtry ────────────────────────────────────────────────────────────────
$fAction = trim((string)($_GET['action'] ?? ''));
$fActor  = trim((string)($_GET['actor'] ?? ''));
$fQ      = trim((string)($_GET['q'] ?? ''));
$fFrom   = trim((string)($_GET['from'] ?? ''));
$fTo     = trim((string)($_GET['to'] ?? ''));
$page    = max(1, (int)($_GET['p'] ?? 1));
$per     = 60;
$off     = ($page - 1) * $per;

$where = [];
$params = [];
if ($fAction !== '') { $where[] = 'action = ?';       $params[] = $fAction; }
if ($fActor !== '')  { $where[] = 'actor_name LIKE ?'; $params[] = '%' . $fActor . '%'; }
if ($fQ !== '')      { $where[] = '(summary LIKE ? OR entity_label LIKE ? OR details LIKE ?)'; $params[] = '%' . $fQ . '%'; $params[] = '%' . $fQ . '%'; $params[] = '%' . $fQ . '%'; }
if ($fFrom !== '')   { $where[] = 'created_at >= ?';  $params[] = $fFrom . ' 00:00:00'; }
if ($fTo !== '')     { $where[] = 'created_at <= ?';  $params[] = $fTo . ' 23:59:59'; }
$wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = 0; $rows = []; $actionOptions = [];
try {
    $cs = $pdo->prepare("SELECT COUNT(*) FROM audit_log $wsql");
    $cs->execute($params);
    $total = (int)$cs->fetchColumn();

    $q = $pdo->prepare("SELECT * FROM audit_log $wsql ORDER BY id DESC LIMIT $per OFFSET $off");
    $q->execute($params);
    $rows = $q->fetchAll();

    $actionOptions = array_column($pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(), 'action');
} catch (Throwable $e) { $rows = []; }
$pages = max(1, (int)ceil($total / max(1, $per)));

// barvy dle typu akce (jen vizuál)
$__actionColor = function (string $a): string {
    if (str_ends_with($a, '.delete')) return 'danger';
    if (str_starts_with($a, 'auth.')) return 'secondary';
    if (str_ends_with($a, '.create') || $a === 'admin.create') return 'success';
    if (str_contains($a, 'status')) return 'info';
    if (str_contains($a, 'permission') || str_contains($a, 'password')) return 'warning';
    return 'primary';
};
$__actorBadge = function (array $r): string {
    $t = (string)($r['actor_type'] ?? '');
    if ($t === 'user') return '<span class="badge bg-danger">Administrátor</span>';
    if ($t === 'technician') { $role = (string)($r['actor_role'] ?? ''); $lbl = $role === 'admin' ? 'Administrátor' : ($role === 'boss' ? 'Boss' : ($role === 'manager' ? 'Manažer' : 'Technik')); return '<span class="badge bg-primary">' . htmlspecialchars($lbl) . '</span>'; }
    if ($t === 'client') return '<span class="badge bg-info text-dark">Klient</span>';
    return '<span class="badge bg-secondary">Systém</span>';
};

require_once 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-clock-rotate-left me-2 text-info"></i>Historie úprav</h2>
    <span class="text-white-50 small"><?php echo number_format($total, 0, ',', ' '); ?> záznamů</span>
</div>
<p class="text-white-50 small mb-3">Spolehlivý přehled, kdo a kdy co v systému provedl — přihlášení, zakázky, klienti i zaměstnanci. Přístup má jen administrátor.</p>

<form method="GET" class="glass-panel p-3 border-secondary mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
            <label class="form-label small mb-1">Úkon</label>
            <select name="action" class="form-select form-select-sm">
                <option value="">— všechny —</option>
                <?php foreach ($actionOptions as $ao): ?>
                    <option value="<?php echo htmlspecialchars($ao); ?>" <?php echo $fAction === $ao ? 'selected' : ''; ?>><?php echo htmlspecialchars(crmAuditActionLabel($ao)); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">Kdo</label>
            <input type="text" name="actor" value="<?php echo htmlspecialchars($fActor); ?>" class="form-control form-control-sm" placeholder="jméno">
        </div>
        <div class="col-md-3 col-12">
            <label class="form-label small mb-1">Hledat</label>
            <input type="text" name="q" value="<?php echo htmlspecialchars($fQ); ?>" class="form-control form-control-sm" placeholder="zakázka, klient, detail…">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">Od</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($fFrom); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">Do</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($fTo); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-12 d-flex gap-2 mt-2">
            <button class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filtrovat</button>
            <a href="history.php" class="btn btn-sm btn-outline-secondary">Zrušit filtr</a>
        </div>
    </div>
</form>

<div class="table-responsive glass-panel border-secondary">
    <table class="table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th style="white-space:nowrap;">Čas</th>
                <th>Úkon</th>
                <th>Kdo provedl</th>
                <th>Čeho se týká</th>
                <th>Detail</th>
                <th class="text-end">IP</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="6" class="text-center text-white-50 py-4">Žádné záznamy.</td></tr>
        <?php else: foreach ($rows as $r):
            $action = (string)$r['action'];
            $col = $__actionColor($action);
            $eLabel = trim((string)($r['entity_label'] ?? ''));
            $eType = (string)($r['entity_type'] ?? '');
            $eId = (int)($r['entity_id'] ?? 0);
            $link = '';
            if ($eType === 'order' && $eId > 0)    { $link = 'view_order.php?id=' . $eId; }
            elseif ($eType === 'customer' && $eId > 0) { $link = 'edit_customer.php?id=' . $eId; }
        ?>
            <tr>
                <td style="white-space:nowrap;" class="small"><?php echo date('d.m.Y H:i:s', strtotime((string)$r['created_at'])); ?></td>
                <td><span class="badge bg-<?php echo $col; ?>"><?php echo htmlspecialchars(crmAuditActionLabel($action)); ?></span></td>
                <td>
                    <strong><?php echo htmlspecialchars((string)$r['actor_name']); ?></strong>
                    <div><?php echo $__actorBadge($r); ?></div>
                </td>
                <td>
                    <?php if ($eLabel !== ''): ?>
                        <?php if ($link !== ''): ?><a href="<?php echo htmlspecialchars($link); ?>" class="text-info text-decoration-none"><?php echo htmlspecialchars($eLabel); ?></a><?php else: ?><?php echo htmlspecialchars($eLabel); ?><?php endif; ?>
                    <?php else: ?><span class="text-white-50">—</span><?php endif; ?>
                </td>
                <td class="small"><?php echo htmlspecialchars((string)($r['summary'] ?? '')); ?></td>
                <td class="text-end small text-white-50"><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $qs = function ($p) use ($fAction, $fActor, $fQ, $fFrom, $fTo) {
            return 'history.php?' . http_build_query(array_filter(['action' => $fAction, 'actor' => $fActor, 'q' => $fQ, 'from' => $fFrom, 'to' => $fTo, 'p' => $p], fn($v) => $v !== '' && $v !== null));
        };
        $start = max(1, $page - 3); $end = min($pages, $page + 3);
        ?>
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($qs(max(1, $page - 1))); ?>">‹</a></li>
        <?php for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($qs($i)); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($qs(min($pages, $page + 1))); ?>">›</a></li>
    </ul>
</nav>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
