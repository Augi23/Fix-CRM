<?php
/**
 * POKLADNA (kasa prodejna) — pultový prodej dílů a produktů + JEDINÉ místo,
 * kde se zaznamenává platba zakázek (hotově / kartou / na fakturu, v3.49.0).
 * Vlevo: živé vyhledávání skladem dostupných položek + dnešní prodeje (dotisk).
 * Vpravo: košík (množství i cena upravitelné — sleva na místě), volba platby
 * hotově / kartou / na fakturu (u faktury povinný zákazník), dokončení prodeje.
 * Prodej okamžitě odepisuje sklad (api/pos_checkout.php, atomicky).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';
require_once 'includes/cash_book.php';
require_once 'includes/pos_shift.php';

ensurePosTables();
ensureProductsTable();
ensureProductsPosColumn();

// ── PŘEVZETÍ POKLADNY (směna) ───────────────────────────────────────────────
// Bez převzaté směny nejde markovat (server to vynucuje v api/pos_checkout.php).
// Tady se rozhoduje, co pracovník uvidí: převzetí / cizí směnu / vlastní kasu.
afxEnsurePosShiftTable();
$shift = afxPosShiftCurrent((int)getCurrentStaffBranchId());
$shiftMine = afxPosShiftIsMine($shift);
$shiftLast = $shift ? null : afxPosShiftLastClosed((int)getCurrentStaffBranchId());
// Stejné pravidlo jako na serveru (afxPosShiftCanForceClose) — jinak by manažer
// nové právo uzavřít zapomenutou směnu nikdy neuviděl a kasa by dál stála.
$shiftCanForce = $shift && function_exists('afxPosShiftCanForceClose')
    ? afxPosShiftCanForceClose($shift)
    : (function_exists('crmCanDeleteOrders') && crmCanDeleteOrders());

// denní součty do hlavičky (uzávěrka na první pohled); historie prodejů
// je záměrně JEN v Historie → Kasa prodejna, kasa je čistě prodejní plocha
$todaySums = ['cash' => 0.0, 'card' => 0.0, 'invoice' => 0.0];
try {
    // Denní uzávěrka jen za SVOU pobočku (admin/Boss vidí celofiremní součet).
    $__posBranch = orderBranchScopeSql('branch_id');
    // rozsah přes created_at místo DATE(...) — funkce nad sloupcem by vyřadila index
    foreach ($pdo->query("SELECT payment_method, SUM(total) s FROM pos_sales
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
          AND status = 'completed'" . $__posBranch . " GROUP BY payment_method") as $r) {
        $todaySums[(string)$r['payment_method']] = (float)$r['s'];
    }
} catch (Throwable $e) {}

// Pokladní deník: dnešní pohyby hotovosti mimo prodeje (výdaje na výkupy, vklady,
// výběry) → stav kasy = prodeje hotově + přijaté zakázky hotově + vklady − výdaje.
ensurePosCashMovementsTable();
$cashIn = 0.0; $cashOut = 0.0; $todayMoves = [];
try {
    $st = $pdo->query("SELECT id, direction, amount, purpose, ref_type, ref_id, ref_label, note, created_by, created_at
        FROM pos_cash_movements
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY"
        . orderBranchScopeSql('branch_id') . " ORDER BY id DESC LIMIT 30");
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
// Napříč pobočkami smí pokladní knihu přepínat globální divák (admin/Boss) a ÚČETNÍ
// (účetnictví se vede za firmu, ne za provozovnu — čtení; zápis blokuje pos_cash_move).
// Ostatním se pobočka bere z DB a GET parametr se ignoruje (pobočková izolace).
$cbGlobalRead = isBranchGlobalViewer() || (function_exists('crmIsAccountant') && crmIsAccountant());
if ($cbGlobalRead && isset($_GET['cb_branch'])) {
    // jen skutečná pobočka — cizí číslo by nechalo auto-založit odpadní pokladnu
    $cbReq = max(0, (int)$_GET['cb_branch']);
    foreach (getBranches(false) as $__b) {
        if ((int)$__b['id'] === $cbReq) { $cbBranch = $cbReq; break; }
    }
}
// účetní bez přidělené pobočky → výchozí je první pobočka (ne falešná „pobočka 0")
if ($cbBranch <= 0 && function_exists('crmIsAccountant') && crmIsAccountant()) {
    foreach (getBranches(false) as $__b) { $cbBranch = (int)$__b['id']; break; }
}
$cbRegister = afxCashRegisterForBranch($cbBranch, $cbBranch > 0);
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
.pos-type.manual { background: rgba(255,159,10,.16); color: #ffc46b; }
.pos-type.order { background: rgba(191,90,242,.18); color: #d9a7ff; }
.pos-type.vykup { background: rgba(255,69,58,.16); color: #ff8f88; }
.pos-manual { padding-bottom: 13px; margin-bottom: 13px; border-bottom: 1px solid rgba(255,255,255,.10); }
.pos-manual .form-control { font-size: 15.5px; padding: 10px 12px; }
.pos-manual .btn { font-weight: 700; white-space: nowrap; }
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
/* ── LCD displej platby hotově — font staré kalkulačky (DSEG7, sedmisegmentovky) ── */
@font-face { font-family: 'DSEG7'; src: url('assets/fonts/DSEG7Classic-Bold.woff2') format('woff2'); font-display: block; }
.lcd-overlay { position: fixed; inset: 0; z-index: 12500; display: none; align-items: center; justify-content: center;
               background: rgba(4,7,11,.72); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
.lcd-overlay.show { display: flex; }
.lcd-card { width: min(460px, 94vw); background: #1C1C1E; border: 1px solid rgba(255,255,255,.1);
            border-radius: 18px; padding: 16px; box-shadow: 0 24px 80px rgba(0,0,0,.55); }
.lcd-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
            color: rgba(235,235,245,.6); font-weight: 600; }
.lcd-x { background: none; border: 0; color: rgba(235,235,245,.6); font-size: 24px; line-height: 1; cursor: pointer; }
.lcd-x:hover { color: #fff; }
.lcd-panel { background: #000000; border: 1px solid rgba(255,255,255,.08); border-radius: 12px;
             padding: 10px 16px; box-shadow: inset 0 2px 16px rgba(0,0,0,.85); }
.lcd-row { display: flex; align-items: baseline; gap: 12px; padding: 10px 0; }
.lcd-row + .lcd-row { border-top: 1px dashed rgba(255,255,255,.08); }
.lcd-label { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: rgba(235,235,245,.6); white-space: nowrap; width: 74px; }
.lcd-val { font-family: 'DSEG7', ui-monospace, Menlo, monospace; font-size: 32px; line-height: 1.15;
           text-align: right; flex: 1; min-width: 0; }
.lcd-unit { font-size: 13px; color: rgba(235,235,245,.6); font-weight: 600; width: 24px; }
.lcd-total { color: #ffffff; text-shadow: 0 0 12px rgba(255,255,255,.22); }
.lcd-input { background: transparent; border: none; outline: none; padding: 0; caret-color: #EBEBF5; color: rgba(235,235,245,.3); }
.lcd-input.ok  { color: #30D158; text-shadow: 0 0 14px rgba(48,209,88,.4); }
.lcd-input.low { color: #FF453A; text-shadow: 0 0 14px rgba(255,69,58,.4); }
.lcd-change { color: #FF9F0A; text-shadow: 0 0 14px rgba(255,159,10,.4); }
/* Tlačítka dle Apple HIG (dark mode): SF Pro, systemGreen #30D158, sekundární
   výplň systemGray5 #2C2C2E, rádius 12, disabled = 40 % (žádné vlastní odstíny). */
.lcd-actions { display: flex; gap: 10px; margin-top: 14px; }
.lcd-actions button { font-family: 'SF Pro Display', 'SF Pro Text', -apple-system, BlinkMacSystemFont, sans-serif;
                      font-size: 17px; font-weight: 600; letter-spacing: -0.01em; border-radius: 12px;
                      border: 0; padding: 13px 18px; cursor: pointer; transition: filter .15s, transform .12s; }
.lcd-actions button:hover { filter: brightness(1.12); }
.lcd-actions button:active { transform: scale(.985); }
#lcdSkip { background: #2C2C2E; color: #FFFFFF; }
.lcd-actions .lcd-finish { flex: 1; width: auto; background: #30D158; color: #FFFFFF;
                           box-shadow: none; font-size: 17px; padding: 13px 18px; border-radius: 12px; }
.lcd-actions .lcd-finish:disabled { opacity: .4; }
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
        <?php if (crmCanUsePos()): ?>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="posCashMove('in')"><i class="fas fa-arrow-down me-1"></i>Vklad</button>
        <button type="button" class="btn btn-sm btn-outline-warning" onclick="posCashMove('out')"><i class="fas fa-arrow-up me-1"></i>Výběr</button>
        <?php endif; ?>
        <?php if ($cbCanEdit): ?>
        <button type="button" class="btn btn-sm btn-outline-info" onclick="posOpeningBalance()" title="Napočítanou hotovost zapíšeš jako počátek — od něj se počítá celý stav pokladny"><i class="fas fa-scale-balanced me-1"></i>Nastavit počáteční zůstatek</button>
        <?php endif; ?>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="posTestReceipt(this)" title="Vytiskne zkušební účtenku na termotiskárně u pokladny"><i class="fas fa-receipt me-1"></i>Test účtenky</button>
        <?php if ($shift && $shiftMine): ?>
        <button type="button" class="btn btn-sm btn-warning" onclick="shiftCloseOpenModal()" title="Spočítej hotovost, zapiš stav a předej pokladnu"><i class="fas fa-lock me-1"></i>Uzavřít / předat pokladnu</button>
        <span class="small text-white-50 align-self-center">převzato <?php echo e(date('H:i', strtotime((string)$shift['opened_at']))); ?></span>
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
    Zákon č. 254/2004 Sb. zakazuje přijmout i poskytnout jednu hotovostní platbu nad tuto částku — týká se tržeb stejně jako výdejů (např. výkup) a taková platba musí proběhnout převodem.
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
            <?php if (!empty($cbGlobalRead)): ?>
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

    <?php if (!empty($cashBook['range_clipped'])): ?>
    <div class="text-white-50 small mb-2"><i class="fas fa-circle-info me-1"></i>
        Zvolené období začínalo před počátkem pokladny — výpis je zkrácen a začíná
        <?php echo e(date('j. n. Y', strtotime((string)$cashBook['from']))); ?>.</div>
    <?php endif; ?>
    <?php /* Doklady datované před počátek pokladny mají číslo v řadě, ale v žádném
             období knihy se neobjeví — mlčky je nezahazovat, účetní by při kontrole
             souvislé řady našla „vytržené" doklady. */ ?>
    <?php if (!empty($cashBook['orphan_docs']['count'])): ?>
    <div class="alert alert-warning border-0 py-2 px-3 small mb-2">
        <i class="fas fa-triangle-exclamation me-1"></i>
        <strong><?php echo (int)$cashBook['orphan_docs']['count']; ?> doklad(y) datované před počátek pokladny</strong>
        (příjmy <?php echo formatMoney((float)$cashBook['orphan_docs']['in']); ?>,
        výdaje <?php echo formatMoney((float)$cashBook['orphan_docs']['out']); ?>) —
        v knize ani v zůstatku nejsou. Uprav počáteční zůstatek pokladny, nebo je stornuj.
    </div>
    <?php endif; ?>

    <?php if (empty($cashBook['rows'])): ?>
        <div class="text-white-50 small py-2">Za zvolené období nejsou žádné pohyby hotovosti.</div>
    <?php else: ?>
    <div class="table-responsive cash-book">
        <?php $cbShowActions = $cbCanEdit || crmCanUsePos(); ?>
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
                    <?php if ($cbShowActions): ?><th style="width:96px;"></th><?php endif; ?>
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
                    <?php if ($cbShowActions): ?>
                    <td class="text-end">
                        <?php if (crmCanUsePos() && $r['source'] === 'pos_sale' && empty($r['is_storno'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-info py-0 px-2 me-1" title="Dotisknout účtenku"
                                onclick="window.posReprintReceipt ? window.posReprintReceipt(<?php echo (int)$r['ref_id']; ?>) : window.open('print_receipt.php?id=<?php echo (int)$r['ref_id']; ?>&format=58&auto=1', '_blank')"><i class="fas fa-receipt"></i></button>
                        <?php endif; ?>
                        <?php if ($cbCanEdit): ?>
                        <?php
                        // Doklad se nikdy nemaže — jen stornuje protidokladem (§ 11 zák. 563/1991 Sb.).
                        // Nabízí se i u dokladu NAVÁZANÉHO na pohyb (vklad/výběr/výkup):
                        // storno tam vedle protidokladu založí i opačný pohyb v deníku,
                        // takže se peníze skutečně vrátí (afxCashDocStorno). Prodej na
                        // kase se stornuje stornem prodeje (vrací i zboží), ne tady.
                        $rowStornoable = false;
                        if ($r['source'] === 'document') {
                            $rowStornoable = empty($r['storno']) && empty($r['is_storno']);
                        } elseif ($r['source'] === 'movement' && (int)$r['doc_id'] > 0) {
                            $rowStornoable = empty($r['doc_storno']) && empty($r['doc_is_storno']);
                        }
                        ?>
                        <?php if ($rowStornoable): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Vystavit storno doklad<?php echo $r['source'] === 'movement' ? ' — založí i opačný pohyb v deníku, peníze se vrátí' : ''; ?>"
                                onclick="posCashDocStorno(<?php echo (int)$r['doc_id']; ?>, '<?php echo e((string)$r['doc_number']); ?>')">Storno</button>
                        <?php endif; ?>
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
            // Číslo vystaveného PPD/VPD hlásíme hned — obsluha ho píše na papírový
            // doklad. Varování (např. částka nad zákonný limit hotovosti) taky.
            var msg = j.doc_number ? 'Uloženo. Pokladní doklad: ' + j.doc_number : '';
            if (j.warning) { msg += (msg ? '\n\n' : '') + j.warning; }
            if (msg) { alert(msg); }
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

<?php if (crmCanUsePos()): ?>
<div class="row g-4">
    <!-- ── vyhledávání ── -->
    <div class="col-lg-6">
        <div class="glass-panel p-3 border-secondary">
            <form id="posManualForm" class="pos-manual">
                <label class="form-label small text-white-50 mb-2"><i class="fas fa-pen-to-square me-1 text-success"></i> Ruční položka mimo sklad</label>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-xl">
                        <input type="text" id="posManualName" class="form-control" maxlength="255" placeholder="Název produktu" autocomplete="off">
                    </div>
                    <div class="col-5 col-md-3 col-xl-2">
                        <input type="number" id="posManualQty" class="form-control text-center" min="1" max="999" step="1" value="1" inputmode="numeric" aria-label="Počet kusů">
                    </div>
                    <div class="col-7 col-md-4 col-xl-3">
                        <input type="text" id="posManualPrice" class="form-control text-center" inputmode="decimal" placeholder="Cena/ks">
                    </div>
                    <div class="col-12 col-md-auto">
                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-1"></i>Přidat</button>
                    </div>
                </div>
            </form>
            <div class="d-flex align-items-center gap-2 flex-wrap mt-1 mb-2">
                <button type="button" id="posVykupBtn" class="btn btn-outline-danger btn-sm"><i class="fas fa-hand-holding-dollar me-1"></i>Výplata výkupu</button>
                <span class="small text-white-50">záporná položka z výkupního listu — čistá výplata, nebo protiúčet za naše zboží</span>
            </div>
            <div id="posVykupPanel" class="pos-results mb-2" style="display:none;"></div>
            <label class="form-label small text-white-50 mb-2"><i class="fas fa-search me-1"></i> Najdi produkt, díl nebo hotovou zakázku · <i class="fas fa-barcode me-1"></i>USB čtečka funguje kdykoli — stačí pípnout kód</label>
            <input type="text" id="posSearch" class="form-control pos-search" placeholder="iPhone 13, displej, číslo zakázky…" autocomplete="off">
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
                <label class="form-label small text-white-50 mb-1">Zákazník — u faktury povinný; u zakázky můžeš nechat prázdné (faktura půjde na jejího klienta)</label>
                <select id="posCustomer" class="form-select" style="width:100%;"></select>
                <?php if (trim((string)get_setting('acc_bank_account', '')) === ''): ?>
                <div class="alert alert-danger border-0 py-2 mt-2 mb-0" style="font-size:.85rem;">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    <strong>Chybí číslo účtu firmy</strong> — faktura se vystaví bez platebních údajů a bez QR platby,
                    zákazník nebude mít kam zaplatit. Doplň ho v <strong>Účetnictví → Nastavení</strong>.
                </div>
                <?php endif; ?>
            </div>

            <button type="button" class="pos-finish" id="posFinish" disabled><i class="fas fa-check me-2"></i>Dokončit prodej</button>
        </div>

        <div class="glass-panel p-3 border-secondary mt-3" id="posDone" style="display:none;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-check-circle text-success fs-4"></i>
                <strong>Prodáno — doklad <code id="posDoneNumber"></code></strong>
            </div>
            <div id="posDoneChange" class="alert alert-warning border-0 py-2" style="display:none;font-size:1.15rem;">
                <i class="fas fa-coins me-1"></i> Vrátit zákazníkovi: <strong id="posDoneChangeVal"></strong>
            </div>
            <div id="posDoneProductNote" class="alert alert-info border-0 py-2 small" style="display:none;">
                <i class="fas fa-check me-1"></i> Sklad CRM odečten automaticky — produkt zůstane vyprodaný i po dalším nahrání souboru z naskladňovací appky.
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-info" id="posDoneReceipt"><i class="fas fa-print me-1"></i> Účtenka</button>
                <button type="button" class="btn btn-warning" id="posDoneInvoice" style="display:none;"><i class="fas fa-file-invoice me-1"></i> Faktura</button>
                <button type="button" class="btn btn-info" id="posDoneInvoiceMail" style="display:none;"><i class="fas fa-envelope me-1"></i> Fakturu e-mailem (QR platba)</button>
                <button type="button" class="btn btn-outline-light ms-auto" id="posDoneNew"><i class="fas fa-plus me-1"></i> Nový prodej</button>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Role účetní: jen pokladní kniha (výše) — prodej, směny a hotovost jsou provozní -->
<div class="glass-panel p-3 border-secondary mb-3 small text-white-50">
    <i class="fas fa-eye me-1"></i>Pokladna je pro tebe jen ke čtení — prodej a pohyby hotovosti dělá provoz.
</div>
<?php endif; ?>

<?php if (crmCanUsePos() && (!$shift || !$shiftMine)): ?>
<!-- ── PŘEVZETÍ POKLADNY — overlay přes celou kasu, dokud směnu někdo nedrží ── -->
<div class="pos-lock" id="posShiftGate" style="display:flex;">
    <div class="pos-lock-card" style="width:min(480px,94vw);">
        <?php if ($shift && !$shiftMine): ?>
            <div class="lk-icon"><i class="fas fa-user-lock"></i></div>
            <h4>Pokladnu má převzatou <?php echo e($shift['opened_by']); ?></h4>
            <div class="lk-who">od <?php echo e(date('j. n. Y H:i', strtotime((string)$shift['opened_at']))); ?> · v jednu chvíli obsluhuje pokladnu jen jeden pracovník</div>
            <?php if ($shiftCanForce): ?>
                <hr class="border-secondary">
                <div class="small text-white-50 mb-2">Vedení může směnu uzavřít za kolegu (např. zapomněl):</div>
                <div class="d-flex gap-2 justify-content-center">
                    <input type="text" class="form-control" id="shiftForceCounted" inputmode="decimal" placeholder="Napočítaná hotovost (Kč)" style="max-width:220px;">
                    <button type="button" class="btn btn-warning" onclick="shiftForceClose()"><i class="fas fa-lock-open me-1"></i>Uzavřít směnu</button>
                </div>
                <div class="lk-err" id="shiftForceErr"></div>
            <?php else: ?>
                <div class="small text-white-50 mt-2">Požádej kolegu o uzávěrku, nebo vedení o uzavření směny.</div>
            <?php endif; ?>
        <?php elseif (function_exists('afxAccountingClosedError')
                      && ($__lockMsg = afxAccountingClosedError(date('Y-m-d'), 'převzetí pokladny')) !== null): ?>
            <!-- Zamčené účetní období: říct to HNED, ne až po zadání hesla a spočítání
                 hotovosti (afxPosShiftOpen by převzetí stejně odmítl) -->
            <div class="lk-icon"><i class="fas fa-lock"></i></div>
            <h4>Pokladnu teď převzít nelze</h4>
            <div class="small text-white-75 mt-2 mb-3"><?php echo e($__lockMsg); ?></div>
            <div class="small text-white-50">Účetní období se odemyká v Nastavení → Uzávěrka období (jen administrátor).</div>
            <a href="index.php" class="lk-other">Zpět na přehled →</a>
        <?php else: ?>
            <!-- KROK 1/3 — potvrzení heslem: kdo přebírá kasu, přebírá odpovědnost
                 za hotovost v zásuvce, takže to musí potvrdit „podpisem" -->
            <div id="tkStep1">
                <div class="lk-icon"><i class="fas fa-user-shield"></i></div>
                <h4>Převzetí pokladny</h4>
                <div class="lk-who">Přihlášen: <strong><?php echo e($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?></strong></div>
                <div class="small text-white-50 mb-2">Potvrď svým heslem, že pokladnu přebíráš opravdu ty — od převzetí za hotovost v zásuvce odpovídáš.</div>
                <form id="tkPassForm" autocomplete="off">
                    <input type="password" class="form-control" id="tkPass" placeholder="Heslo" autocomplete="current-password">
                    <div class="lk-err" id="tkPassErr"></div>
                    <button type="submit" class="lk-btn"><i class="fas fa-arrow-right me-2"></i>Pokračovat</button>
                </form>
                <div class="mt-2">
                    <a href="index.php" class="lk-other me-3">Odejít bez převzetí pokladny</a>
                    <a href="logout.php" class="lk-other">Přihlásit jiného zaměstnance →</a>
                </div>
            </div>

            <!-- KROK 2/3 — vědomé rozhodnutí + výzva SPOČÍTAT hotovost teď, dokud
                 jde couvnout: na kroku 3 už se převzetí odmítnout nedá -->
            <div id="tkStep2" style="display:none;">
                <div class="lk-icon"><i class="fas fa-hand-holding-dollar"></i></div>
                <h4>Chceš teď převzít pokladnu?</h4>
                <div class="alert alert-warning bg-transparent border-warning text-start small py-2 px-3 mb-3">
                    <i class="fas fa-calculator me-1"></i><strong>Nejdřív spočítej hotovost v zásuvce.</strong>
                    V dalším kroku uvidíš, kolik v kase podle systému <em>má</em> být, a <strong>už jen potvrdíš,
                    jestli to sedí</strong> — na přepočítávání tam nebude čas ani prostor.
                </div>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-success px-4" id="tkYes"><i class="fas fa-check me-1"></i>ANO, mám spočítáno</button>
                    <a href="index.php" class="btn btn-outline-light px-4"><i class="fas fa-xmark me-1"></i>Ne, teď ne</a>
                </div>
            </div>

            <!-- KROK 3/3 — stávající obrazovka se stavem kasy -->
            <div id="tkStep3" style="display:none;">
            <div class="lk-icon"><i class="fas fa-cash-register"></i></div>
            <h4>Souhlasí hotovost?</h4>
            <div class="mb-2">Stav pokladny podle systému:
                <div class="fs-3 fw-bold text-info my-1"><?php echo formatMoney($cashState); ?></div>
                <?php if ($shiftLast): ?>
                    <div class="small text-white-50">Poslední uzávěrka: <?php echo e($shiftLast['closed_by'] ?? $shiftLast['opened_by']); ?>,
                        <?php echo e(date('j. n. H:i', strtotime((string)$shiftLast['closed_at']))); ?>,
                        napočítáno <?php echo formatMoney((float)$shiftLast['closing_counted']); ?></div>
                <?php endif; ?>
            </div>
            <div class="mb-3"><strong>Souhlasí hotovost v zásuvce?</strong></div>
            <div class="d-flex gap-2 justify-content-center mb-2">
                <button type="button" class="btn btn-success px-4" onclick="shiftOpen(1)"><i class="fas fa-check me-1"></i>ANO, souhlasí</button>
                <button type="button" class="btn btn-outline-warning px-4" id="shiftRecountBtn" onclick="document.getElementById('shiftRecount').style.display='';this.style.display='none';"><i class="fas fa-calculator me-1"></i>NE, přepočítám</button>
            </div>
            <div id="shiftRecount" style="display:none;">
                <div class="small text-white-50 mb-1">Zapiš, kolik v pokladně reálně je — od této částky se dnes odvíjí stav pokladny:</div>
                <div class="d-flex gap-2 justify-content-center">
                    <input type="text" class="form-control" id="shiftCounted" inputmode="decimal" placeholder="Skutečná hotovost (Kč)" style="max-width:220px;">
                    <button type="button" class="btn btn-warning" onclick="shiftOpen(0)"><i class="fas fa-pen me-1"></i>Zapsat a převzít</button>
                </div>
            </div>
            <div class="lk-err" id="shiftErr"></div>
            <div class="mt-2">
                <a href="#" class="lk-other me-3" onclick="location.reload();return false;">Načíst znovu</a>
                <a href="index.php" class="lk-other">Odejít bez převzetí →</a>
            </div>
            </div>
        <?php endif; ?>
        <hr class="border-secondary">
        <button type="button" class="btn btn-sm btn-outline-light" onclick="posTestReceipt(this)"><i class="fas fa-receipt me-1"></i>Test tiskárny účtenek</button>
    </div>
</div>
<?php endif; ?>

<!-- ── PLATBA HOTOVĚ — kalkulačkový displej (K úhradě / Přijato / Vrátit) ── -->
<div class="lcd-overlay" id="lcdOverlay">
    <div class="lcd-card">
        <div class="lcd-head">
            <span><i class="fas fa-money-bill-wave me-2"></i>Platba hotově</span>
            <button type="button" class="lcd-x" id="lcdClose" title="Zavřít">&times;</button>
        </div>
        <div class="lcd-panel">
            <div class="lcd-row">
                <span class="lcd-label">K úhradě</span>
                <span class="lcd-val lcd-total" id="lcdTotal">0</span>
                <span class="lcd-unit">Kč</span>
            </div>
            <div class="lcd-row">
                <span class="lcd-label">Přijato</span>
                <input type="text" class="lcd-val lcd-input" id="posCashReceived" inputmode="numeric" autocomplete="off" maxlength="8">
                <span class="lcd-unit">Kč</span>
            </div>
            <div class="lcd-row" id="lcdChangeRow" style="visibility:hidden;">
                <span class="lcd-label">Vrátit</span>
                <span class="lcd-val lcd-change" id="lcdChange">0</span>
                <span class="lcd-unit">Kč</span>
            </div>
        </div>
        <div class="lcd-actions">
            <button type="button" class="btn btn-outline-light" id="lcdSkip" title="Dokončit bez evidence přijaté hotovosti">Bez evidence</button>
            <button type="button" class="pos-finish lcd-finish mt-0" id="lcdFinish" disabled><i class="fas fa-check me-2"></i>Dokončit prodej</button>
        </div>
    </div>
</div>

<!-- ── UZÁVĚRKA POKLADNY — modal (z tlačítka i z pokusu o odhlášení) ── -->
<div class="modal fade" id="shiftCloseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-lock me-2 text-warning"></i>Uzávěrka pokladny</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">Stav podle systému: <strong class="text-info"><?php echo formatMoney($cashState); ?></strong></div>
                <label class="form-label small text-white-50">Spočítej hotovost v zásuvce a zapiš ji:</label>
                <input type="text" class="form-control mb-2" id="shiftCloseCounted" inputmode="decimal" placeholder="Napočítaná hotovost (Kč)" autocomplete="off">
                <div class="small mb-2" id="shiftCloseDiff"></div>
                <input type="text" class="form-control" id="shiftCloseNote" placeholder="Poznámka (nepovinné)" maxlength="255">
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Zpět</button>
                <button type="button" class="btn btn-warning" id="shiftCloseBtn"><i class="fas fa-lock me-1"></i>Uzavřít pokladnu</button>
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

<?php if (crmCanUsePos()): ?>
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
    var $manualForm = document.getElementById('posManualForm');
    var $manualName = document.getElementById('posManualName');
    var $manualQty = document.getElementById('posManualQty');
    var $manualPrice = document.getElementById('posManualPrice');

    function fmt(n) { return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(n) + ' Kč'; }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function manualKey(name, price) { return 'manual:' + String(name).trim().toLocaleLowerCase('cs-CZ') + ':' + Number(price).toFixed(2); }
    function cartKey(c) { return c.type === 'manual' ? manualKey(c.name, c.price) : c.type + ':' + c.id; }
    function parseCzk(v) {
        var raw = String(v || '').replace(/\s/g, '').replace(',', '.');
        return raw === '' ? NaN : parseFloat(raw);
    }

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
                    // d.message vysvětlí, že zboží leží na druhé pobočce — bez toho
                    // obsluha zírá na „nic nenalezeno" u kusu, který v CRM vidí
                    $results.innerHTML = '<div class="pos-empty">'
                        + (d.message ? String(d.message).replace(/</g, '&lt;') : 'Nic skladem nenalezeno.')
                        + '</div>';
                    return;
                }
                d.results.forEach(function (r) {
                    var el = document.createElement('div');
                    var typeLabel = { part: 'DÍL', product: 'PRODUKT', order: 'ZAKÁZKA' }[r.type] || 'POLOŽKA';
                    var detail = r.type === 'order' ? 'připraveno k úhradě' : ('skladem ' + r.stock + ' ks');
                    el.className = 'pos-hit';
                    el.innerHTML = '<span class="pos-type ' + r.type + '">' + typeLabel + '</span>'
                        + '<div><div class="nm">' + esc(r.name) + '</div><div class="cd">' + esc(r.code || '') + ' · ' + detail + '</div></div>'
                        + '<span class="pr">' + fmt(r.price) + '</span>';
                    el.addEventListener('click', function () { addToCart(r); });
                    $results.appendChild(el);
                });
            })
            .catch(function () {});
    }
    $search.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(doSearch, 280); });
    $search.addEventListener('focus', function () { if (!$results.children.length) doSearch(); });

    // ?q=číslo zakázky — tlačítko „Uhradit v Pokladně" z detailu zakázky předvyhledá
    // zakázku rovnou, obsluha ji jen klikne do košíku a zvolí platbu.
    (function () {
        var preQ = new URLSearchParams(window.location.search).get('q');
        if (preQ) { $search.value = preQ; doSearch(); $search.focus(); }
    })();

    // ── košík ──
    function addToCart(r) {
        var stock = r.type === 'manual' ? 999 : (parseInt(r.stock, 10) || 0);
        var addQty = parseInt(r.qty, 10) || 1;
        if (addQty < 1) addQty = 1;
        var found = cart.find(function (c) { return cartKey(c) === cartKey(r); });
        if (found) {
            if (found.qty + addQty > stock) { alert(r.type === 'manual' ? 'Maximum pro ruční položku je 999 ks.' : 'Skladem je jen ' + stock + ' ks.'); return; }
            found.qty += addQty;
        } else {
            cart.push({ type: r.type, id: r.id || 0, name: r.name, code: r.code, price: r.price, qty: addQty, stock: stock });
        }
        render();
    }
    $manualForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = $manualName.value.trim().replace(/\s+/g, ' ');
        var qty = parseInt($manualQty.value, 10);
        var price = parseCzk($manualPrice.value);
        if (name === '') { alert('Zadej název ruční položky.'); $manualName.focus(); return; }
        if (!qty || qty < 1 || qty > 999) { alert('Počet kusů musí být 1 až 999.'); $manualQty.focus(); return; }
        if (isNaN(price) || price < 0 || price > 1000000) { alert('Cena za kus je mimo rozsah.'); $manualPrice.focus(); return; }
        addToCart({ type: 'manual', id: 0, name: name, code: 'Ruční položka', price: Math.round(price * 100) / 100, qty: qty, stock: 999 });
        $manualName.value = '';
        $manualQty.value = '1';
        $manualPrice.value = '';
        $manualName.focus();
    });
    // ── výplata výkupu: seznam nevyplacených výkupních listů ──
    var $vykupBtn = document.getElementById('posVykupBtn');
    var $vykupPanel = document.getElementById('posVykupPanel');
    if ($vykupBtn) {
        $vykupBtn.addEventListener('click', function () {
            if ($vykupPanel.style.display !== 'none') { $vykupPanel.style.display = 'none'; return; }
            $vykupPanel.style.display = '';
            $vykupPanel.innerHTML = '<div class="p-2 small text-white-50">Načítám nevyplacené výkupy…</div>';
            fetch('api/pos_vykup_list.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var rows = d.results || [];
                    if (!rows.length) {
                        $vykupPanel.innerHTML = '<div class="p-2 small text-white-50">Žádný nevyplacený výkupní list — nejdřív ho vypiš v Dokumentech (Výkup zařízení).</div>';
                        return;
                    }
                    $vykupPanel.innerHTML = '';
                    rows.forEach(function (r) {
                        var el = document.createElement('div');
                        el.className = 'pos-hit';
                        el.innerHTML = '<span class="pos-type vykup">VÝKUP</span>'
                            + '<div><div class="nm">' + esc(r.doc_number + (r.subject ? ' — ' + r.subject : '')) + '</div>'
                            + '<div class="cd">' + esc((r.customer || '') + (r.date ? ' · ' + r.date : '')) + '</div></div>'
                            + '<span class="pr">−' + fmt(r.amount || 0) + '</span>';
                        el.addEventListener('click', function () {
                            var dupe = cart.some(function (c) { return c.type === 'vykup' && c.id === r.id; });
                            if (dupe) { alert('Tenhle výkup už v košíku je.'); return; }
                            addToCart({ type: 'vykup', id: r.id, name: 'Výplata výkupu ' + r.doc_number + (r.subject ? ' — ' + r.subject : ''), code: r.doc_number, price: -(r.amount || 0), qty: 1, stock: 1 });
                            $vykupPanel.style.display = 'none';
                        });
                        $vykupPanel.appendChild(el);
                    });
                })
                .catch(function () { $vykupPanel.innerHTML = '<div class="p-2 small text-danger">Načtení výkupů se nepovedlo.</div>'; });
        });
    }

    window.posRemove = function (i) { cart.splice(i, 1); render(); };
    window.posQty = function (i, v) {
        v = parseInt(v, 10) || 1;
        if (v < 1) v = 1;
        var max = cart[i].type === 'manual' ? 999 : (cart[i].type === 'order' ? 1 : cart[i].stock);
        if (v > max) { alert(cart[i].type === 'manual' ? 'Maximum pro ruční položku je 999 ks.' : 'Skladem je jen ' + max + ' ks.'); v = max; }
        cart[i].qty = v; render();
    };
    window.posPrice = function (i, v) {
        v = parseFloat(String(v).replace(',', '.'));
        if (isNaN(v)) v = 0;
        if (cart[i].type === 'vykup') {
            // výplatní řádek je vždy ZÁPORNÝ — obsluha smí napsat 3000 i −3000
            v = -Math.min(1000000, Math.abs(v));
        } else if (v < 0) { v = 0; }
        cart[i].price = v; render();
    };

    function total() { return cart.reduce(function (s, c) { return s + c.price * c.qty; }, 0); }

    function render() {
        $body.innerHTML = '';
        cart.forEach(function (c, i) {
            var tr = document.createElement('tr');
            var lockedOrder = c.type === 'order';
            var lockedVykup = c.type === 'vykup';
            var qtyAttrs = lockedOrder ? ' readonly title="Zakázka se účtuje vždy jako 1 ks"'
                : (lockedVykup ? ' readonly title="Výkup se vyplácí vždy jako 1 ks"' : '');
            var priceAttrs = lockedOrder ? ' readonly title="Cena zakázky se bere z detailu zakázky"' : '';
            tr.innerHTML = '<td><div class="pos-item-name">' + esc(c.name) + '</div><div class="pos-item-code">' + esc(c.code || '') + '</div></td>'
                + '<td><input type="number" class="form-control pos-qty" min="1" max="' + (c.type === 'manual' ? 999 : c.stock) + '" value="' + c.qty + '" onchange="posQty(' + i + ', this.value)"' + qtyAttrs + '></td>'
                + '<td><input type="text" class="form-control pos-price" value="' + c.price + '" onchange="posPrice(' + i + ', this.value)"' + priceAttrs + '></td>'
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
            // hotově → rovnou kalkulačkový displej (K úhradě / Přijato / Vrátit);
            // při záporném součtu (vyplácíme MY) displej nemá smysl
            if (payment === 'cash' && cart.length && total() > 0) { lcdShow(); }
        });
    });

    // ── LCD displej platby hotově ──
    var $lcd = document.getElementById('lcdOverlay');
    var $cashReceived = document.getElementById('posCashReceived');
    var $lcdTotal = document.getElementById('lcdTotal');
    var $lcdChange = document.getElementById('lcdChange');
    var $lcdChangeRow = document.getElementById('lcdChangeRow');
    var $lcdFinish = document.getElementById('lcdFinish');

    function cashVal() {
        var raw = $cashReceived.value.replace(/\D/g, '');
        return raw === '' ? null : parseInt(raw, 10);
    }
    function lcdUpdate() {
        var t = Math.round(total());
        $lcdTotal.textContent = String(t);
        var v = cashVal();
        $cashReceived.classList.remove('ok', 'low');
        if (v === null) {
            $lcdChangeRow.style.visibility = 'hidden';
            $lcdFinish.disabled = true;
            return;
        }
        // méně než k úhradě = červeně, dost = zeleně; oranžově kolik vrátit
        $cashReceived.classList.add(v < t ? 'low' : 'ok');
        var chg = v - t;
        $lcdChangeRow.style.visibility = chg > 0 ? 'visible' : 'hidden';
        $lcdChange.textContent = String(Math.max(0, chg));
        $lcdFinish.disabled = v < t || cart.length === 0;
    }
    function lcdShow() {
        lcdUpdate();
        $lcd.classList.add('show');
        setTimeout(function () { $cashReceived.focus(); }, 60);
    }
    function lcdHide() { $lcd.classList.remove('show'); }
    $cashReceived.addEventListener('input', function () {
        var clean = this.value.replace(/\D/g, '');
        if (clean !== this.value) { this.value = clean; }
        lcdUpdate();
    });
    $cashReceived.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !$lcdFinish.disabled) { $lcdFinish.click(); }
        if (ev.key === 'Escape') { lcdHide(); }
    });
    document.getElementById('lcdClose').addEventListener('click', lcdHide);
    document.getElementById('lcdFinish').addEventListener('click', function () { lcdHide(); submitSale(); });
    document.getElementById('lcdSkip').addEventListener('click', function () {
        $cashReceived.value = '';   // bez evidence — doklad nebude mít Placeno/Vráceno
        lcdHide(); submitSale();
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
        var t = total();
        var ok = cart.length > 0 && payment !== '';
        // u zakázky v košíku zákazník nutný není — odběratelem faktury se
        // automaticky stává klient zakázky (pos_checkout si ho dotáhne sám)
        var hasOrder = cart.some(function (c) { return c.type === 'order'; });
        if (payment === 'invoice' && !$('#posCustomer').val() && !hasOrder) ok = false;
        if (t < 0 && payment !== '' && payment !== 'cash') ok = false;   // výplata zákazníkovi jde jen hotově
        $finish.disabled = !ok;
        $finish.innerHTML = t < 0
            ? '<i class="fas fa-hand-holding-dollar me-2"></i>Vyplatit ' + fmt(Math.abs(t))
            : '<i class="fas fa-check me-2"></i>Dokončit prodej';
    }

    // ── dokončení ──
    // Hotově jde přes LCD displej (Přijato/Vrátit); karta a faktura rovnou.
    // Záporný součet (výplata výkupu) jde rovnou — nic se nepřijímá.
    $finish.addEventListener('click', function () {
        if (payment === 'cash' && total() > 0) { lcdShow(); return; }
        submitSale();
    });

    // TISK ÚČTENKY: tiskne VŽDY jen tento počítač — CRM vrátí hotové bajty
    // a prohlížeč je pošle na lokální můstek (127.0.0.1:9101 → USB tiskárna).
    // Na počítači bez tiskárny můstek neběží → u prodeje se nabídne tisk
    // dialogem prohlížeče, u testu jen hláška. Server sám nikdy netiskne.
    function localPrint(payload, onFail, onOk) {
        fetch('api/print_receipt_server.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>', bytes: 1 }, payload))
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok || !d.b64) { onFail(d.error || 'Doklad se nepodařilo připravit.'); return; }
            var bin = Uint8Array.from(atob(d.b64), function (c) { return c.charCodeAt(0); });
            return fetch('http://127.0.0.1:9101/print', { method: 'POST', mode: 'cors', body: bin })
                .then(function (r2) {
                    if (!r2.ok) { onFail('Můstek tiskárny odpověděl chybou.'); }
                    else if (onOk) { onOk(); }
                })
                .catch(function () { onFail('Tiskárna není na tomto počítači (můstek na 127.0.0.1:9101 neběží).'); });
        })
        .catch(function () { onFail('Síťová chyba při přípravě dokladu.'); });
    }
    function serverPrintReceipt(saleId, drawer) {
        localPrint({ sale_id: saleId, drawer: drawer ? 1 : 0 }, function () {
            // fallback: tisk dialogem prohlížeče (obsluha si vybere tiskárnu sama)
            window.open('print_receipt.php?id=' + saleId + '&format=58&auto=1', '_blank');
        });
    }
    window.posReprintReceipt = function (saleId) {
        saleId = parseInt(saleId, 10) || 0;
        if (saleId > 0) { serverPrintReceipt(saleId, false); }
    };

    function submitSale() {
        var wasCash = payment === 'cash';   // payment se po úspěchu nuluje — šuplík potřebuje hodnotu z doby prodeje
        $finish.disabled = true;
        $finish.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Zpracovávám…';
        fetch('api/pos_checkout.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>',
                payment: payment,
                customer_id: parseInt($('#posCustomer').val() || 0, 10),
                cash_received: payment === 'cash' ? (cashVal() === null ? null : cashVal()) : null,
                items: cart.map(function (c) {
                    var item = { type: c.type, id: c.type === 'manual' ? 0 : c.id, qty: c.qty, price: c.price };
                    if (c.type === 'manual') { item.name = c.name; }
                    return item;
                })
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
            document.getElementById('posDoneInvoiceMail').style.display = d.invoice_id ? '' : 'none';
            // velké „Vrátit zákazníkovi" — obsluha to potřebuje vidět hned, ne až na účtence
            var chg = (d.cash_change !== null && d.cash_change !== undefined && d.cash_change > 0);
            document.getElementById('posDoneChange').style.display = chg ? '' : 'none';
            if (chg) document.getElementById('posDoneChangeVal').textContent = fmt(d.cash_change);
            document.getElementById('posDone').style.display = '';
            cart = []; payment = '';
            document.querySelectorAll('.pos-pay').forEach(function (b) { b.className = 'pos-pay'; });
            $custWrap.style.display = 'none';
            $cashReceived.value = '';
            $('#posCustomer').val(null).trigger('change');
            render();
            // účtenka na termotiskárnu (hotově s otevřením šuplíku), fallback = prohlížeč
            serverPrintReceipt(d.sale_id, wasCash);
        })
        .catch(function () {
            $finish.innerHTML = '<i class="fas fa-check me-2"></i>Dokončit prodej';
            alert('Síťová chyba — prodej se možná neuložil, zkontroluj dnešní prodeje.');
            updateFinish();
        });
    }

    document.getElementById('posDoneReceipt').addEventListener('click', function () {
        if (lastSale) serverPrintReceipt(lastSale.sale_id, false);   // dotisk bez šuplíku
    });
    document.getElementById('posDoneInvoice').addEventListener('click', function () {
        if (lastSale && lastSale.invoice_id) window.open('print_invoice.php?id=' + lastSale.invoice_id, '_blank');
    });
    // faktura e-mailem s QR platbou — klient zaplatí online z telefonu.
    // Bez uloženého e-mailu klienta si adresu vyžádá (need_email) a pošle znovu.
    function sendInvoiceMail(to) {
        if (!lastSale || !lastSale.invoice_id) return;
        var b = document.getElementById('posDoneInvoiceMail');
        b.disabled = true;
        fetch('api/invoice_email.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF, invoice_id: lastSale.invoice_id, to: to || '' })
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            b.disabled = false;
            if (j.ok) { posToast(true, '✉️ Faktura odeslána na ' + j.to); return; }
            if (j.need_email) {
                var mail = prompt('Klient nemá uložený e-mail. Kam fakturu poslat?');
                if (mail) { sendInvoiceMail(mail.trim()); }
                return;
            }
            posToast(false, j.error || 'Odeslání selhalo.');
        })
        .catch(function () { b.disabled = false; posToast(false, 'Síťová chyba — e-mail neodešel.'); });
    }
    document.getElementById('posDoneInvoiceMail').addEventListener('click', function () { sendInvoiceMail(''); });
    document.getElementById('posDoneNew').addEventListener('click', function () {
        document.getElementById('posDone').style.display = 'none';
        location.reload();
    });

    // ── SMĚNY POKLADNY (převzetí / uzávěrka / odhlášení) ──
    var CSRF = '<?php echo $_SESSION['csrf_token'] ?? ''; ?>';
    var shiftMine = <?php echo ($shift && $shiftMine) ? 'true' : 'false'; ?>;
    var shiftExpected = <?php echo json_encode(round((float)$cashState, 2)); ?>;

    function shiftApi(payload, errEl, onOk) {
        fetch('api/pos_shift.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF }, payload))
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (!d.ok) {
                // platnost potvrzení heslem vypršela (počítání trvalo dlouho) →
                // vrátit na krok 1 místo nesrozumitelné hlášky
                if (d.need_password && document.getElementById('tkStep1')) {
                    ['tkStep2', 'tkStep3'].forEach(function (id) {
                        var el = document.getElementById(id); if (el) { el.style.display = 'none'; }
                    });
                    var s1 = document.getElementById('tkStep1');
                    s1.style.display = '';
                    var pe = document.getElementById('tkPassErr');
                    if (pe) { pe.textContent = 'Potvrzení heslem vypršelo — zadej ho prosím znovu.'; }
                    var pf = document.getElementById('tkPass'); if (pf) { pf.focus(); }
                    return;
                }
                var msg = d.error || 'Nepodařilo se.';
                if (errEl) { errEl.textContent = msg; errEl.style.display = ''; }
                // stav se mezitím změnil (kolega předběhl, nebo je to moje druhá
                // záložka) → obrazovka se sama přepne na skutečnost, ať uživatel
                // nezůstane viset pod overlayem bez cesty ven
                if (/převzatou|už má|už je otevřen|právě přebírá/i.test(msg)) {
                    setTimeout(function () { location.reload(); }, 1800);
                }
                return;
            }
            onOk(d);
        })
        .catch(function () { if (errEl) { errEl.textContent = 'Síťová chyba.'; errEl.style.display = ''; } });
    }

    // ── PŘEVZETÍ POKLADNY: heslo → vědomé potvrzení → stav hotovosti ─────────
    // Krok 1 ověřuje heslo přes stejné API jako zámek kasy (má i brzdu proti
    // zkoušení hesel); server si ověření pamatuje 10 minut a bez něj převzetí
    // odmítne — obrazovka tedy není jen ozdoba.
    (function () {
        var f = document.getElementById('tkPassForm');
        if (!f) { return; }                       // směnu drží někdo jiný / už je moje
        var $pass = document.getElementById('tkPass');
        var $err = document.getElementById('tkPassErr');
        var $card = document.querySelector('#posShiftGate .pos-lock-card');
        function show(step) {
            ['tkStep1', 'tkStep2', 'tkStep3'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) { el.style.display = (id === step) ? '' : 'none'; }
            });
            if (step === 'tkStep3') {
                // krok 3 vždy od začátku — po návratu z vypršelého hesla by jinak
                // zůstalo otevřené pole přepočtu i stará chybová hláška
                var rc = document.getElementById('shiftRecount'); if (rc) { rc.style.display = 'none'; }
                var rb = document.getElementById('shiftRecountBtn'); if (rb) { rb.style.display = ''; }
                var ci = document.getElementById('shiftCounted'); if (ci) { ci.value = ''; }
                var er = document.getElementById('shiftErr'); if (er) { er.textContent = ''; }
            }
        }
        setTimeout(function () { $pass.focus(); }, 80);

        f.addEventListener('submit', function (e) {
            e.preventDefault();
            if ($pass.value === '') { $err.textContent = 'Zadej heslo.'; return; }
            $err.textContent = '';
            var fd = new FormData();
            fd.append('password', $pass.value);
            fd.append('purpose', 'shift_open');   // ověření platí JEN pro převzetí kasy
            fd.append('csrf_token', '<?php echo $_SESSION['csrf_token'] ?? ''; ?>');
            fetch('api/pos_unlock.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { $pass.value = ''; show('tkStep2'); return; }
                    if (d.redirect) { window.location = d.redirect; return; }
                    $err.textContent = d.message || 'Špatné heslo.';
                    $pass.value = ''; $pass.focus();
                    if ($card) {
                        $card.classList.remove('shake');
                        void $card.offsetWidth;
                        $card.classList.add('shake');
                    }
                })
                .catch(function () { $err.textContent = 'Síťová chyba — zkus to znovu.'; });
        });

        document.getElementById('tkYes').addEventListener('click', function () { show('tkStep3'); });
    })();

    // převzetí: ANO (match=1) / NE + napočítaná částka (match=0)
    window.shiftOpen = function (match) {
        var counted = null;
        if (!match) {
            var el = document.getElementById('shiftCounted');
            counted = el.value.replace(/\s/g, '').replace(',', '.');
            if (counted === '' || isNaN(parseFloat(counted))) {
                var e1 = document.getElementById('shiftErr');
                e1.textContent = 'Zapiš napočítanou hotovost.'; e1.style.display = '';
                return;
            }
        }
        shiftApi({ action: 'open', match: match ? 1 : 0, counted: counted },
            document.getElementById('shiftErr'),
            function () {
                // lístek „kolik má být v kase" — tiskne tento počítač; reload nečeká na tiskárnu
                try { localPrint({ slip: 'shift_open' }, function () {}); } catch (e) {}
                setTimeout(function () { location.reload(); }, 600);
            });
    };

    // vedení: uzavření cizí zapomenuté směny
    window.shiftForceClose = function () {
        var el = document.getElementById('shiftForceCounted');
        var v = el.value.replace(/\s/g, '').replace(',', '.');
        if (v === '' || isNaN(parseFloat(v))) {
            var e2 = document.getElementById('shiftForceErr');
            e2.textContent = 'Zapiš napočítanou hotovost.'; e2.style.display = '';
            return;
        }
        shiftApi({ action: 'close', counted: v, force: 1, note: 'uzavřeno vedením' },
            document.getElementById('shiftForceErr'),
            function () {
                try { localPrint({ slip: 'shift_close' }, function () {}); } catch (e) {}
                setTimeout(function () { location.reload(); }, 600);
            });
    };

    // uzávěrka vlastní směny — modal s živým rozdílem proti systému
    var shiftCloseModal = null;
    var shiftAfterClose = null;   // po uzávěrce z odhlášení → pokračovat na logout
    window.shiftCloseOpenModal = function (afterUrl) {
        shiftAfterClose = afterUrl || null;
        if (!shiftCloseModal) shiftCloseModal = new bootstrap.Modal(document.getElementById('shiftCloseModal'));
        shiftCloseModal.show();
        setTimeout(function () { document.getElementById('shiftCloseCounted').focus(); }, 300);
    };
    document.getElementById('shiftCloseCounted').addEventListener('input', function () {
        var v = parseFloat(this.value.replace(/\s/g, '').replace(',', '.'));
        var out = document.getElementById('shiftCloseDiff');
        if (isNaN(v)) { out.textContent = ''; return; }
        var diff = Math.round((v - shiftExpected) * 100) / 100;
        out.innerHTML = diff === 0
            ? '<span class="text-success"><i class="fas fa-check me-1"></i>Souhlasí se systémem.</span>'
            : '<span class="' + (diff < 0 ? 'text-danger' : 'text-warning') + '">Rozdíl proti systému: <strong>'
                + (diff > 0 ? '+' : '') + fmt(diff) + '</strong></span>';
    });
    document.getElementById('shiftCloseBtn').addEventListener('click', function () {
        var v = document.getElementById('shiftCloseCounted').value.replace(/\s/g, '').replace(',', '.');
        if (v === '' || isNaN(parseFloat(v))) { alert('Zapiš napočítanou hotovost.'); return; }
        shiftApi({ action: 'close', counted: v, note: document.getElementById('shiftCloseNote').value }, null,
            function () {
                try { localPrint({ slip: 'shift_close' }, function () {}); } catch (e) {}
                setTimeout(function () {
                    // Po uzavření/předání pokladny → na Nástěnku. Reload kasy by hned
                    // zase ukázal bránu „Převzetí pokladny" s počítáním hotovosti,
                    // což pracovníka, který právě končí, jen mate (přání majitele).
                    window.location.href = shiftAfterClose || 'index.php';
                }, 600);
            });
    });

    // zkušební tisk na termotiskárnu (tlačítko v hlavičce kasy)
    window.posTestReceipt = function (btn) {
        btn.disabled = true;
        localPrint({ test: 1 },
            function (err) { btn.disabled = false; alert('Tisk se nepodařil: ' + err); },
            function () { btn.disabled = false; alert('Zkušební účtenka odeslána na tiskárnu tohoto počítače.'); });
    };

    // odhlášení s převzatou pokladnou → nejdřív připomenout uzávěrku
    if (shiftMine) {
        document.querySelectorAll('a[href$="logout.php"]').forEach(function (a) {
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                if (confirm('Máš převzatou pokladnu.\n\nPřed odhlášením spočítej hotovost, zapiš stav a uzavři pokladnu.\n\nOK = uzavřít pokladnu teď · Zrušit = zůstat')) {
                    shiftCloseOpenModal(a.href);
                }
            });
        });
    }

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
                var exactCodes = [code].concat(Array.isArray(d.code_candidates) ? d.code_candidates : [])
                    .map(function (v) { return String(v || '').toLowerCase(); })
                    .filter(function (v, i, a) { return v && a.indexOf(v) === i; });
                var exact = results.filter(function (r) { return exactCodes.indexOf(String(r.code || '').toLowerCase()) !== -1; });
                var hit = exact.length === 1 ? exact[0] : (exact.length === 0 && results.length === 1 ? results[0] : null);
                if (hit) {
                    addToCart(hit);
                    beep(true);
                    posToast(true, 'Přidáno: ' + hit.name);
                } else {
                    beep(false);
                    posToast(false, exact.length > 1 || results.length > 1
                        ? 'Kód „' + code + '" odpovídá více položkám — vyber ručně.'
                        : (d.message ? d.message : 'Kód „' + code + '" není skladem ani v systému.'));
                }
            })
            .catch(function () { beep(false); posToast(false, 'Síťová chyba při hledání kódu.'); });
    }

    document.addEventListener('keydown', function (e) {
        if (locked || document.getElementById('posShiftGate')) { scanBuf = ''; return; }
        // Pole s HESLEM je pro čtečku tabu: heuristika „strojové tempo + Enter" by
        // svižně naklepané heslo vyhodnotila jako sken, snědla Enter (formulář by
        // se neodeslal) a heslo poslala do URL api/pos_search.php?q=… tedy do logu.
        var __ae = document.activeElement;
        if (__ae && __ae.tagName === 'INPUT' && __ae.type === 'password') { return; }
        var now = Date.now();
        if (e.key === 'Enter') {
            // dávka ≥4 znaků napsaná strojovým tempem = sken
            if (scanBuf.length >= 4 && (now - scanLast) < 120 && (now - scanStart) < scanBuf.length * 55 + 200) {
                var code = scanBuf;
                scanBuf = '';
                e.preventDefault();
                e.stopImmediatePropagation();
                // čtečka znaky „napsala" i do zrovna aktivního pole — uklidit je
                var el = document.activeElement;
                if (el && ('value' in el) && typeof el.value === 'string' && el.value.slice(-code.length) === code) {
                    el.value = el.value.slice(0, -code.length);
                    el.dispatchEvent(new Event('input', { bubbles: true }));
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
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
