<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// Historie: všichni zaměstnanci KROMĚ techniků vedlejších poboček
// (Roman, Mark — Na Příkopě). Pravidlo 16.7.2026, viz crmCanViewHistory().
// Guard akceptuje obě session varianty dual-loginu (dřív jen user_id — technici padali).
if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !crmCanViewHistory()) {
    header('Location: index.php');
    exit;
}

ensureAuditLogTable();

// Podsekce: audit (Historie úprav) | kasa (Kasa prodejna)
$activeTab = ($_GET['tab'] ?? 'audit') === 'kasa' ? 'kasa' : 'audit';

// ── Kasa prodejna: data ───────────────────────────────────────────────────
$kSales = []; $kSums = []; $kTotal = 0; $kPages = 1;
$kFrom = trim((string)($_GET['from'] ?? ''));
$kTo = trim((string)($_GET['to'] ?? ''));
$kPay = trim((string)($_GET['pay'] ?? ''));
if ($activeTab === 'kasa') {
    ensurePosTables();
    $kPage = max(1, (int)($_GET['p'] ?? 1));
    $kPer = 60;
    $kw = []; $kp = [];
    if ($kFrom !== '') { $kw[] = 's.created_at >= ?'; $kp[] = $kFrom . ' 00:00:00'; }
    if ($kTo !== '')   { $kw[] = 's.created_at <= ?'; $kp[] = $kTo . ' 23:59:59'; }
    if (in_array($kPay, ['cash', 'card', 'invoice'], true)) { $kw[] = 's.payment_method = ?'; $kp[] = $kPay; }
    $kws = $kw ? ('WHERE ' . implode(' AND ', $kw)) : '';
    try {
        $c = $pdo->prepare("SELECT COUNT(*) FROM pos_sales s $kws");
        $c->execute($kp);
        $kTotal = (int)$c->fetchColumn();
        $kPages = max(1, (int)ceil($kTotal / $kPer));

        $q = $pdo->prepare("SELECT s.*, c.first_name, c.last_name, c.company, i.invoice_number,
                (SELECT GROUP_CONCAT(CONCAT(pi.quantity, '× ', pi.item_name) SEPARATOR ', ')
                 FROM pos_sale_items pi WHERE pi.sale_id = s.id) AS items_txt
            FROM pos_sales s
            LEFT JOIN customers c ON s.customer_id = c.id
            LEFT JOIN invoices i ON s.invoice_id = i.id
            $kws ORDER BY s.id DESC LIMIT $kPer OFFSET " . (($kPage - 1) * $kPer));
        $q->execute($kp);
        $kSales = $q->fetchAll();

        // souhrn za filtrovaný rozsah (jen dokončené) = denní/měsíční uzávěrka
        $s = $pdo->prepare("SELECT payment_method, COUNT(*) n, SUM(total) t FROM pos_sales s
            $kws " . ($kws ? 'AND' : 'WHERE') . " s.status = 'completed' GROUP BY payment_method");
        $s->execute($kp);
        foreach ($s->fetchAll() as $r) { $kSums[(string)$r['payment_method']] = $r; }
    } catch (Throwable $e) {}
}

// ── Filtry (Historie úprav) ───────────────────────────────────────────────
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
if ($activeTab === 'audit') {
    try {
        $cs = $pdo->prepare("SELECT COUNT(*) FROM audit_log $wsql");
        $cs->execute($params);
        $total = (int)$cs->fetchColumn();

        $q = $pdo->prepare("SELECT * FROM audit_log $wsql ORDER BY id DESC LIMIT $per OFFSET $off");
        $q->execute($params);
        $rows = $q->fetchAll();

        $actionOptions = array_column($pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(), 'action');
    } catch (Throwable $e) { $rows = []; }
}
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
    <h2 class="mb-0"><i class="fas fa-clock-rotate-left me-2 text-info"></i>Historie</h2>
    <span class="text-white-50 small"><?php echo number_format($activeTab === 'kasa' ? $kTotal : $total, 0, ',', ' '); ?> záznamů</span>
</div>

<ul class="nav nav-pills mb-3 glass-panel p-2 border-secondary">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'audit' ? 'active' : 'text-white-75'; ?>" href="history.php"><i class="fas fa-clock-rotate-left me-2"></i>Historie úprav</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'kasa' ? 'active' : 'text-white-75'; ?>" href="history.php?tab=kasa"><i class="fas fa-cash-register me-2"></i>Kasa prodejna</a>
    </li>
</ul>

<?php if ($activeTab === 'kasa'): ?>
<p class="text-white-50 small mb-3">Všechny prodeje přes Pokladnu — doklady, položky, typ platby, prodejce. Storno smí jen vedení a vrací zboží na sklad.</p>

<form method="GET" class="glass-panel p-3 border-secondary mb-3">
    <input type="hidden" name="tab" value="kasa">
    <div class="row g-2 align-items-end">
        <div class="col-md-3 col-6">
            <label class="form-label small mb-1">Platba</label>
            <select name="pay" class="form-select form-select-sm">
                <option value="">— všechny —</option>
                <option value="cash" <?php echo $kPay === 'cash' ? 'selected' : ''; ?>>Hotově</option>
                <option value="card" <?php echo $kPay === 'card' ? 'selected' : ''; ?>>Kartou</option>
                <option value="invoice" <?php echo $kPay === 'invoice' ? 'selected' : ''; ?>>Na fakturu</option>
            </select>
        </div>
        <div class="col-md-3 col-6">
            <label class="form-label small mb-1">Od</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($kFrom); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-3 col-6">
            <label class="form-label small mb-1">Do</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($kTo); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-3 col-6 d-flex gap-2">
            <button class="btn btn-sm btn-primary flex-grow-1"><i class="fas fa-filter me-1"></i>Filtrovat</button>
            <a href="history.php?tab=kasa" class="btn btn-sm btn-outline-secondary">Zrušit</a>
        </div>
    </div>
</form>

<div class="row g-3 mb-3">
    <?php foreach ([['cash', 'Hotově', 'money-bill-wave', 'success'], ['card', 'Kartou', 'credit-card', 'info'], ['invoice', 'Na fakturu', 'file-invoice', 'warning']] as [$pm, $lbl, $ico, $clr]): ?>
    <div class="col-md-3 col-6">
        <div class="glass-panel p-3 border-secondary">
            <div class="small text-white-50"><i class="fas fa-<?php echo $ico; ?> me-1 text-<?php echo $clr; ?>"></i><?php echo $lbl; ?> (<?php echo (int)($kSums[$pm]['n'] ?? 0); ?>×)</div>
            <div class="fs-5 fw-bold"><?php echo formatMoney((float)($kSums[$pm]['t'] ?? 0)); ?></div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-md-3 col-6">
        <div class="glass-panel p-3 border-secondary">
            <div class="small text-white-50"><i class="fas fa-coins me-1"></i>Celkem (bez storen)</div>
            <div class="fs-5 fw-bold text-info"><?php echo formatMoney(array_sum(array_map(static fn($r) => (float)$r['t'], $kSums))); ?></div>
        </div>
    </div>
</div>

<div class="table-responsive glass-panel border-secondary">
    <table class="table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Doklad</th>
                <th style="white-space:nowrap;">Čas</th>
                <th>Položky</th>
                <th>Zákazník</th>
                <th>Platba</th>
                <th class="text-end">Částka</th>
                <th>Prodejce</th>
                <th class="text-end">Akce</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($kSales)): ?>
            <tr><td colspan="8" class="text-center text-white-50 py-4">Zatím žádné prodeje.</td></tr>
        <?php else: foreach ($kSales as $s):
            $cn = (string)$s['status'] === 'cancelled';
            $custName = trim((string)($s['company'] ?? '')) ?: trim((string)($s['first_name'] ?? '') . ' ' . (string)($s['last_name'] ?? ''));
        ?>
            <tr<?php echo $cn ? ' class="opacity-50"' : ''; ?>>
                <td>
                    <a href="print_receipt.php?id=<?php echo (int)$s['id']; ?>" target="_blank" class="text-info text-decoration-none"><code><?php echo htmlspecialchars($s['sale_number']); ?></code></a>
                    <?php if ($cn): ?><div><span class="badge bg-danger">Storno</span></div><?php endif; ?>
                </td>
                <td class="small" style="white-space:nowrap;"><?php echo date('d.m.Y H:i', strtotime((string)$s['created_at'])); ?></td>
                <td class="small"><?php echo htmlspecialchars(mb_substr((string)($s['items_txt'] ?? ''), 0, 90)); ?><?php echo mb_strlen((string)($s['items_txt'] ?? '')) > 90 ? '…' : ''; ?></td>
                <td class="small"><?php echo $custName !== '' ? htmlspecialchars($custName) : '<span class="text-white-50">—</span>'; ?></td>
                <td>
                    <span class="badge <?php echo ['cash' => 'bg-success', 'card' => 'bg-info text-dark', 'invoice' => 'bg-warning text-dark'][(string)$s['payment_method']] ?? 'bg-secondary'; ?>"><?php echo ['cash' => 'Hotově', 'card' => 'Kartou', 'invoice' => 'Faktura'][(string)$s['payment_method']] ?? ''; ?></span>
                    <?php if (!empty($s['invoice_number'])): ?><div class="small text-white-50 mt-1"><?php echo htmlspecialchars($s['invoice_number']); ?></div><?php endif; ?>
                </td>
                <td class="text-end fw-bold<?php echo $cn ? ' text-decoration-line-through' : ''; ?>"><?php echo formatMoney((float)$s['total']); ?></td>
                <td class="small"><?php echo htmlspecialchars((string)$s['seller_name']); ?></td>
                <td class="text-end">
                    <div class="btn-group btn-group-sm">
                        <a href="print_receipt.php?id=<?php echo (int)$s['id']; ?>" target="_blank" class="btn btn-white border text-info" title="Účtenka"><i class="fas fa-print"></i></a>
                        <?php if (!empty($s['invoice_id'])): ?>
                            <a href="print_invoice.php?id=<?php echo (int)$s['invoice_id']; ?>" target="_blank" class="btn btn-white border text-warning" title="Faktura"><i class="fas fa-file-invoice"></i></a>
                        <?php endif; ?>
                        <?php if (!$cn && crmCanCancelPosSale()): ?>
                            <button type="button" class="btn btn-white border text-danger kasa-cancel-btn" data-id="<?php echo (int)$s['id']; ?>" data-num="<?php echo htmlspecialchars($s['sale_number']); ?>" title="Storno (vrátí zboží na sklad)"><i class="fas fa-rotate-left"></i></button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($kPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $kqs = function ($p) use ($kFrom, $kTo, $kPay) {
            return 'history.php?' . http_build_query(array_filter(['tab' => 'kasa', 'from' => $kFrom, 'to' => $kTo, 'pay' => $kPay, 'p' => $p], fn($v) => $v !== '' && $v !== null));
        };
        $kPage = max(1, (int)($_GET['p'] ?? 1));
        $ks = max(1, $kPage - 3); $ke = min($kPages, $kPage + 3);
        ?>
        <li class="page-item <?php echo $kPage <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($kqs(max(1, $kPage - 1))); ?>">‹</a></li>
        <?php for ($i = $ks; $i <= $ke; $i++): ?>
            <li class="page-item <?php echo $i === $kPage ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($kqs($i)); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?php echo $kPage >= $kPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($kqs(min($kPages, $kPage + 1))); ?>">›</a></li>
    </ul>
</nav>
<?php endif; ?>

<?php if (crmCanCancelPosSale()): ?>
<script>
$(document).on('click', '.kasa-cancel-btn', function () {
    var id = this.dataset.id, num = this.dataset.num;
    showConfirm('Stornovat prodej ' + num + '? Zboží se vrátí na sklad CRM a případná faktura se zruší. (Pokud byl kus mezitím vyřazen i v naskladňovací appce, vrať ho tam — jinak ho příští import zase odepíše.)', function () {
        $.post('api/pos_cancel.php', { id: id, csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>' }, function (res) {
            if (res.success) { location.reload(); }
            else { showAlert('Chyba: ' + String(res.message || '').replace(/</g, '&lt;')); }
        }).fail(function (xhr) {
            var m = 'Storno selhalo — obnov stránku a zkus znovu.';
            try { m = JSON.parse(xhr.responseText).message || m; } catch (e) {}
            showAlert(String(m).replace(/</g, '&lt;'));
        });
    });
});
</script>
<?php endif; ?>

<?php else: ?>
<p class="text-white-50 small mb-3">Spolehlivý přehled, kdo a kdy co v systému provedl — přihlášení, zakázky, klienti i zaměstnanci.</p>

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
            // Ruční změny údajů/klienta = citlivé zásahy → výrazné zvýraznění řádku
            $__manual = in_array($action, ['customer.identity_change', 'order.customer_change'], true);
            if ($__manual) { $col = 'warning text-dark'; }
            $eLabel = trim((string)($r['entity_label'] ?? ''));
            $eType = (string)($r['entity_type'] ?? '');
            $eId = (int)($r['entity_id'] ?? 0);
            $link = '';
            if ($eType === 'order' && $eId > 0)    { $link = 'view_order.php?id=' . $eId; }
            elseif ($eType === 'customer' && $eId > 0) { $link = 'edit_customer.php?id=' . $eId; }
        ?>
            <tr<?php echo $__manual ? ' style="background:rgba(255,193,7,.08);box-shadow:inset 3px 0 0 #ffc107;"' : ''; ?>>
                <td style="white-space:nowrap;" class="small"><?php echo date('d.m.Y H:i:s', strtotime((string)$r['created_at'])); ?></td>
                <td>
                    <span class="badge bg-<?php echo $col; ?>"><?php echo $__manual ? '<i class="fas fa-pen-nib me-1"></i>' : ''; ?><?php echo htmlspecialchars(crmAuditActionLabel($action)); ?></span>
                </td>
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
<?php endif; /* konec tabu Historie úprav */ ?>

<?php require_once 'includes/footer.php'; ?>
