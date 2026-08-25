<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/scan_resolver.php';
require_once 'includes/header.php';

// Filter for Dashboard - keep same accepted statuses as orders.php
$allowed_statuses = getAllowedOrderFilterStatuses();
$filter_status = in_array($_GET['filter'] ?? '', $allowed_statuses, true) ? $_GET['filter'] : null;

// Branch scope for stats: managers/admins see all, branch staff see only their branch.
$tech_cond = orderBranchScopeSql('branch_id', 'technician_id');
$tech_cond_o = orderBranchScopeSql('o.branch_id', 'o.technician_id');

// Count for Stats
$newStatuses = orderStatusSqlIn($pdo, 'new');
$pendingStatuses = orderStatusSqlIn($pdo, 'pending_approval');
$progressStatuses = orderStatusSqlIn($pdo, 'in_progress');
$waitingStatuses = orderStatusSqlIn($pdo, 'waiting_parts');
$doneStatuses = orderStatusSqlIn($pdo, 'done');
$activeStatuses = orderStatusSqlIn($pdo, 'active');

// Dlaždice „Nepřidělené" a „Nedokončené" (16.7.2026): vedení (admin/manažer/Boss)
// vidí VŽDY součet obou poboček; řadoví zaměstnanci jen svou pobočku — pobočky
// si tyto údaje navzájem nevidí.
$__tilesGlobal = isBranchGlobalViewer();
$__myBranchId = (int)getCurrentStaffBranchId();
$__branch_cond = (!$__tilesGlobal && $__myBranchId > 0) ? " AND branch_id = " . $__myBranchId : '';
$__tilesBranchLabel = $__tilesGlobal ? 'Obě pobočky' : getBranchLabel($__myBranchId);
// Migrace 7/2026: číselné údaje Nástěnky = jen zakázky vzniklé v CRM (source <> 'legacy');
// importované ze zakazkovylist.cz do hlavních čísel ani tržeb nevstupují
ensureOrdersSourceColumn();
ensureOrderDeviceBranchColumn(); // fyzické umístění zařízení (oranžová pilulka u stavu)
$noLegacy = " AND source <> 'legacy'";
$unassigned_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($activeStatuses) AND (technician_id IS NULL OR technician_id = 0)" . $noLegacy . $__branch_cond)->fetchColumn();
$unfinished_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($activeStatuses)" . $noLegacy . $__branch_cond)->fetchColumn();

$new_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($newStatuses)" . $noLegacy . $tech_cond)->fetchColumn();
$pending_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($pendingStatuses)" . $noLegacy . $tech_cond)->fetchColumn();
$progress_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($progressStatuses)" . $noLegacy . $tech_cond)->fetchColumn();
$ready_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($doneStatuses)" . $noLegacy . $tech_cond)->fetchColumn();

// Design-system stats
$waiting_count = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($waitingStatuses)" . $noLegacy . $tech_cond)->fetchColumn();
$active_count = (int)$new_count + (int)$pending_count + (int)$progress_count + $waiting_count;
$urgent_waiting = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($waitingStatuses) AND priority = 'High'" . $noLegacy . $tech_cond)->fetchColumn();
try {
    // Rozsahy dat schválně BEZ funkce nad sloupcem (DATE()/MONTH() by znemožnily
    // použití indexu a nástěnka by při každém načtení skenovala celé orders).
    // „dnešek" = updated_at >= CURDATE() AND < CURDATE()+1 den, stejně měsíce.
    $__day  = " AND updated_at >= CURDATE() AND updated_at < CURDATE() + INTERVAL 1 DAY";
    $__dayC = " AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY";
    $completed_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($doneStatuses)" . $__day . $noLegacy . $tech_cond)->fetchColumn();
    $planned_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE 1=1" . $__dayC . $noLegacy . $tech_cond)->fetchColumn();
    $new_today = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ($newStatuses)" . $__dayC . $noLegacy . $tech_cond)->fetchColumn();
    $revenue_today = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses)" . $__day . $noLegacy . $tech_cond)->fetchColumn();
    $revenue_yesterday = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses) AND updated_at >= CURDATE() - INTERVAL 1 DAY AND updated_at < CURDATE()" . $noLegacy . $tech_cond)->fetchColumn();
    $revenue_today_trend = $revenue_yesterday > 0 ? round((($revenue_today - $revenue_yesterday) / $revenue_yesterday) * 100) : 0;

    $revenue_month = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses) AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND updated_at < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')" . $noLegacy . $tech_cond)->fetchColumn();
    $revenue_prev = (float)$pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses) AND updated_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') AND updated_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')" . $noLegacy . $tech_cond)->fetchColumn();
    $revenue_trend = $revenue_prev > 0 ? round((($revenue_month - $revenue_prev) / $revenue_prev) * 100) : 0;
    $revenue_12m = [];
    for ($i = 11; $i >= 0; $i--) {
        // měsíc „před $i měsíci" jako polouzavřený interval <začátek; začátek dalšího)
        // — INTERVAL -1 MONTH pro $i=0 dá korektně začátek příštího měsíce
        $prev = $i - 1;
        $m = $pdo->query("SELECT COALESCE(SUM(final_cost),0) FROM orders WHERE status IN ($doneStatuses)
            AND updated_at >= DATE_FORMAT(CURDATE() - INTERVAL $i MONTH, '%Y-%m-01')
            AND updated_at < DATE_FORMAT(CURDATE() - INTERVAL $prev MONTH, '%Y-%m-01')" . $noLegacy . $tech_cond)->fetchColumn();
        $revenue_12m[] = (float)$m;
    }
} catch (Throwable $e) {
    $completed_today = $planned_today = $new_today = 0;
    $revenue_today = $revenue_yesterday = $revenue_today_trend = 0;
    $revenue_month = $revenue_prev = 0; $revenue_trend = 0; $revenue_12m = array_fill(0, 12, 0);
}
// ─── PŘIJATÉ PENÍZE vs. TRŽBA ────────────────────────────────────────────────
// Tržba výše počítá dokončené zakázky. Majitel ale chce vidět i peníze, které reálně
// přišly — včetně záloh. Záloha ÚČETNĚ NENÍ výnos (je to závazek, dokud není plnění
// uskutečněné), proto se s tržbou NESČÍTÁ a ukazuje se jako samostatný údaj.
// Kdyby se sečetla, měsíc by vypadal lépe, než jaký doopravdy je, a po dokončení zakázky
// by se tytéž peníze započítaly podruhé.
$received_today = $advance_today = 0.0;
$received_month = $advance_month = 0.0;
try {
    // Pojistka pro případ, že se kód nasadí dřív než doběhne run_migrations.php.
    if (function_exists('afxEnsureInvoicePayments')) { afxEnsureInvoicePayments(); }
    // Faktura ještě není uhrazená celá → přijaté peníze na ní jsou zatím záloha/část.
    // Tolerance 1 Kč nad 100 Kč = stejné pravidlo jako u párování plateb (afxPayTolerance).
    // paid_amount = 0 u starých ručně uzavřených faktur, proto fallback na stav faktury.
    // Kdyby sloupec paid_amount ještě nebyl (nedoběhlá migrace 037), pozná se záloha
    // aspoň podle stavu faktury — přehled peněz se kvůli tomu nesmí vypnout celý.
    $__hasPaidCol = (bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'paid_amount'")->fetch();
    $__advCond = $__hasPaidCol
        ? " AND IF(i.paid_amount > 0,
                   i.paid_amount < i.total_amount - IF(i.total_amount >= 100, 1.0, 0.0),
                   i.status <> 'paid')"
        : " AND i.status <> 'paid'";
    // Pobočka se bere ze ZAKÁZKY. Faktura BEZ zakázky žádnou pobočku nemá — LEFT JOIN
    // pro ni vrátí NULL a holá podmínka „o.branch_id = X" by ji zahodila i s platbou.
    // Proto se NULL zakázka výslovně pouští dál (stejné pravidlo jako getCashFlowStats
    // v Přehledech, jinak by nástěnka a Přehledy ukazovaly různá čísla za týž měsíc).
    // Dobropisy se vylučují ze stejného důvodu — sestavy účetní je nepočítají.
    $__scope = orderBranchScopeSql('o.branch_id');
    $__scopeCond = $__scope !== '' ? ' AND (o.id IS NULL OR ' . substr($__scope, 5) . ')' : '';
    $__payBase = "FROM invoice_payments p
                  JOIN invoices i ON i.id = p.invoice_id
                  LEFT JOIN orders o ON o.id = i.order_id
                  WHERE i.status <> 'cancelled'
                    AND COALESCE(i.invoice_type, 'invoice') <> 'credit_note'" . $__scopeCond;
    // Platba bez vyplněného paid_on se datuje dnem zápisu — s holým paid_on by
    // nespadla do žádného dne ani měsíce a číslo by nesedělo na paid_amount faktur.
    $__payDate = "COALESCE(p.paid_on, DATE(p.created_at))";
    $__payToday = " AND $__payDate = CURDATE()";
    $__payMonth = " AND $__payDate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                    AND $__payDate < DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')";
    $received_today = (float)$pdo->query("SELECT COALESCE(SUM(p.amount),0) " . $__payBase . $__payToday)->fetchColumn();
    $advance_today  = (float)$pdo->query("SELECT COALESCE(SUM(p.amount),0) " . $__payBase . $__payToday . $__advCond)->fetchColumn();
    $received_month = (float)$pdo->query("SELECT COALESCE(SUM(p.amount),0) " . $__payBase . $__payMonth)->fetchColumn();
    $advance_month  = (float)$pdo->query("SELECT COALESCE(SUM(p.amount),0) " . $__payBase . $__payMonth . $__advCond)->fetchColumn();
} catch (Throwable $e) {
    // invoice_payments ještě neexistuje → chováme se, jako by byla prázdná (nástěnka musí běžet dál)
    $received_today = $advance_today = $received_month = $advance_month = 0.0;
}

// Popisky grafu „Tržby po měsících": sloupce jsou KLOUZAVÉ okno (před 11 měsíci
// … aktuální měsíc), iniciály se proto rotují stejně. Dřív byly napevno
// leden…prosinec — aktuální (zvýrazněný) sloupec pak nesl v srpnu popisek „P".
$__inic = explode(',', __('month_initials'));   // 12 kalendářních iniciál (leden…prosinec)
$month_labels = [];
for ($__i = 11; $__i >= 0; $__i--) {
    $month_labels[] = trim((string)($__inic[((int)date('n') - 1 - $__i + 24) % 12] ?? ''));
}
$rev_max = max(1, max($revenue_12m));

$branch_overview = [];
if (isBranchGlobalViewer()) {
    try {
        $branch_overview = $pdo->query("SELECT b.id, b.name,
                COUNT(o.id) AS total_orders,
                SUM(o.status IN ($activeStatuses)) AS active_orders,
                SUM(o.status IN ($doneStatuses)) AS done_orders,
                COALESCE(SUM(CASE WHEN o.status IN ($doneStatuses) THEN o.final_cost ELSE 0 END), 0) AS revenue
            FROM branches b
            LEFT JOIN orders o ON o.branch_id = b.id AND o.source <> 'legacy'
            WHERE b.is_active = 1
            GROUP BY b.id, b.name
            ORDER BY b.id ASC")->fetchAll();
    } catch (Throwable $e) {
        $branch_overview = [];
    }
}

// Online Techs (Last 5 minutes) - Admin or those with admin_access
$online_count = 0;
if (hasPermission('admin_access')) {
    $online_count = $pdo->query("SELECT COUNT(*) FROM technicians WHERE last_seen > (NOW() - INTERVAL 5 MINUTE) AND is_active = 1")->fetchColumn();
}

$order_templates_raw = trim((string)get_setting('order_templates', ''));
$order_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_templates_raw))));

$order_note_templates_raw = trim((string)get_setting('order_note_templates', ''));
$order_note_templates = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $order_note_templates_raw))));

?>

<?php if (crmCanManageProducts()): ?>
<?php /* Prodeje z vlastního e-shopu (applefix.click) — počet + rozklikávací seznam.
         Rezervace („platba při vyzvednutí") se do prodejů NEPOČÍTAJÍ: zboží ještě
         není zaplacené, čeká na kase. Data: api/eshop_dashboard.php. */ ?>
<div class="eshop-tile mb-4" id="eshopTile" role="button" tabindex="0" title="Klikni pro seznam prodaných produktů"
     data-can-manage="<?php echo crmCanDeleteOrders() ? '1' : '0'; ?>">
    <div class="eshop-tile-ic"><i class="fas fa-cart-shopping"></i></div>
    <div class="eshop-tile-main">
        <div class="eshop-tile-label">Prodeje z e-shopu</div>
        <div class="eshop-tile-value"><span id="eshopTileToday">–</span> <span class="eshop-tile-unit">dnes</span></div>
    </div>
    <div class="eshop-tile-side">
        <div class="eshop-tile-month"><span id="eshopTileMonth">–</span> tento měsíc · <span id="eshopTileSum">–</span></div>
        <div class="eshop-tile-res" id="eshopTileRes" style="display:none;"></div>
    </div>
    <div class="eshop-tile-cta"><i class="fas fa-list me-1"></i>Zobrazit prodané</div>
</div>
<style>
.eshop-tile{display:flex;align-items:center;gap:16px;padding:14px 18px;border-radius:var(--r2,16px);cursor:pointer;
    background:linear-gradient(135deg,rgba(48,209,88,.14),rgba(48,209,88,.05));border:1px solid rgba(48,209,88,.32);
    transition:filter .15s ease,transform .15s ease;}
.eshop-tile:hover{filter:brightness(1.08);transform:translateY(-1px);}
.eshop-tile-ic{width:42px;height:42px;flex:0 0 42px;display:flex;align-items:center;justify-content:center;border-radius:12px;
    background:rgba(48,209,88,.18);color:#7ce39a;font-size:1.15rem;}
.eshop-tile-label{font-size:11px;letter-spacing:.1em;text-transform:uppercase;font-weight:800;color:rgba(233,238,247,.6);}
.eshop-tile-value{font-size:1.6rem;font-weight:800;line-height:1.15;color:#fff;}
.eshop-tile-unit{font-size:.9rem;font-weight:600;color:rgba(233,238,247,.55);}
.eshop-tile-side{margin-left:auto;text-align:right;}
.eshop-tile-month{font-size:.9rem;color:rgba(233,238,247,.75);font-weight:600;}
.eshop-tile-res{margin-top:4px;font-size:.82rem;font-weight:700;color:#ffd479;}
.eshop-tile-cta{padding:8px 14px;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.16);
    font-size:.85rem;font-weight:700;color:#eef4ff;white-space:nowrap;}
@media (max-width:575.98px){.eshop-tile{flex-wrap:wrap;}.eshop-tile-side{margin-left:0;text-align:left;width:100%;}.eshop-tile-cta{width:100%;text-align:center;}}
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
    var tiles = document.querySelectorAll('.crm-stat-web');
    if (!tiles.length) return;
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
    tiles.forEach(function (t) {
        t.addEventListener('click', function () { open(t.dataset.webSite); });
        t.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(t.dataset.webSite); } });
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
        var labels = { paid: 'paid', reserved: 'reserved', collected: 'collected', shipped: 'reserved', returned: 'reserved', cancelled: 'reserved' };
        var canManage = tile.dataset.canManage === '1';   // akce smí jen vedení (admin/Boss)
        var btn = function (act, o, cls, ic, label) {
            if (!canManage) { return ''; }
            return '<button type="button" class="btn btn-sm ' + cls + ' eshop-act-btn" data-act="' + act + '" data-id="' + Number(o.id)
                + '" data-ref="' + esc(o.order_ref) + '"><i class="fas ' + ic + ' me-1"></i>' + esc(label) + '</button>';
        };
        body.innerHTML = d.orders.map(function (o) {
            var lb = [labels[o.status] || 'paid', o.status_label || o.status];
            var items = (o.items || []).map(function (i) { return i.qty + '× ' + i.name; }).join('\n');
            return '<div class="eshop-modal-row"><div style="min-width:0;">'
                + '<span class="ref">' + esc(o.order_ref) + '</span> <span class="eshop-badge ' + lb[0] + '">' + esc(lb[1]) + '</span>'

                + '<div class="who">' + esc(o.customer) + (o.phone ? ' · ' + esc(o.phone) : '') + ' · ' + esc(o.date) + '</div>'
                + '<div class="items">' + esc(items || '—') + '</div>'
                + (o.waiting_days > 2 ? '<div class="who" style="color:#ffd479;">čeká už ' + Number(o.waiting_days) + ' dní</div>' : '')
                + '<div class="d-flex gap-2 flex-wrap mt-2">'
                + (o.can_ship ? btn('ship', o, 'btn-primary', 'fa-truck', 'Předáno dopravci — odesláno') : '')
                + (o.can_pay ? btn('paid', o, 'btn-success', 'fa-check', o.status === 'shipped' ? 'Platba od dopravce dorazila' : 'Platba dorazila — uvolnit k odeslání') : '')
                + (o.can_return ? btn('return', o, 'btn-outline-warning', 'fa-rotate-left', 'Nedoručeno — zpět na sklad') : '')
                + (o.can_cancel ? btn('cancel', o, 'btn-outline-danger', 'fa-ban', 'Zrušit rezervaci') : '')
                + '</div></div><div class="amt">' + money(o.total || 0) + '</div></div>';
        }).join('');
        // ruční kroky, které nejdou automaticky (platba mimo párování, expedice dobírky,
        // vrácená zásilka, zrušení rezervace)
        var asks = {
            paid: 'Opravdu je objednávka ORDER zaplacená? U převodu se zboží teď odepíše ze skladu k odeslání.',
            ship: 'Předáváš objednávku ORDER dopravci? Zboží se odepíše ze skladu, platba dorazí od dopravce.',
            'return': 'Vrátila se zásilka k objednávce ORDER? Zboží se vrátí na sklad.',
            cancel: 'Zrušit rezervaci ORDER? Zboží se uvolní zpět do prodeje (na e-shop i kasu).'
        };
        body.querySelectorAll('.eshop-act-btn').forEach(function (b) {
            b.addEventListener('click', function () {
                var act = b.dataset.act;
                if (!confirm((asks[act] || 'Provést?').replace('ORDER', b.dataset.ref))) { return; }
                b.disabled = true;
                var fd = new FormData();
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
    load();
})();
</script>
<?php endif; ?>

<!-- 4 statistiky + pobočky na jednom řádku (pobočky nad sebou, stejná celková výška) -->
<div class="crm-stat-row mb-4">
<?php /* Sdílené dlaždice (stejné na Nástěnce i v Zakázkách) + návštěvnost webů jen tady */
$__st_with_web = crmCanManageProducts();
include __DIR__ . '/includes/partials/stat_tiles.php'; ?>

<?php if (!empty($branch_overview)): ?>
<div class="crm-branch-col">
    <?php foreach ($branch_overview as $branch): ?>
    <div class="crm-branch-mini card glass-card border-0">
        <div class="crm-branch-mini-main">
            <div class="crm-branch-mini-name"><i class="fas fa-store me-2 text-primary"></i><?php echo e($branch['name']); ?></div>
            <div class="crm-branch-mini-sub"><?php echo __('active_short'); ?>: <?php echo (int)$branch['active_orders']; ?> · <?php echo __('done_short'); ?>: <?php echo (int)$branch['done_orders']; ?></div>
        </div>
        <div class="crm-branch-mini-num">
            <b><?php echo (int)$branch['total_orders']; ?></b>
            <small><?php echo number_format((float)$branch['revenue'], 0, ',', ' '); ?> Kč</small>
        </div>
        <a class="stretched-link" href="orders.php?branch_id=<?php echo (int)$branch['id']; ?>" aria-label="<?php echo e(__('branch')); ?> <?php echo e($branch['name']); ?>"></a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div><!-- /.crm-stat-row -->

<div class="row g-4 align-items-start dashboard-main-row">
    <div class="col-12 col-lg-9 dashboard-main-col">
        <div class="card glass-card border-0">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <?php
                    if ($filter_status === null) {
                        echo __('recent_orders');
                    } else {
                        echo e(getOrderStatusLabel($filter_status));
                    }
                    ?>
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <?php /* Filtrování z nástěnky odebráno (pokyn 8/2026) — filtry zůstávají
                             v seznamu zakázek (orders.php). Nástěnka ukazuje přehled bez filtru. */ ?>
                    <?php if ($filter_status): ?>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary"><?php echo __('show_all'); ?></a>
                    <?php else: ?>
                        <a href="orders.php" class="btn btn-sm btn-primary"><?php echo __('all_orders'); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <?php /* Sjednoceno se seznamem zakázek (orders.php): stejné sloupce,
                                     stejné pořadí i obsah buněk — všichni (admin i technici) vidí
                                     na nástěnce a v seznamu totéž. Technik je součástí buňky Stav. */ ?>
                            <tr>
                                <th><?php echo __('order_no'); ?> / <?php echo __('created'); ?></th>
                                <th><?php echo __('client'); ?></th>
                                <th><?php echo __('device_model'); ?></th>
                                <th><?php echo __('problem'); ?></th>
                                <th><?php echo __('status'); ?></th>
                                <th class="col-priority"><?php echo __('priority'); ?></th>
                                <th><?php echo __('amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $search = trim($_GET['search'] ?? '');

                            $where_clauses = [];
                            $params = [];

                            addOrderBranchScope($where_clauses, $params, 'o');

                            // Filtrování podle technika z nástěnky odebráno (pokyn 8/2026).
                            // (Filtry zůstávají v seznamu zakázek orders.php.)

                            // Same search fields as orders.php
                            if ($search !== '') {
                                ensureOrdersSourceColumn();   // legacy_code (migrace 7/2026) — dotaz níže se na něj ptá
                                $searchTerm = "%$search%";
                                if (is_numeric($search)) {
                                    $where_clauses[] = '(o.order_code LIKE ? OR o.legacy_code LIKE ? OR o.id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_model LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ? OR o.id = ?)';
                                    for ($i = 0; $i < 10; $i++) $params[] = $searchTerm;
                                    $params[] = (int)$search;
                                } else {
                                    $where_clauses[] = '(o.order_code LIKE ? OR o.legacy_code LIKE ? OR o.id LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR c.phone LIKE ? OR o.device_model LIKE ? OR o.problem_description LIKE ? OR o.serial_number LIKE ? OR o.serial_number_2 LIKE ?)';
                                    for ($i = 0; $i < 10; $i++) $params[] = $searchTerm;
                                }
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
                            try { ensureWebBookingsSchema(); } catch (Throwable $e) {}   // subquery web_appointment_at
                            $sql = "SELECT o.*, c.first_name, c.last_name, c.phone, c.email, c.company, c.customer_type, t.name as tech_name,
                                           (SELECT MAX(l.changed_at) FROM order_status_log l WHERE l.order_id = o.id) AS last_status_change,
                                           (SELECT MAX(wb.appointment_at) FROM web_bookings wb WHERE wb.order_id = o.id) AS web_appointment_at
                                    FROM orders o
                                    JOIN customers c ON o.customer_id = c.id
                                    LEFT JOIN technicians t ON o.technician_id = t.id" .
                                    $where_clause .
                                    " ORDER BY " . orderSortSql('o', 'o.id = ?') . " LIMIT 15";

                            $stmt = $pdo->prepare($sql);
                            $exec_params = array_merge($params, [$search_id]);
                            $stmt->execute($exec_params);

                            $orders_list = $stmt->fetchAll();
                            
                            $has_media_ids = [];
                            if (!empty($orders_list)) {
                                $order_ids = array_column($orders_list, 'id');
                                $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
                                $m_stmt = $pdo->prepare("SELECT order_id FROM order_attachments WHERE order_id IN ($placeholders) GROUP BY order_id");
                                $m_stmt->execute($order_ids);
                                $has_media_ids = array_flip($m_stmt->fetchAll(PDO::FETCH_COLUMN));
                            }
                            
                            $found = false;
                            foreach($orders_list as $r):
                                $found = true;
                                $icon = getDeviceIcon($r['device_type']);
                                $phone_href = normalizePhoneForTel($r['phone'] ?? '');

                                $has_media = isset($has_media_ids[$r['id']]);
                                $display_code = orderDisplayCode($r);
                            ?>
                            <?php [$staleCls, $staleTitle] = orderStaleRowAttrs($r); ?>
                            <?php $__isInternal = crmIsInternalCustomer($r['customer_id'] ?? 0); ?>
                            <?php // Importované zakázky (source='legacy') = šedý řádek bez stavové barvy; stav barevně jen v pilulce Stav
                                  $__rowCls = (($r['source'] ?? 'crm') === 'legacy') ? 'order-row--legacy' : 'order-row--status-' . getOrderStatusBadgeToken($r['status']); ?>
                            <tr <?php echo $staleTitle ? 'title="' . e($staleTitle) . '" ' : ''; ?>class="clickable-order-row <?php echo e($__rowCls); ?><?php echo $staleCls; ?><?php echo $__isInternal ? ' order-row--internal' : (!empty($r['company']) || ($r['customer_type'] ?? '') === 'company' ? ' order-row--company' : ''); ?><?php echo $r['priority'] == 'High' ? ' order-row--high' : ''; ?>" style="cursor: pointer;" onclick="window.location.href='view_order.php?id=<?php echo (int)$r['id']; ?>'" tabindex="0" role="link">
                                <td>
                                    <a href="view_order.php?id=<?php echo (int)$r['id']; ?>" class="order-code-main text-decoration-none"><?php echo e($display_code); ?></a>
                                    <?php if($has_media): ?>
                                        <i class="fas fa-camera text-info ms-1" title="<?php echo __('has_media'); ?>"></i>
                                    <?php endif; ?>
                                    <?php if (($__legacyCode = trim((string)($r['legacy_code'] ?? ''))) !== ''): ?>
                                        <div class="order-code-prev">(<?php echo __('ord_prev_code'); ?> <?php echo e($__legacyCode); ?>)</div>
                                    <?php endif; ?>
                                    <div class="text-white-75" style="font-size:10.5px;line-height:1.3;white-space:nowrap;"><?php echo crmDateTime($r['created_at'], false); ?></div>
                                    <?php if (trim((string)($r['created_by_name'] ?? '')) !== ''): ?>
                                        <div class="text-white-50" style="font-size:.72rem;" title="Zakázku vytvořil(a)"><i class="fas fa-user-pen me-1" style="font-size:.65rem;"></i><?php echo e($r['created_by_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($__isInternal): ?>
                                        <div><span class="afx-internal-chip" title="Interní zakázka — není pro veřejného klienta"><i class="fas fa-screwdriver-wrench"></i>Interní</span></div>
                                        <div class="small text-white-50 mt-1"><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></div>
                                    <?php else: ?>
                                        <strong><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></strong>
                                    <?php endif; ?><br>
                                    <small class="text-white-75">
                                        <?php if ($phone_href !== ''): ?>
                                            <a href="tel:<?php echo e($phone_href); ?>" class="text-reset text-decoration-none crm-phone-text" onclick="event.stopPropagation();"><i class="fas fa-phone me-1 text-success"></i><?php echo htmlspecialchars($r['phone']); ?></a>
                                        <?php elseif (!empty($r['phone'])): ?>
                                            <span class="crm-phone-text"><i class="fas fa-phone me-1 text-success"></i><?php echo htmlspecialchars($r['phone']); ?></span>
                                        <?php endif; ?>
                                    </small>
                                    <?php if (!empty($r['email'])): ?>
                                        <div><a class="small text-white-75 text-decoration-none" href="mailto:<?php echo e($r['email']); ?>" onclick="event.stopPropagation();">
                                            <i class="fas fa-envelope me-1 text-info"></i><?php echo e($r['email']); ?>
                                        </a></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $icon; ?> <strong><?php echo htmlspecialchars($r['device_brand']); ?></strong><br>
                                    <span class="device-model-lg"><?php echo htmlspecialchars($r['device_model']); ?></span>
                                    <?php if(!empty($r['serial_number'])): ?>
                                        <div class="small text-white-75"><i class="fas fa-barcode me-1"></i><?php echo __('sn1'); ?>: <?php echo htmlspecialchars($r['serial_number']); ?></div>
                                    <?php endif; ?>
                                    <?php if(!empty($r['serial_number_2'])): ?>
                                        <div class="small text-white-75"><i class="fas fa-barcode me-1"></i><?php echo __('sn2'); ?>: <?php echo htmlspecialchars($r['serial_number_2']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="text-truncate" style="max-width: 240px; font-size: 0.92rem; line-height: 1.35;" title="<?php echo htmlspecialchars($r['problem_description']); ?>">
                                        <?php echo htmlspecialchars($r['problem_description']); ?>
                                    </div>
                                </td>
                                <td class="cell-status">
                                    <?php echo getStatusBadge($r['status']); ?><?php echo crmDeviceLocationPill($r); ?>
                                    <?php if (!empty($r['web_appointment_at'])):
                                        $wbTs = strtotime((string)$r['web_appointment_at']);
                                        $wbToday = $wbTs && date('Y-m-d', $wbTs) === date('Y-m-d');
                                    ?>
                                    <div class="afx-booked-chip<?php echo $wbToday ? ' is-today' : ''; ?>" title="<?php echo e(__('web_booking_no')); ?>">
                                        <i class="far fa-calendar-check"></i>
                                        <b><?php echo $wbToday ? __('today') : date('j.n.', $wbTs); ?> <?php echo date('H:i', $wbTs); ?></b>
                                    </div>
                                    <?php endif; ?>
                                    <?php if(!empty($r['shipping_method'])): ?>
                                        <div class="mt-1 small text-info"><i class="fas fa-truck me-1"></i><?php echo htmlspecialchars(crmTranslateWebServiceMethod((string)$r['shipping_method'])); ?></div>
                                    <?php endif; ?>
                                    <?php if($_SESSION['role'] == 'admin' && ($r['extra_expenses'] ?? 0) > 0): ?>
                                        <div class="mt-1 small text-danger"><i class="fas fa-minus-circle me-1"></i><?php echo __('extra_expenses'); ?>: <?php echo e($r['extra_expenses']); ?></div>
                                    <?php endif; ?>
                                    <div class="small text-white-75 mt-1">
                                        <i class="far fa-clock me-1"></i><?php echo date('d.m.Y H:i', strtotime($r['updated_at'])); ?>
                                    </div>
                                    <?php if(!empty($r['tech_name'])): ?>
                                    <div class="small text-white-75 mt-1">
                                        <i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($r['tech_name']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (isBranchGlobalViewer() && !empty($r['branch_id'])): ?>
                                    <div class="small mt-1"><span class="badge bg-dark border border-secondary afx-branch-tag"><i class="fas fa-store me-1"></i><?php echo e(getBranchLabel((int)$r['branch_id'])); ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td class="col-priority"><?php echo getOrderPriorityBadge($r['priority'] ?? 'Normal', (string)$r['status']); ?></td>
                                <td><strong><?php echo formatMoney($r['final_cost'] ?: $r['estimated_cost']); ?></strong></td>
                            </tr>
                            <?php endforeach; 
                            
                            if (!$found): ?>
                                <tr><td colspan="7" class="text-center text-white-75 py-4"><?php echo __('not_found'); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php
        /* Globální hledání musí najít i SKLAD — dosud prohledávalo jen zakázky,
           takže naskladněný kus podle IMEI/kódu/názvu nešlo z nástěnky dohledat.
           Panel se ukáže jen při hledání a jen tomu, kdo na sklad má právo. */
        if ($search !== '' && hasPermission('manage_inventory')):
            $sk_term = "%$search%";
            $st = $pdo->prepare("SELECT id, title, product_code, price, stock_qty, grade, stock_key
                FROM products WHERE title LIKE ? OR product_code LIKE ? OR model LIKE ?
                ORDER BY (stock_qty > 0) DESC, added_at DESC, id DESC LIMIT 8");
            $st->execute([$sk_term, $sk_term, $sk_term]);
            $sk_products = $st->fetchAll();
            $st = $pdo->prepare("SELECT id, part_name, sku, quantity, sale_price
                FROM inventory WHERE part_name LIKE ? OR sku LIKE ?
                ORDER BY (quantity > 0) DESC, part_name ASC LIMIT 6");
            $st->execute([$sk_term, $sk_term]);
            $sk_parts = $st->fetchAll();
            if ($sk_products || $sk_parts): ?>
        <div class="card glass-card border-0 mt-4">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-warehouse text-info me-2"></i><?php echo __('inventory'); ?></h5>
                <span class="small text-white-75"><?php echo __('search_placeholder'); ?>: <strong><?php echo e($search); ?></strong></span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <tbody>
                        <?php foreach ($sk_products as $sp): ?>
                            <tr>
                                <td style="width:120px"><span class="badge bg-success">Produkt</span></td>
                                <td>
                                    <a class="text-decoration-none" href="products.php?search=<?php echo urlencode((string)$sp['product_code']); ?>"><strong><?php echo e($sp['title']); ?></strong></a>
                                    <div class="small text-white-75">
                                        <?php echo e($sp['product_code']); ?>
                                        <?php if (!empty($sp['grade'])): ?> · <?php echo e($sp['grade']); ?><?php endif; ?>
                                        <?php if (!empty($sp['stock_key'])): ?> · <?php echo $sp['stock_key'] === 'karlin' ? 'Karlín' : 'Václavák'; ?><?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-nowrap">
                                    <?php if ((int)$sp['stock_qty'] > 0): ?>
                                        <span class="text-success">Skladem <?php echo (int)$sp['stock_qty']; ?> ks</span>
                                    <?php else: ?>
                                        <span class="text-white-75">Vyprodáno</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><strong><?php echo formatMoney((float)$sp['price']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($sk_parts as $pp): ?>
                            <tr>
                                <td><span class="badge bg-secondary">Díl</span></td>
                                <td>
                                    <a class="text-decoration-none" href="inventory.php?search=<?php echo urlencode((string)$pp['sku']); ?>"><strong><?php echo e($pp['part_name']); ?></strong></a>
                                    <div class="small text-white-75"><?php echo e($pp['sku']); ?></div>
                                </td>
                                <td class="text-nowrap">
                                    <?php if ((int)$pp['quantity'] > 0): ?>
                                        <span class="text-success">Skladem <?php echo (int)$pp['quantity']; ?> ks</span>
                                    <?php else: ?>
                                        <span class="text-white-75">Vyprodáno</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><strong><?php echo formatMoney((float)$pp['sale_price']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; endif; ?>
    </div>
    <div class="col-12 col-lg-3">
        <!-- Revenue chart (design-system) -->
        <div class="crm-revenue-card mb-4">
            <div class="crm-revenue-label"><?php echo __('revenue_by_months'); ?></div>
            <div class="crm-revenue-value"><?php echo number_format($revenue_month, 0, ',', ' '); ?> Kč</div>
            <?php
                $chart_h = 56; $chart_w = 260;
                $bar_w = 12; $bar_gap = ($chart_w - count($revenue_12m)*$bar_w) / max(1, count($revenue_12m)-1);
            ?>
            <svg class="crm-revenue-chart" width="100%" height="<?php echo $chart_h + 16; ?>" viewBox="0 0 <?php echo $chart_w; ?> <?php echo $chart_h + 16; ?>">
                <defs>
                    <linearGradient id="barGrad" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%" stop-color="#0A84FF"/>
                        <stop offset="50%" stop-color="#5E5CE6"/>
                        <stop offset="100%" stop-color="#BF5AF2"/>
                    </linearGradient>
                </defs>
                <?php foreach ($revenue_12m as $i => $v):
                    $bh = max(1, round(($v / $rev_max) * $chart_h));
                    $x = $i * ($bar_w + $bar_gap);
                    $is_last = $i === count($revenue_12m) - 1;
                ?>
                    <rect x="<?php echo $x; ?>" y="<?php echo $chart_h - $bh; ?>" width="<?php echo $bar_w; ?>" height="<?php echo $bh; ?>" rx="3"
                        fill="<?php echo $is_last ? 'url(#barGrad)' : 'rgba(110,58,250,0.25)'; ?>"/>
                <?php endforeach; ?>
                <?php foreach ($month_labels as $i => $lbl): ?>
                    <text x="<?php echo $i*($bar_w+$bar_gap) + $bar_w/2; ?>" y="<?php echo $chart_h + 12; ?>" text-anchor="middle" font-size="8" fill="rgba(255,255,255,0.25)"><?php echo $lbl; ?></text>
                <?php endforeach; ?>
            </svg>

            <?php /* Peníze, které reálně přišly (i zálohy) — vedle tržby, ne místo ní.
                     Zálohy jsou schválně vypíchnuté zvlášť: účetně to není výnos. */ ?>
            <div class="crm-revenue-cash" style="margin-top:12px;padding-top:10px;border-top:1px solid rgba(128,128,128,.22);font-size:12px;line-height:1.75;">
                <div class="d-flex justify-content-between gap-2">
                    <span style="opacity:.65;">Přijaté peníze (měsíc)</span>
                    <strong><?php echo number_format($received_month, 0, ',', ' '); ?> Kč</strong>
                </div>
                <div class="d-flex justify-content-between gap-2">
                    <span style="opacity:.65;">z toho zálohy</span>
                    <strong class="text-warning"><?php echo number_format($advance_month, 0, ',', ' '); ?> Kč</strong>
                </div>
                <div class="d-flex justify-content-between gap-2">
                    <span style="opacity:.65;">Dnes přijato / z toho zálohy</span>
                    <strong><?php echo number_format($received_today, 0, ',', ' '); ?> / <span class="text-warning"><?php echo number_format($advance_today, 0, ',', ' '); ?></span> Kč</strong>
                </div>
                <div style="opacity:.5;margin-top:6px;">Zálohy nejsou tržba — výnos vzniká až dokončením zakázky.</div>
            </div>
        </div>

        <div class="card glass-card border-0 mb-4 imei-check-card">
            <div class="card-header bg-transparent border-bottom-0 d-flex align-items-center">
                <h5 class="mb-0"><i class="fas fa-mobile-screen-button text-info me-2"></i><?php echo __('imei_check_title'); ?></h5>
            </div>
            <div class="card-body">
                <form id="imeiCheckForm">
                    <label class="form-label"><?php echo __('serial'); ?></label>
                    <div class="mb-2">
                        <input type="text" class="form-control w-100" id="imeiCheckInput" placeholder="<?php echo e(__('imei_input_placeholder')); ?>" inputmode="numeric" autocomplete="off" maxlength="15">
                    </div>
                    <div class="text-center">
                        <button class="btn btn-outline-info px-4" type="submit">
                            <i class="fas fa-search me-1"></i><?php echo __('check'); ?>
                        </button>
                    </div>
                    <div class="form-text text-white-75"><?php echo __('imei_help_text'); ?></div>
                </form>
                <div id="imeiCheckResult" class="imei-check-result mt-3" aria-live="polite"></div>
            </div>
        </div>

        <?php /* Sekce „Fronta dnes" odstraněna 29.7.2026 na přání majitele —
                 stejné informace jsou v hlavní tabulce Nástěnky. */ ?>
        <div class="card glass-card border-0 mb-4 mt-5">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('quick_actions'); ?></h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#newOrderModal"><i class="fas fa-plus me-2"></i> <?php echo __('new_order'); ?></button>
                    <?php if (hasPermission('edit_customers')): ?>
                    <a href="customers.php" class="btn btn-outline-secondary"><i class="fas fa-user-plus me-2"></i> <?php echo __('customers'); ?></a>
                    <?php endif; ?>
                    <?php if (hasPermission('manage_inventory')): ?>
                    <a href="inventory.php" class="btn btn-outline-info"><i class="fas fa-search me-2"></i> <?php echo __('check_stock'); ?></a>
                    <?php endif; ?>
                    <a href="dokument.php?type=vykup" target="_blank" rel="noopener" class="btn btn-outline-light mt-2">
                        <i class="fas fa-file-signature me-2"></i> <?php echo __('buyout_sheet_purchase_agreement'); ?>
                    </a>
                    <a href="dokument.php?type=zastava" target="_blank" rel="noopener" class="btn btn-outline-light">
                        <i class="fas fa-file-contract me-2"></i> <?php echo __('pawn_form'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Dashboard Right Column (Techs list if Admin) -->
        <?php if ($_SESSION['role'] == 'admin'): ?>
        <div class="card glass-card border-0 mb-4">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><?php echo __('online_techs'); ?></h5>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php
                    $all_techs = $pdo->query("SELECT name, last_seen FROM technicians WHERE is_active = 1 ORDER BY last_seen DESC")->fetchAll();
                    foreach ($all_techs as $tech):
                        $is_online = (strtotime($tech['last_seen'] ?? '0') > strtotime("-5 minutes"));
                    ?>
                    <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center">
                            <div class="position-relative me-3">
                                <i class="fas fa-user-circle fa-2x text-white-75 opacity-50"></i>
                                <span class="position-absolute bottom-0 end-0 p-1 <?php echo $is_online ? 'bg-success' : 'bg-secondary'; ?> border border-light rounded-circle"></span>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($tech['name']); ?></div>
                                <small class="text-white-75">
                                    <?php echo $is_online ? __('tech_online') : __('tech_last_seen') . ': ' . ($tech['last_seen'] ? date('H:i, d.m', strtotime($tech['last_seen'])) : __('never')); ?>
                                </small>
                            </div>
                        </div>
                        <?php if ($is_online): ?>
                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2"><?php echo __('tech_online'); ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>



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

