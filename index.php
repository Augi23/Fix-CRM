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
$D = crmDashboardBuild($pdo);
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
$dshTrend = function (?int $p, string $vs) {
    if ($p === null) { return ''; }
    $cls = $p > 0 ? 'up' : ($p < 0 ? 'down' : '');
    return '<b class="dsh-trend ' . $cls . '">' . ($p > 0 ? '↑ ' : ($p < 0 ? '↓ ' : '')) . abs($p) . ' %</b> <em>' . e($vs) . '</em>';
};
/** Řádek zakázky (Moje / Nejnovější) — stejné údaje jako seznam zakázek, ale kompaktně. */
$dshOrderRow = function (array $r) {
    [$staleCls, $staleTitle] = orderStaleRowAttrs($r);
    $tok = getOrderStatusBadgeToken($r['status']);
    $phone = normalizePhoneForTel($r['phone'] ?? '');
    $legacy = ($r['source'] ?? 'crm') === 'legacy';
    $internal = crmIsInternalCustomer($r['customer_id'] ?? 0);
    $amount = (float)($r['final_cost'] ?: $r['estimated_cost']);
    ob_start(); ?>
    <div class="dsh-o<?php echo $legacy ? ' is-legacy' : ''; ?><?php echo $staleCls ? ' is-stale' : ''; ?>"<?php echo $staleTitle ? ' title="' . e($staleTitle) . '"' : ''; ?>>
        <a class="dsh-o-link" href="view_order.php?id=<?php echo (int)$r['id']; ?>" aria-label="Zakázka <?php echo e(orderDisplayCode($r)); ?>"></a>
        <div class="dsh-o-code">
            <b><?php echo e(orderDisplayCode($r)); ?></b>
            <em><?php echo e(date('j. n. H:i', strtotime((string)$r['created_at']))); ?><?php if ($legacy): ?> <i class="dsh-o-leg" title="Importovaná (stará) zakázka ze zakazkovylist.cz">(import)</i><?php endif; ?></em>
        </div>
        <div class="dsh-o-who">
            <b><?php echo $internal ? '<i class="fas fa-screwdriver-wrench"></i> Interní' : e(trim($r['first_name'] . ' ' . $r['last_name'])); ?></b>
            <em><?php echo e(crmDashDevice($r)); ?></em>
        </div>
        <div class="dsh-o-prob"><?php echo e((string)($r['problem_description'] ?? '')); ?></div>
        <div class="dsh-o-st">
            <b class="dsh-pill t-<?php echo e($tok); ?>"><?php echo e(getOrderStatusLabel((string)$r['status'])); ?></b>
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
<link rel="stylesheet" href="assets/css/dashboard.css?v=3.97.0">
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
(function () {
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
})();
</script>
<?php endif; ?>
<div class="dsh">
    <?php /* ── 1) Den + rychlé akce ── */ ?>
    <section class="dsh-hero">
        <div class="dsh-hello">
            <div class="dsh-greet"><?php echo e($__greet); ?><?php echo $__me !== '' ? ', ' . e($__me) : ''; ?></div>
            <h1 class="dsh-date"><?php echo e(mb_convert_case($__dayNames[(int)date('w')], MB_CASE_TITLE, 'UTF-8') . ' ' . date('j') . '. ' . $__monNames[(int)date('n') - 1]); ?></h1>
            <div class="dsh-scope"><i class="fas fa-store"></i> <?php echo e($D['scope_label']); ?></div>
        </div>
        <div class="dsh-actions" role="toolbar" aria-label="Rychlé akce">
            <button type="button" class="dsh-act is-primary" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus"></i><b>Nová zakázka</b></button>
            <a class="dsh-act" href="pokladna.php"><i class="fas fa-cash-register"></i><b>Pokladna</b></a>
            <a class="dsh-act" href="dokument.php?type=vykup" target="_blank" rel="noopener"><i class="fas fa-file-signature"></i><b>Výkup</b></a>
            <a class="dsh-act" href="dokument.php?type=zastava" target="_blank" rel="noopener"><i class="fas fa-file-contract"></i><b>Zástava</b></a>
            <button type="button" class="dsh-act" data-dsh-imei><i class="fas fa-shield-halved"></i><b>Kontrola IMEI</b></button>
        </div>
    </section>

    <?php /* ── 2) Peníze dne — admin/Boss obě pobočky s rozpadem, ostatní jen svou ── */ ?>
    <section class="dsh-kpis">
        <div class="dsh-kpi">
            <div class="dsh-kpi-label">Tržba dnes</div>
            <div class="dsh-kpi-value"><?php echo $dshMoney($__mt['done_sum']); ?></div>
            <div class="dsh-kpi-sub"><?php echo (int)$__mt['done_n']; ?> <?php echo $__mt['done_n'] === 1 ? 'hotová zakázka' : ($__mt['done_n'] >= 2 && $__mt['done_n'] <= 4 ? 'hotové zakázky' : 'hotových zakázek'); ?>
                <?php echo $D['day_trend'] !== null ? ' · ' . $dshTrend($D['day_trend'], 'vs včera') : ''; ?></div>
            <?php if ($__showSplit): ?><div class="dsh-split">
                <?php foreach ($D['money'] as $m): ?><div><em><?php echo e($m['name']); ?></em><b><?php echo $dshMoney($m['done_sum']); ?></b></div><?php endforeach; ?>
            </div><?php endif; ?>
        </div>
        <div class="dsh-kpi">
            <div class="dsh-kpi-label">Pokladna dnes</div>
            <div class="dsh-kpi-value"><?php echo $dshMoney($__mt['pos_sum']); ?></div>
            <div class="dsh-kpi-sub">hotově <?php echo $dshMoney($__mt['pos_cash']); ?> · kartou <?php echo $dshMoney($__mt['pos_card']); ?><?php echo $__mt['pos_other'] > 0 ? ' · fakturou ' . $dshMoney($__mt['pos_other']) : ''; ?></div>
            <div class="dsh-split dsh-kasa">
                <?php foreach ($D['money'] as $m):
                    $__sh = $m['shift'];
                    $__old = $__sh && strtotime($__sh['at']) < strtotime('today'); ?>
                <div class="<?php echo !$__sh ? 'is-off' : ($__old ? 'is-warn' : ''); ?>">
                    <em><?php echo $__showSplit ? e($m['name']) . ' · ' : ''; ?><?php if ($__sh): ?><i class="fas fa-user-lock"></i> <?php echo e($__sh['by']); ?> od <?php echo e(date($__old ? 'j. n.' : 'H:i', strtotime($__sh['at']))); ?><?php else: ?>kasa nepřevzatá<?php endif; ?></em>
                    <b title="Hotovost v kase podle pokladní knihy"><?php echo $m['drawer'] !== null ? $dshMoney($m['drawer']) : '—'; ?></b>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="dsh-kpi">
            <div class="dsh-kpi-label">Tento měsíc</div>
            <div class="dsh-kpi-value"><?php echo $dshMoney($__mt['month']); ?></div>
            <div class="dsh-kpi-sub"><?php echo $D['month_pace'] !== null ? $dshTrend($D['month_pace'], 'vs stejné dny minulého měsíce') : 'tržba z hotových zakázek'; ?></div>
            <div class="dsh-split">
                <?php if ($__showSplit): foreach ($D['money'] as $m): ?><div><em><?php echo e($m['name']); ?></em><b><?php echo $dshMoney($m['month']); ?></b></div><?php endforeach; endif; ?>
                <div title="Zálohy nejsou tržba — výnos vzniká až dokončením zakázky."><em>Přijato na fakturách<?php echo $D['advance_month'] > 0 ? ' (z toho zálohy ' . $dshMoney($D['advance_month']) . ')' : ''; ?></em><b><?php echo $dshMoney($D['received_month']); ?></b></div>
            </div>
        </div>
        <?php if (crmCanManageProducts()): ?>
        <div class="dsh-kpi dsh-kpi--eshop" id="eshopTile" role="button" tabindex="0" title="Klikni pro objednávky z e-shopu"
             data-can-manage="<?php echo crmCanDeleteOrders() ? '1' : '0'; ?>">
            <div class="dsh-kpi-label">E-shop dnes</div>
            <div class="dsh-kpi-value"><b id="eshopTileToday">–</b> <em>prodáno</em></div>
            <div class="dsh-kpi-sub"><b id="eshopTileMonth">–</b> tento měsíc · <b id="eshopTileSum">–</b></div>
            <div class="dsh-kpi-note" id="eshopTileRes" style="display:none;"></div>
            <div class="dsh-kpi-more">Objednávky <i class="fas fa-chevron-right"></i></div>
        </div>
        <?php else: ?>
        <a class="dsh-kpi" href="orders.php">
            <div class="dsh-kpi-label">Přijato dnes</div>
            <div class="dsh-kpi-value"><?php echo (int)$D['new_today']; ?> <em>zakázek</em></div>
            <div class="dsh-kpi-sub">nové zakázky od rána</div>
        </a>
        <?php endif; ?>
    </section>

    <?php /* ── 3) Tok zakázek — kde co stojí (klik = seznam s filtrem) ── */ ?>
    <nav class="dsh-flow" aria-label="Zakázky podle stavu">
        <?php foreach ($D['flow'] as $i => $f): ?>
        <a class="dsh-step t-<?php echo e($f['tone']); ?><?php echo $f['n'] === 0 ? ' is-zero' : ''; ?>" href="orders.php?filter=<?php echo urlencode($f['filter']); ?>">
            <b class="dsh-step-n"><?php echo (int)$f['n']; ?></b>
            <em class="dsh-step-l"><?php echo e($f['label']); ?></em>
            <?php if ($f['leg'] > 0): ?><em class="dsh-step-leg" title="Importované (staré) zakázky ze zakazkovylist.cz — v hlavním čísle nejsou">(<?php echo (int)$f['leg']; ?>)</em><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php if ($D['new_today'] > 0 && crmCanManageProducts()): ?>
        <div class="dsh-flow-today"><i class="fas fa-arrow-trend-up"></i> dnes přijato <b><?php echo (int)$D['new_today']; ?></b></div>
        <?php endif; ?>
    </nav>

    <div class="dsh-grid">
        <div class="dsh-main">
            <?php /* ── 4) Na řadě — úkoly, které čekají na člověka ── */ ?>
            <section class="dsh-card dsh-tasks">
                <header class="dsh-card-h">
                    <h2><i class="fas fa-list-check"></i> Na řadě</h2>
                    <em><?php echo $D['tasks'] ? 'seřazeno podle naléhavosti' : ''; ?></em>
                </header>
                <?php if (!$D['tasks']): ?>
                    <div class="dsh-empty"><i class="fas fa-circle-check"></i> Všechno vyřízeno — nic nečeká.</div>
                <?php endif; ?>
                <?php foreach ($D['tasks'] as $ti => $t):
                    $__rows = $t['rows'] ?? [];
                    $__open = $ti < 2 && $__rows && $t['key'] !== 'forgotten'; ?>
                <details class="dsh-task t-<?php echo e($t['tone']); ?>"<?php echo $__open ? ' open' : ''; ?><?php echo !empty($t['eshop']) ? ' data-eshop-open' : ''; ?>>
                    <summary>
                        <i class="fas <?php echo e($t['icon']); ?> dsh-task-ic"></i>
                        <div class="dsh-task-t"><b><?php echo e($t['title']); ?></b><em><?php echo e($t['hint']); ?></em></div>
                        <b class="dsh-task-n"><?php echo (int)$t['count']; ?></b>
                        <i class="fas fa-chevron-down dsh-task-chev"></i>
                    </summary>
                    <?php if ($__rows): ?>
                    <div class="dsh-task-rows">
                        <?php foreach ($__rows as $ri => $r):
                            $__href = $r['href'] ?? ($r['id'] ? 'view_order.php?id=' . (int)$r['id'] : '');
                            $__tel = normalizePhoneForTel($r['phone'] ?? ''); ?>
                        <div class="dsh-tr<?php echo $ri >= 3 ? ' is-more' : ''; ?><?php echo !empty($r['high']) ? ' is-hot' : ''; ?><?php echo !empty($r['past']) ? ' is-past' : ''; ?>">
                            <?php if ($__href): ?><a class="dsh-o-link" href="<?php echo e($__href); ?>" aria-label="Otevřít <?php echo e($r['code'] ?: $r['client']); ?>"></a><?php endif; ?>
                            <b class="dsh-tr-age"><?php echo e($r['age']); ?></b>
                            <div class="dsh-tr-main">
                                <b><?php echo e($r['client'] ?: '—'); ?></b>
                                <em><?php echo e(trim($r['device'] . ($r['code'] ? ' · ' . $r['code'] : ''), ' ·')); ?></em>
                            </div>
                            <em class="dsh-tr-info"><?php echo e($r['info']); ?></em>
                            <?php if ($__tel !== ''): ?><a class="dsh-call" href="tel:<?php echo e($__tel); ?>" title="Zavolat <?php echo e($r['phone']); ?>" aria-label="Zavolat"><i class="fas fa-phone"></i></a><?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dsh-task-foot">
                        <?php if (count($__rows) > 3): ?><button type="button" class="dsh-link" data-dsh-more>Zobrazit další (<?php echo count($__rows) - 3; ?>)</button><?php endif; ?>
                        <?php if (!empty($t['link'])): ?><a class="dsh-link" href="<?php echo e($t['link']); ?>">Otevřít seznam<?php echo $t['count'] > count($__rows) ? ' (všech ' . (int)$t['count'] . ')' : ''; ?> <i class="fas fa-arrow-right"></i></a>
                        <?php elseif ($t['count'] > count($__rows)): ?><em>zobrazeno <?php echo count($__rows); ?> z <?php echo (int)$t['count']; ?></em><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </details>
                <?php endforeach; ?>
            </section>

            <?php /* ── 5) Zakázky — moje práce (technik) / nejnovější / výsledky hledání ── */ ?>
            <section class="dsh-card dsh-orders">
                <header class="dsh-card-h">
                    <?php if ($search !== '' || $filter_status): ?>
                        <h2><i class="fas fa-magnifying-glass"></i> <?php echo $filter_status ? e(getOrderStatusLabel($filter_status)) : 'Hledání: ' . e($search); ?></h2>
                        <a class="dsh-link" href="index.php">Zrušit</a>
                    <?php else: ?>
                    <div class="dsh-tabs" role="tablist">
                        <?php if ($D['tech_id'] > 0): ?>
                        <button type="button" class="dsh-tab is-on" data-dsh-tab="mine" role="tab"><i class="fas fa-user-gear"></i> Moje práce <b><?php echo (int)$D['mine_count']; ?></b></button>
                        <?php endif; ?>
                        <button type="button" class="dsh-tab<?php echo $D['tech_id'] > 0 ? '' : ' is-on'; ?>" data-dsh-tab="recent" role="tab"><i class="fas fa-clock-rotate-left"></i> Nejnovější</button>
                    </div>
                    <a class="dsh-link" href="orders.php">Všechny zakázky <i class="fas fa-arrow-right"></i></a>
                    <?php endif; ?>
                </header>
                <?php if ($D['tech_id'] > 0 && $search === '' && !$filter_status): ?>
                <div class="dsh-olist" data-dsh-pane="mine">
                    <?php foreach (array_slice($D['mine'], 0, 15) as $r) { echo $dshOrderRow($r); } ?>
                    <?php if (!$D['mine']): ?><div class="dsh-empty"><i class="fas fa-mug-hot"></i> Nemáš přiřazenou žádnou rozpracovanou zakázku.</div><?php endif; ?>
                    <?php if ($D['mine_count'] > 15): ?><a class="dsh-link dsh-olist-more" href="orders.php?tech=<?php echo (int)$D['tech_id']; ?>">Všech <?php echo (int)$D['mine_count']; ?> mých zakázek <i class="fas fa-arrow-right"></i></a><?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="dsh-olist" data-dsh-pane="recent"<?php echo ($D['tech_id'] > 0 && $search === '' && !$filter_status) ? ' hidden' : ''; ?>>
                    <?php foreach ($recent as $r) { echo $dshOrderRow($r); } ?>
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
                <header class="dsh-card-h"><h2><i class="fas fa-warehouse"></i> <?php echo __('inventory'); ?></h2></header>
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
            <?php /* ── 6) Tým dnes — plán směn, kdo je přihlášený, kdo drží kasu ── */ ?>
            <section class="dsh-card">
                <header class="dsh-card-h"><h2><i class="fas fa-user-group"></i> Tým dnes</h2></header>
                <?php if (!$D['team']): ?><div class="dsh-empty">Dnes zatím nikdo přihlášený.</div><?php endif; ?>
                <div class="dsh-team">
                    <?php foreach ($D['team'] as $p): ?>
                    <div class="dsh-person<?php echo $p['online'] ? ' is-on' : ''; ?>">
                        <i class="dsh-dot" aria-hidden="true"></i>
                        <div><b><?php echo e($p['name']); ?></b>
                            <em><?php echo $p['plan'] !== '' ? 'směna ' . e($p['plan']) : ($p['online'] ? 'přihlášen(a)' : 'naposledy ' . e($p['seen'])); ?><?php echo $D['global'] ? ' · ' . e(crmDashBranchShort($D['branches'][$p['branch']] ?? getBranchLabel($p['branch']))) : ''; ?></em></div>
                        <?php if ($p['kasa']): ?><b class="dsh-pill t-completed" title="Má převzatou pokladnu"><i class="fas fa-cash-register"></i> kasa</b><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dsh-card imei-check-card" id="dshImei">
                <header class="dsh-card-h"><h2><i class="fas fa-shield-halved"></i> <?php echo __('imei_check_title'); ?></h2></header>
                <form id="imeiCheckForm" class="dsh-imei">
                    <input type="text" class="form-control" id="imeiCheckInput" placeholder="<?php echo e(__('imei_input_placeholder')); ?>" inputmode="numeric" autocomplete="off" maxlength="15" aria-label="<?php echo e(__('serial')); ?>">
                    <button class="dsh-act is-primary" type="submit"><i class="fas fa-search"></i><b><?php echo __('check'); ?></b></button>
                </form>
                <div class="dsh-imei-help"><?php echo __('imei_help_text'); ?></div>
                <div id="imeiCheckResult" class="imei-check-result" aria-live="polite"></div>
            </section>

            <?php if ($__web): ?>
            <section class="dsh-card">
                <header class="dsh-card-h"><h2><i class="fas fa-chart-line"></i> Návštěvnost webů dnes</h2></header>
                <?php foreach ($__web as $__wk => $__w):
                    $__tr = $__w['yday'] > 0 ? (int)round((($__w['today'] - $__w['yday']) / $__w['yday']) * 100) : null; ?>
                <div class="dsh-web crm-stat-web" role="button" tabindex="0" data-web-site="<?php echo e($__wk); ?>" title="Klikni pro posledních 14 dní">
                    <em><?php echo e($__w['label']); ?></em>
                    <b><?php echo (int)$__w['today']; ?></b>
                    <?php echo $__tr !== null ? $dshTrend($__tr, '') : '<em>—</em>'; ?>
                </div>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <a class="dsh-card dsh-stats-link" href="reports.php">
                <i class="fas fa-chart-pie"></i>
                <div><b>Statistiky a grafy</b><em>tržby po měsících, technici, pobočky</em></div>
                <i class="fas fa-chevron-right"></i>
            </a>
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

    document.addEventListener('click', function (e) {
        var more = e.target.closest('[data-dsh-more]');
        if (more) {
            var box = more.closest('details');
            box.querySelectorAll('.dsh-tr.is-more').forEach(function (r) { r.classList.remove('is-more'); });
            more.remove();
            return;
        }
        // úkol E-shop otevře okno objednávek (stejné jako dlaždice E-shop dnes)
        var es = e.target.closest('details[data-eshop-open] > summary');
        if (es) { e.preventDefault(); var tile = document.getElementById('eshopTile'); if (tile) { tile.click(); } return; }
        if (e.target.closest('[data-dsh-imei]')) {
            var card = document.getElementById('dshImei');
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function () { document.getElementById('imeiCheckInput').focus(); }, 350);
        }
    });
})();
</script>

<script>
$(document).ready(function() {
    // OPRAVA 17.7.2026: chyběly deklarace + otevření IMEI_I18N → celý inline blok
    // padal na SyntaxError a IMEI kontrola na nástěnce vůbec nefungovala.
    var $imeiInput = $('#imeiCheckInput');
    var $imeiResult = $('#imeiCheckResult');

    const IMEI_I18N = {
        checking: <?php echo json_encode(__('checking'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        imeiMinDigits: <?php echo json_encode(__('imei_min_digits'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        checkCouldNotComplete: <?php echo json_encode(__('check_could_not_complete'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        policeDb: <?php echo json_encode(__('police_db'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        resultUnknown: <?php echo json_encode(__('result_unknown'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        resultFound: <?php echo json_encode(__('result_found'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        resultNotFound: <?php echo json_encode(__('result_not_found'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        checkFailed: <?php echo json_encode(__('check_failed'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        serviceId: <?php echo json_encode(__('service_id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        yesRaw: <?php echo json_encode(__('yes'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        noRaw: <?php echo json_encode(__('no'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    function renderImeiResult(type, message, details) {
        const icon = type === 'success' ? 'check-circle' : type === 'danger' ? 'triangle-exclamation' : 'circle-exclamation';
        const alertClass = type === 'success'
            ? 'alert alert-success border-success border-opacity-25 bg-success bg-opacity-10 text-success'
            : type === 'danger'
                ? 'alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger'
                : 'alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning';

        const detailHtml = details ? `<div class="small mt-1 opacity-75">${window.escapeHtml(details)}</div>` : '';
        $imeiResult.html(`<div class="${alertClass} mb-0 py-2"><i class="fas fa-${icon} me-2"></i>${window.escapeHtml(message)}${detailHtml}</div>`);
    }

    function renderIfreeicloudResult(result) {
        if (!result) { return ''; }
        // stav + barva podle výsledku iFreeiCloud (success/warning)
        var ok = !!result.success;
        var icon = ok ? 'check-circle' : 'circle-exclamation';
        var alertClass = ok
            ? 'alert alert-success border-success border-opacity-25 bg-success bg-opacity-10 text-success'
            : 'alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning';
        var headline = window.escapeHtml(result.headline || (ok ? (result.model || 'OK') : (result.message || '')));

        // detailní pole (model, záruka, IMEI2, sériové…) pokud je vrací API
        var detailsHtml = '';
        if (result.details && typeof result.details === 'object') {
            var rows = Object.keys(result.details).map(function (k) {
                return '<div class="d-flex justify-content-between small py-1 border-bottom border-opacity-10"><span class="opacity-75">'
                    + window.escapeHtml(k) + '</span><span class="fw-semibold">' + window.escapeHtml(String(result.details[k])) + '</span></div>';
            }).join('');
            if (rows) { detailsHtml = '<div class="mt-2">' + rows + '</div>'; }
        }
        var imageHtml = result.image ? '<div class="mt-2 text-center"><img src="' + window.escapeHtml(result.image) + '" alt="" style="max-height:120px;border-radius:8px;"></div>' : '';
        var message = '';

        const meta = result.service_id !== undefined ? `<div class="small mt-2 opacity-50">${window.escapeHtml(IMEI_I18N.serviceId)}: ${window.escapeHtml(String(result.service_id))}${result.http_code ? ` · HTTP ${window.escapeHtml(String(result.http_code))}` : ''}</div>` : '';
        const note = (!detailsHtml && !imageHtml && result.summary) ? `<pre class="small mt-2 mb-0 p-2 rounded border border-opacity-25 bg-dark bg-opacity-25 text-white-75" style="white-space: pre-wrap;">${window.escapeHtml(result.summary)}</pre>` : '';

        return `<div class="${alertClass} mb-0 py-2"><div class="fw-semibold mb-1"><i class="fas fa-sim-card me-2"></i>iFreeiCloud</div><div><i class="fas fa-${icon} me-2"></i>${headline}</div>${message}${imageHtml}${detailsHtml}${note}${meta}</div>`;
    }

    $imeiInput.on('input', function() {
        this.value = this.value.replace(/\D+/g, '').slice(0, 15);
    });

    $('#imeiCheckForm').on('submit', function(e) {
        e.preventDefault();

        const imei = ($imeiInput.val() || '').replace(/\D+/g, '').slice(0, 15);
        if (imei.length < 14) {
            renderImeiResult('warning', IMEI_I18N.imeiMinDigits);
            return;
        }

        const $btn = $(this).find('button[type="submit"]');
        const oldHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + window.escapeHtml(IMEI_I18N.checking));
        $imeiResult.empty();

        $.post('api/check_imei.php', { imei: imei, csrf_token: $('input[name="csrf_token"]').first().val() }, function(res) {
            $btn.prop('disabled', false).html(oldHtml);

            if (!res || !res.success) {
                const policeMsg = res && res.police && res.police.message ? res.police.message : (res && res.message ? res.message : IMEI_I18N.checkCouldNotComplete);
                const warningAlert = `<div class="alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning mb-3 py-2"><div class="fw-semibold mb-1"><i class="fas fa-shield-halved me-2"></i>${window.escapeHtml(IMEI_I18N.policeDb)}</div><div><i class="fas fa-circle-exclamation me-2"></i>${window.escapeHtml(policeMsg)}</div></div>`;
                $imeiResult.html(warningAlert + renderIfreeicloudResult(res && res.ifreeicloud));
                return;
            }

            const policeMessage = res.police && res.police.message ? res.police.message : (res.message || '');
            let policeType = 'warning';
            let policeHeadline = IMEI_I18N.resultUnknown;
            if (res.status === 'found') {
                policeType = 'danger';
                policeHeadline = IMEI_I18N.resultFound;
            } else if (res.status === 'not_found') {
                policeType = 'success';
                policeHeadline = IMEI_I18N.resultNotFound;
            } else if (policeMessage) {
                policeHeadline = policeMessage;
            }

            const policeAlert = (() => {
                const icon = policeType === 'success' ? 'check-circle' : policeType === 'danger' ? 'triangle-exclamation' : 'circle-exclamation';
                const alertClass = policeType === 'success'
                    ? 'alert alert-success border-success border-opacity-25 bg-success bg-opacity-10 text-success'
                    : policeType === 'danger'
                        ? 'alert alert-danger border-danger border-opacity-25 bg-danger bg-opacity-10 text-danger'
                        : 'alert alert-warning border-warning border-opacity-25 bg-warning bg-opacity-10 text-warning';
                const detailHtml = policeMessage ? `<div class="small mt-1 opacity-75">${window.escapeHtml(policeMessage)}</div>` : '';
                return `<div class="${alertClass} mb-3 py-2"><div class="fw-semibold mb-1"><i class="fas fa-shield-halved me-2"></i>${window.escapeHtml(IMEI_I18N.policeDb)}</div><div><i class="fas fa-${icon} me-2"></i>${window.escapeHtml(policeHeadline)}</div>${detailHtml}</div>`;
            })();

            const ifreeicloudHtml = renderIfreeicloudResult(res.ifreeicloud);
            $imeiResult.html(policeAlert + ifreeicloudHtml);
        }, 'json').fail(function() {
            $btn.prop('disabled', false).html(oldHtml);
            renderImeiResult('warning', IMEI_I18N.checkFailed);
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>

