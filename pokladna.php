<?php
/**
 * POKLADNA (kasa prodejna) — přímý pultový prodej dílů a produktů bez zakázky.
 * Vlevo: živé vyhledávání skladem dostupných položek + dnešní prodeje (dotisk).
 * Vpravo: košík (množství i cena upravitelné — sleva na místě), volba platby
 * hotově / kartou / na fakturu (u faktury povinný zákazník), dokončení prodeje.
 * Prodej okamžitě odepisuje sklad (api/pos_checkout.php, atomicky).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';
require_once 'includes/cash_book.php';

ensurePosTables();
ensureProductsTable();
ensureProductsPosColumn();

// denní součty do hlavičky (uzávěrka na první pohled); historie prodejů
// je záměrně JEN v Historie → Kasa prodejna, kasa je čistě prodejní plocha
$todaySums = ['cash' => 0.0, 'card' => 0.0, 'invoice' => 0.0];
try {
    // Denní uzávěrka jen za SVOU pobočku (admin/Boss vidí celofiremní součet).
    $__posBranch = orderBranchScopeSql('branch_id');
    foreach ($pdo->query("SELECT payment_method, SUM(total) s FROM pos_sales
        WHERE DATE(created_at) = CURDATE() AND status = 'completed'" . $__posBranch . " GROUP BY payment_method") as $r) {
        $todaySums[(string)$r['payment_method']] = (float)$r['s'];
    }
} catch (Throwable $e) {}

// Pokladní deník: dnešní pohyby hotovosti mimo prodeje (výdaje na výkupy, vklady,
// výběry) → stav kasy = prodeje hotově + přijaté zakázky hotově + vklady − výdaje.
ensurePosCashMovementsTable();
$cashIn = 0.0; $cashOut = 0.0; $todayMoves = [];
try {
    $st = $pdo->query("SELECT id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by, created_at
        FROM pos_cash_movements WHERE DATE(created_at) = CURDATE()" . orderBranchScopeSql('branch_id') . " ORDER BY id DESC LIMIT 30");
    foreach ($st as $m) {
        $todayMoves[] = $m;
        if ($m['direction'] === 'in') { $cashIn += (float)$m['amount']; } else { $cashOut += (float)$m['amount']; }
    }
} catch (Throwable $e) {}
// Pohyb hotovosti POUZE za dnešek (to, co kasa ukazovala dřív jako „stav").
$cashToday = $todaySums['cash'] + $cashIn - $cashOut;

// ── POKLADNÍ KNIHA ──────────────────────────────────────────────────────────
// Skutečný stav hotovosti = POČÁTEČNÍ ZŮSTATEK pokladny + všechny pohyby od
// data počátku. Dřív se počítalo od nuly každý den, takže kasa hlásila jen dnešní
// obrat a nikdy peníze, které v zásuvce reálně ležely z minulých dnů.
afxEnsureCashBookTables();
$cbBranch = (int)getCurrentStaffBranchId();
// Napříč pobočkami smí pokladní knihu přepínat jen globální divák (admin/Boss);
// ostatním se pobočka bere z DB a GET parametr se ignoruje (pobočková izolace).
if (isBranchGlobalViewer() && isset($_GET['cb_branch'])) {
    $cbBranch = max(0, (int)$_GET['cb_branch']);
}
$cbRegister = afxCashRegisterForBranch($cbBranch);
$cbBranchName = (string)($cbRegister['name'] ?? 'Pokladna');

/** Datum z GET, jen pokud je skutečně ve tvaru Y-m-d (jinak výchozí). */
$cbDate = static function (string $key, string $default): string {
    $v = trim((string)($_GET[$key] ?? ''));
    $d = $v !== '' ? date_create_from_format('Y-m-d', $v) : false;
    return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
};
$cbFrom = $cbDate('cb_from', date('Y-m-01'));
$cbTo   = $cbDate('cb_to', date('Y-m-d'));
if ($cbTo < $cbFrom) { $cbTo = $cbFrom; }

$cashBook  = afxCashBook($cbBranch, $cbFrom, $cbTo);
$cashState = afxCashBalanceAt($cbBranch, date('Y-m-d'));
$cbDayIn   = afxCashDayIncome($cbBranch, date('Y-m-d'));
$cbCanEdit = crmCanManageInvoices();   // počáteční zůstatek a storna = jen vedení
?>

<style>
/* Kasa — velké dotykové ovládání, liquid glass akcenty ladí s dokem (.afx-cell.act-*) */
.pos-search { font-size: 17px; padding: 13px 16px; border-radius: 14px; }
.pos-results { max-height: 380px; overflow-y: auto; }
.pos-hit { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 12px; cursor: pointer; transition: background .13s; }
.pos-hit:hover { background: rgba(0,163,255,.12); }
.pos-hit .nm { font-weight: 600; }
.pos-hit .cd { font-size: 11.5px; color: rgba(255,255,255,.5); }
.pos-hit .pr { margin-left: auto; font-weight: 700; white-space: nowrap; }
.pos-type { font-size: 10px; font-weight: 700; letter-spacing: .05em; border-radius: 7px; padding: 2px 7px; white-space: nowrap; }
.pos-type.part { background: rgba(0,163,255,.18); color: #6fd0ff; }
.pos-type.product { background: rgba(48,209,88,.16); color: #6fe08d; }
/* Košík = hlavní plocha kasy — všechno velké a čitelné jako na skutečné pokladně */
.pos-cart td { vertical-align: middle; padding: 12px 8px; }
.pos-cart thead th { font-size: 15.5px; font-weight: 700; color: rgba(255,255,255,.75) !important; text-transform: uppercase; letter-spacing: .04em; }
.pos-item-name { font-size: 16px; font-weight: 600; line-height: 1.25; }
.pos-item-code { font-size: 12.5px; color: rgba(255,255,255,.5); }
.pos-cart input.pos-qty, .pos-cart input.pos-price { font-size: 18px; font-weight: 600; text-align: center; padding: 9px 6px; }
.pos-line-total { font-size: 18px; font-weight: 700; white-space: nowrap; }
.pos-remove { font-size: 26px; line-height: 1; padding: 6px 10px; border: 0; background: none; color: #ff5f57; cursor: pointer; transition: transform .12s, color .12s; }
.pos-remove:hover { color: #ff2d21; transform: scale(1.18); }
.pos-total { font-size: 58px; font-weight: 700; letter-spacing: -.02em; line-height: 1.05; }
.pos-total-label { font-size: 22px; font-weight: 600; }
.pos-pay { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 7px;
  flex: 1; padding: 19px 10px; border-radius: 19px; border: 0; cursor: pointer; font-weight: 700; font-size: 15.5px;
  background: rgba(255,255,255,.05); color: #a7b1c2; box-shadow: inset 0 0 0 1px rgba(255,255,255,.09);
  transition: background .16s, color .16s, box-shadow .16s; }
.pos-pay i { font-size: 25px; }
.pos-pay.sel-cash { color: #6fe08d; background: rgba(48,209,88,.16); box-shadow: inset 0 0 0 1px rgba(48,209,88,.4), 0 0 16px rgba(48,209,88,.22); text-shadow: 0 0 12px rgba(48,209,88,.5); }
.pos-pay.sel-card { color: #5fd2ff; background: rgba(0,163,255,.18); box-shadow: inset 0 0 0 1px rgba(0,163,255,.45), 0 0 16px rgba(0,163,255,.25); text-shadow: 0 0 12px rgba(95,210,255,.5); }
.pos-pay.sel-invoice { color: #ffc46b; background: rgba(255,159,10,.15); box-shadow: inset 0 0 0 1px rgba(255,159,10,.42), 0 0 16px rgba(255,159,10,.22); text-shadow: 0 0 12px rgba(255,159,10,.5); }
.pos-finish { width: 100%; padding: 20px; border: 0; border-radius: 19px; font-size: 19px; font-weight: 700;
  color: #eaf6ff; background: linear-gradient(135deg, rgba(0,163,255,.34), rgba(90,200,250,.22));
  box-shadow: inset 0 0 0 1px rgba(0,163,255,.5), 0 8px 26px rgba(0,120,210,.28); cursor: pointer; transition: filter .15s, transform .12s; }
.pos-finish:hover { filter: brightness(1.15); }
.pos-finish:active { transform: scale(.985); }
.pos-finish:disabled { opacity: .45; cursor: not-allowed; }
.pos-empty { color: rgba(255,255,255,.4); text-align: center; padding: 34px 0; font-size: 15.5px; }
/* Zámek kasy po nečinnosti — plné překrytí, pod ním nejde nic dělat ani číst */
.pos-lock { position: fixed; inset: 0; z-index: 12000; display: none; align-items: center; justify-content: center;
  background: rgba(5,8,14,.72); backdrop-filter: blur(18px) saturate(1.2); -webkit-backdrop-filter: blur(18px) saturate(1.2); }
.pos-lock.show { display: flex; }
.pos-lock-card { width: min(400px, 92vw); background: rgba(14,18,26,.92); border: 1px solid rgba(255,255,255,.12);
  border-radius: 22px; padding: 30px 28px; text-align: center; box-shadow: 0 30px 80px rgba(0,0,0,.5); }
.pos-lock-card .lk-icon { font-size: 34px; color: #5fd2ff; margin-bottom: 10px; }
.pos-lock-card h4 { font-weight: 700; margin-bottom: 4px; }
.pos-lock-card .lk-who { color: rgba(255,255,255,.65); margin-bottom: 18px; }
.pos-lock-card .lk-who strong { color: #fff; }
.pos-lock-card input[type=password] { font-size: 20px; text-align: center; padding: 11px; letter-spacing: .12em; }
.pos-lock-card .lk-err { color: #ff7a7a; font-size: 13.5px; min-height: 20px; margin-top: 9px; }
.pos-lock-card .lk-btn { width: 100%; margin-top: 8px; padding: 13px; border: 0; border-radius: 14px; font-size: 16.5px; font-weight: 700;
  color: #eaf6ff; background: linear-gradient(135deg, rgba(0,163,255,.34), rgba(90,200,250,.22));
  box-shadow: inset 0 0 0 1px rgba(0,163,255,.5); cursor: pointer; }
.pos-lock-card .lk-btn:hover { filter: brightness(1.15); }
.pos-lock-card .lk-other { display: inline-block; margin-top: 14px; font-size: 13px; color: rgba(255,255,255,.5); text-decoration: none; }
.pos-lock-card .lk-other:hover { color: #5fd2ff; }
@keyframes lkshake { 20%, 60% { transform: translateX(-7px); } 40%, 80% { transform: translateX(7px); } }
.pos-lock-card.shake { animation: lkshake .4s; }
/* Pokladní kniha — hustý účetní výpis, ať se do obrazovky vejde celý měsíc */
.cash-book { max-height: 420px; overflow-y: auto; }
.cash-book td, .cash-book th { padding: 5px 8px; white-space: nowrap; }
.cash-book td:nth-child(3) { white-space: normal; }
.cash-book code { color: #6fd0ff; }
/* toast po skenu čtečkou */
.pos-toast { position: fixed; top: 64px; right: 22px; z-index: 11500; display: none; align-items: center; gap: 10px;
  padding: 13px 18px; border-radius: 14px; font-size: 15.5px; font-weight: 600; color: #fff;
  box-shadow: 0 14px 40px rgba(0,0,0,.4); }
.pos-toast.ok { display: flex; background: rgba(28,120,60,.96); border: 1px solid rgba(110,224,141,.5); }
.pos-toast.err { display: flex; background: rgba(150,40,40,.96); border: 1px solid rgba(255,122,122,.5); }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-cash-register me-2 text-info"></i>Pokladna</h2>
    <span class="text-white-50 small">Dnes: hotově <strong><?php echo formatMoney($todaySums['cash']); ?></strong>
        · kartou <strong><?php echo formatMoney($todaySums['card']); ?></strong>
        · fakturou <strong><?php echo formatMoney($todaySums['invoice']); ?></strong>
        <?php if ($cashIn > 0): ?> · vklady <strong class="text-success"><?php echo formatMoney($cashIn); ?></strong><?php endif; ?>
        <?php if ($cashOut > 0): ?> · výdaje <strong class="text-warning">−<?php echo formatMoney($cashOut); ?></strong><?php endif; ?>
        · <span title="Počáteční zůstatek pokladny (<?php echo e(formatMoney((float)$cbRegister['opening_balance'])); ?> k <?php echo e(date('j. n. Y', strtotime((string)$cbRegister['opening_date']))); ?>) + všechny příjmy − výdaje. Dnešní pohyb: <?php echo e(formatMoney($cashToday)); ?>">stav hotovosti <strong class="<?php echo $cashState < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo formatMoney($cashState); ?></strong></span>
        </span>
    <div class="d-flex gap-2">
        <?php if (crmCanViewHistory()): ?>
        <a href="history.php?tab=kasa" class="btn btn-sm btn-info"><i class="fas fa-clock-rotate-left me-1"></i>Historie Pokladny</a>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="posCashMove('in')"><i class="fas fa-arrow-down me-1"></i>Vklad</button>
        <button type="button" class="btn btn-sm btn-outline-warning" onclick="posCashMove('out')"><i class="fas fa-arrow-up me-1"></i>Výběr</button>
        <?php if ($cbCanEdit): ?>
        <button type="button" class="btn btn-sm btn-outline-info" onclick="posOpeningBalance()" title="Napočítanou hotovost zapíšeš jako počátek — od něj se počítá celý stav pokladny"><i class="fas fa-scale-balanced me-1"></i>Nastavit počáteční zůstatek</button>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($todayMoves)): ?>
<div class="glass-panel p-2 px-3 border-secondary mb-3">
    <div class="small text-white-50 mb-1"><i class="fas fa-book me-1"></i>Dnešní pohyby hotovosti (mimo prodeje)</div>
    <div class="d-flex flex-column gap-1">
        <?php foreach ($todayMoves as $m):
            $isOut = $m['direction'] === 'out';
            $purposeLabels = ['vykup' => 'Výkup', 'vklad' => 'Vklad', 'vyber' => 'Výběr', 'zakazka' => 'Zakázka'];
            $pl = $purposeLabels[(string)$m['purpose']] ?? ucfirst((string)$m['purpose']);
        ?>
        <div class="d-flex align-items-center gap-2 small">
            <span class="<?php echo $isOut ? 'text-warning' : 'text-success'; ?>" style="min-width:86px;"><strong><?php echo ($isOut ? '−' : '+') . formatMoney((float)$m['amount']); ?></strong></span>
            <span><?php echo e($pl); ?><?php if (!empty($m['ref_label'])): ?>
                <?php if ($m['ref_type'] === 'document'): ?> <a class="text-info text-decoration-none" href="dokument.php?type=vykup&id=<?php echo (int)$m['ref_id']; ?>"><?php echo e((string)$m['ref_label']); ?></a>
                <?php else: ?> <?php echo e((string)$m['ref_label']); ?><?php endif; ?>
            <?php endif; ?></span>
            <?php if (!empty($m['note'])): ?><span class="text-white-50 text-truncate" style="max-width:340px;"><?php echo e((string)$m['note']); ?></span><?php endif; ?>
            <span class="text-white-50 ms-auto"><?php echo e(date('H:i', strtotime((string)$m['created_at']))); ?><?php if (!empty($m['created_by'])): ?> · <?php echo e((string)$m['created_by']); ?><?php endif; ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
// ── Upozornění na zákonný limit hotovosti (270 000 Kč, zák. 254/2004 Sb.) ──
// Limit platí na JEDNU platbu mezi týmž plátcem a příjemcem za den, ne na denní
// tržbu. Jednotlivá platba nad limit je proto tvrdé varování, denní součet jen
// připomínka „zkontroluj, jestli to nebyla jedna platba od jednoho člověka".
$cbLimit = AFX_CASH_LIMIT_CZK;
$cbToday = date('Y-m-d');
$cbOverSingle = $cashBook['limit_over'];
$cbOverDay = $cashBook['days_over'];
// Dnešek se přidává, JEN když ho zvolené období knihy nepokrývá — jinak by se
// tytéž platby započítaly dvakrát a hláška by lhala o jejich počtu.
if ($cbToday < $cbFrom || $cbToday > $cbTo) {
    if ($cbDayIn['max_single'] > $cbLimit) {
        $cbOverSingle[] = ['amount' => $cbDayIn['max_single'], 'date' => $cbToday, 'title' => 'dnešní platba'];
    }
    if ($cbDayIn['total'] > $cbLimit) { $cbOverDay[$cbToday] = $cbDayIn['total']; }
}
?>
<?php if (!empty($cbOverSingle)): ?>
<div class="alert alert-danger border-0 py-2 px-3 mb-3">
    <i class="fas fa-triangle-exclamation me-1"></i>
    <strong>Hotovostní platba nad zákonný limit <?php echo formatMoney($cbLimit); ?>.</strong>
    Zákon č. 254/2004 Sb. zakazuje přijmout jednu hotovostní platbu nad tuto částku — takovou úhradu je nutné přijmout převodem.
    <span class="text-white-50">(nalezeno <?php echo count($cbOverSingle); ?> ×, největší <?php echo formatMoney(max(array_map(static fn($r) => (float)$r['amount'], $cbOverSingle))); ?>)</span>
</div>
<?php elseif (!empty($cbOverDay)): ?>
<div class="alert alert-warning border-0 py-2 px-3 mb-3">
    <i class="fas fa-circle-exclamation me-1"></i>
    <strong>Denní hotovostní příjem překročil <?php echo formatMoney($cbLimit); ?>.</strong>
    Jednotlivé platby jsou v pořádku (limit platí na jednu platbu od jednoho plátce za den) — jen zkontroluj, že to nebyla jedna velká úhrada rozepsaná na víc dokladů.
    <span class="text-white-50">
        <?php foreach ($cbOverDay as $__d => $__s): ?><?php echo e(date('j. n. Y', strtotime((string)$__d))); ?>: <?php echo formatMoney((float)$__s); ?> · <?php endforeach; ?>
    </span>
</div>
<?php endif; ?>

<!-- ── Pokladní kniha (analytika 211): počáteční zůstatek → pohyby → konečný zůstatek ── -->
<div class="glass-panel p-3 border-secondary mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <strong><i class="fas fa-book-open me-2 text-info"></i>Pokladní kniha — <?php echo e($cbBranchName); ?></strong>
        <form method="get" class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (isBranchGlobalViewer()): ?>
            <select name="cb_branch" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                <?php foreach (getBranches(false) as $__b): ?>
                <option value="<?php echo (int)$__b['id']; ?>"<?php echo (int)$__b['id'] === $cbBranch ? ' selected' : ''; ?>><?php echo e((string)$__b['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="date" name="cb_from" class="form-control form-control-sm" style="width:auto;" value="<?php echo e($cbFrom); ?>">
            <span class="text-white-50 small">–</span>
            <input type="date" name="cb_to" class="form-control form-control-sm" style="width:auto;" value="<?php echo e($cbTo); ?>">
            <button type="submit" class="btn btn-sm btn-outline-info"><i class="fas fa-filter me-1"></i>Zobrazit</button>
        </form>
    </div>

    <div class="d-flex flex-wrap gap-3 small mb-2">
        <span class="text-white-50">Počáteční zůstatek <strong class="text-white"><?php echo formatMoney((float)$cashBook['opening']); ?></strong></span>
        <span class="text-white-50">Příjmy <strong class="text-success"><?php echo formatMoney((float)$cashBook['sum_in']); ?></strong></span>
        <span class="text-white-50">Výdaje <strong class="text-warning">−<?php echo formatMoney((float)$cashBook['sum_out']); ?></strong></span>
        <span class="text-white-50">Konečný zůstatek <strong class="<?php echo $cashBook['closing'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo formatMoney((float)$cashBook['closing']); ?></strong></span>
        <span class="text-white-50 ms-auto">Pokladna vedena od <?php echo e(date('j. n. Y', strtotime((string)$cbRegister['opening_date']))); ?>
            (<?php echo formatMoney((float)$cbRegister['opening_balance']); ?>)</span>
    </div>

    <?php if (empty($cashBook['rows'])): ?>
        <div class="text-white-50 small py-2">Za zvolené období nejsou žádné pohyby hotovosti.</div>
    <?php else: ?>
    <div class="table-responsive cash-book">
        <table class="table table-dark table-sm align-middle mb-0">
            <thead>
                <tr class="text-white-50">
                    <th style="width:96px;">Datum</th>
                    <th style="width:98px;">Doklad</th>
                    <th>Popis</th>
                    <th class="text-end" style="width:110px;">Příjem</th>
                    <th class="text-end" style="width:110px;">Výdej</th>
                    <th class="text-end" style="width:120px;">Zůstatek</th>
                    <th style="width:130px;">Vystavil</th>
                    <?php if ($cbCanEdit): ?><th style="width:80px;"></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($cashBook['rows'], 0, 500) as $r): ?>
                <tr<?php echo !empty($r['storno']) ? ' class="text-white-50" style="text-decoration:line-through;"' : ''; ?>>
                    <td class="small"><?php echo e(date('j. n.', strtotime((string)$r['date']))); ?> <span class="text-white-50"><?php echo e((string)$r['time']); ?></span></td>
                    <td class="small">
                        <?php echo $r['doc_number'] !== '' ? '<code>' . e((string)$r['doc_number']) . '</code>' : '<span class="text-white-50">—</span>'; ?>
                        <?php if (!empty($r['doc_storno'])): ?><span class="badge bg-danger ms-1" title="Papírový doklad byl stornován, peníze v pokladně zůstávají">storno</span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if (!empty($r['is_storno'])): ?><span class="badge bg-secondary me-1">STORNO</span><?php endif; ?>
                        <?php echo e((string)$r['title']); ?>
                        <?php if (trim((string)$r['detail']) !== ''): ?><span class="text-white-50"> · <?php echo e(mb_substr(trim((string)$r['detail']), 0, 90)); ?></span><?php endif; ?>
                    </td>
                    <td class="text-end small text-success"><?php echo $r['dir'] === 'in' ? formatMoney((float)$r['amount']) : ''; ?></td>
                    <td class="text-end small text-warning"><?php echo $r['dir'] === 'out' ? '−' . formatMoney((float)$r['amount']) : ''; ?></td>
                    <td class="text-end small"><strong><?php echo formatMoney((float)($r['balance'] ?? 0)); ?></strong></td>
                    <td class="small text-white-50"><?php echo e(mb_substr((string)$r['by'], 0, 22)); ?></td>
                    <?php if ($cbCanEdit): ?>
                    <td class="text-end">
                        <?php // Doklad se nikdy nemaže — jen stornuje protidokladem (§ 11 zák. 563/1991 Sb.) ?>
                        <?php if ($r['source'] === 'document' && empty($r['storno']) && empty($r['is_storno'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Vystavit storno doklad"
                                onclick="posCashDocStorno(<?php echo (int)$r['doc_id']; ?>, '<?php echo e((string)$r['doc_number']); ?>')">Storno</button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($cashBook['rows']) > 500): ?>
        <div class="text-white-50 small mt-2">Zobrazeno prvních 500 z <?php echo count($cashBook['rows']); ?> pohybů — zvol kratší období.</div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Počáteční zůstatek pokladny — inventarizace hotovosti (jen vedení) -->
<?php if ($cbCanEdit): ?>
<div class="modal fade" id="posOpeningModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-scale-balanced me-2 text-info"></i>Počáteční zůstatek — <?php echo e($cbBranchName); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <form id="posOpeningForm">
                <div class="modal-body">
                    <p class="small text-white-50">
                        Spočítej hotovost v zásuvce a zapiš ji sem. Od tohoto data a částky se počítá celý stav pokladny —
                        starší pohyby se do zůstatku už nepřičítají (jinak by kasa hlásila peníze dvakrát).
                    </p>
                    <div class="mb-2">
                        <label class="form-label small text-white-50 mb-1">Napočítaná hotovost (Kč)</label>
                        <input type="text" class="form-control" name="balance" inputmode="decimal"
                               value="<?php echo e(number_format((float)$cbRegister['opening_balance'], 0, ',', '')); ?>" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-white-50 mb-1">Ke dni</label>
                        <input type="date" class="form-control" name="opening_date" max="<?php echo e(date('Y-m-d')); ?>"
                               value="<?php echo e((string)$cbRegister['opening_date']); ?>" required>
                    </div>
                    <div>
                        <label class="form-label small text-white-50 mb-1">Poznámka (nepovinné)</label>
                        <input type="text" class="form-control" name="note" maxlength="255" placeholder="např. inventarizace pokladny">
                    </div>
                    <div class="alert alert-warning border-0 py-2 px-3 small mt-3 mb-0">
                        <i class="fas fa-circle-info me-1"></i>Zápis se loguje do Historie — je vždy dohledatelné, kdo zůstatek změnil.
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Zrušit</button>
                    <button type="submit" class="btn btn-info"><i class="fas fa-check me-1"></i>Uložit zůstatek</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
/* Vklad/výběr hotovosti — jednoduchý prompt flow (kasa je dotyková, ale tohle je výjimečná akce) */
function posCashMove(direction) {
    var label = direction === 'in' ? 'VKLAD do kasy' : 'VÝBĚR z kasy';
    var amount = prompt(label + ' — částka v Kč:');
    if (amount === null) return;
    var note = prompt('Poznámka (za co / proč):') || '';
    var fd = new FormData();
    fd.append('direction', direction);
    fd.append('amount', amount);
    fd.append('note', note);
    fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
    fetch('api/pos_cash_move.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { alert(j.error || 'Pohyb se nepodařilo uložit'); return; }
            // Číslo vystaveného PPD/VPD hlásíme hned — obsluha ho píše na papírový doklad.
            if (j.doc_number) { alert('Uloženo. Pokladní doklad: ' + j.doc_number); }
            location.reload();
        })
        .catch(function () { alert('Chyba spojení'); });
}

/* Počáteční zůstatek pokladny (jen vedení) — modal, protože se zadává částka i datum. */
function posOpeningBalance() {
    var el = document.getElementById('posOpeningModal');
    if (!el) { return; }
    new bootstrap.Modal(el).show();
}

/* Storno pokladního dokladu — doklad se nikdy nemaže, vystaví se protidoklad. */
function posCashDocStorno(docId, docNumber) {
    var reason = prompt('Storno dokladu ' + docNumber + ' — důvod (zapíše se na storno doklad):');
    if (reason === null) { return; }
    var fd = new FormData();
    fd.append('action', 'storno');
    fd.append('doc_id', docId);
    fd.append('reason', reason);
    fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
    fetch('api/pos_cash_move.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.ok) { alert(j.error || 'Storno se nepodařilo'); return; }
            alert('Vystaven storno doklad ' + (j.number || ''));
            location.reload();
        })
        .catch(function () { alert('Chyba spojení'); });
}

document.addEventListener('DOMContentLoaded', function () {
    var f = document.getElementById('posOpeningForm');
    if (!f) { return; }
    f.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(f);
        fd.append('action', 'opening');
        fd.append('branch_id', '<?php echo (int)$cbBranch; ?>');
        fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
        fetch('api/pos_cash_move.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) { alert(j.error || 'Zůstatek se nepodařilo uložit'); return; }
                location.reload();
            })
            .catch(function () { alert('Chyba spojení'); });
    });
});
</script>

<div class="row g-4">
    <!-- ── vyhledávání ── -->
    <div class="col-lg-6">
        <div class="glass-panel p-3 border-secondary">
            <label class="form-label small text-white-50 mb-2"><i class="fas fa-search me-1"></i> Najdi produkt nebo díl (jen skladem) · <i class="fas fa-barcode me-1"></i>USB čtečka funguje kdykoli — stačí pípnout kód</label>
            <input type="text" id="posSearch" class="form-control pos-search" placeholder="iPhone 13, displej, sériové číslo…" autocomplete="off">
            <div id="posResults" class="pos-results mt-2"></div>
        </div>
    </div>

    <!-- ── košík + platba ── -->
    <div class="col-lg-6">
        <div class="glass-panel p-3 border-secondary">
            <strong class="d-block mb-2 fs-5"><i class="fas fa-shopping-basket me-2 text-white-50"></i>Košík</strong>
            <div class="table-responsive">
                <table class="table table-dark align-middle mb-2 pos-cart">
                    <thead>
                        <tr class="text-white-50"><th>Položka</th><th style="width:92px;">Ks</th><th style="width:132px;">Cena/ks</th><th class="text-end">Celkem</th><th style="width:44px;"></th></tr>
                    </thead>
                    <tbody id="posCartBody"></tbody>
                </table>
            </div>
            <div id="posCartEmpty" class="pos-empty">Košík je prázdný — vyhledej zboží vlevo.</div>

            <div class="d-flex justify-content-between align-items-center my-3">
                <span class="text-white-50 pos-total-label">Celkem</span>
                <span class="pos-total" id="posTotal">0 Kč</span>
            </div>

            <div class="d-flex gap-2 mb-3">
                <button type="button" class="pos-pay" data-pay="cash"><i class="fas fa-money-bill-wave"></i>Hotově</button>
                <button type="button" class="pos-pay" data-pay="card"><i class="fas fa-credit-card"></i>Kartou<span class="small" style="font-size:10px;opacity:.7;">terminál zvlášť</span></button>
                <button type="button" class="pos-pay" data-pay="invoice"><i class="fas fa-file-invoice"></i>Na fakturu</button>
            </div>

            <div id="posCustomerWrap" class="mb-3" style="display:none;">
                <label class="form-label small text-white-50 mb-1">Zákazník (povinné u faktury)</label>
                <select id="posCustomer" class="form-select" style="width:100%;"></select>
            </div>

            <button type="button" class="pos-finish" id="posFinish" disabled><i class="fas fa-check me-2"></i>Dokončit prodej</button>
        </div>

        <div class="glass-panel p-3 border-secondary mt-3" id="posDone" style="display:none;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-check-circle text-success fs-4"></i>
                <strong>Prodáno — doklad <code id="posDoneNumber"></code></strong>
            </div>
            <div id="posDoneProductNote" class="alert alert-info border-0 py-2 small" style="display:none;">
                <i class="fas fa-check me-1"></i> Sklad CRM odečten automaticky — produkt zůstane vyprodaný i po dalším nahrání souboru z naskladňovací appky.
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-info" id="posDoneReceipt"><i class="fas fa-print me-1"></i> Účtenka</button>
                <button type="button" class="btn btn-warning" id="posDoneInvoice" style="display:none;"><i class="fas fa-file-invoice me-1"></i> Faktura</button>
                <button type="button" class="btn btn-outline-light ms-auto" id="posDoneNew"><i class="fas fa-plus me-1"></i> Nový prodej</button>
            </div>
        </div>
    </div>
</div>

<!-- Zámek kasy po 15 min nečinnosti — odemyká heslem přihlášený zaměstnanec -->
<div class="pos-lock" id="posLock">
    <div class="pos-lock-card" id="posLockCard">
        <div class="lk-icon"><i class="fas fa-lock"></i></div>
        <h4>Kasa uzamčena</h4>
        <div class="lk-who">Přihlášen: <strong><?php echo e($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></strong></div>
        <form id="posLockForm" autocomplete="off">
            <input type="password" class="form-control" id="posLockPass" placeholder="Heslo" autocomplete="current-password">
            <div class="lk-err" id="posLockErr"></div>
            <button type="submit" class="lk-btn"><i class="fas fa-unlock me-2"></i>Odemknout</button>
        </form>
        <a href="logout.php" class="lk-other">Přihlásit jiného zaměstnance →</a>
    </div>
</div>

<script>
(function () {
    var cart = [];          // {type, id, name, code, price, qty, stock}
    var payment = '';
    var lastSale = null;

    var $body = document.getElementById('posCartBody');
    var $empty = document.getElementById('posCartEmpty');
    var $total = document.getElementById('posTotal');
    var $finish = document.getElementById('posFinish');
    var $custWrap = document.getElementById('posCustomerWrap');

    function fmt(n) { return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(n) + ' Kč'; }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // ── vyhledávání ──
    var searchTimer = null;
    var $search = document.getElementById('posSearch');
    var $results = document.getElementById('posResults');
    function doSearch() {
        var q = $search.value.trim();
        fetch('api/pos_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) return;
                $results.innerHTML = '';
                if (!d.results.length) {
                    $results.innerHTML = '<div class="pos-empty">Nic skladem nenalezeno.</div>';
                    return;
                }
                d.results.forEach(function (r) {
                    var el = document.createElement('div');
                    el.className = 'pos-hit';
                    el.innerHTML = '<span class="pos-type ' + r.type + '">' + (r.type === 'part' ? 'DÍL' : 'PRODUKT') + '</span>'
                        + '<div><div class="nm">' + esc(r.name) + '</div><div class="cd">' + esc(r.code || '') + ' · skladem ' + r.stock + ' ks</div></div>'
                        + '<span class="pr">' + fmt(r.price) + '</span>';
                    el.addEventListener('click', function () { addToCart(r); });
                    $results.appendChild(el);
                });
            })
            .catch(function () {});
    }
    $search.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(doSearch, 280); });
    $search.addEventListener('focus', function () { if (!$results.children.length) doSearch(); });

    // ── košík ──
    function addToCart(r) {
        var found = cart.find(function (c) { return c.type === r.type && c.id === r.id; });
        if (found) {
            if (found.qty + 1 > r.stock) { alert('Skladem je jen ' + r.stock + ' ks.'); return; }
            found.qty++;
        } else {
            cart.push({ type: r.type, id: r.id, name: r.name, code: r.code, price: r.price, qty: 1, stock: r.stock });
        }
        render();
    }
    window.posRemove = function (i) { cart.splice(i, 1); render(); };
    window.posQty = function (i, v) {
        v = parseInt(v, 10) || 1;
        if (v < 1) v = 1;
        if (v > cart[i].stock) { alert('Skladem je jen ' + cart[i].stock + ' ks.'); v = cart[i].stock; }
        cart[i].qty = v; render();
    };
    window.posPrice = function (i, v) {
        v = parseFloat(String(v).replace(',', '.'));
        if (isNaN(v) || v < 0) v = 0;
        cart[i].price = v; render();
    };

    function total() { return cart.reduce(function (s, c) { return s + c.price * c.qty; }, 0); }

    function render() {
        $body.innerHTML = '';
        cart.forEach(function (c, i) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><div class="pos-item-name">' + esc(c.name) + '</div><div class="pos-item-code">' + esc(c.code || '') + '</div></td>'
                + '<td><input type="number" class="form-control pos-qty" min="1" max="' + c.stock + '" value="' + c.qty + '" onchange="posQty(' + i + ', this.value)"></td>'
                + '<td><input type="text" class="form-control pos-price" value="' + c.price + '" onchange="posPrice(' + i + ', this.value)"></td>'
                + '<td class="text-end pos-line-total">' + fmt(c.price * c.qty) + '</td>'
                + '<td><button type="button" class="pos-remove" onclick="posRemove(' + i + ')" title="Smazat položku z košíku"><i class="fas fa-times"></i></button></td>';
            $body.appendChild(tr);
        });
        $empty.style.display = cart.length ? 'none' : '';
        $total.textContent = fmt(total());
        updateFinish();
    }

    // ── platba ──
    document.querySelectorAll('.pos-pay').forEach(function (btn) {
        btn.addEventListener('click', function () {
            payment = btn.dataset.pay;
            document.querySelectorAll('.pos-pay').forEach(function (b) { b.className = 'pos-pay'; });
            btn.classList.add('sel-' + payment);
            $custWrap.style.display = payment === 'invoice' ? '' : 'none';
            updateFinish();
        });
    });

    // zákazník (select2 — stejný endpoint jako wizard Nová zakázka)
    $(document).ready(function () {
        $('#posCustomer').select2({
            width: '100%', placeholder: 'Hledat zákazníka…', allowClear: true, minimumInputLength: 0,
            ajax: {
                url: 'api/search_customers.php', dataType: 'json', delay: 250,
                data: function (params) { return { q: params.term || '', page: params.page || 1 }; },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results, pagination: { more: data.pagination && data.pagination.more } };
                }
            }
        }).on('change', updateFinish);
    });

    function updateFinish() {
        var ok = cart.length > 0 && payment !== '';
        if (payment === 'invoice' && !$('#posCustomer').val()) ok = false;
        $finish.disabled = !ok;
    }

    // ── dokončení ──
    $finish.addEventListener('click', function () {
        $finish.disabled = true;
        $finish.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Zpracovávám…';
        fetch('api/pos_checkout.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>',
                payment: payment,
                customer_id: parseInt($('#posCustomer').val() || 0, 10),
                items: cart.map(function (c) { return { type: c.type, id: c.id, qty: c.qty, price: c.price }; })
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            $finish.innerHTML = '<i class="fas fa-check me-2"></i>Dokončit prodej';
            if (!d.success) { alert(d.message || 'Prodej se nepodařil.'); updateFinish(); return; }
            lastSale = d;
            document.getElementById('posDoneNumber').textContent = d.sale_number;
            document.getElementById('posDoneProductNote').style.display = d.has_product ? '' : 'none';
            document.getElementById('posDoneInvoice').style.display = d.invoice_id ? '' : 'none';
            document.getElementById('posDone').style.display = '';
            cart = []; payment = '';
            document.querySelectorAll('.pos-pay').forEach(function (b) { b.className = 'pos-pay'; });
            $custWrap.style.display = 'none';
            $('#posCustomer').val(null).trigger('change');
            render();
            window.open('print_receipt.php?id=' + d.sale_id + '&auto=1', '_blank');
        })
        .catch(function () {
            $finish.innerHTML = '<i class="fas fa-check me-2"></i>Dokončit prodej';
            alert('Síťová chyba — prodej se možná neuložil, zkontroluj dnešní prodeje.');
            updateFinish();
        });
    });

    document.getElementById('posDoneReceipt').addEventListener('click', function () {
        if (lastSale) window.open('print_receipt.php?id=' + lastSale.sale_id + '&auto=1', '_blank');
    });
    document.getElementById('posDoneInvoice').addEventListener('click', function () {
        if (lastSale && lastSale.invoice_id) window.open('print_invoice.php?id=' + lastSale.invoice_id, '_blank');
    });
    document.getElementById('posDoneNew').addEventListener('click', function () {
        document.getElementById('posDone').style.display = 'none';
        location.reload();
    });

    // ── USB čtečka čárových kódů — funguje KDEKOLI na stránce ──
    // Čtečka je klávesnice, která „napíše" kód strojovým tempem (10–40 ms/znak)
    // a pošle Enter. Globální zachytávač pozná rychlou dávku znaků: nezáleží,
    // jestli je kurzor v poli, mimo něj, nebo nikde.
    var scanBuf = '', scanLast = 0, scanStart = 0;
    var toastTimer = null;
    var $toast = document.createElement('div');
    $toast.className = 'pos-toast';
    document.body.appendChild($toast);

    function posToast(ok, text) {
        $toast.className = 'pos-toast ' + (ok ? 'ok' : 'err');
        $toast.innerHTML = '<i class="fas fa-' + (ok ? 'check-circle' : 'exclamation-circle') + '"></i><span></span>';
        $toast.querySelector('span').textContent = text;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { $toast.className = 'pos-toast'; }, 3200);
    }
    function beep(ok) {   // krátké pípnutí jako u skutečné kasy (vysoké = OK, nízké = chyba)
        try {
            var ctx = window.__posAC = window.__posAC || new (window.AudioContext || window.webkitAudioContext)();
            var o = ctx.createOscillator(), g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = ok ? 1320 : 220;
            g.gain.value = 0.07;
            o.start(); o.stop(ctx.currentTime + (ok ? 0.09 : 0.25));
        } catch (e) {}
    }

    function handleScan(code) {
        fetch('api/pos_search.php?q=' + encodeURIComponent(code), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var results = (d.success && d.results) || [];
                var exact = results.filter(function (r) { return (r.code || '').toLowerCase() === code.toLowerCase(); });
                var hit = exact.length === 1 ? exact[0] : (exact.length === 0 && results.length === 1 ? results[0] : null);
                if (hit) {
                    addToCart(hit);
                    beep(true);
                    posToast(true, 'Přidáno: ' + hit.name);
                } else {
                    beep(false);
                    posToast(false, exact.length > 1 || results.length > 1
                        ? 'Kód „' + code + '" odpovídá více položkám — vyber ručně.'
                        : 'Kód „' + code + '" není skladem ani v systému.');
                }
            })
            .catch(function () { beep(false); posToast(false, 'Síťová chyba při hledání kódu.'); });
    }

    document.addEventListener('keydown', function (e) {
        if (locked) return;
        var now = Date.now();
        if (e.key === 'Enter') {
            // dávka ≥4 znaků napsaná strojovým tempem = sken
            if (scanBuf.length >= 4 && (now - scanLast) < 120 && (now - scanStart) < scanBuf.length * 55 + 200) {
                var code = scanBuf;
                scanBuf = '';
                e.preventDefault();
                // čtečka znaky „napsala" i do zrovna aktivního pole — uklidit je
                var el = document.activeElement;
                if (el && ('value' in el) && typeof el.value === 'string' && el.value.slice(-code.length) === code) {
                    el.value = el.value.slice(0, -code.length);
                }
                handleScan(code);
            } else {
                scanBuf = '';
            }
            return;
        }
        if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            if (now - scanLast > 120) { scanBuf = ''; scanStart = now; }   // pauza = píše člověk, začít znovu
            scanBuf += e.key;
            scanLast = now;
            if (scanBuf.length > 64) { scanBuf = scanBuf.slice(-64); }
        } else if (e.key !== 'Shift') {
            scanBuf = '';
        }
    });

    // ── zámek po nečinnosti (15 min) ──
    // Hlídá se skutečná aktivita v záložce; po probuzení záložky (visibilitychange)
    // se kontroluje i uplynulý čas — prohlížeč timery na pozadí uspává.
    var LOCK_AFTER = 15 * 60 * 1000;
    var lastActivity = Date.now();
    var locked = false;
    var $lock = document.getElementById('posLock');
    var $lockCard = document.getElementById('posLockCard');
    var $lockPass = document.getElementById('posLockPass');
    var $lockErr = document.getElementById('posLockErr');

    function lockNow() {
        if (locked) return;
        locked = true;
        $lockErr.textContent = '';
        $lockPass.value = '';
        $lock.classList.add('show');
        setTimeout(function () { $lockPass.focus(); }, 60);
    }
    function touchActivity() {
        if (locked) return;
        lastActivity = Date.now();
    }
    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'input', 'wheel'].forEach(function (ev) {
        document.addEventListener(ev, touchActivity, { passive: true });
    });
    setInterval(function () {
        if (!locked && Date.now() - lastActivity >= LOCK_AFTER) { lockNow(); }
    }, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && !locked && Date.now() - lastActivity >= LOCK_AFTER) { lockNow(); }
    });

    document.getElementById('posLockForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData();
        fd.append('password', $lockPass.value);
        fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
        fetch('api/pos_unlock.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.ok) {
                    locked = false;
                    lastActivity = Date.now();
                    $lock.classList.remove('show');
                    $lockPass.value = '';
                    $lockErr.textContent = '';
                    return;
                }
                if (d.redirect) { window.location = d.redirect; return; }
                $lockErr.textContent = d.message || 'Špatné heslo.';
                $lockPass.value = '';
                $lockPass.focus();
                $lockCard.classList.remove('shake');
                void $lockCard.offsetWidth;   // restart animace
                $lockCard.classList.add('shake');
            })
            .catch(function () { $lockErr.textContent = 'Síťová chyba — zkus to znovu.'; });
    });

    render();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
