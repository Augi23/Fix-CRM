<?php
/**
 * ÚČETNICTVÍ → PRODEJ (v3.64.0)
 *
 * Seznam JEDNOTLIVÝCH prodejů z kasy. Faktury ukazují jen to, co se fakturovalo,
 * a sestava „Tržby z pokladny po dnech" jen denní součty — tady je vidět doklad
 * po dokladu i s tím, co se prodalo, kdo to prodal a jak bylo zaplaceno.
 *
 * Výpis je čtecí; JEDINÝ zápis odsud je „Vystavit fakturu" k už proběhlému
 * prodeji (v3.71.0, api/pos_invoice_after.php). Storna zůstávají vidět, jen se
 * nepočítají do tržby — doklad, který zmizí, je pro účetní horší než doklad
 * označený jako stornovaný.
 *
 * Přístup: vedení (crmCanManageInvoices) nebo účetní (crmCanAccountingRead) —
 * tedy stejná hranice jako zbytek sekce Účetnictví.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once __DIR__ . '/includes/ucetni_reports.php';

if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !afxUcetniCanRead()) {
    header('Location: index.php');
    exit;
}
require_once 'includes/header.php';

$period = afxUcetniResolvePeriod($_GET);
// Účetní vidí obě provozovny (účetnictví se vede za firmu) — stejné odůvodnění
// jako v ucetni_sestavy.php, jinak by jí afxUcetniForcedBranchId() zamkla
// pobočku z karty zaměstnance a půlka prodejů by tiše zmizela.
if (function_exists('crmIsAccountant') && crmIsAccountant()) {
    $branchId = 0;
    $__raw = (int)($_GET['pobocka'] ?? 0);
    if ($__raw > 0) {
        foreach (afxUcetniBranchList() as $__b) { if ((int)$__b['id'] === $__raw) { $branchId = $__raw; break; } }
    }
} else {
    $branchId = afxUcetniResolveBranch($_GET['pobocka'] ?? 0);
}

$payFilter = (string)($_GET['platba'] ?? '');
$payLabels = ['cash' => 'Hotově', 'card' => 'Kartou', 'invoice' => 'Faktura s.r.o.', 'invoice_ico' => 'Faktura IČO'];
if (!isset($payLabels[$payFilter])) { $payFilter = ''; }
$q = trim((string)($_GET['q'] ?? ''));
$showCancelled = !empty($_GET['storna']);

$rows = [];
$sums = ['celkem' => 0.0, 'doklady' => 0, 'cash' => 0.0, 'card' => 0.0, 'invoice' => 0.0, 'invoice_ico' => 0.0,
         'storno_pocet' => 0, 'storno_castka' => 0.0, 'zaklad' => 0.0, 'dph' => 0.0, 'pouzite' => 0.0];
$missing = false;

if (!afxUcetniTableExists('pos_sales')) {
    $missing = true;
} else {
    $where = ['DATE(s.created_at) BETWEEN ? AND ?'];
    $par = [$period['from'], $period['to']];
    if ($branchId > 0) { $where[] = 's.branch_id = ?'; $par[] = $branchId; }
    if ($payFilter !== '') { $where[] = 's.payment_method = ?'; $par[] = $payFilter; }
    if (!$showCancelled) { $where[] = "COALESCE(s.status, 'completed') <> 'cancelled'"; }
    if ($q !== '') {
        $where[] = "(s.sale_number LIKE ? OR s.seller_name LIKE ? OR s.note LIKE ?
                     OR EXISTS (SELECT 1 FROM pos_sale_items i2 WHERE i2.sale_id = s.id
                                AND (i2.item_name LIKE ? OR i2.item_code LIKE ?)))";
        $like = '%' . $q . '%';
        array_push($par, $like, $like, $like, $like, $like);
    }
    $sql = "SELECT s.*, b.name AS branch_name,
                   TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) AS cust_name,
                   c.company AS cust_company, iv.invoice_number
            FROM pos_sales s
            LEFT JOIN branches b ON b.id = s.branch_id
            LEFT JOIN customers c ON c.id = s.customer_id
            LEFT JOIN invoices iv ON iv.id = s.invoice_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.created_at DESC, s.id DESC LIMIT 500";
    $rows = afxUcetniQuery($sql, $par);

    // položky k dokladům jedním dotazem (ne N+1)
    $items = [];
    if ($rows) {
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $in = implode(',', $ids);
        foreach (afxUcetniQuery("SELECT * FROM pos_sale_items WHERE sale_id IN ($in) ORDER BY id ASC") as $it) {
            $items[(int)$it['sale_id']][] = $it;
        }
    }

    foreach ($rows as $r) {
        $isStorno = (string)($r['status'] ?? 'completed') === 'cancelled';
        if ($isStorno) {
            $sums['storno_pocet']++;
            $sums['storno_castka'] += (float)$r['total'];
            continue;                      // storno není tržba
        }
        $sums['doklady']++;
        $sums['celkem'] += (float)$r['total'];
        $pm = (string)$r['payment_method'];
        if (isset($sums[$pm])) { $sums[$pm] += (float)$r['total']; }
        // DPH: použité zboží jede v režimu §90 (bez nároku na odpočet u kupujícího),
        // takže se do základu a daně nepočítá — jinak by sestava lhala.
        $vat = (float)($r['vat_rate'] ?? 21);
        foreach ($items[(int)$r['id']] ?? [] as $it) {
            $line = (float)$it['unit_price'] * (int)$it['quantity'];
            if (!empty($it['is_used_goods']) || empty($r['is_vat_payer'])) { $sums['pouzite'] += $line; continue; }
            $base = $vat > 0 ? $line / (1 + $vat / 100) : $line;
            $sums['zaklad'] += $base;
            $sums['dph'] += $line - $base;
        }
    }
}

$branches = afxUcetniBranchList();
$qs = static function (array $over) use ($period, $branchId, $payFilter, $q, $showCancelled): string {
    $p = array_merge([
        'od' => $period['from'], 'do' => $period['to'], 'pobocka' => $branchId ?: null,
        'platba' => $payFilter ?: null, 'q' => $q !== '' ? $q : null, 'storna' => $showCancelled ? 1 : null,
    ], $over);
    return 'ucetni_prodej.php?' . http_build_query(array_filter($p, static fn($v) => $v !== null && $v !== ''));
};
?>

<div class="container-fluid">
    <h4 class="mb-3 text-white"><i class="fas fa-cash-register me-2 text-info"></i>Prodej</h4>
    <?php require __DIR__ . '/includes/accounting_tabs.php'; ?>

    <?php if ($missing): ?>
        <div class="glass-panel p-4 border-secondary text-white-75">
            Pokladna zatím nemá v databázi žádná data — jakmile proběhne první prodej, objeví se tady.
        </div>
    <?php else: ?>

    <form method="GET" class="glass-panel p-3 border-secondary mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small text-white-50 mb-1">Od</label>
                <input type="date" name="od" class="form-control" value="<?php echo e($period['from']); ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small text-white-50 mb-1">Do</label>
                <input type="date" name="do" class="form-control" value="<?php echo e($period['to']); ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small text-white-50 mb-1">Provozovna</label>
                <select name="pobocka" class="form-select">
                    <option value="0">Všechny</option>
                    <?php foreach ($branches as $b): ?>
                        <option value="<?php echo (int)$b['id']; ?>"<?php echo $branchId === (int)$b['id'] ? ' selected' : ''; ?>><?php echo e($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small text-white-50 mb-1">Platba</label>
                <select name="platba" class="form-select">
                    <option value="">Vše</option>
                    <?php foreach ($payLabels as $k => $l): ?>
                        <option value="<?php echo e($k); ?>"<?php echo $payFilter === $k ? ' selected' : ''; ?>><?php echo e($l); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small text-white-50 mb-1">Hledat</label>
                <input type="text" name="q" class="form-control" value="<?php echo e($q); ?>" placeholder="doklad, zboží, prodavač…">
            </div>
            <div class="col-12 col-md-1 d-grid">
                <button class="btn btn-info"><i class="fas fa-filter"></i></button>
            </div>
        </div>
        <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" name="storna" value="1" id="fStorna"<?php echo $showCancelled ? ' checked' : ''; ?>>
            <label class="form-check-label small text-white-50" for="fStorna">zobrazit i stornované doklady</label>
        </div>
    </form>

    <div class="row g-2 mb-3">
        <?php
        $tiles = [
            ['Tržba za období', formatMoney($sums['celkem']), 'fa-coins', '#7ce39a'],
            ['Dokladů', (string)$sums['doklady'], 'fa-receipt', '#8cc8ff'],
            ['Hotově', formatMoney($sums['cash']), 'fa-money-bill-wave', '#8cc8ff'],
            ['Kartou', formatMoney($sums['card']), 'fa-credit-card', '#8cc8ff'],
            ['Na fakturu', formatMoney($sums['invoice'] + $sums['invoice_ico']), 'fa-file-invoice', '#ffc46b'],
        ];
        foreach ($tiles as $t): ?>
        <div class="col-6 col-md">
            <div class="glass-panel p-3 border-secondary h-100">
                <div class="small text-white-50 mb-1"><i class="fas <?php echo $t[2]; ?> me-1"></i><?php echo e($t[0]); ?></div>
                <div style="font-size:1.25rem;font-weight:700;color:<?php echo $t[3]; ?>;"><?php echo e($t[1]); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($sums['zaklad'] > 0 || $sums['pouzite'] > 0 || $sums['storno_pocet'] > 0): ?>
    <div class="glass-panel p-3 border-secondary mb-3 small text-white-75">
        <?php if ($sums['zaklad'] > 0): ?>
            <span class="me-3">Základ DPH <strong><?php echo formatMoney($sums['zaklad']); ?></strong></span>
            <span class="me-3">DPH <strong><?php echo formatMoney($sums['dph']); ?></strong></span>
        <?php endif; ?>
        <?php if ($sums['pouzite'] > 0): ?>
            <span class="me-3" title="Zvláštní režim podle § 90 zákona o DPH — daň se z těchto položek neodvádí z celé ceny">
                Použité zboží (§ 90) <strong><?php echo formatMoney($sums['pouzite']); ?></strong>
            </span>
        <?php endif; ?>
        <?php if ($sums['storno_pocet'] > 0): ?>
            <span class="text-warning">Storna: <strong><?php echo (int)$sums['storno_pocet']; ?></strong> dokladů
                za <?php echo formatMoney($sums['storno_castka']); ?> (do tržby se nepočítají)</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="glass-panel p-0 border-secondary">
        <div class="table-responsive">
            <table class="table table-dark align-middle mb-0">
                <thead>
                    <tr class="text-white-50">
                        <th>Doklad</th><th>Datum</th><th>Provozovna</th><th>Prodal</th>
                        <th>Zboží</th><th>Platba</th><th class="text-end">Částka</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="text-center text-white-50 py-4">Za zvolené období tu není žádný prodej.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $sid = (int)$r['id'];
                    $isStorno = (string)($r['status'] ?? 'completed') === 'cancelled';
                    $its = $items[$sid] ?? [];
                    $names = array_map(static fn($i) => trim((string)$i['item_name']) . ((int)$i['quantity'] > 1 ? ' ×' . (int)$i['quantity'] : ''), $its);
                    $customer = trim((string)($r['cust_company'] ?: $r['cust_name']));
                ?>
                    <tr<?php echo $isStorno ? ' style="opacity:.55;"' : ''; ?>>
                        <td>
                            <code><?php echo e((string)$r['sale_number']); ?></code>
                            <?php if ($isStorno): ?><span class="badge bg-danger ms-1">STORNO</span><?php endif; ?>
                            <?php if (!empty($r['order_id'])): ?>
                                <div class="small"><a href="view_order.php?id=<?php echo (int)$r['order_id']; ?>" class="text-info text-decoration-none">zakázka #<?php echo (int)$r['order_id']; ?></a></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?php echo e(date('j. n. Y H:i', strtotime((string)$r['created_at']))); ?></td>
                        <td class="small"><?php echo e((string)($r['branch_name'] ?? '—')); ?></td>
                        <td class="small"><?php echo e((string)($r['seller_name'] ?? '—')); ?></td>
                        <td class="small" style="max-width:340px;">
                            <?php echo e(implode(', ', array_slice($names, 0, 3))); ?><?php echo count($names) > 3 ? ' + ' . (count($names) - 3) . ' další' : ''; ?>
                            <?php if ($customer !== ''): ?><div class="text-white-50"><?php echo e($customer); ?></div><?php endif; ?>
                        </td>
                        <td class="small">
                            <?php echo e($payLabels[(string)$r['payment_method']] ?? (string)$r['payment_method']); ?>
                            <?php if (!empty($r['invoice_number'])): ?>
                                <div><a href="print_invoice.php?id=<?php echo (int)$r['invoice_id']; ?>" target="_blank" class="text-info text-decoration-none small"><?php echo e((string)$r['invoice_number']); ?></a></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-semibold"><?php echo formatMoney((float)$r['total']); ?></td>
                        <td class="text-end text-nowrap">
                            <?php /* Dodatečná faktura (v3.71.0): zákazník zaplatil kartou nebo
                                     hotově a teprve pak si řekne o fakturu — vystaví se k hotovému
                                     prodeji jako už uhrazená, prodej se nepřepisuje. */ ?>
                            <?php if (!$isStorno && empty($r['invoice_id']) && (float)$r['total'] > 0
                                      && !in_array((string)$r['payment_method'], ['invoice', 'invoice_ico'], true)
                                      && crmCanUseInvoices()): ?>
                            <button type="button" class="btn btn-sm btn-outline-success" title="Vystavit fakturu k tomuto prodeji"
                                onclick="afxInvoiceAfterSale(<?php echo $sid; ?>, <?php echo htmlspecialchars(json_encode((string)$r['sale_number'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode(formatMoney((float)$r['total']), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)($payLabels[(string)$r['payment_method']] ?? ''), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </button>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-light" href="print_receipt.php?id=<?php echo $sid; ?>&amp;format=58&amp;auto=1" target="_blank" title="Účtenka">
                                <i class="fas fa-receipt"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (count($rows) >= 500): ?>
        <div class="small text-white-50 mt-2">Zobrazeno prvních 500 dokladů — zvol užší období.</div>
    <?php endif; ?>

    <div class="small text-white-50 mt-3">
        Podrobnější rozpady (denní tržby, kniha faktur, pokladní kniha) najdeš v
        <a href="ucetni_sestavy.php" class="text-info">Podkladech pro účetní</a>.
    </div>
    <?php endif; ?>
</div>

<?php /* okno „Vystavit fakturu k prodeji" (v3.71.0) — sdílené s druhou stránkou */ ?>
<?php require_once 'includes/modals/invoice_after_sale_modal.php'; ?>

<?php require_once 'includes/footer.php'; ?>
