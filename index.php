<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/scan_resolver.php';
require_once 'includes/header.php';
// Třídění pošty na pozadí (levná kontrola, běh max. 1× za ~3 min). Patří do
// api/notify_poll.php, jenže tam aktualizace na serveru zatím nesmí zapisovat.
require_once __DIR__ . '/posta/lib.php';
crmMailSortMaybeSchedule();
// ─── Nástěnka v2 (3.97.0) ───────────────────────────────────────────────────
// Nástěnka = co je potřeba DNES: peníze dne, úkoly „Na řadě", tok zakázek, tým.
// Grafy a dlouhodobá čísla patří do Přehledů (reports.php), sem ne.
// Data skládá includes/dashboard_data.php (tam jsou i pravidla viditelnosti poboček).
require_once __DIR__ . '/includes/dashboard_data.php';
// Admin/Boss: přepínač Obě pobočky / jedna pobočka — volba se drží po dobu přihlášení
if (isBranchGlobalViewer() && isset($_GET['branch'])) {
    $_SESSION['dsh_branch'] = max(0, (int)$_GET['branch']);
}
$D = crmDashboardBuild($pdo, isBranchGlobalViewer() ? (int)($_SESSION['dsh_branch'] ?? 0) : 0);
$__mt = $D['money_total'];
$__showSplit = $D['global'] && count($D['money']) > 1;

// Hledání / filtr přes index.php?search=…&filter=… (staré odkazy) — seznam zakázek
// pak ukazuje výsledky místo nejnovějších zakázek.
$allowed_statuses = getAllowedOrderFilterStatuses();
$filter_status = in_array($_GET['filter'] ?? '', $allowed_statuses, true) ? $_GET['filter'] : null;
$search = trim($_GET['search'] ?? '');
$where_clauses = [];
$params = [];
addOrderBranchScope($where_clauses, $params, 'o');
if ($D['pick'] > 0) { $where_clauses[] = 'o.branch_id = ?'; $params[] = $D['pick']; }
if ($search !== '') {
    $searchTerm = "%$search%";
    $where_clauses[] = '(o.order_code LIKE ? OR o.legacy_code LIKE ? OR o.id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_model LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ?'
        . (is_numeric($search) ? ' OR o.id = ?' : '') . ')';
    for ($i = 0; $i < 10; $i++) { $params[] = $searchTerm; }
    if (is_numeric($search)) { $params[] = (int)$search; }
}
if ($filter_status) {
    $filter_group = getOrderStatusGroup($filter_status);
    if ($filter_group !== null) {
        $filter_key = $filter_group === 'completed' ? 'done' : $filter_group;
        $where_clauses[] = 'o.status IN (' . orderStatusSqlIn($pdo, $filter_key) . ')';
    } else {
        $where_clauses[] = 'o.status = ?';
        $params[] = $filter_status;
    }
}
$where_clause = $where_clauses ? ' WHERE ' . implode(' AND ', $where_clauses) : '';
$search_id = is_numeric($search) ? (int)$search : 0;
$recent = [];
try {
    $st = $pdo->prepare("SELECT o.*, c.first_name, c.last_name, c.phone, c.company, t.name AS tech_name,
            (SELECT MAX(l.changed_at) FROM order_status_log l WHERE l.order_id = o.id) AS last_status_change
        FROM orders o JOIN customers c ON o.customer_id = c.id LEFT JOIN technicians t ON o.technician_id = t.id"
        . $where_clause . " ORDER BY " . orderSortSql('o', 'o.id = ?') . " LIMIT 15");
    $st->execute(array_merge($params, [$search_id]));
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $recent = []; }

// Návštěvnost webů (jen kdo spravuje e-shop) — dnes vs včera
$__web = [];
if (crmCanManageProducts() && function_exists('afxWebVisitStats')) {
    try {
        $__ws = afxWebVisitStats(2);
        foreach (AFX_WEB_SITES as $__k => $__label) {
            $t = $__ws[$__k][date('Y-m-d')] ?? ['visitors' => 0];
            $y = $__ws[$__k][date('Y-m-d', strtotime('-1 day'))] ?? ['visitors' => 0];
            $__web[$__k] = ['label' => $__label, 'today' => (int)$t['visitors'], 'yday' => (int)$y['visitors']];
        }
    } catch (Throwable $e) { $__web = []; }
}

$__dayNames = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
$__monNames = ['ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'];
$__h = (int)date('G');
$__greet = $__h < 10 ? 'Dobré ráno' : ($__h < 18 ? 'Dobrý den' : 'Dobrý večer');
$__me = trim((string)($_SESSION['full_name'] ?? ''));

$dshMoney = fn($v) => number_format((float)$v, 0, ',', ' ') . ' Kč';
/** Řádek zakázky (Moje / Nejnovější) — stejné údaje jako seznam zakázek, ale kompaktně. */
$dshOrderRow = function (array $r) {
    [$staleCls, $staleTitle] = orderStaleRowAttrs($r);
    $tok = getOrderStatusBadgeToken($r['status']);
    $phone = normalizePhoneForTel($r['phone'] ?? '');
    $legacy = ($r['source'] ?? 'crm') === 'legacy';
    $internal = crmIsInternalCustomer($r['customer_id'] ?? 0);
    $amount = (float)($r['final_cost'] ?: $r['estimated_cost']);
    ob_start(); ?>
    <div class="dsh-o <?php echo $legacy ? 'is-legacy' : 's-' . e($tok); ?><?php echo $staleCls ? ' is-stale' : ''; ?>"<?php echo $staleTitle ? ' title="' . e($staleTitle) . '"' : ''; ?>>
        <a class="dsh-o-link" href="view_order.php?id=<?php echo (int)$r['id']; ?>" aria-label="Zakázka <?php echo e(orderDisplayCode($r)); ?>"></a>
        <div class="dsh-o-code">
            <b><?php echo e(orderDisplayCode($r)); ?></b>
            <em><?php echo e(date('j. n. H:i', strtotime((string)$r['created_at']))); ?><?php if ($legacy): ?> <i class="dsh-o-leg" title="Importovaná (stará) zakázka ze zakazkovylist.cz">(import)</i><?php endif; ?></em>
        </div>
        <div class="dsh-o-who">
            <b><?php echo $internal ? '<i class="fas fa-screwdriver-wrench"></i> Interní' : e(trim($r['first_name'] . ' ' . $r['last_name'])); ?></b>
            <em><?php echo e(crmDashDevice($r)); ?></em>
        </div>
        <div class="dsh-o-st">
            <?php echo getStatusBadge($r['status']); ?>
            <?php if (($r['priority'] ?? '') === 'High'): ?><b class="dsh-pill t-urgent"><i class="fas fa-bolt"></i> Spěchá</b><?php endif; ?>
            <?php if (!empty($r['prepaid_at'])): ?><b class="dsh-pill t-completed"><i class="fas fa-circle-check"></i> Zaplaceno</b><?php endif; ?>
            <em><?php echo !empty($r['tech_name']) ? '<i class="fas fa-user-gear"></i> ' . e($r['tech_name']) : '<i class="fas fa-user-plus"></i> bez technika'; ?><?php if (isBranchGlobalViewer() && !empty($r['branch_id'])): ?> · <?php echo e(crmDashBranchShort(getBranchLabel((int)$r['branch_id']))); ?><?php endif; ?></em>
        </div>
        <div class="dsh-o-amt"><?php echo $amount > 0 ? formatMoney($amount) : '—'; ?></div>
        <?php if ($phone !== ''): ?>
        <a class="dsh-call" href="tel:<?php echo e($phone); ?>" title="Zavolat <?php echo e($r['phone']); ?>" aria-label="Zavolat"><i class="fas fa-phone"></i></a>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};
?>
<link rel="stylesheet" href="assets/css/dashboard.css?v=3.98.0">
<?php if (crmCanManageProducts()): ?>
<style>
.eshop-modal-row{display:flex;gap:12px;padding:11px 12px;border-radius:12px;background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.09);margin-bottom:8px;align-items:flex-start;}
.eshop-modal-row .ref{font-family:ui-monospace,Menlo,monospace;font-weight:700;color:#9fe7b6;font-size:.9rem;}
.eshop-modal-row .who{font-size:.85rem;color:rgba(233,238,247,.7);}
.eshop-modal-row .items{margin-top:5px;font-size:.9rem;color:#eef4ff;white-space:pre-line;}
.eshop-modal-row .amt{margin-left:auto;text-align:right;font-weight:800;white-space:nowrap;}
.eshop-badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:10.5px;font-weight:800;letter-spacing:.04em;}
.eshop-badge.paid{background:rgba(48,209,88,.18);color:#7ce39a;}
.eshop-badge.reserved{background:rgba(255,214,10,.18);color:#ffd479;}
.eshop-badge.collected{background:rgba(10,132,255,.18);color:#7ab8ff;}
</style>
<div class="modal fade" id="webVisitsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content glass-card">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-chart-line me-2 text-info"></i>Návštěvnost webů <span id="webVisitsWhich" class="text-white-50 fs-6"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="webVisitsBody">
                <div class="text-white-50 small">Načítám…</div>
            </div>
        </div>
    </div>
</div>
<style>
.crm-stat-web { cursor: pointer; }
.wv-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.wv-table th { font-size: 11px; letter-spacing: .05em; text-transform: uppercase; color: rgba(233,238,247,.5);
    font-weight: 700; text-align: right; padding: 6px 8px; border-bottom: 1px solid rgba(255,255,255,.12); }
.wv-table th:first-child { text-align: left; }
.wv-table td { padding: 7px 8px; border-bottom: 1px solid rgba(255,255,255,.06); text-align: right; }
.wv-table td:first-child { text-align: left; color: rgba(233,238,247,.75); }
.wv-bar { height: 8px; border-radius: 4px; background: linear-gradient(90deg,#0A84FF,#5AC8FA); min-width: 2px; }
.wv-sum { margin-top: 10px; font-size: 13px; color: rgba(233,238,247,.6); }
</style>
<script>
(function () {
    // POZOR: tenhle blok je v DOM PŘED dlaždicemi (jsou níž na stránce), takže
    // querySelectorAll by v tu chvíli nic nenašel — posluchač musí být delegovaný.
    function esc(x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function open(site) {
        var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('webVisitsModal'));
        var body = document.getElementById('webVisitsBody');
        body.innerHTML = '<div class="text-white-50 small">Načítám…</div>';
        m.show();
        fetch('api/web_visits.php?days=14', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.sites || !d.sites[site]) {
                    body.innerHTML = '<div class="text-white-50 small">Data se nepodařilo načíst.</div>';
                    return;
                }
                var s = d.sites[site];
                document.getElementById('webVisitsWhich').textContent = '· ' + s.label;
                var max = Math.max.apply(null, s.days.map(function (x) { return x.visitors; }).concat([1]));
                body.innerHTML = '<table class="wv-table"><thead><tr><th>Den</th><th>Návštěvníci</th><th>Zobrazení</th><th style="width:38%;"></th></tr></thead><tbody>'
                    + s.days.slice().reverse().map(function (x) {
                        return '<tr><td>' + esc(x.label) + '</td><td><b>' + x.visitors + '</b></td><td>' + x.hits + '</td>'
                            + '<td><div class="wv-bar" style="width:' + Math.round((x.visitors / max) * 100) + '%"></div></td></tr>';
                    }).join('')
                    + '</tbody></table>'
                    + '<div class="wv-sum">Za posledních ' + d.days + ' dní celkem <b>' + s.sum_visitors + '</b> návštěvníků. '
                    + 'Počítá se bez cookies, roboti se nezapočítávají.</div>';
            })
            .catch(function () { body.innerHTML = '<div class="text-white-50 small">Chyba spojení.</div>'; });
    }
    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('.crm-stat-web') : null;
        // setTimeout: kliknutí ještě bublá k document, kde si ho Bootstrap vyhodnotí
        // jako „klik mimo okno" a hned by ho zase zavřel
        if (t && t.dataset.webSite) { var site = t.dataset.webSite; setTimeout(function () { open(site); }, 0); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var t = e.target.closest ? e.target.closest('.crm-stat-web') : null;
        if (t && t.dataset.webSite) { e.preventDefault(); open(t.dataset.webSite); }
    });
})();
</script>

<div class="modal fade" id="eshopSalesModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content glass-card">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-cart-shopping me-2 text-success"></i>Prodeje z e-shopu</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="eshopSalesBody">
                <div class="text-white-50 small">Načítám…</div>
            </div>
        </div>
    </div>
</div>
<script>
// Dlaždice e-shopu je v DOM AŽ ZA tímto skriptem (úkol v „Na řadě") → spustit po načtení stránky.
document.addEventListener('DOMContentLoaded', function () {
    var tile = document.getElementById('eshopTile');
    if (!tile) return;
    var loaded = null;
    function esc(x) { return String(x == null ? '' : x).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function money(v) { return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(v) + ' Kč'; }
    function load() {
        return fetch('api/eshop_dashboard.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return null;
                loaded = d;
                document.getElementById('eshopTileToday').textContent = d.today;
                document.getElementById('eshopTileMonth').textContent = d.month;
                document.getElementById('eshopTileSum').textContent = money(d.month_total || 0);
                var res = document.getElementById('eshopTileRes');
                if (d.reserved > 0) {
                    res.style.display = '';
                    res.innerHTML = '<i class="fas fa-clock me-1"></i>' + d.reserved + ' rezervac' + (d.reserved === 1 ? 'e čeká' : (d.reserved < 5 ? 'e čekají' : 'í čeká')) + ' na zaplacení'
                        + (d.shipped > 0 ? ' · ' + d.shipped + ' na cestě (dobírka)' : '');
                } else if (d.shipped > 0) {
                    res.style.display = '';
                    res.innerHTML = '<i class="fas fa-truck me-1"></i>' + d.shipped + ' dobírk' + (d.shipped === 1 ? 'a' : 'y') + ' na cestě — čeká na platbu';
                } else { res.style.display = 'none'; }
                // zaplacené, ale dosud neodeslané/nepředané — dřív visely „Zaplaceno" navždy
                if (d.to_fulfill > 0) {
                    res.style.display = '';
                    res.innerHTML = (res.innerHTML && res.style.display !== 'none' && (d.reserved > 0 || d.shipped > 0) ? res.innerHTML + ' · ' : '')
                        + '<i class="fas fa-box me-1"></i>' + d.to_fulfill + ' zaplacen' + (d.to_fulfill === 1 ? 'á čeká' : (d.to_fulfill < 5 ? 'é čekají' : 'ých čeká')) + ' na odeslání';
                }
                return d;
            })
            .catch(function () { return null; });
    }
    function render(d) {
        var body = document.getElementById('eshopSalesBody');
        if (!d || !d.orders || !d.orders.length) {
            body.innerHTML = '<div class="text-white-50 small">Zatím žádná objednávka z e-shopu.</div>';
            return;
        }
        var labels = { paid: 'reserved', fulfilled: 'collected', reserved: 'reserved', collected: 'collected', shipped: 'reserved', returned: 'reserved', cancelled: 'reserved' };
        var canManage = tile.dataset.canManage === '1';   // akce smí jen vedení (admin/Boss)
        var btn = function (act, o, cls, ic, label) {
            if (!canManage) { return ''; }
            return '<button type="button" class="btn btn-sm ' + cls + ' eshop-act-btn" data-act="' + act + '" data-id="' + Number(o.id)
                + '" data-ref="' + esc(o.order_ref) + '"><i class="fas ' + ic + ' me-1"></i>' + esc(label) + '</button>';
        };
        body.innerHTML = d.orders.map(function (o) {
            var lb = [labels[o.status] || 'paid', o.status_label || o.status];
            var items = (o.items || []).map(function (i) { return i.qty + '× ' + i.name; }).join('\n');
            return '<div class="eshop-modal-row" data-ref="' + esc(o.order_ref) + '"><div style="min-width:0;">'
                + '<span class="ref">' + esc(o.order_ref) + '</span> <span class="eshop-badge ' + lb[0] + '">' + esc(lb[1]) + '</span>'

                + '<div class="who">' + esc(o.customer) + (o.phone ? ' · ' + esc(o.phone) : '') + ' · ' + esc(o.date) + '</div>'
                + '<div class="items">' + esc(items || '—') + '</div>'
                + (o.customer_note ? '<div class="who" style="white-space:pre-line;overflow-wrap:anywhere;">💬 ' + esc(o.customer_note) + '</div>' : '')
                + (o.waiting_days > 2 ? '<div class="who" style="color:#ffd479;">' + (o.status === 'paid' ? 'zaplaceno a neodesláno už ' : 'čeká už ') + Number(o.waiting_days) + ' dní</div>' : '')
                + (o.tracking_no ? '<div class="who"><i class="fas fa-truck me-1"></i>zásilka ' + esc(o.tracking_no) + '</div>' : '')
                + (o.status === 'fulfilled' && o.fulfill_method === 'pickup' ? '<div class="who"><i class="fas fa-store me-1"></i>předáno na prodejně</div>' : '')
                + '<div class="d-flex gap-2 flex-wrap mt-2">'
                + (o.can_ship ? btn('ship', o, 'btn-primary', 'fa-truck', 'Předáno dopravci — odesláno') : '')
                + (o.can_pay ? btn('paid', o, 'btn-success', 'fa-check', o.status === 'shipped' ? 'Platba od dopravce dorazila' : 'Platba dorazila — uvolnit k odeslání') : '')
                + (o.can_return ? btn('return', o, 'btn-outline-warning', 'fa-rotate-left', 'Nedoručeno — zpět na sklad') : '')
                + (o.can_cancel ? btn('cancel', o, 'btn-outline-danger', 'fa-ban', 'Zrušit rezervaci') : '')
                + (o.can_fulfill ? btn('fulfill-ship', o, 'btn-primary', 'fa-truck', 'Odesláno') : '')
                + (o.can_fulfill ? btn('fulfill-pickup', o, 'btn-outline-success', 'fa-store', 'Předáno na prodejně') : '')
                + '</div></div><div class="amt">' + money(o.total || 0) + '</div></div>';
        }).join('');
        // ruční kroky, které nejdou automaticky (platba mimo párování, expedice dobírky,
        // vrácená zásilka, zrušení rezervace)
        var asks = {
            paid: 'Opravdu je objednávka ORDER zaplacená? U převodu se zboží teď odepíše ze skladu k odeslání.',
            ship: 'Předáváš objednávku ORDER dopravci? Zboží se odepíše ze skladu, platba dorazí od dopravce.',
            'return': 'Vrátila se zásilka k objednávce ORDER? Zboží se vrátí na sklad.',
            cancel: 'Zrušit rezervaci ORDER? Zboží se uvolní zpět do prodeje (na e-shop i kasu).',
            'fulfill-pickup': 'Převzal si zákazník zaplacenou objednávku ORDER na prodejně?'
        };
        body.querySelectorAll('.eshop-act-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                var act = b.dataset.act;
                var fd = new FormData();
                if (act === 'fulfill-ship') {
                    // číslo zásilky je nepovinné (zásilky z PPL v adminu e-shopu se doplní samy)
                    var tr = prompt('Objednávka ' + b.dataset.ref + ' odeslána. Číslo zásilky (nepovinné):', '');
                    if (tr === null) { return; }
                    fd.append('method', 'ship'); fd.append('tracking', tr.trim()); act = 'fulfill';
                } else if (act === 'fulfill-pickup') {
                    if (!confirm(asks[act].replace('ORDER', b.dataset.ref))) { return; }
                    fd.append('method', 'pickup'); act = 'fulfill';
                } else if (!confirm((asks[act] || 'Provést?').replace('ORDER', b.dataset.ref))) { return; }
                b.disabled = true;
                fd.append('order_id', b.dataset.id);
                fd.append('action', act);
                fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
                fetch('api/eshop_order_paid.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) { loaded = null; load().then(render); }
                        else { b.disabled = false; alert((res && res.error) || 'Nepovedlo se.'); }
                    })
                    .catch(function () { b.disabled = false; alert('Síťová chyba.'); });
            });
        });
    }
    function open() {
        var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('eshopSalesModal'));
        document.getElementById('eshopSalesBody').innerHTML = '<div class="text-white-50 small">Načítám…</div>';
        m.show();
        (loaded ? Promise.resolve(loaded) : load()).then(render);
    }
    tile.addEventListener('click', open);
    tile.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    // odkaz z hledání: index.php#eshop-AFX-XXXX → otevřít okno a najet na objednávku
    function fromHash() {
        var m = /^#eshop(?:-(.+))?$/.exec(location.hash || '');
        if (!m) { return; }
        var ref = m[1] ? decodeURIComponent(m[1]) : '';
        var mm = bootstrap.Modal.getOrCreateInstance(document.getElementById('eshopSalesModal'));
        document.getElementById('eshopSalesBody').innerHTML = '<div class="text-white-50 small">Načítám…</div>';
        mm.show();
        (loaded ? Promise.resolve(loaded) : load()).then(function (d) {
            render(d);
            if (!ref) { return; }
            var row = Array.prototype.find.call(document.querySelectorAll('#eshopSalesBody .eshop-modal-row'), function (r) { return r.dataset.ref === ref; });
            if (row) { row.scrollIntoView({ block: 'center' }); row.style.outline = '2px solid #0a84ff'; row.style.borderRadius = '12px'; }
        });
        history.replaceState(null, '', location.pathname + location.search);
    }
    window.addEventListener('hashchange', fromHash);
    load();
    fromHash();
});
</script>
<?php endif; ?>
<?php
/* Nástěnka v2.1 (3.98.0) — zjednodušené rozvržení: 4 čísla dne, jeden pruh
   „V servisu", krátký seznam Na řadě (podrobnosti až po rozkliknutí). */
$__flowTotal = array_sum(array_column($D['flow'], 'n'));
$__drawers = array_sum(array_map(fn($m) => (float)($m['drawer'] ?? 0), $D['money']));
$__monDat = ['lednu', 'únoru', 'březnu', 'dubnu', 'květnu', 'červnu', 'červenci', 'srpnu', 'září', 'říjnu', 'listopadu', 'prosinci'];
$__prevMon = $__monDat[((int)date('n') + 10) % 12];
$__split = function (string $k) use ($D, $__showSplit, $dshMoney) {
    if (!$__showSplit) { return ''; }
    return ' title="' . e(implode(' · ', array_map(fn($m) => $m['name'] . ' ' . $dshMoney($m[$k]), $D['money']))) . '"';
};
$__plural = fn(int $n, string $one, string $few, string $many) => $n === 1 ? $one : ($n >= 2 && $n <= 4 ? $few : $many);
?>
<div class="dsh" id="dsh">
    <header class="dsh-top">
        <div class="dsh-hello">
            <h1 class="dsh-date"><?php echo e(mb_convert_case($__dayNames[(int)date('w')], MB_CASE_TITLE, 'UTF-8') . ' ' . date('j') . '. ' . $__monNames[(int)date('n') - 1]); ?></h1>
            <div class="dsh-greet"><?php echo e($__greet); ?><?php echo $__me !== '' ? ', ' . e(explode(' ', $__me)[0]) : ''; ?><?php if (!$D['can_pick']): ?> · <?php echo e($D['scope_label']); ?><?php endif; ?></div>
        </div>
        <?php if ($D['can_pick'] && count($D['all_branches']) > 1): ?>
        <nav class="dsh-seg" aria-label="Pobočka">
            <a class="<?php echo $D['pick'] === 0 ? 'is-on' : ''; ?>" href="index.php?branch=0">Obě pobočky</a>
            <?php foreach ($D['all_branches'] as $__bid => $__bn): ?>
            <a class="<?php echo $D['pick'] === (int)$__bid ? 'is-on' : ''; ?>" href="index.php?branch=<?php echo (int)$__bid; ?>"><?php echo e(crmDashBranchShort($__bn)); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <div class="dsh-actions" role="toolbar" aria-label="Rychlé akce">
            <button type="button" class="dsh-btn is-primary dsh-dup" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus"></i>Nová zakázka</button>
            <a class="dsh-btn dsh-dup" href="pokladna.php">Pokladna</a>
            <a class="dsh-btn" href="dokument.php?type=vykup" target="_blank" rel="noopener">Výkup</a>
            <a class="dsh-btn" href="dokument.php?type=zastava" target="_blank" rel="noopener">Zástava</a>
            <button type="button" class="dsh-btn" data-dsh-imei>Kontrola IMEI</button>
        </div>
    </header>

    <?php /* ── Čtyři čísla dne. Rozpad po pobočkách je v bublině (najetí myší). ── */ ?>
    <section class="dsh-stats" aria-label="Dnes">
        <div class="dsh-stat"<?php echo $__split('done_sum'); ?>>
            <div class="dsh-stat-l">Tržba dnes</div>
            <div class="dsh-stat-v"><?php echo $dshMoney($__mt['done_sum']); ?></div>
            <div class="dsh-stat-s">včera <?php echo $dshMoney($__mt['done_yday']); ?></div>
        </div>
        <a class="dsh-stat" href="pokladna.php"<?php echo $__split('pos_sum'); ?>>
            <div class="dsh-stat-l">Pokladna dnes</div>
            <div class="dsh-stat-v"><?php echo $dshMoney($__mt['pos_sum']); ?></div>
            <div class="dsh-stat-s">v kase <?php echo $dshMoney($__drawers); ?></div>
        </a>
        <div class="dsh-stat"<?php echo $__split('month'); ?>>
            <div class="dsh-stat-l">Tento měsíc</div>
            <div class="dsh-stat-v"><?php echo $dshMoney($__mt['month']); ?></div>
            <div class="dsh-stat-s"><?php if ($D['month_pace'] !== null): ?><b class="<?php echo $D['month_pace'] >= 0 ? 'up' : 'down'; ?>"><?php echo ($D['month_pace'] > 0 ? '+' : '') . $D['month_pace']; ?> %</b> proti <?php echo e($__prevMon); ?><?php else: ?>z hotových zakázek<?php endif; ?></div>
        </div>
        <div class="dsh-stat">
            <div class="dsh-stat-l">Zakázky dnes</div>
            <div class="dsh-stat-v"><?php echo (int)$__mt['done_n']; ?> <em><?php echo $__plural((int)$__mt['done_n'], 'hotová', 'hotové', 'hotových'); ?></em></div>
            <div class="dsh-stat-s"><?php echo (int)$D['new_today']; ?> <?php echo $__plural((int)$D['new_today'], 'nově přijatá', 'nově přijaté', 'nově přijatých'); ?></div>
        </div>
    </section>

    <?php /* ── V servisu: jeden pruh poměrů + legenda (klik = seznam s filtrem) ── */ ?>
    <section class="dsh-flow" aria-label="Zakázky v servisu">
        <div class="dsh-flow-h">
            <div><b><?php echo (int)$__flowTotal; ?></b> <?php echo $__plural((int)$__flowTotal, 'zakázka', 'zakázky', 'zakázek'); ?> v servisu</div>
            <a href="orders.php">Všechny zakázky <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="dsh-bar" role="img" aria-label="Rozložení zakázek podle stavu">
            <?php foreach ($D['flow'] as $f): if ($f['n'] <= 0) { continue; } ?>
            <a class="t-<?php echo e($f['tone']); ?>" style="flex-grow:<?php echo (int)$f['n']; ?>" href="orders.php?filter=<?php echo urlencode($f['filter']); ?>&group=1" title="<?php echo e($f['label'] . ': ' . $f['n']); ?>"></a>
            <?php endforeach; ?>
        </div>
        <div class="dsh-legend">
            <?php foreach ($D['flow'] as $f): ?>
            <a class="t-<?php echo e($f['tone']); ?><?php echo $f['n'] === 0 ? ' is-zero' : ''; ?>" href="orders.php?filter=<?php echo urlencode($f['filter']); ?>&group=1">
                <i aria-hidden="true"></i><?php echo e($f['label']); ?> <b><?php echo (int)$f['n']; ?></b><?php if ($f['leg'] > 0): ?> <small title="Importované (staré) zakázky ze zakazkovylist.cz — v čísle nejsou">(<?php echo (int)$f['leg']; ?>)</small><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="dsh-grid">
        <div class="dsh-main">
            <?php /* ── Na řadě: jeden řádek = jeden druh úkolu, podrobnosti po rozkliknutí ── */ ?>
            <section class="dsh-card dsh-todo">
                <h2 class="dsh-h">Na řadě</h2>
                <?php if (!$D['tasks']): ?><div class="dsh-empty">Všechno vyřízeno, nic nečeká.</div><?php endif; ?>
                <?php foreach ($D['tasks'] as $t): $__rows = $t['rows'] ?? []; ?>
                <?php if (!empty($t['eshop'])): ?>
                <div class="dsh-todo-i t-<?php echo e($t['tone']); ?>" id="eshopTile" role="button" tabindex="0" data-can-manage="<?php echo crmCanDeleteOrders() ? '1' : '0'; ?>">
                    <div class="dsh-todo-row">
                        <b class="dsh-todo-n"><?php echo (int)$t['count']; ?></b>
                        <div class="dsh-todo-t"><b><?php echo e($t['title']); ?></b> <em><?php echo e($t['hint']); ?><i hidden><i id="eshopTileToday"></i><i id="eshopTileMonth"></i><i id="eshopTileSum"></i><i id="eshopTileRes"></i></i></em></div>
                        <i class="fas fa-chevron-right dsh-todo-chev"></i>
                    </div>
                </div>
                <?php continue; endif; ?>
                <details class="dsh-todo-i t-<?php echo e($t['tone']); ?>">
                    <summary class="dsh-todo-row">
                        <b class="dsh-todo-n"><?php echo (int)$t['count']; ?></b>
                        <div class="dsh-todo-t"><b><?php echo e($t['title']); ?></b> <em><?php echo e($t['hint']); ?></em></div>
                        <i class="fas fa-chevron-down dsh-todo-chev"></i>
                    </summary>
                    <div class="dsh-todo-list">
                        <?php foreach ($__rows as $__ri => $r):
                            $__href = $r['href'] ?? ($r['id'] ? 'view_order.php?id=' . (int)$r['id'] : '');
                            $__tel = normalizePhoneForTel($r['phone'] ?? ''); ?>
                        <div class="dsh-tr<?php echo !empty($r['past']) ? ' is-past' : ''; ?><?php echo $__ri >= 8 ? ' is-more' : ''; ?>">
                            <?php if ($__href): ?><a class="dsh-o-link" href="<?php echo e($__href); ?>" aria-label="Otevřít <?php echo e($r['code'] ?: $r['client']); ?>"></a><?php endif; ?>
                            <em class="dsh-tr-age<?php echo !empty($r['high']) ? ' is-hot' : ''; ?>"><?php echo e($r['age']); ?></em>
                            <div class="dsh-tr-main"><b><?php echo e($r['client'] ?: '—'); ?></b> <em><?php echo e(trim($r['device'] . ($r['code'] ? ' · ' . $r['code'] : ''), ' ·')); ?></em></div>
                            <em class="dsh-tr-info"><?php echo e($r['info']); ?></em>
                            <?php if ($__tel !== ''): ?><a class="dsh-call" href="tel:<?php echo e($__tel); ?>" title="Zavolat <?php echo e($r['phone']); ?>" aria-label="Zavolat"><i class="fas fa-phone"></i></a><?php else: ?><i></i><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php
                        // Žádná slepá „a dalších N": zbytek načtených se rozbalí na místě,
                        // a co se nenačetlo, je v seznamu (vlastní odkaz úkolu, jinak Zakázky).
                        $__link = $t['link'] ?? '';
                        if ($__link === '' && $t['count'] > count($__rows)) { $__link = 'orders.php'; }
                        if (count($__rows) > 8 || $__link !== ''): ?>
                        <div class="dsh-todo-foot">
                            <?php if (count($__rows) > 8): ?><button type="button" class="dsh-linkbtn" data-dsh-more>Zobrazit další (<?php echo count($__rows) - 8; ?>)</button><?php endif; ?>
                            <?php if ($__link !== ''): ?><a href="<?php echo e($__link); ?>">Otevřít <?php echo $t['count'] > 8 ? 'všech ' . (int)$t['count'] : 'seznam'; ?> <i class="fas fa-arrow-right"></i></a><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </section>

            <?php /* ── Zakázky: Moje práce (technik) / Nejnovější / výsledky hledání ── */ ?>
            <section class="dsh-card dsh-orders">
                <div class="dsh-orders-h">
                    <?php if ($search !== '' || $filter_status): ?>
                        <h2 class="dsh-h"><?php echo $filter_status ? e(getOrderStatusLabel($filter_status)) : 'Hledání: ' . e($search); ?></h2>
                        <a class="dsh-more" href="index.php">Zrušit</a>
                    <?php else: ?>
                    <?php if ($D['tech_id'] > 0): ?>
                    <div class="dsh-seg dsh-seg--tabs" role="tablist">
                        <button type="button" class="is-on" data-dsh-tab="mine" role="tab" aria-selected="true">Moje práce <small><?php echo (int)$D['mine_count']; ?></small></button>
                        <button type="button" data-dsh-tab="recent" role="tab" aria-selected="false">Nejnovější</button>
                    </div>
                    <?php else: ?>
                    <h2 class="dsh-h">Nejnovější zakázky</h2>
                    <?php endif; ?>
                    <a class="dsh-more" href="orders.php">Všechny zakázky <i class="fas fa-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
                <?php if ($D['tech_id'] > 0 && $search === '' && !$filter_status): ?>
                <div class="dsh-olist" data-dsh-pane="mine">
                    <?php foreach (array_slice($D['mine'], 0, 12) as $r) { echo $dshOrderRow($r); } ?>
                    <?php if (!$D['mine']): ?><div class="dsh-empty">Nemáš přiřazenou žádnou rozpracovanou zakázku.</div><?php endif; ?>
                    <?php if ($D['mine_count'] > 12): ?><a class="dsh-more dsh-olist-more" href="orders.php?tech=<?php echo (int)$D['tech_id']; ?>">Všech <?php echo (int)$D['mine_count']; ?> mých zakázek <i class="fas fa-arrow-right"></i></a><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="dsh-olist" data-dsh-pane="recent"<?php echo ($D['tech_id'] > 0 && $search === '' && !$filter_status) ? ' hidden' : ''; ?>>
                    <?php foreach (array_slice($recent, 0, 12) as $r) { echo $dshOrderRow($r); } ?>
                    <?php if (!$recent): ?><div class="dsh-empty"><?php echo __('not_found'); ?></div><?php endif; ?>
                </div>
            </section>
            <?php
            /* Hledání přes index.php?search= najde i SKLAD (kus podle IMEI/kódu/názvu). */
            if ($search !== '' && hasPermission('manage_inventory')):
                $sk_term = "%$search%";
                $st = $pdo->prepare("SELECT id, title, product_code, price, stock_qty, grade, branch_id, COALESCE(is_vykup,0) AS is_vykup
                    FROM products WHERE title LIKE ? OR product_code LIKE ? OR model LIKE ?
                    ORDER BY (stock_qty > 0) DESC, added_at DESC, id DESC LIMIT 8");
                $st->execute([$sk_term, $sk_term, $sk_term]);
                $sk_products = $st->fetchAll();
                $st = $pdo->prepare("SELECT id, part_name, sku, quantity, sale_price FROM inventory WHERE part_name LIKE ? OR sku LIKE ?
                    ORDER BY (quantity > 0) DESC, part_name ASC LIMIT 6");
                $st->execute([$sk_term, $sk_term]);
                $sk_parts = $st->fetchAll();
                if ($sk_products || $sk_parts): ?>
            <section class="dsh-card">
                <h2 class="dsh-h"><?php echo __('inventory'); ?></h2>
                <div class="dsh-olist">
                    <?php foreach ($sk_products as $sp): ?>
                    <div class="dsh-o dsh-o--stock">
                        <a class="dsh-o-link" href="products.php?branch=<?php echo (int)($sp['branch_id'] ?: 1); ?><?php echo (int)$sp['is_vykup'] === 1 ? '&cat=vykupy' : ''; ?>&search=<?php echo urlencode((string)$sp['product_code']); ?>" aria-label="<?php echo e($sp['title']); ?>"></a>
                        <div class="dsh-o-who"><b><?php echo e($sp['title']); ?></b><em><?php echo e($sp['product_code']); ?><?php echo !empty($sp['grade']) ? ' · ' . e($sp['grade']) : ''; ?> · <?php echo e(crmDashBranchShort(getBranchLabel((int)$sp['branch_id']))); ?></em></div>
                        <div class="dsh-o-st"><b class="dsh-pill <?php echo (int)$sp['stock_qty'] > 0 ? 't-completed' : 't-cancelled'; ?>"><?php echo (int)$sp['stock_qty'] > 0 ? 'Skladem ' . (int)$sp['stock_qty'] . ' ks' : 'Vyprodáno'; ?></b></div>
                        <div class="dsh-o-amt"><?php echo formatMoney((float)$sp['price']); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php foreach ($sk_parts as $pp): ?>
                    <div class="dsh-o dsh-o--stock">
                        <a class="dsh-o-link" href="inventory.php?search=<?php echo urlencode((string)$pp['sku']); ?>" aria-label="<?php echo e($pp['part_name']); ?>"></a>
                        <div class="dsh-o-who"><b><?php echo e($pp['part_name']); ?></b><em>Díl · <?php echo e($pp['sku']); ?></em></div>
                        <div class="dsh-o-st"><b class="dsh-pill <?php echo (int)$pp['quantity'] > 0 ? 't-completed' : 't-cancelled'; ?>"><?php echo (int)$pp['quantity'] > 0 ? 'Skladem ' . (int)$pp['quantity'] . ' ks' : 'Vyprodáno'; ?></b></div>
                        <div class="dsh-o-amt"><?php echo formatMoney((float)$pp['sale_price']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; endif; ?>
        </div>

        <aside class="dsh-side">
            <section class="dsh-card">
                <h2 class="dsh-h">Tým dnes</h2>
                <?php if (!$D['team']): ?><div class="dsh-empty">Zatím nikdo přihlášený.</div><?php endif; ?>
                <ul class="dsh-team">
                    <?php foreach ($D['team'] as $p): ?>
                    <li class="<?php echo $p['online'] ? 'is-on' : ''; ?>">
                        <i class="dsh-dot" aria-hidden="true"></i>
                        <b><?php echo e($p['name']); ?></b>
                        <em><?php echo $p['kasa'] ? 'má kasu' : ($p['plan'] !== '' ? $p['plan'] : ($p['online'] ? '' : $p['seen'])); ?></em>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="dsh-kasa">
                    <?php foreach ($D['money'] as $m): $__sh = $m['shift']; $__old = $__sh && strtotime($__sh['at']) < strtotime('today'); ?>
                    <div class="<?php echo $__old ? 'is-warn' : ''; ?>">
                        <em>Kasa<?php echo count($D['money']) > 1 ? ' ' . e($m['name']) : ''; ?></em>
                        <b><?php echo $m['drawer'] !== null ? $dshMoney($m['drawer']) : '—'; ?></b>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php /* Kontrola IMEI je od v4.1.0 v horní liště; tahle karta zůstává jen pro mobil/iPad (≤1080 px) */ ?>
            <section class="dsh-card dsh-imei-card" id="dshImei">
                <h2 class="dsh-h"><?php echo __('imei_check_title'); ?></h2>
                <form id="imeiCheckForm" class="dsh-imei" data-imei-check data-result="#imeiCheckResult" title="<?php echo e(__('imei_help_text')); ?>">
                    <input type="text" class="form-control" id="imeiCheckInput" placeholder="IMEI" inputmode="numeric" autocomplete="off" maxlength="15" aria-label="<?php echo e(__('serial')); ?>">
                    <button class="dsh-btn" type="submit"><?php echo __('check'); ?></button>
                </form>
                <div id="imeiCheckResult" class="imei-check-result" aria-live="polite"></div>
            </section>

            <?php if ($__web): ?>
            <section class="dsh-card">
                <h2 class="dsh-h">Návštěvnost webů dnes</h2>
                <?php foreach ($__web as $__wk => $__w): ?>
                <div class="dsh-web crm-stat-web" role="button" tabindex="0" data-web-site="<?php echo e($__wk); ?>" title="Klikni pro posledních 14 dní">
                    <em><?php echo e($__w['label']); ?></em>
                    <b><?php echo (int)$__w['today']; ?></b>
                    <small>včera <?php echo (int)$__w['yday']; ?></small>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <a class="dsh-more dsh-stats-link" href="reports.php">Statistiky a grafy <i class="fas fa-arrow-right"></i></a>
        </aside>
    </div>
</div>

<script>
(function () {
    // přepínání Moje práce / Nejnovější (volba se pamatuje v prohlížeči)
    var tabs = document.querySelectorAll('[data-dsh-tab]');
    function show(name) {
        tabs.forEach(function (t) { t.classList.toggle('is-on', t.dataset.dshTab === name); t.setAttribute('aria-selected', t.dataset.dshTab === name); });
        document.querySelectorAll('[data-dsh-pane]').forEach(function (p) { p.hidden = p.dataset.dshPane !== name; });
        try { localStorage.setItem('dsh-tab', name); } catch (e) {}
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.dshTab); }); });
    try { var saved = localStorage.getItem('dsh-tab'); if (saved && document.querySelector('[data-dsh-tab="' + saved + '"]')) { show(saved); } } catch (e) {}

    // Na řadě: otevřený je vždy jen jeden úkol, ať seznam zůstane krátký. Zavřením úkolu
    // NAD tím rozkliknutým by stránka poskočila (na telefonu i o celou obrazovku) —
    // proto se posun vyrovná, aby rozkliknutý řádek zůstal tam, kde byl pod prstem.
    function scroller(el) {
        for (var p = el.parentElement; p; p = p.parentElement) {
            var oy = getComputedStyle(p).overflowY;
            if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight) { return p; }
        }
        return document.scrollingElement || document.documentElement;
    }
    document.querySelectorAll('details.dsh-todo-i').forEach(function (d) {
        d.addEventListener('toggle', function () {
            if (!d.open) { return; }
            var sum = d.querySelector('summary');
            var before = sum.getBoundingClientRect().top;
            document.querySelectorAll('details.dsh-todo-i[open]').forEach(function (o) { if (o !== d) { o.open = false; } });
            var delta = sum.getBoundingClientRect().top - before;
            if (Math.abs(delta) > 1) { scroller(d).scrollBy({ top: delta, behavior: 'instant' }); }   // bez animace (Bootstrap má smooth)
        });
    });
    document.addEventListener('click', function (e) {
        var more = e.target.closest('[data-dsh-more]');
        if (more) {
            more.closest('details').querySelectorAll('.dsh-tr.is-more').forEach(function (r) { r.classList.remove('is-more'); });
            more.remove();
            return;
        }
        if (e.target.closest('[data-dsh-imei]')) {
            var top = document.getElementById('afxImeiInput');
            if (top && top.offsetParent) { window.scrollTo({ top: 0, behavior: 'smooth' }); top.focus(); return; }   // lišta (počítač)
            document.getElementById('dshImei').scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function () { document.getElementById('imeiCheckInput').focus(); }, 350);
        }
    });
})();
</script>

<?php /* Kontrola IMEI: logika je v assets/js/imei-check.js (v4.1.0) */ ?>

<?php require_once 'includes/footer.php'; ?>

