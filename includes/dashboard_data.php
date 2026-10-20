<?php
/**
 * Nástěnka v2 (3.97.0) — data pro index.php. Nástěnka = „co je dnes potřeba",
 * ne statistiky (ty zůstávají v Přehledech / reports.php).
 *
 * Viditelnost:
 *  - PENÍZE (tržba, kasa, měsíc): admin + Boss vidí obě pobočky (součet + rozpad),
 *    ostatní VÝHRADNĚ svou pobočku — schválně bez výjimky „zakázky přiřazené mně"
 *    z orderBranchScopeSql, jinak by technik viděl tržbu cizí pobočky.
 *  - PROVOZ (úkoly, tok zakázek, seznamy): stejný rozsah jako seznam zakázek
 *    (addOrderBranchScope / orderBranchScopeSql s technikem).
 * Importované zakázky (source = 'legacy') do úkolů ani peněz nevstupují.
 */

require_once __DIR__ . '/pos_shift.php';

/** Pobočky, které nástěnka ukazuje (bez demo pobočky pro App Store review). */
function crmDashBranches(): array {
    $out = [];
    foreach (getBranches(true) as $b) {
        if (strtoupper((string)($b['code'] ?? '')) === 'DEMO') { continue; }
        $out[(int)$b['id']] = (string)$b['name'];
    }
    return $out;
}

/** Krátký název pobočky do rozpadu („Praha 8 - Karlín" → „Karlín"). */
function crmDashBranchShort(string $name): string {
    $p = preg_split('/\s+-\s+/u', $name);
    return trim((string)end($p)) ?: $name;
}

/** „před 3 dny" / „před 5 h" — stáří bez pohybu v řádcích úkolů. */
function crmDashAge(?string $when): string {
    $t = $when ? strtotime($when) : 0;
    if (!$t) { return ''; }
    $s = max(0, time() - $t);
    if ($s < 3600) { return max(1, (int)floor($s / 60)) . ' min'; }
    if ($s < 86400) { return (int)floor($s / 3600) . ' h'; }
    $d = (int)floor($s / 86400);
    return $d . ' ' . ($d === 1 ? 'den' : ($d < 5 ? 'dny' : 'dní'));
}

/** Značka + model bez zdvojení („Apple Apple MacBook…" když model značku už obsahuje). */
function crmDashDevice(array $r): string {
    $brand = trim((string)($r['device_brand'] ?? ''));
    $model = trim((string)($r['device_model'] ?? ''));
    if ($brand !== '' && mb_stripos($model, $brand) === 0) { return $model; }
    return trim($brand . ' ' . $model);
}

function crmDashboardBuild(PDO $pdo): array {
    $global = isBranchGlobalViewer();
    $myBranch = (int)getCurrentStaffBranchId();
    $tid = (int)($_SESSION['tech_id'] ?? 0);
    $branches = crmDashBranches();
    if (!$global) {
        $branches = [$myBranch => getBranchLabel($myBranch) ?: ($branches[$myBranch] ?? '')];
    }
    ensureOrdersSourceColumn();
    $noLeg = " AND o.source <> 'legacy'";
    $scope = orderBranchScopeSql('o.branch_id', 'o.technician_id');
    $in = fn(string $k) => orderStatusSqlIn($pdo, $k);
    $D = [
        'global' => $global, 'my_branch' => $myBranch, 'tech_id' => $tid,
        'branches' => $branches,
        'scope_label' => $global ? 'Obě pobočky' : crmDashBranchShort(getBranchLabel($myBranch)),
    ];

    // ── PENÍZE DNES / MĚSÍC — po pobočkách ─────────────────────────────────────
    $money = [];
    $dayStart = date('Y-m-d 00:00:00');
    $prevFrom = date('Y-m-01', strtotime('first day of last month'));
    // stejný den minulého měsíce (31. → poslední den kratšího měsíce)
    $prevTo = date('Y-m-d', min(strtotime($prevFrom . ' +' . ((int)date('j') - 1) . ' days'),
                                strtotime('last day of last month'))) . ' 23:59:59';
    $done = $in('done');
    foreach ($branches as $bid => $bname) {
        $m = ['id' => $bid, 'name' => crmDashBranchShort($bname),
              'done_n' => 0, 'done_sum' => 0.0, 'done_yday' => 0.0,
              'pos_sum' => 0.0, 'pos_cash' => 0.0, 'pos_card' => 0.0, 'pos_other' => 0.0, 'pos_n' => 0,
              'drawer' => null, 'shift' => null, 'month' => 0.0, 'month_prev_same' => 0.0];
        try {
            $st = $pdo->prepare("SELECT
                    SUM(o.updated_at >= CURDATE()) AS n,
                    COALESCE(SUM(CASE WHEN o.updated_at >= CURDATE() THEN o.final_cost END), 0) AS s,
                    COALESCE(SUM(CASE WHEN o.updated_at >= CURDATE() - INTERVAL 1 DAY AND o.updated_at < CURDATE() THEN o.final_cost END), 0) AS y,
                    COALESCE(SUM(CASE WHEN o.updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN o.final_cost END), 0) AS mo,
                    COALESCE(SUM(CASE WHEN o.updated_at >= ? AND o.updated_at <= ? THEN o.final_cost END), 0) AS mp
                FROM orders o
                WHERE o.status IN ($done) AND o.branch_id = ? AND o.updated_at >= ?" . $noLeg);
            $st->execute([$prevFrom . ' 00:00:00', $prevTo, $bid, $prevFrom . ' 00:00:00']);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $m['done_n'] = (int)($r['n'] ?? 0);
            $m['done_sum'] = (float)($r['s'] ?? 0);
            $m['done_yday'] = (float)($r['y'] ?? 0);
            $m['month'] = (float)($r['mo'] ?? 0);
            $m['month_prev_same'] = (float)($r['mp'] ?? 0);
        } catch (Throwable $e) {}
        try {
            ensurePosTables();
            $st = $pdo->query("SELECT payment_method, COUNT(*) n, COALESCE(SUM(total),0) s FROM pos_sales
                WHERE status = 'completed' AND created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY"
                . afxCashBranchSql('branch_id', $bid) . " GROUP BY payment_method");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $s = (float)$r['s'];
                $m['pos_sum'] += $s; $m['pos_n'] += (int)$r['n'];
                if ($r['payment_method'] === 'cash') { $m['pos_cash'] += $s; }
                elseif ($r['payment_method'] === 'card') { $m['pos_card'] += $s; }
                else { $m['pos_other'] += $s; }
            }
        } catch (Throwable $e) {}
        try { $m['drawer'] = afxCashBalanceAt($bid, date('Y-m-d')); } catch (Throwable $e) {}
        try {
            $sh = afxPosShiftCurrent($bid);
            if ($sh) { $m['shift'] = ['by' => (string)$sh['opened_by'], 'at' => (string)$sh['opened_at']]; }
        } catch (Throwable $e) {}
        $money[$bid] = $m;
    }
    $sum = fn(string $k) => array_sum(array_map(fn($m) => (float)$m[$k], $money));
    $D['money'] = $money;
    $D['money_total'] = [
        'done_n' => (int)$sum('done_n'), 'done_sum' => $sum('done_sum'), 'done_yday' => $sum('done_yday'),
        'pos_sum' => $sum('pos_sum'), 'pos_cash' => $sum('pos_cash'), 'pos_card' => $sum('pos_card'),
        'pos_other' => $sum('pos_other'), 'pos_n' => (int)$sum('pos_n'),
        'month' => $sum('month'), 'month_prev_same' => $sum('month_prev_same'),
    ];
    $mt = $D['money_total'];
    $D['month_pace'] = $mt['month_prev_same'] > 0 ? (int)round((($mt['month'] - $mt['month_prev_same']) / $mt['month_prev_same']) * 100) : null;
    $D['day_trend'] = $mt['done_yday'] > 0 ? (int)round((($mt['done_sum'] - $mt['done_yday']) / $mt['done_yday']) * 100) : null;

    // Zálohy (přijaté, ale účetně ještě ne výnos) — stejná logika jako dřív, jen měsíc
    $D['received_month'] = $D['advance_month'] = 0.0;
    try {
        if (function_exists('afxEnsureInvoicePayments')) { afxEnsureInvoicePayments(); }
        $hasPaid = (bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'paid_amount'")->fetch();
        $adv = $hasPaid
            ? " AND IF(i.paid_amount > 0, i.paid_amount < i.total_amount - IF(i.total_amount >= 100, 1.0, 0.0), i.status <> 'paid')"
            : " AND i.status <> 'paid'";
        // faktura bez zakázky patří výchozí pobočce (jinak by ji započítaly obě)
        $bScope = $global ? '' : ' AND (o.branch_id = ' . $myBranch . ($myBranch === getDefaultBranchId() ? ' OR o.id IS NULL' : '') . ')';
        $base = "FROM invoice_payments p JOIN invoices i ON i.id = p.invoice_id LEFT JOIN orders o ON o.id = i.order_id
                 WHERE i.status <> 'cancelled' AND COALESCE(i.invoice_type, 'invoice') <> 'credit_note'" . $bScope . "
                   AND COALESCE(p.paid_on, DATE(p.created_at)) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $D['received_month'] = (float)$pdo->query("SELECT COALESCE(SUM(p.amount),0) " . $base)->fetchColumn();
        $D['advance_month'] = (float)$pdo->query("SELECT COALESCE(SUM(p.amount),0) " . $base . $adv)->fetchColumn();
    } catch (Throwable $e) {}

    // ── TOK ZAKÁZEK (pipeline) ────────────────────────────────────────────────
    $flow = [
        ['key' => 'new',              'label' => 'Přijato',           'filter' => 'Přijato',               'tone' => 'blue'],
        ['key' => 'in_progress',      'label' => 'V opravě',          'filter' => 'V opravě',              'tone' => 'violet'],
        ['key' => 'waiting_parts',    'label' => 'Čeká na díl',       'filter' => 'Čeká na díl',           'tone' => 'orange'],
        ['key' => 'pending_approval', 'label' => 'Čeká na klienta',   'filter' => 'Čeká na zákazníka',     'tone' => 'yellow'],
        ['key' => 'completed',        'label' => 'K vyzvednutí',      'filter' => 'Připraveno k převzetí', 'tone' => 'green'],
    ];
    foreach ($flow as &$f) {
        $f['n'] = $f['leg'] = 0;
        try {
            $r = $pdo->query("SELECT SUM(o.source <> 'legacy') a, SUM(o.source = 'legacy') b FROM orders o WHERE o.status IN (" . $in($f['key']) . ")" . $scope)->fetch();
            $f['n'] = (int)($r['a'] ?? 0); $f['leg'] = (int)($r['b'] ?? 0);
        } catch (Throwable $e) {}
    }
    unset($f);
    $D['flow'] = $flow;
    $D['new_today'] = 0;
    try { $D['new_today'] = (int)$pdo->query("SELECT COUNT(*) FROM orders o WHERE o.created_at >= CURDATE()" . $noLeg . $scope)->fetchColumn(); } catch (Throwable $e) {}

    // ── NA ŘADĚ — úkoly, které čekají na člověka ─────────────────────────────
    $tasks = [];
    $cols = "o.id, o.order_code, o.legacy_code, o.source, o.status, o.priority, o.device_brand, o.device_model,
             o.technician_id, o.branch_id, o.created_at, o.updated_at, o.final_cost, o.estimated_cost,
             o.customer_id, o.device_type, o.problem_description, o.device_branch_id, o.prepaid_at,
             c.first_name, c.last_name, c.phone, c.company, t.name AS tech_name,
             (SELECT MAX(l.changed_at) FROM order_status_log l WHERE l.order_id = o.id) AS last_status_change";
    $from = " FROM orders o JOIN customers c ON c.id = o.customer_id LEFT JOIN technicians t ON t.id = o.technician_id ";
    try { ensurePickupReadyColumns($pdo); $cols .= ", o.pickup_notified_at"; } catch (Throwable $e) {}
    $rowOf = function (array $r, string $info, string $when = '') {
        $last = max(array_filter([strtotime((string)($r['last_status_change'] ?? '')), strtotime((string)$r['updated_at'])]) ?: [0]);
        return [
            'id' => (int)$r['id'], 'code' => orderDisplayCode($r),
            'client' => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            'phone' => (string)($r['phone'] ?? ''),
            'device' => crmDashDevice($r),
            'status' => (string)$r['status'], 'info' => $info,
            'age' => $when !== '' ? $when : crmDashAge($last ? date('Y-m-d H:i:s', $last) : null),
            'high' => ($r['priority'] ?? '') === 'High',
            'branch' => (int)($r['branch_id'] ?? 0),
        ];
    };
    $open = [];
    try {
        $open = $pdo->query("SELECT $cols $from WHERE o.status IN (" . $in('active') . "," . $in('completed') . ","
            . $pdo->quote('Vydáno - čeká na platbu') . ")" . $noLeg . $scope . " ORDER BY o.id DESC LIMIT 1500")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $go = crmGoLiveTs();
    $bucket = ['unassigned' => [], 'stale' => [], 'customer' => [], 'pickup' => [], 'unpaid' => [], 'mine' => [], 'forgotten' => []];
    $month = 30 * 86400;
    foreach ($open as $r) {
        $st = (string)$r['status'];
        $grp = getOrderStatusGroup($st);
        $active = in_array($grp, ['new', 'pending_approval', 'in_progress', 'waiting_parts'], true);
        $sec = orderStaleSeconds($r);
        // Přes 30 dní bez jakéhokoli pohybu = zakázka nejspíš už skončila, jen se v CRM
        // neuzavřela. Nemíchat ji mezi denní úkoly (zahltila by je) — vlastní skupina.
        if (($active || $grp === 'completed') && $sec >= $month) {
            $bucket['forgotten'][] = [-$sec, $rowOf($r, getOrderStatusLabel($st))];
            if ($active && $tid > 0 && (int)$r['technician_id'] === $tid) {
                $bucket['mine'][] = [($r['priority'] === 'High' ? 1 : 0) * 1e9 - $sec, $r];
            }
            continue;
        }
        if ($active && (int)$r['technician_id'] <= 0) {
            $bucket['unassigned'][] = [$sec, $rowOf($r, getOrderStatusLabel($st))];
        }
        if ($active && $tid > 0 && (int)$r['technician_id'] === $tid) {
            $bucket['mine'][] = [($r['priority'] === 'High' ? 1 : 0) * 1e9 + $month - $sec, $r];
        }
        if ($grp === 'pending_approval') {
            $bucket['customer'][] = [$sec, $rowOf($r, 'čeká na odpověď')];
        } elseif ($active && strtotime((string)$r['created_at']) >= $go) {
            // Limity ve dnech, ne v hodinách jako blikání řádků v seznamu — tady jde
            // o to, co je potřeba dnes rozhýbat, ne o každou zakázku starší 2 h.
            $limitDays = ['new' => 1, 'in_progress' => 2, 'waiting_parts' => 7][$grp] ?? 2;
            if (preg_match('/extern|aut/iu', $st)) { $limitDays = 14; }
            if ($sec >= $limitDays * 86400) {
                $bucket['stale'][] = [$sec, $rowOf($r, getOrderStatusLabel($st))];
            }
        }
        if ($grp === 'completed' && $sec >= 3 * 86400) {
            $n = !empty($r['pickup_notified_at']) ? 'upozorněn ' . date('j. n.', strtotime((string)$r['pickup_notified_at'])) : 'klient zatím neupozorněn';
            $bucket['pickup'][] = [$sec, $rowOf($r, $n)];
        }
        if ($st === 'Vydáno - čeká na platbu') {
            $amt = (float)($r['final_cost'] ?: $r['estimated_cost']);
            $bucket['unpaid'][] = [$sec, $rowOf($r, $amt > 0 ? formatMoney($amt) : 'vydáno bez platby')];
        }
    }
    $sortRows = function (array $b, int $limit = 30) {
        usort($b, fn($x, $y) => $y[0] <=> $x[0]);
        return array_map(fn($x) => $x[1], array_slice($b, 0, $limit));
    };

    // dnešní rezervace z webu — vždy nahoře, i když už jsou převedené na zakázku
    $book = [];
    try {
        ensureWebBookingsSchema();
        // rezervace bez zakázky patří výchozí pobočce (tam je převod zakládá)
        $bScope = $global ? '' : ' AND (o.branch_id = ' . $myBranch . ($myBranch === getDefaultBranchId() ? ' OR o.id IS NULL' : '') . ')';
        $book = $pdo->query("SELECT wb.id, wb.customer_name, wb.phone, wb.device, wb.service, wb.appointment_at, wb.order_id,
                o.order_code, o.legacy_code, o.source, o.status, o.branch_id
            FROM web_bookings wb LEFT JOIN orders o ON o.id = wb.order_id
            WHERE wb.appointment_at >= CURDATE() AND wb.appointment_at < CURDATE() + INTERVAL 1 DAY
              AND wb.status <> 'dismissed'" . $bScope . " ORDER BY wb.appointment_at")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if ($book) {
        $rows = [];
        $upcoming = 0;
        foreach ($book as $b) {
            $ts = strtotime((string)$b['appointment_at']);
            if ($ts >= time() - 1800) { $upcoming++; }
            $rows[] = [
                'id' => (int)($b['order_id'] ?? 0), 'code' => $b['order_id'] ? orderDisplayCode($b) : '',
                'client' => (string)$b['customer_name'], 'phone' => (string)$b['phone'],
                'device' => trim((string)$b['device'] . ($b['service'] ? ' · ' . $b['service'] : '')),
                'status' => (string)($b['status'] ?? ''), 'info' => $b['status'] ? getOrderStatusLabel((string)$b['status']) : '',
                'age' => date('H:i', $ts), 'high' => $ts >= time() - 1800 && $ts <= time() + 3600, 'branch' => (int)($b['branch_id'] ?? 0),
                'past' => $ts < time() - 1800,
            ];
        }
        $tasks[] = ['key' => 'bookings', 'tone' => 'blue', 'icon' => 'fa-calendar-check',
            'title' => 'Dnešní rezervace z webu', 'count' => count($rows),
            'hint' => $upcoming > 0 ? 'ještě dorazí ' . $upcoming : 'všechny termíny už proběhly', 'rows' => $rows, 'time' => true];
    }
    $add = function (string $key, string $tone, string $icon, string $title, string $hint, array $b, string $link = '') use (&$tasks, $sortRows) {
        if (!$b) { return; }
        $tasks[] = ['key' => $key, 'tone' => $tone, 'icon' => $icon, 'title' => $title, 'count' => count($b),
            'hint' => $hint, 'rows' => $sortRows($b), 'link' => $link];
    };
    $add('unassigned', 'red', 'fa-user-plus', 'Bez technika', 'přiřaď zakázku technikovi', $bucket['unassigned']);
    $add('stale', 'orange', 'fa-hourglass-half', 'Dlouho bez pohybu', 'zakázka stojí déle, než by měla', $bucket['stale']);
    $add('customer', 'yellow', 'fa-phone', 'Čeká na vyjádření klienta', 'zavolej a domluv cenu / postup', $bucket['customer'], 'orders.php?filter=' . urlencode('Čeká na zákazníka'));
    $add('pickup', 'green', 'fa-box-open', 'Hotové, nevyzvednuté přes 3 dny', 'připomeň klientovi vyzvednutí', $bucket['pickup'], 'orders.php?filter=' . urlencode('Připraveno k převzetí'));
    $add('unpaid', 'red', 'fa-money-bill-wave', 'Vydáno, ale nezaplaceno', 'dořeš platbu', $bucket['unpaid'], 'orders.php?filter=' . urlencode('Vydáno - čeká na platbu'));
    $add('forgotten', 'gray', 'fa-box-archive', 'Zapomenuté zakázky', 'přes 30 dní bez pohybu — vydej, nebo stornuj', $bucket['forgotten']);

    // reklamace — zákonná lhůta 30 dní od přijetí
    try {
        ensureComplaintsClientColumns($pdo);
        $cScope = $global ? '' : ' AND (o.branch_id = ' . $myBranch . ' OR c.order_id IS NULL OR c.order_id = 0)';
        $cs = $pdo->query("SELECT c.id, c.complaint_code, c.device, c.phone, c.complaint_status, c.created_at, c.order_code,
                cu.first_name, cu.last_name
            FROM complaints c LEFT JOIN orders o ON o.id = c.order_id LEFT JOIN customers cu ON cu.id = c.customer_id
            WHERE c.complaint_status NOT IN ('Vyřízeno','Zamítnuto') AND c.resolved_at IS NULL" . $cScope . "
            ORDER BY c.created_at ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        if ($cs) {
            $rows = [];
            $urgent = 0;
            foreach ($cs as $c) {
                $left = 30 - (int)floor((time() - strtotime((string)$c['created_at'])) / 86400);
                if ($left <= 7) { $urgent++; }
                $rows[] = ['href' => 'view_complaint.php?id=' . (int)$c['id'], 'id' => 0,
                    'code' => (string)$c['complaint_code'], 'client' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
                    'phone' => (string)$c['phone'], 'device' => (string)$c['device'], 'status' => '',
                    'info' => (string)$c['complaint_status'],
                    'age' => $left >= 0 ? 'lhůta ' . $left . ' d' : 'po lhůtě!', 'high' => $left <= 7, 'branch' => 0];
            }
            $tasks[] = ['key' => 'complaints', 'tone' => $urgent ? 'red' : 'violet', 'icon' => 'fa-shield-halved',
                'title' => 'Otevřené reklamace', 'count' => count($rows),
                'hint' => $urgent ? $urgent . '× končí lhůta do 7 dní' : 'vyřiď do 30 dní od přijetí', 'rows' => $rows, 'link' => 'reklamace.php'];
        }
    } catch (Throwable $e) {}

    // nákupní seznam — požadavky čekající na objednání (jen kdo smí objednávat)
    if (crmCanManageProcurement()) {
        try {
            if (ensureProcurementSchema()) {
                $pr = $pdo->query("SELECT pr.id, pr.item_name, pr.quantity, pr.priority, pr.created_at, o.order_code, o.legacy_code, o.source, o.id AS oid
                    FROM purchase_requests pr LEFT JOIN orders o ON o.id = pr.order_id
                    WHERE pr.status = 'pending'" . ($global ? '' : ' AND (o.id IS NULL OR o.branch_id = ' . $myBranch . ')') . "
                    ORDER BY FIELD(pr.priority,'today','this_week','later'), pr.created_at LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
                if ($pr) {
                    $rows = []; $today = 0;
                    foreach ($pr as $p) {
                        if ($p['priority'] === 'today') { $today++; }
                        $rows[] = ['href' => 'nakupni-seznam.php', 'id' => 0, 'code' => $p['oid'] ? orderDisplayCode($p) : '',
                            'client' => (int)$p['quantity'] . '× ' . $p['item_name'], 'phone' => '', 'device' => '',
                            'status' => '', 'info' => ['today' => 'objednat dnes', 'this_week' => 'tento týden', 'later' => 'později'][$p['priority']] ?? '',
                            'age' => crmDashAge((string)$p['created_at']), 'high' => $p['priority'] === 'today', 'branch' => 0];
                    }
                    $tasks[] = ['key' => 'procurement', 'tone' => $today ? 'orange' : 'blue', 'icon' => 'fa-cart-flatbed',
                        'title' => 'Díly k objednání', 'count' => count($rows),
                        'hint' => $today ? $today . '× objednat ještě dnes' : 'nákupní seznam', 'rows' => $rows, 'link' => 'nakupni-seznam.php'];
                }
            }
        } catch (Throwable $e) {}
    }

    // zapůjčená zařízení — upozornit, když jsou venku déle než 14 dní
    try {
        ensureProductsLoanColumns();
        $ln = $pdo->query("SELECT id, title, product_code, branch_id, loan_to, loan_at FROM products
            WHERE loan_at IS NOT NULL" . ($global ? '' : ' AND branch_id = ' . $myBranch) . " ORDER BY loan_at ASC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        if ($ln) {
            $rows = []; $long = 0;
            foreach ($ln as $l) {
                $days = (int)floor((time() - strtotime((string)$l['loan_at'])) / 86400);
                if ($days >= 14) { $long++; }
                $rows[] = ['href' => 'products.php?avail=loan', 'id' => 0, 'code' => (string)$l['product_code'],
                    'client' => (string)$l['loan_to'], 'phone' => '', 'device' => (string)$l['title'], 'status' => '',
                    'info' => 'od ' . date('j. n.', strtotime((string)$l['loan_at'])), 'age' => $days . ' d', 'high' => $days >= 14, 'branch' => (int)$l['branch_id']];
            }
            $tasks[] = ['key' => 'loans', 'tone' => $long ? 'orange' : 'blue', 'icon' => 'fa-handshake',
                'title' => 'Zapůjčená zařízení', 'count' => count($rows),
                'hint' => $long ? $long . '× venku déle než 14 dní' : 'vrátit při vyzvednutí opravy', 'rows' => $rows, 'link' => 'products.php?avail=loan'];
        }
    } catch (Throwable $e) {}

    // e-shop — zaplacené k odeslání a staré rezervace (detail v okně e-shopu)
    if (crmCanManageProducts()) {
        try {
            ensureEshopOrdersTable();
            $es = $pdo->query("SELECT SUM(status = 'paid') pay, SUM(status = 'reserved' AND created_at < NOW() - INTERVAL 2 DAY) res FROM eshop_orders")->fetch();
            $pay = (int)($es['pay'] ?? 0); $res = (int)($es['res'] ?? 0);
            if ($pay + $res > 0) {
                $tasks[] = ['key' => 'eshop', 'tone' => 'green', 'icon' => 'fa-cart-shopping', 'title' => 'E-shop k vyřízení',
                    'count' => $pay + $res, 'rows' => [], 'eshop' => true,
                    'hint' => trim(($pay ? $pay . '× zaplaceno, odeslat / předat' : '') . ($pay && $res ? ' · ' : '') . ($res ? $res . '× rezervace čeká přes 2 dny' : ''))];
            }
        } catch (Throwable $e) {}
    }

    // pořadí: co nejvíc hoří nahoru (rezervace dnes vždy první)
    $rank = ['bookings' => 0, 'unpaid' => 1, 'unassigned' => 2, 'complaints' => 3, 'customer' => 4, 'stale' => 5, 'eshop' => 6, 'procurement' => 7, 'pickup' => 8, 'loans' => 9, 'forgotten' => 10];
    usort($tasks, fn($a, $b) => ($rank[$a['key']] ?? 99) <=> ($rank[$b['key']] ?? 99));
    $D['tasks'] = $tasks;

    // ── MOJE PRÁCE (technik) ─────────────────────────────────────────────────
    usort($bucket['mine'], fn($x, $y) => $y[0] <=> $x[0]);
    $D['mine'] = array_map(fn($x) => $x[1], array_slice($bucket['mine'], 0, 30));
    $D['mine_count'] = count($bucket['mine']);

    // ── TÝM DNES — plán směn + kdo je online + kdo drží kasu ────────────────
    $team = [];
    try {
        $bIds = implode(',', array_map('intval', array_keys($branches))) ?: '0';
        $techs = $pdo->query("SELECT id, name, branch_id, last_seen FROM technicians WHERE is_active = 1 AND branch_id IN ($bIds)")->fetchAll(PDO::FETCH_ASSOC);
        $plan = [];
        try {
            foreach ($pdo->query("SELECT tech_id, branch_id, time_from, time_to FROM shift_plan WHERE work_date = CURDATE() AND branch_id IN ($bIds)")->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $plan[(int)$p['tech_id']] = $p;
            }
        } catch (Throwable $e) {}
        $holders = [];
        foreach ($money as $m) { if ($m['shift']) { $holders[mb_strtolower(trim($m['shift']['by']))] = $m['id']; } }
        foreach ($techs as $t) {
            $id = (int)$t['id'];
            $online = $t['last_seen'] && strtotime((string)$t['last_seen']) > time() - 300;
            $p = $plan[$id] ?? null;
            $seenToday = $t['last_seen'] && strtotime((string)$t['last_seen']) >= strtotime('today');
            if (!$p && !$seenToday) { continue; }
            // účty zařízení (podpisová stanice, App Store review) nejsou lidé ve směně
            if (!$p && preg_match('/^podpis|apple review/iu', (string)$t['name'])) { continue; }
            $team[] = [
                'name' => (string)$t['name'], 'branch' => (int)($p['branch_id'] ?? $t['branch_id']),
                'online' => $online, 'seen' => $t['last_seen'] ? date('H:i', strtotime((string)$t['last_seen'])) : '',
                'plan' => $p ? substr((string)$p['time_from'], 0, 5) . '–' . substr((string)$p['time_to'], 0, 5) : '',
                'kasa' => isset($holders[mb_strtolower(trim((string)$t['name']))]),
            ];
        }
        usort($team, fn($a, $b) => [$b['online'], $b['kasa'], $a['name']] <=> [$a['online'], $a['kasa'], $b['name']]);
    } catch (Throwable $e) {}
    $D['team'] = $team;

    return $D;
}
