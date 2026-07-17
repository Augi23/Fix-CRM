<?php
/**
 * BANKA — napojený účet KB v CRM (styl Money S3):
 * zůstatek, stažené pohyby, automatické párování příchozích plateb s fakturami
 * podle VS (+ ruční párování), synchronizace přes KB ADAA API.
 * Přístup: vedení (admin, Boss) — stejná hranice jako Účetnictví.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/kb_api.php';

if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !crmCanManageInvoices()) {
    header('Location: index.php');
    exit;
}

require_once 'includes/header.php';
ensureBankTables();

$configured = kbApiConfigured();
$lastSync = (string)get_setting('kb_last_sync_at', '');
$env = kbApiEnv();

// zůstatek — jen z lokálních dat (nevolat API při každém zobrazení stránky)
$fDir = trim((string)($_GET['dir'] ?? ''));
$fMatch = trim((string)($_GET['match'] ?? ''));
$fFrom = trim((string)($_GET['from'] ?? ''));
$fTo = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$per = 60;

// vždy jen AKTUÁLNÍ prostředí a účet — sandbox/starý účet se nesmí míchat do čísel
$where = ['env = ?', 'account_id = ?'];
$params = [kbApiEnv(), (string)get_setting('kb_account_id', '')];
if (in_array($fDir, ['in', 'out'], true)) { $where[] = 'direction = ?'; $params[] = $fDir; }
if ($fMatch === 'matched') { $where[] = "match_status IN ('auto','manual')"; }
elseif ($fMatch === 'review') { $where[] = "match_status = 'review'"; }
elseif ($fMatch === 'none') { $where[] = "match_status = 'none'"; }
if ($fFrom !== '') { $where[] = 'booking_date >= ?'; $params[] = $fFrom; }
if ($fTo !== '') { $where[] = 'booking_date <= ?'; $params[] = $fTo; }
$wsql = 'WHERE ' . implode(' AND ', $where);

$total = 0; $rows = []; $sums = ['in' => 0.0, 'out' => 0.0, 'review' => 0];
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM bank_transactions $wsql");
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $q = $pdo->prepare("SELECT t.*, i.invoice_number FROM bank_transactions t
        LEFT JOIN invoices i ON t.matched_invoice_id = i.id
        $wsql ORDER BY t.booking_date DESC, t.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $q->execute($params);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $sq = $pdo->prepare("SELECT direction, SUM(amount) s FROM bank_transactions
        WHERE env = ? AND account_id = ? AND booking_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') GROUP BY direction");
    $sq->execute([kbApiEnv(), (string)get_setting('kb_account_id', '')]);
    foreach ($sq->fetchAll() as $r) {
        $sums[(string)$r['direction']] = (float)$r['s'];
    }
    $rq = $pdo->prepare("SELECT COUNT(*) FROM bank_transactions WHERE env = ? AND account_id = ? AND match_status = 'review'");
    $rq->execute([kbApiEnv(), (string)get_setting('kb_account_id', '')]);
    $sums['review'] = (int)$rq->fetchColumn();
} catch (Throwable $e) {}
$pages = max(1, (int)ceil($total / $per));

// nezaplacené faktury pro ruční párování
$openInvoices = [];
try {
    $openInvoices = $pdo->query("SELECT id, invoice_number, total_amount FROM invoices
        WHERE status IN ('issued','overdue') AND invoice_type = 'invoice'
        ORDER BY id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-file-invoice-dollar me-2"></i> <?php echo __('accounting'); ?> <span class="fs-6 text-white-50">Komerční banka<?php echo $env === 'sandbox' ? ' · SANDBOX' : ''; ?></span></h2>
    <div class="d-flex gap-2 align-items-center">
        <span class="text-white-50 small">Poslední sync: <?php echo $lastSync !== '' ? date('d.m.Y H:i', strtotime($lastSync)) : 'nikdy'; ?></span>
        <button class="btn btn-info" id="kbSyncBtn" <?php echo $configured ? '' : 'disabled'; ?>><i class="fas fa-rotate me-2"></i>Synchronizovat</button>
    </div>
</div>

<?php require 'includes/accounting_tabs.php'; ?>

<?php if (!$configured): ?>
<div class="glass-panel p-4 border-secondary mb-4">
    <h5><i class="fas fa-plug me-2 text-warning"></i>Banka ještě není připojená</h5>
    <p class="text-white-50 mb-2">Napojení na Komerční banku (KB API — přímý přístup k účtu) se nastavuje jednorázově:</p>
    <ol class="text-white-50 small mb-3">
        <li>Registrace na <a href="https://developers.kb.cz" target="_blank" class="text-info">developers.kb.cz</a> → API klíče pro <b>OAuth2</b> a <b>ADAA</b> (sandbox hned, zdarma).</li>
        <li>Pro produkci: <b>kvalifikovaný certifikát I.CA/PostSignum</b> → software statement → registrace aplikace (client_id + secret).</li>
        <li>Jednatel jednou potvrdí přístup <b>KB Klíčem</b> — souhlas platí 12 měsíců.</li>
        <li>Vše se vloží do <a href="settings.php?tab=banka" class="text-info">Nastavení → Banka</a>.</li>
    </ol>
    <a href="settings.php?tab=banka" class="btn btn-outline-info"><i class="fas fa-gear me-2"></i>Otevřít nastavení banky</a>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="glass-panel p-3 border-secondary">
            <div class="small text-white-50"><i class="fas fa-arrow-down me-1 text-success"></i>Příjmy tento měsíc</div>
            <div class="fs-5 fw-bold text-success"><?php echo formatMoney($sums['in']); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="glass-panel p-3 border-secondary">
            <div class="small text-white-50"><i class="fas fa-arrow-up me-1 text-danger"></i>Výdaje tento měsíc</div>
            <div class="fs-5 fw-bold"><?php echo formatMoney($sums['out']); ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="glass-panel p-3 border-secondary">
            <div class="small text-white-50"><i class="fas fa-triangle-exclamation me-1 text-warning"></i>K prověření</div>
            <div class="fs-5 fw-bold <?php echo $sums['review'] > 0 ? 'text-warning' : ''; ?>"><?php echo $sums['review']; ?></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="glass-panel p-3 border-secondary">
            <div class="small text-white-50"><i class="fas fa-list me-1"></i>Pohybů celkem</div>
            <div class="fs-5 fw-bold"><?php echo number_format($total, 0, ',', ' '); ?></div>
        </div>
    </div>
</div>

<form method="GET" class="glass-panel p-3 border-secondary mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">Směr</label>
            <select name="dir" class="form-select form-select-sm">
                <option value="">— vše —</option>
                <option value="in" <?php echo $fDir === 'in' ? 'selected' : ''; ?>>Příchozí</option>
                <option value="out" <?php echo $fDir === 'out' ? 'selected' : ''; ?>>Odchozí</option>
            </select>
        </div>
        <div class="col-md-3 col-6">
            <label class="form-label small mb-1">Párování</label>
            <select name="match" class="form-select form-select-sm">
                <option value="">— vše —</option>
                <option value="matched" <?php echo $fMatch === 'matched' ? 'selected' : ''; ?>>Spárované</option>
                <option value="review" <?php echo $fMatch === 'review' ? 'selected' : ''; ?>>K prověření</option>
                <option value="none" <?php echo $fMatch === 'none' ? 'selected' : ''; ?>>Nespárované</option>
            </select>
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">Od</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($fFrom); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-2 col-6">
            <label class="form-label small mb-1">Do</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($fTo); ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-3 col-12 d-flex gap-2">
            <button class="btn btn-sm btn-primary flex-grow-1"><i class="fas fa-filter me-1"></i>Filtrovat</button>
            <a href="banka.php" class="btn btn-sm btn-outline-secondary">Zrušit</a>
        </div>
    </div>
</form>

<div class="table-responsive glass-panel border-secondary">
    <table class="table table-dark table-hover align-middle mb-0">
        <thead>
            <tr>
                <th style="white-space:nowrap;">Datum</th>
                <th>Protistrana</th>
                <th>Zpráva</th>
                <th>VS</th>
                <th class="text-end">Částka</th>
                <th>Párování</th>
                <th class="text-end">Akce</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-white-50 py-4"><?php echo $configured ? 'Zatím žádné pohyby — klikni na Synchronizovat.' : 'Nejdřív připoj banku v Nastavení.'; ?></td></tr>
        <?php else: foreach ($rows as $t):
            $in = (string)$t['direction'] === 'in';
        ?>
            <tr<?php echo (string)$t['match_status'] === 'review' ? ' style="background:rgba(255,193,7,.07);box-shadow:inset 3px 0 0 #ffc107;"' : ''; ?>>
                <td class="small" style="white-space:nowrap;"><?php echo $t['booking_date'] ? date('d.m.Y', strtotime((string)$t['booking_date'])) : '—'; ?></td>
                <td>
                    <div class="fw-semibold small"><?php echo htmlspecialchars($t['counterparty_name'] ?: '—'); ?></div>
                    <div class="small text-white-50"><?php echo htmlspecialchars((string)($t['counterparty_account'] ?? '')); ?></div>
                </td>
                <td class="small"><?php echo htmlspecialchars(mb_substr((string)($t['message'] ?? ''), 0, 60)); ?></td>
                <td><code><?php echo htmlspecialchars($t['vs'] ?: '—'); ?></code></td>
                <td class="text-end fw-bold <?php echo $in ? 'text-success' : ''; ?>"><?php echo ($in ? '+' : '−') . formatMoney((float)$t['amount']); ?></td>
                <td>
                    <?php if (in_array((string)$t['match_status'], ['auto', 'manual'], true) && !empty($t['invoice_number'])): ?>
                        <span class="badge bg-success">Faktura <?php echo htmlspecialchars($t['invoice_number']); ?></span>
                        <span class="small text-white-50"><?php echo $t['match_status'] === 'auto' ? 'auto' : 'ručně'; ?></span>
                    <?php elseif ((string)$t['match_status'] === 'review'): ?>
                        <span class="badge bg-warning text-dark">K prověření<?php echo !empty($t['invoice_number']) ? ' · ' . htmlspecialchars($t['invoice_number']) : ''; ?></span>
                    <?php elseif ($in): ?>
                        <span class="text-white-50 small">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?php if ($in && in_array((string)$t['match_status'], ['none', 'review'], true)): ?>
                        <button type="button" class="btn btn-sm btn-white border text-success kb-match-btn" data-id="<?php echo (int)$t['id']; ?>" data-amount="<?php echo (float)$t['amount']; ?>" data-suggest="<?php echo (int)($t['matched_invoice_id'] ?? 0); ?>" title="Spárovat s fakturou"><i class="fas fa-link"></i></button>
                    <?php elseif (in_array((string)$t['match_status'], ['auto', 'manual'], true)): ?>
                        <button type="button" class="btn btn-sm btn-white border text-danger kb-unmatch-btn" data-id="<?php echo (int)$t['id']; ?>" title="Zrušit párování (vrátí fakturu na nezaplacenou)"><i class="fas fa-link-slash"></i></button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php
        $qs = function ($p) use ($fDir, $fMatch, $fFrom, $fTo) {
            return 'banka.php?' . http_build_query(array_filter(['dir' => $fDir, 'match' => $fMatch, 'from' => $fFrom, 'to' => $fTo, 'p' => $p], fn($v) => $v !== '' && $v !== null));
        };
        $s = max(1, $page - 3); $e = min($pages, $page + 3);
        ?>
        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($qs(max(1, $page - 1))); ?>">‹</a></li>
        <?php for ($i = $s; $i <= $e; $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($qs($i)); ?>"><?php echo $i; ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?php echo $page >= $pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($qs(min($pages, $page + 1))); ?>">›</a></li>
    </ul>
</nav>
<?php endif; ?>

<!-- ruční párování -->
<div class="modal fade" id="kbMatchModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-card">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-link me-2 text-success"></i>Spárovat platbu s fakturou</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="kbMatchTx">
                <div class="alert alert-info border-0 py-2 small">Platba: <strong id="kbMatchAmount"></strong> — po spárování se faktura označí jako <strong>ZAPLACENÁ</strong>.</div>
                <label class="form-label small">Nezaplacená faktura</label>
                <select id="kbMatchInvoice" class="form-select">
                    <option value="">— vyber fakturu —</option>
                    <?php foreach ($openInvoices as $oi): ?>
                        <option value="<?php echo (int)$oi['id']; ?>"><?php echo htmlspecialchars($oi['invoice_number']); ?> — <?php echo formatMoney((float)$oi['total_amount']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('cancel'); ?></button>
                <button type="button" class="btn btn-success" id="kbMatchConfirm"><i class="fas fa-link me-1"></i>Spárovat a označit zaplaceno</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var CSRF = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';

    function doSync(force) {
        var btn = document.getElementById('kbSyncBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Stahuji pohyby…';
        var fd = new FormData();
        fd.append('action', 'sync');
        fd.append('csrf_token', CSRF);
        if (force) { fd.append('force', '1'); }
        fetch('api/kb_sync.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.success) { location.reload(); return; }
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-rotate me-2"></i>Synchronizovat';
                <?php if (hasPermission('admin_access')): ?>
                // throttle šetří tarif KB — vynucení jen po výslovném potvrzení admina
                if (d.throttled && !force) {
                    showConfirm(String(d.message || '') + ' Vynutit synchronizaci hned? (počítá se do tarifu KB)', function () { doSync(true); });
                    return;
                }
                <?php endif; ?>
                showAlert(String(d.message || 'Synchronizace selhala.').replace(/</g, '&lt;'));
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-rotate me-2"></i>Synchronizovat';
                showAlert('Síťová chyba.');
            });
    }
    document.getElementById('kbSyncBtn').addEventListener('click', function () { doSync(false); });

    var matchModal = null;
    $(document).on('click', '.kb-match-btn', function () {
        document.getElementById('kbMatchTx').value = this.dataset.id;
        document.getElementById('kbMatchAmount').textContent = new Intl.NumberFormat('cs-CZ').format(this.dataset.amount) + ' Kč';
        // předvybrat navrženou fakturu (žluté „k prověření"), jinak nutit výběr
        var sel = document.getElementById('kbMatchInvoice');
        var suggest = this.dataset.suggest || '';
        sel.value = (suggest !== '0' && Array.prototype.some.call(sel.options, function (o) { return o.value === suggest; })) ? suggest : '';
        matchModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('kbMatchModal'));
        matchModal.show();
    });
    document.getElementById('kbMatchConfirm').addEventListener('click', function () {
        if (!document.getElementById('kbMatchInvoice').value) { showAlert('Vyber fakturu, se kterou se má platba spárovat.'); return; }
        $.post('api/kb_match.php', {
            action: 'match',
            tx_id: document.getElementById('kbMatchTx').value,
            invoice_id: document.getElementById('kbMatchInvoice').value,
            csrf_token: CSRF
        }, function (res) {
            if (res.success) { location.reload(); }
            else { showAlert('Chyba: ' + String(res.message || '').replace(/</g, '&lt;')); }
        }).fail(function () { showAlert('Párování selhalo — zkus to znovu.'); });
    });
    $(document).on('click', '.kb-unmatch-btn', function () {
        var id = this.dataset.id;
        showConfirm('Zrušit párování? Faktura se vrátí mezi nezaplacené.', function () {
            $.post('api/kb_match.php', { action: 'unmatch', tx_id: id, csrf_token: CSRF }, function (res) {
                if (res.success) { location.reload(); }
                else { showAlert('Chyba: ' + String(res.message || '').replace(/</g, '&lt;')); }
            }).fail(function () { showAlert('Zrušení selhalo.'); });
        });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
