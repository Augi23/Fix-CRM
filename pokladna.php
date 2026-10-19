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
// Třídění pošty na pozadí (levná kontrola, běh max. 1× za ~3 min). Patří do
// api/notify_poll.php, jenže tam aktualizace na serveru zatím nesmí zapisovat.
require_once __DIR__ . '/posta/lib.php';
crmMailSortMaybeSchedule();
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
$todaySums = ['cash' => 0.0, 'card' => 0.0, 'invoice' => 0.0, 'invoice_ico' => 0.0];
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
        . orderBranchScopeSql('branch_id') . " ORDER BY id DESC LIMIT 40");
    foreach ($st as $m) { $todayMoves[] = $m; }
    // Součty za den se počítají ZVLÁŠŤ — dřív se sčítaly jen vypsané řádky, takže
    // při rušném dni (přes limit) ukazovala hlavička nižší vklady i výdaje.
    $ag = $pdo->query("SELECT direction, SUM(amount) s FROM pos_cash_movements
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY"
        . orderBranchScopeSql('branch_id') . " GROUP BY direction");
    foreach ($ag as $a) {
        if ((string)$a['direction'] === 'in') { $cashIn = (float)$a['s']; } else { $cashOut = (float)$a['s']; }
    }
} catch (Throwable $e) {}

// Dnešní PRODEJE — všechny způsoby platby (v3.70.0). Dřív se pod kasou ukazovaly
// jen pohyby hotovosti, takže prodej kartou nebo na fakturu jako by neexistoval
// a obsluha nepoznala, jestli se doklad vůbec uložil.
$todaySales = [];
try {
    $ss = $pdo->query("SELECT id, sale_number, payment_method, total, status, invoice_id, order_id,
                              seller_name, created_at
        FROM pos_sales
        WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY"
        . orderBranchScopeSql('branch_id') . " ORDER BY id DESC LIMIT 40");
    $todaySales = $ss->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $todaySales = []; }

// Jeden časově řazený proud: prodeje + pohyby hotovosti.
$todayFeed = [];
foreach ($todaySales as $x) { $todayFeed[] = ['kind' => 'sale', 'at' => (string)$x['created_at'], 'row' => $x]; }
foreach ($todayMoves as $x) { $todayFeed[] = ['kind' => 'move', 'at' => (string)$x['created_at'], 'row' => $x]; }
usort($todayFeed, static fn($a, $b) => strcmp($b['at'], $a['at']) ?: 0);
$todayFeed = array_slice($todayFeed, 0, 40);
$posPayLabels = ['cash' => 'Hotově', 'card' => 'Kartou', 'invoice' => 'Faktura s.r.o.', 'invoice_ico' => 'Faktura IČO'];
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
/* ══ POKLADNA v2 (3.95.0) — prodej nahoře ve 3 krocích, kniha a pohyby dole v záložkách ══
   Dotykové cíle min. 44 px, jeden sloupec na telefonu, košík vpravo „přilepený" na PC. */
.pos-app { --pos-card: rgba(20,22,28,.94) !important; --pos-card2: rgba(255,255,255,.035); --pos-line: rgba(255,255,255,.08) !important;
  --pos-fg: #fff !important; --pos-fg2: rgba(255,255,255,.64); --pos-fg3: rgba(255,255,255,.42); --pos-soft: rgba(255,255,255,.055) !important;
  --pos-accent: #0A84FF !important; --pos-ok: #30D158 !important; --pos-warn: #FF9F0A !important; --pos-bad: #FF453A !important; --pos-vio: #BF5AF2 !important; }
html[data-lg-theme="light"] .pos-app { --pos-card: #ffffff !important; --pos-card2: rgba(0,0,0,.025); --pos-line: rgba(0,0,0,.09) !important;
  --pos-fg: #111 !important; --pos-fg2: rgba(0,0,0,.62); --pos-fg3: rgba(0,0,0,.45); --pos-soft: rgba(0,0,0,.045) !important;
  --pos-ok: #1f9d45 !important; --pos-warn: #c26a00 !important; --pos-bad: #d70015 !important; --pos-vio: #8e3fc0 !important; }
.pos-app { color: var(--pos-fg) !important; }
/* CRM má globálně span/small/label/.btn/.form-control na 18 px (!important) — kasa si drží vlastní stupnici */
.pos-app .small, .pos-app .form-label, .pos-cust label { font-size: 13px !important; }
.pos-app .badge { font-size: 11.5px !important; }
.pos-app .form-control, .pos-app .form-select { font-size: 15px !important; }
.pos-app .btn { font-size: 14.5px !important; }
.pos-top-actions .btn span { font-size: 14.5px !important; }
.pos-pay > span { font-size: 15.5px !important; line-height: 1.2 !important; }
.pos-eshop-bar span { font-size: 13.5px !important; }
.pos-feed-row .btn { font-size: 13px !important; }
.pos-feed-row .d { font-size: 14px !important; }
.pos-mbar .lbl, .pos-mbar .lbl span { font-size: 12px !important; }
.pos-done code { font-size: 13px !important; }
.pos-cust .select2-container, .pos-cust .select2-selection__rendered { font-size: 15px !important; }
.pos-card { background: var(--pos-card) !important; border: 1px solid var(--pos-line) !important; border-radius: 18px !important; padding: 18px !important; }

/* ── horní lišta: název + směna · hotovost a dnešní tržby · akce ── */
.pos-top { display: flex !important; align-items: center !important; gap: 14px 22px !important; flex-wrap: wrap !important; margin-bottom: 16px !important; }
.pos-top-title { display: flex !important; align-items: center !important; gap: 12px !important; min-width: 0 !important; }
.pos-top-ico { width: 44px !important; height: 44px !important; border-radius: 13px !important; display: grid !important; place-items: center !important; font-size: 19px !important;
  color: #fff !important; background: linear-gradient(135deg, #0A84FF, #5AC8FA) !important; flex: 0 0 auto !important; }
.pos-top h2 { font-size: 24px !important; font-weight: 700 !important; margin: 0 !important; letter-spacing: -.02em !important; line-height: 1.1 !important; }
.pos-shift { font-size: 13px !important; color: var(--pos-fg2) !important; margin-top: 2px !important; display: flex !important; align-items: center !important; gap: 6px !important; }
.pos-shift .dot { width: 8px !important; height: 8px !important; border-radius: 50% !important; background: var(--pos-ok) !important; box-shadow: 0 0 0 3px rgba(48,209,88,.2) !important; }
.pos-shift.off .dot { background: var(--pos-warn) !important; box-shadow: 0 0 0 3px rgba(255,159,10,.2) !important; }
.pos-kpis { display: flex !important; gap: 8px !important; flex-wrap: wrap !important; flex: 1 1 auto !important; }
.pos-kpi { background: var(--pos-soft) !important; border: 1px solid var(--pos-line) !important; border-radius: 12px !important; padding: 7px 13px !important; line-height: 1.2 !important; min-width: 112px !important; }
.pos-kpi span { display: block !important; font-size: 11.5px !important; color: var(--pos-fg3) !important; text-transform: uppercase !important; letter-spacing: .05em !important; font-weight: 600 !important; }
.pos-kpi b { font-size: 17px !important; font-weight: 700 !important; white-space: nowrap !important; }
.pos-kpi.main { background: rgba(48,209,88,.10) !important; border-color: rgba(48,209,88,.32) !important; }
.pos-kpi.main.neg { background: rgba(255,69,58,.10) !important; border-color: rgba(255,69,58,.35) !important; }
.pos-kpi.main b { font-size: 19px !important; color: var(--pos-ok) !important; }
.pos-kpi.main.neg b { color: var(--pos-bad) !important; }
.pos-top-actions { display: flex !important; gap: 8px !important; flex-wrap: wrap !important; margin-left: auto !important; }
.pos-top-actions .btn { min-height: 40px !important; border-radius: 11px !important; font-weight: 600 !important; display: inline-flex !important; align-items: center !important; gap: 6px !important; }
html[data-lg-theme="light"] .pos-top-actions .btn { opacity: 1 !important; filter: none !important; }
html[data-lg-theme="light"] .pos-top-actions .btn-outline-success { color: #1a7f37 !important; border-color: rgba(26,127,55,.45) !important; background: rgba(52,199,89,.10) !important; }
html[data-lg-theme="light"] .pos-top-actions .btn-outline-warning { color: #a35400 !important; border-color: rgba(194,106,0,.45) !important; background: rgba(255,159,10,.12) !important; }
html[data-lg-theme="light"] .pos-top-actions .btn-warning { color: #3d2400 !important; background: #FFB340 !important; border-color: #FFB340 !important; }
.pos-top-actions .pos-more { color: var(--pos-fg) !important; border-color: var(--pos-line) !important; background: var(--pos-soft) !important; }
.pos-top-actions .dropdown-menu { border-radius: 12px !important; padding: 6px !important; }
.pos-top-actions .dropdown-item { border-radius: 8px !important; padding: 9px 12px !important; }

/* ── prodejní plocha ── */
.pos-grid { display: grid !important; grid-template-columns: minmax(0, 1fr) minmax(360px, 460px) !important; grid-template-rows: auto 1fr !important; gap: 18px !important; align-items: start !important; }
.pos-col-add { grid-column: 1 !important; grid-row: 1 !important; min-width: 0 !important; }
.pos-col-lower { grid-column: 1 !important; grid-row: 2 !important; min-width: 0 !important; }
.pos-col-cart { grid-column: 2 !important; grid-row: 1 / span 2 !important; position: sticky !important; top: calc(var(--topbar-h, 52px) + 12px) !important;
  max-height: calc(100vh - var(--topbar-h, 52px) - 24px) !important; overflow-y: auto !important; overscroll-behavior: contain !important; border-radius: 18px !important; }
.pos-col-cart::-webkit-scrollbar { width: 6px; } .pos-col-cart::-webkit-scrollbar-thumb { background: rgba(127,127,127,.35); border-radius: 3px; }
.pos-step { display: flex !important; align-items: center !important; gap: 10px !important; font-size: 17px !important; font-weight: 700 !important; margin-bottom: 12px !important; }
.pos-step-n { width: 26px !important; height: 26px !important; border-radius: 50% !important; display: grid !important; place-items: center !important; font-size: 13.5px !important; font-weight: 800 !important;
  background: var(--pos-accent) !important; color: #fff !important; flex: 0 0 auto !important; }
.pos-step .muted { font-weight: 500 !important; font-size: 13px !important; color: var(--pos-fg3) !important; }

.pos-search-wrap { position: relative !important; }
.pos-search-wrap > i { position: absolute !important; left: 16px !important; top: 50% !important; transform: translateY(-50%) !important; font-size: 18px !important; color: var(--pos-fg3) !important; pointer-events: none !important; }
.pos-search-wrap input.pos-search { width: 100% !important; font-size: 18px !important; padding: 15px 16px 15px 46px !important; border-radius: 14px !important; min-height: 56px !important; }
.pos-scan-hint { display: flex !important; align-items: center !important; gap: 6px !important; font-size: 12.5px !important; color: var(--pos-fg3) !important; margin: 8px 2px 0 !important; }
.pos-results { max-height: 420px !important; overflow-y: auto !important; margin-top: 8px !important; }
.pos-results:empty { display: none !important; }
.pos-hit { display: flex !important; align-items: center !important; gap: 12px !important; padding: 11px 12px !important; border-radius: 12px !important; cursor: pointer !important; transition: background .12s !important; min-height: 56px !important; }
.pos-hit + .pos-hit { border-top: 1px solid var(--pos-line) !important; }
.pos-hit:hover, .pos-hit:active { background: rgba(10,132,255,.12) !important; }
.pos-hit .nm { font-weight: 600 !important; font-size: 15px !important; line-height: 1.25 !important; }
.pos-hit .cd { font-size: 12px !important; color: var(--pos-fg3) !important; margin-top: 1px !important; }
.pos-hit .pr { margin-left: auto !important; font-weight: 700 !important; font-size: 16px !important; white-space: nowrap !important; }
.pos-hit .add { flex: 0 0 auto !important; width: 34px !important; height: 34px !important; border-radius: 50% !important; display: grid !important; place-items: center !important;
  background: rgba(10,132,255,.16) !important; color: #5fb4ff !important; font-size: 14px !important; }
.pos-type { font-size: 10px !important; font-weight: 800 !important; letter-spacing: .05em !important; border-radius: 7px !important; padding: 3px 7px !important; white-space: nowrap !important; flex: 0 0 auto !important; }
.pos-type.part { background: rgba(0,163,255,.18) !important; color: #6fd0ff !important; }
.pos-type.product { background: rgba(48,209,88,.16) !important; color: #6fe08d !important; }
.pos-type.manual { background: rgba(255,159,10,.16) !important; color: #ffc46b !important; }
.pos-type.order { background: rgba(191,90,242,.18) !important; color: #d9a7ff !important; }
.pos-type.vykup { background: rgba(255,69,58,.16) !important; color: #ff8f88 !important; }
.pos-type.expense { background: rgba(255,159,10,.20) !important; color: #ffb84d !important; }
.pos-type.eshop { background: rgba(48,209,88,.18) !important; color: #7ce39a !important; }
html[data-lg-theme="light"] .pos-type.part { color: #0068b3 !important; } html[data-lg-theme="light"] .pos-type.product { color: #1e7e38 !important; }
html[data-lg-theme="light"] .pos-type.order { color: #7a2fae !important; } html[data-lg-theme="light"] .pos-type.vykup { color: #c0392b !important; }
html[data-lg-theme="light"] .pos-type.eshop { color: #1e7e38 !important; } html[data-lg-theme="light"] .pos-type.manual,
html[data-lg-theme="light"] .pos-type.expense { color: #a35400 !important; }

/* dlaždice „jiné akce" — otevírají panel pod sebou (vždy jen jeden) */
.pos-tiles-label { font-size: 12px !important; text-transform: uppercase !important; letter-spacing: .06em !important; font-weight: 700 !important; color: var(--pos-fg3) !important; margin: 18px 2px 8px !important; }
.pos-tiles { display: grid !important; grid-template-columns: repeat(4, minmax(0, 1fr)) !important; gap: 8px !important; }
.pos-tile { display: flex !important; flex-direction: column !important; align-items: flex-start !important; gap: 3px !important; text-align: left !important; padding: 12px 13px !important; min-height: 76px !important;
  border-radius: 14px !important; border: 1px solid var(--pos-line) !important; background: var(--pos-card2) !important; color: var(--pos-fg) !important; cursor: pointer !important;
  transition: background .14s, border-color .14s !important; font-family: inherit !important; }
.pos-tile i { font-size: 18px !important; margin-bottom: 3px !important; }
.pos-tile b { font-size: 14px !important; font-weight: 700 !important; line-height: 1.2 !important; }
.pos-tile small { font-size: 11.5px !important; color: var(--pos-fg3) !important; line-height: 1.25 !important; }
.pos-tile:hover { background: var(--pos-soft) !important; }
.pos-tile.t-manual i { color: #ffc46b !important; } .pos-tile.t-eshop i { color: #6fe08d !important; }
.pos-tile.t-vykup i { color: #ff8f88 !important; } .pos-tile.t-expense i { color: #ffb84d !important; }
.pos-tile.active { border-color: var(--pos-accent) !important; background: rgba(10,132,255,.12) !important; box-shadow: inset 0 0 0 1px var(--pos-accent) !important; }
.pos-panel { margin-top: 10px !important; padding: 12px !important; border-radius: 14px !important; background: var(--pos-card2) !important; border: 1px solid var(--pos-line) !important; }
.pos-panel .form-control { font-size: 16px !important; padding: 11px 12px !important; min-height: 46px !important; }
.pos-panel .btn { min-height: 46px !important; font-weight: 700 !important; white-space: nowrap !important; }
.pos-panel-title { font-size: 13px !important; font-weight: 600 !important; color: var(--pos-fg2) !important; margin-bottom: 8px !important; }

/* ── košík ── */
.pos-cart-head { display: flex !important; align-items: center !important; gap: 10px !important; margin-bottom: 6px !important; }
.pos-cart-head .pos-step { margin-bottom: 0 !important; }
.pos-count { font-size: 12px !important; font-weight: 700 !important; padding: 2px 8px !important; border-radius: 99px !important; background: var(--pos-soft) !important; color: var(--pos-fg2) !important; }
.pos-clear { margin-left: auto !important; background: none !important; border: 0 !important; color: var(--pos-fg3) !important; font-size: 13px !important; padding: 6px 8px !important; border-radius: 8px !important; cursor: pointer !important; }
.pos-clear:hover { color: var(--pos-bad) !important; background: rgba(255,69,58,.08) !important; }
.pos-eshop-bar { display: none !important; align-items: center !important; gap: 10px !important; flex-wrap: wrap !important; margin: 8px 0 !important; padding: 10px 12px !important;
  border-radius: 12px !important; background: rgba(48,209,88,.12) !important; border: 1px solid rgba(48,209,88,.35) !important; font-size: 13.5px !important; }
.pos-eshop-bar b { color: #9fe7b6 !important; }
html[data-lg-theme="light"] .pos-eshop-bar b { color: #1e7e38 !important; }
.pos-eshop-bar .drop { margin-left: auto !important; background: none !important; border: 1px solid var(--pos-line) !important; color: var(--pos-bad) !important;
  border-radius: 8px !important; padding: 4px 10px !important; font-size: 12px !important; font-weight: 700 !important; cursor: pointer !important; }
.pos-lines { margin: 0 !important; padding: 0 !important; }
.pos-line { display: grid !important; grid-template-columns: minmax(0, 1fr) auto !important; gap: 8px 10px !important; padding: 12px 0 !important; border-top: 1px solid var(--pos-line) !important; }
.pos-line:first-child { border-top: 0 !important; }
.pos-item-name { font-size: 15.5px !important; font-weight: 600 !important; line-height: 1.25 !important; word-break: break-word !important; }
.pos-item-code { font-size: 12px !important; color: var(--pos-fg3) !important; margin-top: 2px !important; }
.pos-remove { width: 36px !important; height: 36px !important; border: 0 !important; border-radius: 10px !important; background: none !important; color: var(--pos-fg3) !important; font-size: 16px !important; cursor: pointer !important; }
.pos-remove:hover { background: rgba(255,69,58,.12) !important; color: var(--pos-bad) !important; }
.pl-ctrl { grid-column: 1 / -1 !important; display: flex !important; align-items: center !important; gap: 10px !important; flex-wrap: wrap !important; }
.pl-qty { display: inline-flex !important; align-items: center !important; border: 1px solid var(--pos-line) !important; border-radius: 12px !important; overflow: hidden !important; background: var(--pos-card2) !important; }
.pl-qty button { width: 40px !important; height: 42px !important; border: 0 !important; background: none !important; color: var(--pos-fg) !important; font-size: 15px !important; cursor: pointer !important; }
.pl-qty button:hover:not(:disabled) { background: var(--pos-soft) !important; }
.pl-qty button:disabled { opacity: .3 !important; cursor: default !important; }
.pl-qty input.pos-qty { width: 46px !important; height: 42px !important; border: 0 !important; border-radius: 0 !important; background: none !important; text-align: center !important; font-size: 17px !important; font-weight: 700 !important; padding: 0 !important; color: var(--pos-fg) !important; -moz-appearance: textfield !important; }
.pl-qty input.pos-qty::-webkit-outer-spin-button, .pl-qty input.pos-qty::-webkit-inner-spin-button { -webkit-appearance: none !important; margin: 0 !important; }
.pl-price { display: inline-flex !important; align-items: center !important; gap: 6px !important; font-size: 12px !important; color: var(--pos-fg3) !important; }
.pl-price input.pos-price { width: 104px !important; height: 42px !important; text-align: right !important; font-size: 16px !important; font-weight: 600 !important; border-radius: 12px !important; padding: 0 10px !important; }
.pl-price input[readonly] { opacity: .75 !important; }
.pos-line-total { margin-left: auto !important; font-size: 18px !important; font-weight: 700 !important; white-space: nowrap !important; }
.pos-line-total.neg { color: var(--pos-bad) !important; }
.pos-empty { color: var(--pos-fg3) !important; text-align: center !important; padding: 26px 8px 22px !important; font-size: 15px !important; }
.pos-empty i { display: block !important; font-size: 30px !important; margin-bottom: 10px !important; opacity: .55 !important; }
.pos-empty small { display: block !important; margin-top: 3px !important; font-size: 12.5px !important; }

.pos-sum { display: flex !important; justify-content: space-between !important; align-items: baseline !important; gap: 10px !important; padding: 14px 0 4px !important; margin-top: 6px !important; border-top: 1px solid var(--pos-line) !important; }
.pos-total-label { font-size: 17px !important; font-weight: 600 !important; color: var(--pos-fg2) !important; }
.pos-total { font-size: 46px !important; font-weight: 800 !important; letter-spacing: -.03em !important; line-height: 1 !important; white-space: nowrap !important; }
.pos-total.neg { color: var(--pos-bad) !important; }
.pos-paystep { margin-top: 16px !important; }
.pos-pay-grid { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 8px !important; }
.pos-pay { display: flex !important; align-items: center !important; gap: 12px !important; padding: 13px 14px !important; min-height: 62px !important; border-radius: 14px !important; border: 0 !important; cursor: pointer !important;
  font-weight: 700 !important; font-size: 15.5px !important; text-align: left !important; font-family: inherit !important;
  background: var(--pos-card2) !important; color: var(--pos-fg) !important; box-shadow: inset 0 0 0 1px var(--pos-line) !important; transition: background .14s, box-shadow .14s !important; }
.pos-pay i { font-size: 21px !important; width: 26px !important; text-align: center !important; color: var(--pos-fg3) !important; }
.pos-pay small { display: block !important; font-size: 11px !important; font-weight: 500 !important; color: var(--pos-fg3) !important; margin-top: 1px !important; }
.pos-pay:hover { background: var(--pos-soft) !important; }
.pos-pay.is-off { opacity: .55 !important; }
.pos-pay.sel-cash { background: rgba(48,209,88,.15) !important; box-shadow: inset 0 0 0 2px var(--pos-ok) !important; } .pos-pay.sel-cash i { color: var(--pos-ok) !important; }
.pos-pay.sel-card { background: rgba(10,132,255,.15) !important; box-shadow: inset 0 0 0 2px var(--pos-accent) !important; } .pos-pay.sel-card i { color: var(--pos-accent) !important; }
.pos-pay.sel-invoice { background: rgba(255,159,10,.14) !important; box-shadow: inset 0 0 0 2px var(--pos-warn) !important; } .pos-pay.sel-invoice i { color: var(--pos-warn) !important; }
.pos-pay.sel-invoice_ico { background: rgba(191,90,242,.14) !important; box-shadow: inset 0 0 0 2px var(--pos-vio) !important; } .pos-pay.sel-invoice_ico i { color: var(--pos-vio) !important; }
.pos-cust { margin-top: 12px !important; padding: 12px !important; border-radius: 14px !important; background: var(--pos-card2) !important; border: 1px solid var(--pos-line) !important; }
.pos-finish { width: 100% !important; margin-top: 14px !important; padding: 18px !important; min-height: 64px !important; border: 0 !important; border-radius: 16px !important; font-size: 19px !important; font-weight: 800 !important;
  color: #fff !important; background: linear-gradient(135deg, #0A84FF, #34AADC) !important; box-shadow: 0 10px 28px rgba(10,132,255,.32) !important;
  cursor: pointer !important; transition: filter .15s, transform .12s, opacity .15s !important; font-family: inherit !important; }
.pos-finish:hover { filter: brightness(1.08) !important; }
.pos-finish:active { transform: scale(.985) !important; }
.pos-finish:disabled { opacity: .38 !important; cursor: not-allowed !important; box-shadow: none !important; filter: grayscale(.4) !important; }
.pos-finish-hint { text-align: center !important; font-size: 12.5px !important; color: var(--pos-fg3) !important; margin-top: 8px !important; min-height: 18px !important; }
.pos-done { margin-top: 14px !important; border-color: rgba(48,209,88,.45) !important; background: rgba(48,209,88,.08) !important; }
.pos-done .btn { min-height: 44px !important; border-radius: 11px !important; font-weight: 600 !important; }

/* ── spodní část: záložky Dnešní pohyby / Pokladní kniha ── */
.pos-lower { margin-top: 0 !important; }
.pos-col-lower > .alert { margin-bottom: 12px !important; }
.pos-tabs { display: inline-flex !important; gap: 4px !important; padding: 4px !important; border-radius: 13px !important; background: var(--pos-soft) !important; border: 1px solid var(--pos-line) !important; margin-bottom: 12px !important; flex-wrap: wrap !important; }
.pos-tab { border: 0 !important; background: none !important; color: var(--pos-fg2) !important; font-weight: 600 !important; font-size: 14px !important; padding: 9px 16px !important; border-radius: 10px !important; cursor: pointer !important; min-height: 40px !important; font-family: inherit !important; }
.pos-tab.active { background: var(--pos-card) !important; color: var(--pos-fg) !important; box-shadow: 0 1px 4px rgba(0,0,0,.25) !important; }
.pos-tab .n { font-size: 11.5px !important; font-weight: 700 !important; margin-left: 4px !important; padding: 1px 7px !important; border-radius: 99px !important; background: var(--pos-soft) !important; }
.pos-feed-row { display: grid !important; grid-template-columns: 52px 112px minmax(0, 1fr) auto !important; align-items: center !important; gap: 10px !important; padding: 9px 4px !important; border-top: 1px solid var(--pos-line) !important; font-size: 14px !important; }
.pos-feed-row:first-child { border-top: 0 !important; }
.pos-feed-row .t { color: var(--pos-fg3) !important; font-variant-numeric: tabular-nums !important; }
.pos-feed-row .a { font-weight: 700 !important; text-align: right !important; white-space: nowrap !important; }
.pos-feed-row .d { min-width: 0 !important; overflow: hidden !important; text-overflow: ellipsis !important; white-space: nowrap !important; }
.pos-feed-row .d small { color: var(--pos-fg3) !important; font-size: 12.5px !important; margin-left: 4px !important; }
.pos-feed-row .x { display: flex !important; gap: 6px !important; justify-content: flex-end !important; }
.pos-feed-row .x .btn { min-width: 34px !important; min-height: 32px !important; }
.pos-feed-row.storno { opacity: .5 !important; } .pos-feed-row.storno .a { text-decoration: line-through !important; }
.pos-feed-empty { color: var(--pos-fg3) !important; padding: 18px 4px !important; font-size: 14px !important; }

/* ── mobil: spodní lišta s částkou → skok k platbě ── */
.pos-mbar { display: none !important; }
@media (max-width: 1199.98px) { .pos-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; } }
@media (max-width: 1080px) and (min-width: 992px) {
  /* tablet na šířku: dole je tab bar CRM — košík ho nesmí podjet, ať je „Dokončit prodej" vždy vidět */
  .pos-col-cart { max-height: calc(100vh - var(--topbar-h, 52px) - 110px) !important; }
}
@media (max-width: 991.98px) {
  .pos-grid { grid-template-columns: minmax(0, 1fr) !important; grid-template-rows: none !important; }
  .pos-col-add, .pos-col-cart, .pos-col-lower { grid-column: 1 !important; grid-row: auto !important; }
  .pos-col-cart { position: static !important; max-height: none !important; overflow: visible !important; }
  .pos-mbar { position: fixed !important; left: 10px !important; right: 10px !important; bottom: calc(82px + env(safe-area-inset-bottom, 0px)) !important; /* nad spodním tab barem CRM */ z-index: 1040 !important; align-items: center !important; gap: 12px !important;
    padding: 10px 10px 10px 16px !important; border-radius: 16px !important; background: rgba(16,18,24,.96) !important; border: 1px solid rgba(255,255,255,.14) !important;
    box-shadow: 0 12px 34px rgba(0,0,0,.45) !important; color: #fff !important; }
  .pos-mbar.show { display: flex !important; }
  .pos-mbar .lbl { font-size: 12px !important; color: rgba(255,255,255,.6) !important; line-height: 1.1 !important; }
  .pos-mbar b { font-size: 21px !important; display: block !important; }
  .pos-mbar button { margin-left: auto !important; border: 0 !important; border-radius: 12px !important; padding: 12px 18px !important; font-weight: 700 !important; font-size: 15.5px !important; color: #fff !important; background: #0A84FF !important; }
  .pos-app.has-mbar { padding-bottom: 84px !important; }
}
@media (max-width: 575.98px) {
  .pos-card { padding: 14px !important; border-radius: 16px !important; }
  .pos-top-actions { width: 100% !important; display: grid !important; grid-template-columns: repeat(4, minmax(0, 1fr)) !important; margin-left: 0 !important; }
  .pos-top-actions .dropdown, .pos-top-actions .dropdown > .btn { width: 100% !important; }
  .pos-top-actions .btn { flex-direction: column !important; justify-content: center !important; gap: 2px !important; padding: 7px 4px !important; min-height: 56px !important; line-height: 1.1 !important; }
  .pos-top-actions .btn span, .pos-top-actions .btn .lbl { font-size: 11.5px !important; text-align: center !important; }
  .pos-kpi { flex: 1 1 calc(50% - 8px) !important; min-width: 0 !important; }
  .pos-total { font-size: 38px !important; }
  .pos-pay { min-height: 58px !important; padding: 11px 12px !important; font-size: 14.5px !important; }
  .pos-feed-row { grid-template-columns: 44px minmax(0, 1fr) auto !important; }
  .pos-feed-row .d { grid-column: 1 / -1 !important; grid-row: 2 !important; white-space: normal !important; }
}
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

<div class="pos-app" id="posApp">
<!-- ── HORNÍ LIŠTA: kdo má kasu · kolik je v ní · dnešní tržby · akce ── -->
<div class="pos-top">
    <div class="pos-top-title">
        <span class="pos-top-ico"><i class="fas fa-cash-register"></i></span>
        <div>
            <h2>Pokladna</h2>
            <?php if ($shift && $shiftMine): ?>
                <div class="pos-shift"><span class="dot"></span>Směna: <strong><?php echo e($shift['opened_by'] ?? ($_SESSION['full_name'] ?? '')); ?></strong> · od <?php echo e(date('H:i', strtotime((string)$shift['opened_at']))); ?></div>
            <?php elseif ($shift): ?>
                <div class="pos-shift off"><span class="dot"></span>Kasu má převzatou <?php echo e($shift['opened_by']); ?></div>
            <?php else: ?>
                <div class="pos-shift off"><span class="dot"></span>Pokladna není převzatá</div>
            <?php endif; ?>
        </div>
    </div>

    <?php $__todayInvoices = $todaySums['invoice'] + $todaySums['invoice_ico']; ?>
    <div class="pos-kpis">
        <div class="pos-kpi main<?php echo $cashState < 0 ? ' neg' : ''; ?>"
             title="Počáteční zůstatek pokladny (<?php echo e(formatMoney((float)$cbRegister['opening_balance'])); ?> k <?php echo e(date('j. n. Y', strtotime((string)$cbRegister['opening_date']))); ?>) + všechny příjmy − výdaje. Dnešní pohyb: <?php echo e(formatMoney($cashToday)); ?><?php echo $cashIn > 0 ? ' · vklady ' . e(formatMoney($cashIn)) : ''; ?><?php echo $cashOut > 0 ? ' · výdaje −' . e(formatMoney($cashOut)) : ''; ?>">
            <span>V kase hotově</span><b><?php echo formatMoney($cashState); ?></b>
        </div>
        <div class="pos-kpi"><span>Dnes hotově</span><b><?php echo formatMoney($todaySums['cash']); ?></b></div>
        <div class="pos-kpi"><span>Dnes kartou</span><b><?php echo formatMoney($todaySums['card']); ?></b></div>
        <div class="pos-kpi" title="Faktura s.r.o. <?php echo e(formatMoney($todaySums['invoice'])); ?><?php echo ($todaySums['invoice_ico'] > 0 || afxIcoSupplier()['ready']) ? ' · Faktura IČO ' . e(formatMoney($todaySums['invoice_ico'])) : ''; ?>"><span>Dnes fakturou</span><b><?php echo formatMoney($__todayInvoices); ?></b></div>
    </div>

    <div class="pos-top-actions">
        <?php if (crmCanUsePos()): ?>
        <button type="button" class="btn btn-outline-success" onclick="posCashMove('in')" title="Vložit hotovost do kasy"><i class="fas fa-arrow-down"></i><span class="lbl">Vklad</span></button>
        <button type="button" class="btn btn-outline-warning" onclick="posCashMove('out')" title="Vybrat hotovost z kasy"><i class="fas fa-arrow-up"></i><span class="lbl">Výběr</span></button>
        <?php endif; ?>
        <div class="dropdown">
            <button type="button" class="btn btn-outline-secondary pos-more" data-bs-toggle="dropdown" aria-expanded="false" title="Další možnosti"><i class="fas fa-ellipsis"></i><span class="lbl">Další</span></button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php if (crmCanViewHistory()): ?>
                <li><a class="dropdown-item" href="history.php?tab=kasa"><i class="fas fa-clock-rotate-left me-2"></i>Historie Pokladny</a></li>
                <?php endif; ?>
                <li><button type="button" class="dropdown-item" onclick="posShowTab('book', true)"><i class="fas fa-book-open me-2"></i>Pokladní kniha</button></li>
                <?php if ($cbCanEdit): ?>
                <li><button type="button" class="dropdown-item" onclick="posOpeningBalance()"><i class="fas fa-scale-balanced me-2"></i>Nastavit počáteční zůstatek</button></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><button type="button" class="dropdown-item" onclick="posTestReceipt(this)"><i class="fas fa-receipt me-2"></i>Test tiskárny účtenek</button></li>
            </ul>
        </div>
        <?php if ($shift && $shiftMine): ?>
        <button type="button" class="btn btn-warning" onclick="shiftCloseOpenModal()" title="Spočítej hotovost, zapiš stav a předej pokladnu"><i class="fas fa-lock"></i><span class="lbl">Uzavřít směnu</span></button>
        <?php endif; ?>
    </div>
</div>

<?php if (crmCanUsePos()): ?>
<div class="pos-grid">
    <!-- ── KROK 1: přidat do košíku ── -->
    <section class="pos-col-add">
        <div class="pos-card">
            <div class="pos-step"><span class="pos-step-n">1</span>Přidej do košíku</div>
            <div class="pos-search-wrap">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search" id="posSearch" class="form-control pos-search" placeholder="Produkt, díl, číslo zakázky nebo příjmení klienta…" autocomplete="off" enterkeyhint="search">
            </div>
            <div class="pos-scan-hint"><i class="fas fa-barcode"></i>Čtečka čárových kódů funguje kdykoli — stačí pípnout, položka se přidá sama.</div>
            <div id="posResults" class="pos-results"></div>

            <div class="pos-tiles-label">Další možnosti</div>
            <div class="pos-tiles">
                <button type="button" class="pos-tile t-manual" id="posManualTile" data-panel="posManualForm"><i class="fas fa-pen-to-square"></i><b>Ruční položka</b><small>zboží mimo sklad</small></button>
                <button type="button" class="pos-tile t-eshop" id="posEshopBtn" data-panel="posEshopPanel"><i class="fas fa-cart-shopping"></i><b>Rezervace e-shopu</b><small>vyzvednutí a platba</small></button>
                <button type="button" class="pos-tile t-vykup" id="posVykupBtn" data-panel="posVykupPanel"><i class="fas fa-hand-holding-dollar"></i><b>Výplata výkupu</b><small>vyplácíme klientovi</small></button>
                <button type="button" class="pos-tile t-expense" id="posExpenseBtn" data-panel="posExpenseForm"><i class="fas fa-money-bill-transfer"></i><b>Výdaj z kasy</b><small>záloha, drobný nákup</small></button>
            </div>

            <form id="posManualForm" class="pos-panel" style="display:none;">
                <div class="pos-panel-title">Ruční položka — zboží nebo služba, která není ve skladu</div>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-xl">
                        <input type="text" id="posManualName" class="form-control" maxlength="255" placeholder="Název" autocomplete="off">
                    </div>
                    <div class="col-4 col-md-3 col-xl-2">
                        <input type="number" id="posManualQty" class="form-control text-center" min="1" max="999" step="1" value="1" inputmode="numeric" aria-label="Počet kusů">
                    </div>
                    <div class="col-8 col-md-4 col-xl-3">
                        <input type="text" id="posManualPrice" class="form-control text-center" inputmode="decimal" placeholder="Cena za kus">
                    </div>
                    <div class="col-12 col-md-auto">
                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-plus me-1"></i>Přidat</button>
                    </div>
                </div>
            </form>
            <div id="posEshopPanel" class="pos-results pos-panel" style="display:none;"></div>
            <div id="posVykupPanel" class="pos-results pos-panel" style="display:none;"></div>
            <form id="posExpenseForm" class="pos-panel" style="display:none;">
                <div class="pos-panel-title">Výdaj z kasy — vždy hotově, markuje se samostatně</div>
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-xl">
                        <input type="text" id="posExpenseName" class="form-control" maxlength="255" placeholder="Účel — např. záloha Khalil, nákup drogerie" autocomplete="off">
                    </div>
                    <div class="col-7 col-md-4 col-xl-3">
                        <input type="text" id="posExpensePrice" class="form-control text-center" inputmode="decimal" placeholder="Částka Kč">
                    </div>
                    <div class="col-5 col-md-auto">
                        <button type="submit" class="btn btn-warning w-100"><i class="fas fa-minus me-1"></i>Přidat</button>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <!-- ── KROK 2 + 3: košík a platba ── -->
    <section class="pos-col-cart" id="posCartCol">
        <div class="pos-card">
            <div class="pos-cart-head">
                <div class="pos-step"><span class="pos-step-n">2</span>Košík</div>
                <span class="pos-count" id="posCartCount">0</span>
                <button type="button" class="pos-clear" id="posCartClear" style="display:none;"><i class="fas fa-trash-can me-1"></i>Vysypat</button>
            </div>
            <div id="posEshopBar" class="pos-eshop-bar">
                <i class="fas fa-cart-shopping" style="color:#7ce39a;"></i>
                <span>Vyzvednutí objednávky <b id="posEshopRef">—</b> <span class="text-white-50" id="posEshopWho"></span></span>
                <button type="button" class="drop" id="posEshopDrop">Odpojit</button>
            </div>
            <div id="posCartBody" class="pos-lines"></div>
            <div id="posCartEmpty" class="pos-empty"><i class="fas fa-basket-shopping"></i>Košík je prázdný<small>Vyhledej zboží vlevo nebo pípni čtečkou.</small></div>

            <div class="pos-sum">
                <span class="pos-total-label">Celkem</span>
                <span class="pos-total" id="posTotal">0 Kč</span>
            </div>

            <div class="pos-paystep" id="posPayStep">
                <div class="pos-step"><span class="pos-step-n">3</span>Způsob platby</div>
                <div class="pos-pay-grid">
                    <button type="button" class="pos-pay" data-pay="cash"><i class="fas fa-money-bill-wave"></i><span>Hotově<small>spočítá, kolik vrátit</small></span></button>
                    <button type="button" class="pos-pay" data-pay="card"><i class="fas fa-credit-card"></i><span>Kartou<small>na platebním terminálu</small></span></button>
                    <button type="button" class="pos-pay" data-pay="invoice"><i class="fas fa-file-invoice"></i><span>Faktura s.r.o.<small>převodem, s QR</small></span></button>
                    <button type="button" class="pos-pay<?php echo afxIcoSupplier()['ready'] ? '' : ' is-off'; ?>" data-pay="invoice_ico"><i class="fas fa-file-signature"></i><span>Faktura IČO<small><?php echo afxIcoSupplier()['ready'] ? 'převodem, OSVČ' : 'zatím neaktivní'; ?></small></span></button>
                </div>

                <div id="posCustomerWrap" class="pos-cust" style="display:none;">
                    <label class="form-label small mb-1" style="color:var(--pos-fg2)">Odběratel faktury — u zakázky můžeš nechat prázdné (faktura půjde na jejího klienta).</label>
                    <select id="posCustomer" class="form-select" style="width:100%;"></select>
                    <?php /* jednorázový odběratel (v3.59.0): fakturu jde vystavit bez zakládání klienta —
                             údaje se uloží JEN na fakturu (cust_*_override), do CRM klientů se nic nepřidá */ ?>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" id="posAdhocOn">
                        <label class="form-check-label small" for="posAdhocOn" style="color:var(--pos-fg2)">
                            Odběratel není v CRM — vyplnit ručně (nezakládá klienta)
                        </label>
                    </div>
                    <div id="posAdhocWrap" class="mt-2" style="display:none;">
                        <div class="row g-2">
                            <div class="col-12">
                                <input type="text" class="form-control" id="posAdhocName" maxlength="190"
                                       placeholder="Název firmy nebo jméno a příjmení *">
                            </div>
                            <div class="col-12">
                                <textarea class="form-control" id="posAdhocAddr" rows="2" maxlength="400"
                                          placeholder="Adresa (ulice, město, PSČ)"></textarea>
                            </div>
                            <div class="col-7">
                                <div class="input-group">
                                    <input type="text" class="form-control" id="posAdhocIco" maxlength="12" inputmode="numeric" placeholder="IČO">
                                    <button class="btn btn-outline-info" type="button" id="posAdhocAres" title="Načíst z ARESu">ARES</button>
                                </div>
                            </div>
                            <div class="col-5">
                                <input type="text" class="form-control" id="posAdhocDic" maxlength="20" placeholder="DIČ">
                            </div>
                            <div class="col-12">
                                <input type="email" class="form-control" id="posAdhocEmail" maxlength="190" placeholder="E-mail (pro zaslání faktury)">
                            </div>
                        </div>
                        <div class="small mt-2" style="color:var(--pos-fg3)">
                            <i class="fas fa-circle-info me-1"></i>Povinný je <strong>jen název / jméno</strong>. IČO a DIČ jen u firmy.
                            Údaje se uloží jen na fakturu, klient v CRM nevzniká. Bez e-mailu se faktura po prodeji <strong>vytiskne</strong>.
                        </div>
                    </div>
                    <?php /* varování o chybějícím účtu dle VÝSTAVCE zvolené faktury —
                             text plní JS podle payment (invoice → s.r.o., invoice_ico → OSVČ) */ ?>
                    <div class="alert alert-danger border-0 py-2 mt-2 mb-0" id="posAccWarn" style="font-size:.85rem;display:none;">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        <strong>Chybí číslo účtu <span id="posAccWarnWho"></span></strong> — faktura se vystaví bez platebních
                        údajů a bez QR platby, zákazník nebude mít kam zaplatit. Doplň ho v <strong>Účetnictví → Nastavení</strong>.
                    </div>
                </div>

                <button type="button" class="pos-finish" id="posFinish" disabled><i class="fas fa-check me-2"></i>Dokončit prodej</button>
                <div class="pos-finish-hint" id="posFinishHint">Přidej položku do košíku.</div>
            </div>
        </div>

        <div class="pos-card pos-done" id="posDone" style="display:none;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-check-circle text-success fs-3"></i>
                <div><strong class="fs-5">Prodáno</strong><div class="small" style="color:var(--pos-fg2)">doklad <code id="posDoneNumber"></code></div></div>
            </div>
            <div id="posDoneChange" class="alert alert-warning border-0 py-2" style="display:none;font-size:1.25rem;">
                <i class="fas fa-coins me-1"></i> Vrátit zákazníkovi: <strong id="posDoneChangeVal"></strong>
            </div>
            <div id="posDoneProductNote" class="alert alert-info border-0 py-2 small" style="display:none;">
                <i class="fas fa-check me-1"></i> Sklad CRM odečten automaticky — produkt zůstane vyprodaný i po dalším nahrání souboru z naskladňovací appky.
            </div>
            <div id="posDoneInvoiceNote" class="alert alert-warning border-0 py-2 small" style="display:none;">
                <i class="fas fa-print me-1"></i> Odběratel nemá e-mail — fakturu <strong>vytiskni</strong> tlačítkem
                „Faktura". Poslat ji jde i tak: „Fakturu e-mailem" se na adresu zeptá.
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-light" id="posDoneReceipt"><i class="fas fa-print me-1"></i> Účtenka znovu</button>
                <button type="button" class="btn btn-warning" id="posDoneInvoice" style="display:none;"><i class="fas fa-file-invoice me-1"></i> Faktura</button>
                <button type="button" class="btn btn-info" id="posDoneInvoiceMail" style="display:none;"><i class="fas fa-envelope me-1"></i> Fakturu e-mailem</button>
                <button type="button" class="btn btn-primary ms-auto" id="posDoneNew"><i class="fas fa-plus me-1"></i> Nový prodej</button>
            </div>
        </div>
    </section>

<!-- mobil / tablet na výšku: částka vždy na očích, klepnutím skok k platbě -->
<div class="pos-mbar" id="posMbar">
    <div><span class="lbl">Celkem · <span id="posMbarCount">0</span> pol.</span><b id="posMbarTotal">0 Kč</b></div>
    <button type="button" onclick="document.getElementById('posPayStep').scrollIntoView({behavior:'smooth', block:'start'})">K platbě <i class="fas fa-arrow-down ms-1"></i></button>
</div>

<?php else: ?>
<!-- Role účetní: jen pokladní kniha (níže) — prodej, směny a hotovost jsou provozní -->
<div class="pos-card mb-3 small" style="color:var(--pos-fg2)">
    <i class="fas fa-eye me-1"></i>Pokladna je pro tebe jen ke čtení — prodej a pohyby hotovosti dělá provoz.
</div>
<?php endif; ?>

<div class="pos-col-lower">
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

<!-- ── SPODNÍ ČÁST: Dnešní pohyby / Pokladní kniha (záložky) ── -->
<?php
// Kniha se otevře rovnou, když se do ní filtruje (období / pobočka) nebo když kasa není k prodeji (účetní).
$posDefaultTab = (isset($_GET['cb_from']) || isset($_GET['cb_to']) || isset($_GET['cb_branch']) || !crmCanUsePos()) ? 'book' : 'feed';
?>
<div class="pos-lower" id="posLower">
    <div class="pos-tabs" role="tablist">
        <button type="button" class="pos-tab<?php echo $posDefaultTab === 'feed' ? ' active' : ''; ?>" data-tab="feed" role="tab"><i class="fas fa-receipt me-1"></i>Dnešní pohyby<span class="n"><?php echo count($todayFeed); ?></span></button>
        <button type="button" class="pos-tab<?php echo $posDefaultTab === 'book' ? ' active' : ''; ?>" data-tab="book" role="tab"><i class="fas fa-book-open me-1"></i>Pokladní kniha</button>
    </div>

    <div class="pos-card" id="posTabFeed"<?php echo $posDefaultTab === 'feed' ? '' : ' hidden'; ?>>
        <?php if (empty($todayFeed)): ?>
            <div class="pos-feed-empty"><i class="fas fa-mug-hot me-2"></i>Dnes zatím žádný prodej ani pohyb hotovosti.</div>
        <?php else: ?>
        <div>
        <?php foreach ($todayFeed as $f): if ($f['kind'] === 'sale'):
            $sale = $f['row'];
            $storno = (string)$sale['status'] === 'cancelled';
            $pm = (string)$sale['payment_method'];
            // záporná prodejka = výplata z kasy (výkup, výdaj) — pokladní kniha ji
            // vede jako výdej, ať to tady neříká něco jiného
            $vydej = (float)$sale['total'] < 0;
            $pmLabel = $vydej ? 'Výdej z kasy' : ($posPayLabels[$pm] ?? $pm);
            $pmClass = $vydej ? 'text-warning' : ($pm === 'cash' ? 'text-success' : ($pm === 'card' ? 'text-info' : 'text-primary'));
        ?>
            <div class="pos-feed-row<?php echo $storno ? ' storno' : ''; ?>">
                <span class="t"><?php echo e(date('H:i', strtotime((string)$sale['created_at']))); ?></span>
                <span class="a <?php echo $storno ? '' : $pmClass; ?>"><?php echo formatMoney((float)$sale['total']); ?></span>
                <span class="d"><?php echo e($pmLabel); ?>
                    <small><?php echo e((string)$sale['sale_number']); ?><?php if (!empty($sale['seller_name'])): ?> · <?php echo e((string)$sale['seller_name']); ?><?php endif; ?></small>
                    <?php if ($storno): ?><span class="badge bg-danger bg-opacity-25 text-danger ms-1">storno</span><?php endif; ?>
                    <?php if (!empty($sale['order_id'])): ?><a class="text-info text-decoration-none ms-1 small" href="view_order.php?id=<?php echo (int)$sale['order_id']; ?>">zakázka</a><?php endif; ?>
                </span>
                <span class="x">
                    <?php if (!empty($sale['invoice_id']) && function_exists('crmCanUseInvoices') && crmCanUseInvoices()): ?>
                    <a class="btn btn-sm btn-outline-light py-0 px-2" target="_blank" title="Faktura"
                       href="print_invoice.php?id=<?php echo (int)$sale['invoice_id']; ?>"><i class="fas fa-file-invoice-dollar"></i></a>
                    <?php endif; ?>
                    <?php /* Vystavit smí vedení/manažer/účetní kdykoli, obsluha kasy dnešní
                             prodej své prodejny — server hlídá totéž (api/pos_invoice_after.php). */ ?>
                    <?php if (!$storno && empty($sale['invoice_id']) && (float)$sale['total'] > 0
                              && !in_array((string)$sale['payment_method'], ['invoice', 'invoice_ico'], true)
                              && (crmCanUseInvoices() || crmCanUsePos())): ?>
                    <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" title="Vystavit fakturu k tomuto prodeji"
                        onclick="afxInvoiceAfterSale(<?php echo (int)$sale['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$sale['sale_number'], JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode(formatMoney((float)$sale['total']), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode((string)$pmLabel, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)"><i class="fas fa-file-invoice-dollar"></i></button>
                    <?php endif; ?>
                    <?php if (!$storno): ?>
                    <button type="button" class="btn btn-sm btn-outline-light py-0 px-2" title="Dotisk účtenky"
                        onclick="window.posReprintReceipt ? window.posReprintReceipt(<?php echo (int)$sale['id']; ?>) : window.open('print_receipt.php?id=<?php echo (int)$sale['id']; ?>&format=58&auto=1', '_blank')"><i class="fas fa-receipt"></i></button>
                    <?php endif; ?>
                </span>
            </div>
        <?php else:
            $m = $f['row'];
            $isOut = $m['direction'] === 'out';
            $purposeLabels = ['vykup' => 'Výkup', 'vklad' => 'Vklad', 'vyber' => 'Výběr', 'zakazka' => 'Zakázka'];
            $pl = $purposeLabels[(string)$m['purpose']] ?? ucfirst((string)$m['purpose']);
        ?>
            <div class="pos-feed-row">
                <span class="t"><?php echo e(date('H:i', strtotime((string)$m['created_at']))); ?></span>
                <span class="a <?php echo $isOut ? 'text-warning' : 'text-success'; ?>"><?php echo ($isOut ? '−' : '+') . formatMoney((float)$m['amount']); ?></span>
                <span class="d"><?php echo e($pl); ?><?php if (!empty($m['ref_label'])): ?>
                    <?php if ($m['ref_type'] === 'document'): ?> <a class="text-info text-decoration-none" href="dokument.php?type=vykup&id=<?php echo (int)$m['ref_id']; ?>"><?php echo e((string)$m['ref_label']); ?></a>
                    <?php else: ?> <?php echo e((string)$m['ref_label']); ?><?php endif; ?>
                <?php endif; ?>
                    <small><?php if (!empty($m['note'])): ?><?php echo e((string)$m['note']); ?> · <?php endif; ?><?php echo e((string)($m['created_by'] ?? '')); ?></small>
                </span>
                <span class="x"></span>
            </div>
        <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div id="posTabBook"<?php echo $posDefaultTab === 'book' ? '' : ' hidden'; ?>>
<!-- ── Pokladní kniha (analytika 211): počáteční zůstatek → pohyby → konečný zůstatek ── -->
<div class="pos-card">
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
                        <?php if (!empty($r['sale_cancelled'])): ?><span class="badge bg-danger bg-opacity-25 text-danger me-1" title="Prodej byl stornovaný — vratka je zapsaná v den storna">stornováno</span><?php endif; ?>
                        <?php echo e((string)$r['title']); ?>
                        <?php if (trim((string)$r['detail']) !== ''): ?><span class="text-white-50" title="<?php echo e(trim((string)$r['detail'])); ?>"> · <?php echo e(mb_substr(trim((string)$r['detail']), 0, 90)); ?><?php echo mb_strlen(trim((string)$r['detail'])) > 90 ? '…' : ''; ?></span><?php endif; ?>
                        <?php if (trim((string)($r['storno_note'] ?? '')) !== ''): ?>
                            <div class="small text-danger mt-1" style="opacity:.85;"><?php echo e((string)$r['storno_note']); ?></div>
                        <?php endif; ?>
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
                        <?php /* Storno prodeje rovnou z knihy (dřív jen z Historie). Prodejka se
                                 nemaže — označí se jako stornovaná, zboží se vrátí na sklad a k dnešku
                                 vznikne výdajový pohyb + VPD, takže uzavřené dny knihy zůstávají beze
                                 změny (opravný záznam dle § 35 zák. č. 563/1991 Sb.). Smí jen vedení. */ ?>
                        <?php /* Stejné dvě branky jako server (api/pos_cancel.php): kasa + právo storna.
                                 Účetní knihu vidí, ale storno mu nepatří (crmCanUsePos() ho nepustí). */ ?>
                        <?php if ($r['source'] === 'pos_sale' && empty($r['sale_cancelled'])
                                  && crmCanUsePos() && crmCanCancelPosSale()): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Stornovat prodej — vrátí zboží na sklad a vydá hotovost zpět"
                                onclick="posSaleStorno(<?php echo (int)$r['ref_id']; ?>, <?php echo htmlspecialchars(json_encode((string)($r['sale_number'] ?? ''), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode(formatMoney((float)$r['amount']), JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>, <?php echo $r['dir'] === 'out' ? 'true' : 'false'; ?>)">Storno</button>
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
    </div><!-- /posTabBook -->
</div><!-- /pos-lower -->
</div><!-- /pos-col-lower -->
<?php if (crmCanUsePos()): ?></div><!-- /pos-grid --><?php endif; ?>
</div><!-- /pos-app -->

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
/**
 * Storno PRODEJE z pokladní knihy (api/pos_cancel.php).
 *
 * Na rozdíl od storna papírového dokladu tohle vrací i zboží na sklad a peníze
 * z kasy. Prodejka se nemaže — dostane příznak „stornováno" (kdo, kdy, proč)
 * a k dnešnímu dni vznikne výdajový pohyb + výdajový pokladní doklad, takže
 * už uzavřené dny knihy zůstávají beze změny (§ 35 zák. č. 563/1991 Sb.).
 */
function posSaleStorno(saleId, saleNumber, amountLabel, isPayout) {
    var co = isPayout
        ? 'Vrácená výplata se zapíše jako PŘÍJEM do pokladny (' + amountLabel + ') a výkupní list půjde vyplatit znovu.'
        : 'Z pokladny se vydá ' + amountLabel + ' zpět zákazníkovi a zboží se vrátí na sklad.';
    var reason = prompt(
        'STORNO PRODEJE ' + saleNumber + ' — ' + amountLabel + '\n\n'
        + co + '\n'
        + 'Vystaví se pokladní doklad k dnešnímu dni; původní prodejka zůstává v knize (nemaže se).\n\n'
        + 'Napiš důvod storna (povinné, uvede se na dokladu):'
    );
    if (reason === null) { return; }
    reason = reason.trim();
    if (reason.length < 3) { alert('Bez důvodu storno provést nejde — napiš aspoň pár slov.'); return; }

    var fd = new FormData();
    fd.append('id', saleId);
    fd.append('reason', reason);
    fd.append('csrf_token', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');
    fetch('api/pos_cancel.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.success) { alert(j.message || 'Storno se nepodařilo'); return; }
            // Hláška se skládá z toho, co se OPRAVDU stalo — u výplaty výkupu se
            // žádné zboží nevrací a chybějící díl se vrátit nedá.
            var t = ['Prodej ' + saleNumber + ' stornován.'];
            if (j.cash_returned) {
                t.push(j.cash_dir === 'out'
                    ? 'Vydej zákazníkovi ' + j.cash_returned + ' — zapsáno do pokladní knihy k dnešku.'
                    : 'Přijato zpět do pokladny: ' + j.cash_returned + '.');
            }
            if (j.storno_doc) { t.push('Pokladní doklad: ' + j.storno_doc); }
            if (j.returned_count > 0) { t.push('Na sklad vráceno kusů: ' + j.returned_count + '.'); }
            if (j.missing_parts && j.missing_parts.length) {
                t.push('POZOR — tyhle díly už ve skladu nejsou a nevrátily se: ' + j.missing_parts.join(', ') + '.');
            }
            if (j.invoice_cancelled) { t.push('Navázaná faktura byla zrušena.'); }
            if (j.eshop_back) { t.push('Objednávka z e-shopu ' + j.eshop_back + ' je zase rezervovaná.'); }
            alert(t.join('\n'));
            location.reload();
        })
        .catch(function () { alert('Chyba spojení — storno se nemuselo provést, zkontroluj knihu.'); });
}

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

/* Záložky dole: Dnešní pohyby / Pokladní kniha (scroll=true → posunout na ně, např. z menu Další) */
function posShowTab(tab, scroll) {
    document.querySelectorAll('.pos-tab').forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab); });
    var feed = document.getElementById('posTabFeed'), book = document.getElementById('posTabBook');
    if (feed) feed.hidden = tab !== 'feed';
    if (book) book.hidden = tab !== 'book';
    if (scroll) { var l = document.getElementById('posLower'); if (l) l.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
}
document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.pos-tab');
    if (b) { posShowTab(b.dataset.tab, false); }
});

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
                    <a href="logout.php?switch=1" class="lk-other">Přihlásit jiného zaměstnance →</a>
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

<!-- ── Odhlášení s převzatou pokladnou: uzavřít / jen odhlásit / zůstat (v3.95.3) ── -->
<style>#posLogoutModal .btn { white-space: normal !important; text-align: left !important; font-size: 15.5px !important; }
#posLogoutModal .btn span.pl-sub { font-size: 12.5px !important; font-weight: 400 !important; opacity: .85; margin-top: 2px; }
#posLogoutModal .btn span { font-size: 15.5px !important; }</style>
<div class="modal fade" id="posLogoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark text-white border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title"><i class="fas fa-cash-register me-2 text-info"></i>Máš převzatou pokladnu</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Končíš směnu, nebo se jen na chvíli odhlašuješ (např. kolega zadá na tomhle počítači zakázku)?</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-warning text-start py-2" id="posLogoutClose">
                        <span class="d-flex align-items-start gap-2"><i class="fas fa-lock mt-1"></i><span><span class="d-block fw-bold">Končím — uzavřít pokladnu</span><span class="d-block pl-sub">spočítat hotovost, zapsat stav a odhlásit</span></span></span>
                    </button>
                    <a class="btn btn-outline-info text-start py-2" href="logout.php?switch=1">
                        <span class="d-flex align-items-start gap-2"><i class="fas fa-user-group mt-1"></i><span><span class="d-block fw-bold">Jen se odhlásit</span><span class="d-block pl-sub">pokladna zůstane převzatá na tebe — nikdo jiný ji obsluhovat nemůže</span></span></span>
                    </a>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zůstat přihlášený</button>
                </div>
            </div>
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
        <a href="logout.php?switch=1" class="lk-other">Přihlásit jiného zaměstnance →</a>
    </div>
</div>

<?php if (crmCanUsePos()): ?>
<script>
(function () {
    var cart = [];          // {type, id, name, code, price, qty, stock}
    var payment = '';
    var lastSale = null;
    // druhá fakturační identita (OSVČ) — dokud není v Účetnictví vyplněná a zapnutá,
    // „Faktura IČO" prodej nepustí (server to hlídá taky)
    var ICO_SUPPLIER_READY = <?php echo afxIcoSupplier()['ready'] ? 'true' : 'false'; ?>;
    var SRO_ACC_MISSING = <?php echo trim((string)get_setting('acc_bank_account', '')) === '' ? 'true' : 'false'; ?>;
    var ICO_ACC_MISSING = <?php echo afxIcoSupplier()['bank_account'] === '' ? 'true' : 'false'; ?>;
    function updateAccWarn() {
        var w = document.getElementById('posAccWarn');
        if (!w) return;
        var show = (payment === 'invoice' && SRO_ACC_MISSING) || (payment === 'invoice_ico' && ICO_ACC_MISSING);
        w.style.display = show ? '' : 'none';
        if (show) document.getElementById('posAccWarnWho').textContent = payment === 'invoice_ico' ? 'OSVČ (Faktura IČO)' : 'firmy (s.r.o.)';
    }

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
    // výdaje z kasy se NIKDY neslučují (každý má vlastní uid) — qty musí zůstat 1
    function cartKey(c) {
        if (c.type === 'manual') { return manualKey(c.name, c.price); }
        if (c.type === 'expense') { return 'expense:' + c.uid; }
        return c.type + ':' + c.id;
    }
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
                    var typeLabel = { part: 'DÍL', product: 'PRODUKT', order: 'ZAKÁZKA', vykup: 'VÝKUP', eshop: 'E-SHOP' }[r.type] || 'POLOŽKA';
                    var detail = r.type === 'order'
                        ? (r.order_mode === 'predem' ? 'PLATBA PŘEDEM — podle předpokládané ceny'
                            : (r.order_mode === 'doplatek' ? 'DOPLATEK — předem zaplaceno ' + fmt(r.paid_before || 0) : 'připraveno k úhradě'))
                        : (r.type === 'vykup' ? 'nevyplacený výkup — VYPLÁCÍME MY'
                        : (r.type === 'eshop' ? ((r.eshop && r.eshop.reason) || 'rezervace čeká na zaplacení') : ('skladem ' + r.stock + ' ks')));
                    el.className = 'pos-hit';
                    el.innerHTML = '<span class="pos-type ' + r.type + '">' + typeLabel + '</span>'
                        + '<div><div class="nm">' + esc(r.name) + '</div><div class="cd">' + esc(r.code || '') + ' · ' + detail + '</div></div>'
                        + '<span class="pr">' + fmt(r.type === 'eshop' && r.eshop ? r.eshop.total : r.price) + '</span>'
                        + '<span class="add"><i class="fas fa-' + (r.type === 'eshop' ? 'arrow-right' : 'plus') + '"></i></span>';
                    el.addEventListener('click', function () {
                        if (r.type === 'eshop') { if (r.eshop) { window.__afxEshopLoad(r.eshop); } return; }
                        addToCart(r);
                    });
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
        <?php
        // ?order_id= (tlačítko „Zaplatit předem" hned po založení zakázky) → číslo zakázky
        $__preOrderCode = '';
        if (!empty($_GET['order_id'])) {
            try {
                $__po = $pdo->prepare("SELECT id, order_code, legacy_code FROM orders WHERE id = ?");
                $__po->execute([(int)$_GET['order_id']]);
                if ($__por = $__po->fetch(PDO::FETCH_ASSOC)) { $__preOrderCode = orderDisplayCode($__por); }
            } catch (Throwable $e) {}
        }
        ?>
        var preQ = new URLSearchParams(window.location.search).get('q') || <?php echo json_encode($__preOrderCode, JSON_UNESCAPED_UNICODE); ?>;
        if (preQ) { $search.value = preQ; doSearch(); $search.focus(); }
    })();

    // ── košík ──
    function addToCart(r) {
        // rezervace e-shopu není položka košíku — natáhne se celá objednávka
        if (r && r.type === 'eshop') { if (r.eshop) { eshopLoad(r.eshop); } return; }
        // k vyzvedávané objednávce z e-shopu se nic nepřidává — účtenka i vazba
        // na objednávku musí zůstat jednoznačné (server to stejně odmítne)
        if (eshopOrderId > 0) {
            alert('Probíhá vyzvednutí objednávky z e-shopu — ostatní zboží prodej zvlášť (nebo objednávku odpoj).');
            return;
        }
        // výdaj z kasy se nemíchá s prodejem (server to stejně odmítne) — netto
        // doklad by zákazníkovi „slevil" o interní zálohu
        if (cart.some(function (c) { return c.type === 'expense'; })) {
            alert('V košíku je výdaj z kasy — nejdřív ho dokonči (nebo smaž), pak markuj zboží.');
            return;
        }
        var stock = r.type === 'manual' ? 999 : (parseInt(r.stock, 10) || 0);
        var addQty = parseInt(r.qty, 10) || 1;
        if (addQty < 1) addQty = 1;
        var found = cart.find(function (c) { return cartKey(c) === cartKey(r); });
        if (found) {
            if (found.type === 'vykup') { alert('Tenhle výkup už v košíku je — vyplácí se jen jednou.'); return; }
            if (found.type === 'order') { alert('Tahle zakázka už v košíku je. Další zakázky klienta přidáš vyhledáním (číslo zakázky nebo příjmení klienta).'); return; }
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
    // ── rezervace z e-shopu: vyzvednutí a zaplacení objednávky ──
    // Objednávka s platbou při vyzvednutí není prodej — do kasy se natáhne celá
    // (všechny kusy) a teprve dokončením prodeje se označí za vyzvednutou.
    var eshopOrderId = 0;
    var $eshopBtn = document.getElementById('posEshopBtn');
    var $eshopPanel = document.getElementById('posEshopPanel');
    var $eshopBar = document.getElementById('posEshopBar');
    function eshopBar(h) {
        eshopOrderId = h ? h.id : 0;
        $eshopBar.style.display = h ? 'flex' : 'none';
        if (h) {
            document.getElementById('posEshopRef').textContent = h.order_ref;
            document.getElementById('posEshopWho').textContent = (h.customer ? '· ' + h.customer : '')
                + ' · objednáno za ' + fmt(h.total);
        }
        updateFinish();
    }
    function eshopLoad(h) {
        if (h.broken) { alert(h.broken); return; }
        if (cart.length && !cart.every(function (c) { return c.type === 'product'; })) {
            alert('Nejdřív dokonči nebo vysyp košík — vyzvednutí objednávky se markuje samostatně.');
            return;
        }
        // rozmarkované zboží se nesmí ztratit potichu
        if (cart.length && !confirm('V košíku máš ' + cart.length + ' rozmarkovaných položek. Načtením objednávky ' + h.order_ref + ' se košík vysype. Pokračovat?')) { return; }
        // změna ceníku mezi objednáním a vyzvednutím: účtuje se OBJEDNANÁ cena,
        // ale obsluha to musí vidět (zákazník má potvrzení s jinou částkou)
        if (h.total_diff && Math.abs(h.total_diff) > 0.5) {
            alert('Pozor: součet položek (' + fmt(h.items_total) + ') nesedí s objednávkou (' + fmt(h.total) + ').'
                + '\nÚčtují se ceny, za které zákazník objednal — rozdíl ' + fmt(Math.abs(h.total_diff)) + ' prověř před dokončením.');
        }
        cart = [];
        h.items.forEach(function (it) {
            cart.push({ type: 'product', id: it.product_id, name: it.name, code: it.code,
                price: it.price, qty: it.qty, stock: Math.max(it.stock, it.qty) });
        });
        eshopBar(h);
        render();
        if ($eshopPanel) { $eshopPanel.style.display = 'none'; }
    }
    window.__afxEshopLoad = eshopLoad;
    if (document.getElementById('posEshopDrop')) {
        document.getElementById('posEshopDrop').addEventListener('click', function () {
            eshopBar(null); cart = []; render();
        });
    }
    if ($eshopBtn) {
        $eshopBtn.addEventListener('click', function () {
            if ($eshopPanel.style.display !== 'none') { $eshopPanel.style.display = 'none'; return; }
            $eshopPanel.style.display = '';
            $eshopPanel.innerHTML = '<div class="p-2 small text-white-50">Načítám rezervace…</div>';
            fetch('api/pos_eshop_list.php', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var rows = d.results || [];
                    if (!rows.length) {
                        $eshopPanel.innerHTML = '<div class="p-2 small text-white-50">Žádná čekající objednávka z e-shopu s platbou při vyzvednutí.</div>';
                        return;
                    }
                    $eshopPanel.innerHTML = '';
                    rows.forEach(function (h) {
                        var kusy = h.items.reduce(function (n, i) { return n + i.qty; }, 0);
                        var el = document.createElement('div');
                        el.className = 'pos-hit';
                        el.innerHTML = '<span class="pos-type eshop">E-SHOP</span>'
                            + '<div><div class="nm">' + esc(h.order_ref + ' — ' + h.customer) + '</div>'
                            + '<div class="cd">' + esc(kusy + ' ks · ' + (h.reason || '') + ' · ' + h.created
                                + (h.branch ? ' · ' + h.branch : '') + (h.phone ? ' · ' + h.phone : '')) + '</div></div>'
                            + '<span class="pr">' + fmt(h.total) + '</span>';
                        el.addEventListener('click', function () { eshopLoad(h); });
                        $eshopPanel.appendChild(el);
                    });
                })
                .catch(function () { $eshopPanel.innerHTML = '<div class="p-2 small text-danger">Načtení rezervací se nepovedlo.</div>'; });
        });
    }

    // ── výdaj z kasy: volná ZÁPORNÁ položka (záloha zaměstnanci, drobný nákup…) ──
    var $expenseBtn = document.getElementById('posExpenseBtn');
    var $expenseForm = document.getElementById('posExpenseForm');
    var $expenseName = document.getElementById('posExpenseName');
    var $expensePrice = document.getElementById('posExpensePrice');
    var expenseUid = 0;
    if ($expenseBtn) {
        $expenseBtn.addEventListener('click', function () {
            var show = $expenseForm.style.display === 'none';
            $expenseForm.style.display = show ? '' : 'none';
            if (show) { setTimeout(function () { $expenseName.focus(); }, 40); }
        });
        $expenseForm.addEventListener('submit', function (e) {
            e.preventDefault();
            // výdaj = samostatný doklad; s rozmarkovaným zbožím v košíku se nemíchá
            if (cart.some(function (c) { return c.type !== 'expense'; })) {
                alert('Výdaj z kasy markuj samostatně — nejdřív dokonči (nebo vysyp) rozmarkovaný prodej v košíku.');
                return;
            }
            var name = $expenseName.value.trim().replace(/\s+/g, ' ');
            var amount = Math.abs(parseCzk($expensePrice.value));   // obsluha smí napsat 500 i −500
            if (name === '') { alert('Napiš účel výdaje — např. „záloha Khalil" nebo „nákup drogerie".'); $expenseName.focus(); return; }
            if (isNaN(amount) || amount < 1 || amount > 1000000) { alert('Zadej částku výdaje (1 až 1 000 000 Kč).'); $expensePrice.focus(); return; }
            cart.push({ type: 'expense', id: 0, uid: ++expenseUid, name: name, code: 'Výdaj z kasy',
                price: -Math.round(amount * 100) / 100, qty: 1, stock: 1 });
            render();
            $expenseName.value = '';
            $expensePrice.value = '';
            $expenseForm.style.display = 'none';
            // výdaj jde jen hotově — rovnou předvybrat (součet je vždy záporný,
            // takže se LCD displej hotovosti neotvírá)
            if (payment !== 'cash') {
                var cashBtn = document.querySelector('.pos-pay[data-pay="cash"]');
                if (cashBtn) { cashBtn.click(); }
            }
        });
    }
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
        if (cart[i].type === 'vykup' || cart[i].type === 'expense') {
            // výplatní řádek je vždy ZÁPORNÝ — obsluha smí napsat 3000 i −3000
            v = -Math.min(1000000, Math.abs(v));
            // výdaj za 0 Kč není výdaj — server by ho stejně odmítl, řekni to hned
            if (cart[i].type === 'expense' && v > -1) {
                alert('Výdaj musí být aspoň 1 Kč.');
                render();
                return;
            }
        } else if (v < 0) { v = 0; }
        cart[i].price = v; render();
    };

    function total() { return cart.reduce(function (s, c) { return s + c.price * c.qty; }, 0); }

    function render() {
        $body.innerHTML = '';
        cart.forEach(function (c, i) {
            var row = document.createElement('div');
            row.className = 'pos-line';
            var lockedOrder = c.type === 'order';
            var lockedVykup = c.type === 'vykup';
            var lockedExpense = c.type === 'expense';
            var qtyLocked = lockedOrder || lockedVykup || lockedExpense;
            var qtyAttrs = lockedOrder ? ' readonly title="Zakázka se účtuje vždy jako 1 ks"'
                : (lockedVykup ? ' readonly title="Výkup se vyplácí vždy jako 1 ks"'
                : (lockedExpense ? ' readonly title="Výdaj z kasy je vždy jedna položka"' : ''));
            var priceAttrs = lockedOrder ? ' readonly title="Cena zakázky se bere z detailu zakázky"'
                : (eshopOrderId > 0 ? ' readonly title="Cena z objednávky e-shopu — zákazník platí, za co objednal"' : '');
            var maxQty = c.type === 'manual' ? 999 : c.stock;
            var lineTotal = c.price * c.qty;
            row.innerHTML = '<div><div class="pos-item-name">' + esc(c.name) + '</div><div class="pos-item-code">' + esc(c.code || '') + '</div></div>'
                + '<button type="button" class="pos-remove" onclick="posRemove(' + i + ')" title="Odebrat z košíku" aria-label="Odebrat"><i class="fas fa-xmark"></i></button>'
                + '<div class="pl-ctrl">'
                +   '<div class="pl-qty">'
                +     '<button type="button" onclick="posQty(' + i + ', ' + (c.qty - 1) + ')" aria-label="Ubrat kus"' + (qtyLocked || c.qty <= 1 ? ' disabled' : '') + '><i class="fas fa-minus"></i></button>'
                +     '<input type="number" class="pos-qty" min="1" max="' + maxQty + '" value="' + c.qty + '" inputmode="numeric" onchange="posQty(' + i + ', this.value)"' + qtyAttrs + '>'
                +     '<button type="button" onclick="posQty(' + i + ', ' + (c.qty + 1) + ')" aria-label="Přidat kus"' + (qtyLocked || c.qty >= maxQty ? ' disabled' : '') + '><i class="fas fa-plus"></i></button>'
                +   '</div>'
                +   '<label class="pl-price">× <input type="text" class="form-control pos-price" inputmode="decimal" value="' + c.price + '" onchange="posPrice(' + i + ', this.value)"' + priceAttrs + ' aria-label="Cena za kus"> Kč</label>'
                +   '<span class="pos-line-total' + (lineTotal < 0 ? ' neg' : '') + '">' + fmt(lineTotal) + '</span>'
                + '</div>';
            $body.appendChild(row);
        });
        $empty.style.display = cart.length ? 'none' : '';
        var t = total();
        $total.textContent = fmt(t);
        $total.classList.toggle('neg', t < 0);
        var pieces = cart.reduce(function (n, c) { return n + c.qty; }, 0);
        document.getElementById('posCartCount').textContent = pieces + ' ks';
        document.getElementById('posCartClear').style.display = cart.length ? '' : 'none';
        // mobil: plovoucí lišta s částkou (jen když je co platit)
        var mbar = document.getElementById('posMbar');
        if (mbar) {
            document.getElementById('posMbarTotal').textContent = fmt(t);
            document.getElementById('posMbarCount').textContent = cart.length;
            mbar.classList.toggle('show', cart.length > 0 && !payStepInView);
            document.getElementById('posApp').classList.toggle('has-mbar', cart.length > 0);
        }
        updateFinish();
        afxCdMirror();
    }
    // lišta „K platbě" zmizí, jakmile je platba na obrazovce (jinak by zakrývala tlačítko Dokončit)
    var payStepInView = false;
    if ('IntersectionObserver' in window && document.getElementById('posPayStep')) {
        new IntersectionObserver(function (es) {
            payStepInView = es[0].isIntersecting;
            var mb = document.getElementById('posMbar');
            if (mb) { mb.classList.toggle('show', cart.length > 0 && !payStepInView); }
        }, { threshold: 0.15 }).observe(document.getElementById('posPayStep'));
    }
    // Vysypat košík (i odpojit vyzvedávanou objednávku e-shopu)
    document.getElementById('posCartClear').addEventListener('click', function () {
        if (!cart.length) return;
        if (!confirm('Vysypat celý košík (' + cart.length + ' pol.)?')) return;
        cart = [];
        if (eshopOrderId > 0) { eshopBar(null); }
        render();
    });

    /* ── Zákaznický displej: zrcadlení košíku na druhý monitor ──
       Debounce 400 ms (render běží při každé změně množství/ceny). Prázdný
       košík vrací displej na reklamu; po účtence se 12 s nic neposílá, ať
       klientovi nezmizí „zaplaceno" (displej se pak na reklamu vrátí sám). */
    var cdTimer = null, cdMirrored = false, cdQuietUntil = 0;
    function afxCdSend() {
        // Během „ticha" po účtence se push jen ODLOŽÍ (ne zahodí) — jinak by
        // rychle načatý další prodej na displeji vůbec nebyl vidět.
        var quiet = cdQuietUntil - Date.now();
        if (quiet > 0) { cdTimer = setTimeout(afxCdSend, quiet + 100); return; }
        if (!cart.length) {
            if (cdMirrored) { cdMirrored = false; window.afxDisplayPush('idle'); }
            return;
        }
        // interní výdaje z kasy (záloha zaměstnanci…) zákazníkovi na monitor nepatří
        var visible = cart.filter(function (c) { return c.type !== 'expense'; });
        if (!visible.length) {
            if (cdMirrored) { cdMirrored = false; window.afxDisplayPush('idle'); }
            return;
        }
        cdMirrored = true;
        window.afxDisplayPush('cart', {
            items: visible.map(function (c) {
                return { name: c.name, qty: c.qty, line_total: c.price * c.qty };
            }),
            total: visible.reduce(function (s, c) { return s + c.price * c.qty; }, 0)
        });
    }
    function afxCdMirror() {
        if (!window.afxDisplayPush) return;
        clearTimeout(cdTimer);
        cdTimer = setTimeout(afxCdSend, 400);
    }
    function afxCdReceipt(d) {
        if (!window.afxDisplayPush) return;
        clearTimeout(cdTimer);          // zrušit odložený push košíku/idle
        cdMirrored = false;
        cdQuietUntil = Date.now() + 12000;
        window.afxDisplayPush('receipt', {
            total: (d && typeof d.total === 'number') ? d.total : totalBeforeClear,
            change: (d && d.cash_change > 0) ? d.cash_change : 0
        });
    }
    var totalBeforeClear = 0;

    // ── platba ──
    document.querySelectorAll('.pos-pay').forEach(function (btn) {
        btn.addEventListener('click', function () {
            payment = btn.dataset.pay;
            document.querySelectorAll('.pos-pay').forEach(function (b) { b.className = 'pos-pay'; });
            btn.classList.add('sel-' + payment);
            var isInvPay = payment === 'invoice' || payment === 'invoice_ico';
            $custWrap.style.display = isInvPay ? '' : 'none';
            // jiná platba než faktura → odemknout výběr klienta (jinak zůstane
            // zamčený od ručního odběratele a obsluha nechápe proč)
            if (!isInvPay && typeof adhocReset === 'function') { adhocReset(); }
            updateAccWarn();
            // „Faktura IČO" jde použít až po vyplnění a zapnutí identity OSVČ v Účetnictví
            if (payment === 'invoice_ico' && !ICO_SUPPLIER_READY) {
                alert('Faktura IČO zatím není aktivní — vyplň a zapni identitu OSVČ v Účetnictví → Nastavení (blok „Druhá fakturační identita").');
            }
            // výdaj z kasy v košíku = jen hotově; tiché zašedlé tlačítko Dokončit je past
            if (payment !== 'cash' && cart.some(function (c) { return c.type === 'expense'; })) {
                alert('V košíku je výdaj z kasy — ten jde jen hotově.');
            }
            if (eshopOrderId > 0 && isInvPay) {
                alert('Vyzvednutou objednávku z e-shopu zaplať hotově nebo kartou — zboží si zákazník odnáší hned.');
            }
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
        if (ev.key === 'Escape') { $cashReceived.value = ''; lcdHide(); }   // zrušeno = zahodit rozepsanou hotovost
    });
    document.getElementById('lcdClose').addEventListener('click', function () { $cashReceived.value = ''; lcdHide(); });
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

    // ── jednorázový odběratel (faktura bez klienta v CRM) ──
    var $adhocOn = document.getElementById('posAdhocOn');
    var $adhocWrap = document.getElementById('posAdhocWrap');
    var $adhocName = document.getElementById('posAdhocName');
    function adhocActive() { return $adhocOn.checked; }
    function adhocData() {
        if (!adhocActive()) return null;
        var n = $adhocName.value.trim();
        if (n === '') return null;
        return {
            name: n,
            address: document.getElementById('posAdhocAddr').value.trim(),
            ico: document.getElementById('posAdhocIco').value.trim(),
            dic: document.getElementById('posAdhocDic').value.trim(),
            email: document.getElementById('posAdhocEmail').value.trim()
        };
    }
    function adhocReset() {
        $adhocOn.checked = false;
        $adhocWrap.style.display = 'none';
        ['posAdhocName', 'posAdhocAddr', 'posAdhocIco', 'posAdhocDic', 'posAdhocEmail']
            .forEach(function (id) { document.getElementById(id).value = ''; });
        $('#posCustomer').prop('disabled', false).trigger('change.select2');
    }
    $adhocOn.addEventListener('change', function () {
        $adhocWrap.style.display = this.checked ? '' : 'none';
        // vybraný klient a ruční odběratel se vylučují — ať je jasné, komu faktura půjde
        if (this.checked) { $('#posCustomer').val(null).trigger('change'); }
        $('#posCustomer').prop('disabled', this.checked).trigger('change.select2');
        if (this.checked) { setTimeout(function () { $adhocName.focus(); }, 40); }
        updateFinish();
    });
    ['posAdhocName', 'posAdhocEmail'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', updateFinish);
    });
    // ARES: doplnění názvu, adresy a DIČ podle IČO (stejný veřejný rejstřík jako u klientů)
    document.getElementById('posAdhocAres').addEventListener('click', function () {
        var ico = document.getElementById('posAdhocIco').value.replace(/\D/g, '');
        if (ico.length !== 8) { alert('IČO má mít 8 číslic.'); return; }
        var btn = this; btn.disabled = true; btn.textContent = '…';
        var ctl = new AbortController();
        var tmr = setTimeout(function () { ctl.abort(); }, 8000);   // bez timeoutu tlačítko zůstalo navždy zamčené
        fetch('https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' + ico, { signal: ctl.signal })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('ares')); })
            .catch(function () { return null; })
            .then(function (d) {
                clearTimeout(tmr);
                btn.disabled = false; btn.textContent = 'ARES';
                if (!d) { alert('ARES subjekt nenašel nebo je nedostupný — vyplň údaje ručně.'); return; }
                if (d.obchodniJmeno) { $adhocName.value = d.obchodniJmeno; }
                if (d.dic) { document.getElementById('posAdhocDic').value = d.dic; }
                var s = d.sidlo || {};
                var addr = s.textovaAdresa || [
                    [s.nazevUlice || s.nazevObce, s.cisloDomovni].filter(Boolean).join(' '),
                    [s.psc, s.nazevObce].filter(Boolean).join(' ')
                ].filter(Boolean).join(', ');
                if (addr) { document.getElementById('posAdhocAddr').value = addr; }
                updateFinish();
            });
    });

    function updateFinish() {
        var t = total();
        var ok = cart.length > 0 && payment !== '';
        // u zakázky v košíku zákazník nutný není — odběratelem faktury se
        // automaticky stává klient zakázky (pos_checkout si ho dotáhne sám)
        var isInvPay = payment === 'invoice' || payment === 'invoice_ico';
        var hasOrder = cart.some(function (c) { return c.type === 'order'; });
        if (isInvPay && !$('#posCustomer').val() && !adhocData() && !hasOrder) ok = false;
        if (payment === 'invoice_ico' && !ICO_SUPPLIER_READY) ok = false;
        if (t < 0 && payment !== '' && payment !== 'cash') ok = false;   // výplata zákazníkovi jde jen hotově
        // výdaj z kasy = peníze ze zásuvky → jen hotově, i když je součet kladný
        var hasExpense = cart.some(function (c) { return c.type === 'expense'; });
        if (hasExpense && payment !== '' && payment !== 'cash') ok = false;
        // vyzvednutí objednávky: zboží odchází hned → zaplatit na místě
        if (eshopOrderId > 0 && isInvPay) ok = false;
        $finish.disabled = !ok;
        $finish.innerHTML = t < 0
            ? '<i class="fas fa-hand-holding-dollar me-2"></i>Vyplatit ' + fmt(Math.abs(t))
            : '<i class="fas fa-check me-2"></i>Dokončit prodej' + (cart.length ? ' · ' + fmt(t) : '');
        // nápověda pod tlačítkem: proč ještě nejde dokončit (místo tichého zašednutí)
        var hint = '';
        if (!cart.length) hint = 'Přidej položku do košíku.';
        else if (payment === '') hint = 'Vyber způsob platby.';
        else if (isInvPay && !$('#posCustomer').val() && !adhocData() && !hasOrder) hint = 'Vyber odběratele faktury (nebo ho vyplň ručně).';
        else if (payment === 'invoice_ico' && !ICO_SUPPLIER_READY) hint = 'Faktura IČO není aktivní — zvol jinou platbu.';
        else if ((t < 0 || hasExpense) && payment !== 'cash') hint = 'Výplata a výdaj z kasy jdou jen hotově.';
        else if (eshopOrderId > 0 && isInvPay) hint = 'Objednávku z e-shopu zaplať hotově nebo kartou.';
        else if (payment === 'cash' && t > 0) hint = 'Po kliknutí zadáš, kolik zákazník dal — kasa spočítá, kolik vrátit.';
        var $hint = document.getElementById('posFinishHint');
        if ($hint) { $hint.textContent = hint; }
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
    // Záloha přes TISKOVOU FRONTU: appka z TestFlightu (WKWebView) i Safari blokují
    // z HTTPS požadavky na http://127.0.0.1 — úloha se proto uloží na server a
    // poller na pokladním Macu ji do ~2 s stáhne a vytiskne. Chrome jde dál napřímo.
    function enqueuePrint(payload, onFail, onOk) {
        fetch('api/print_receipt_server.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>', enqueue: 1 }, payload))
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok && d.queued) {
                posToast(true, '🖨️ Účtenka odeslána na pokladní tiskárnu (do 2 s).');
                if (onOk) { onOk(); }
                return;
            }
            onFail((d.error || 'Frontu tisku se nepodařilo naplnit.') + ' Tiskne jen počítač s tiskárnou v USB a spuštěným agentem.');
        })
        .catch(function () { onFail('Síťová chyba při zařazení tisku do fronty.'); });
    }
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
                    if (!r2.ok) { enqueuePrint(payload, onFail, onOk); }
                    else if (onOk) { onOk(); }
                })
                .catch(function () {
                    // WKWebView/Safari sem spadnou VŽDY (blokují localhost z HTTPS) —
                    // plynule se přejde na frontu, obsluha nic řešit nemusí
                    enqueuePrint(payload, onFail, onOk);
                });
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
                eshop_order_id: eshopOrderId,
                customer_id: parseInt($('#posCustomer').val() || 0, 10),
                buyer: adhocData(),   // jednorázový odběratel faktury (null = žádný)
                cash_received: (payment === 'cash' && total() > 0) ? (cashVal() === null ? null : cashVal()) : null,
                items: cart.map(function (c) {
                    var freeText = c.type === 'manual' || c.type === 'expense';   // volný název, bez vazby na sklad
                    var item = { type: c.type, id: freeText ? 0 : c.id, qty: c.qty, price: c.price };
                    if (freeText) { item.name = c.name; }
                    return item;
                })
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            $finish.innerHTML = '<i class="fas fa-check me-2"></i>Dokončit prodej';
            if (!d.success) { alert(d.message || 'Prodej se nepodařil.'); updateFinish(); return; }   // adhoc necháváme vyplněný — obsluha to jen opraví
            lastSale = d;
            totalBeforeClear = total();   // pro zákaznický displej (než se košík smaže)
            // čistý výdaj z kasy: zákazník žádný není → „zaplaceno" na monitor nepatří
            var cdSkipReceipt = cart.length > 0 && cart.every(function (c) { return c.type === 'expense'; });
            document.getElementById('posDoneNumber').textContent = d.sale_number;
            document.getElementById('posDoneProductNote').style.display = d.has_product ? '' : 'none';
            document.getElementById('posDoneInvoice').style.display = d.invoice_id ? '' : 'none';
            document.getElementById('posDoneInvoiceMail').style.display = d.invoice_id ? '' : 'none';
            // faktura bez e-mailu: rovnou říct, že se tiskne (jinak obsluha klikne na mail a narazí)
            document.getElementById('posDoneInvoiceNote').style.display = (d.invoice_id && !d.invoice_mail) ? '' : 'none';
            // velké „Vrátit zákazníkovi" — obsluha to potřebuje vidět hned, ne až na účtence
            var chg = (d.cash_change !== null && d.cash_change !== undefined && d.cash_change > 0);
            document.getElementById('posDoneChange').style.display = chg ? '' : 'none';
            if (chg) document.getElementById('posDoneChangeVal').textContent = fmt(d.cash_change);
            document.getElementById('posDone').style.display = '';
            cart = []; payment = '';
            if (eshopOrderId > 0) { eshopBar(null); }   // objednávka je vyzvednutá a zaplacená
            document.querySelectorAll('.pos-pay').forEach(function (b) { b.className = 'pos-pay'; });
            $custWrap.style.display = 'none';
            $cashReceived.value = '';
            $('#posCustomer').val(null).trigger('change');
            adhocReset();
            render();
            // zákaznický displej: „zaplaceno" + vráceno (sám se vrátí na reklamu);
            // u čistého výdaje z kasy se displej nechává na reklamě
            if (!cdSkipReceipt) { afxCdReceipt(d); }
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
    // Odhlášení s převzatou pokladnou: uzávěrka NENÍ povinná — na sdíleném Macu na recepci
    // se často jen přepíná zaměstnanec (zadá novou zakázku). Pokladna zůstane převzatá
    // a obsluhovat ji smí dál jen držitel směny (v3.95.3).
    if (shiftMine) {
        document.querySelectorAll('a[href$="logout.php"]').forEach(function (a) {
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                var m = document.getElementById('posLogoutModal');
                m.dataset.href = a.href;
                bootstrap.Modal.getOrCreateInstance(m).show();
            });
        });
        document.getElementById('posLogoutClose').addEventListener('click', function () {
            var m = document.getElementById('posLogoutModal');
            bootstrap.Modal.getOrCreateInstance(m).hide();
            setTimeout(function () { shiftCloseOpenModal(m.dataset.href || 'logout.php'); }, 250);
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
                    // Rezervovaný kus vrací vyhledávání jako REZERVACI (běžný produktový
                    // hit je skrytý, dokud objednávka drží dostupnost) — sken sériovky
                    // proto rovnou natáhne celou objednávku k vyzvednutí.
                    if (hit.type === 'eshop') {
                        if (hit.eshop) { window.__afxEshopLoad(hit.eshop); beep(true); posToast(true, 'Rezervace ' + hit.eshop.order_ref + ' načtena'); }
                        else { beep(false); posToast(false, 'Rezervaci se nepodařilo načíst — otevři ji tlačítkem „Rezervace e-shopu".'); }
                        return;
                    }
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

    // Sken z fyzické čtečky v Android appce (Zebra TC27 → DataWedge). Appka nejdřív
    // zkusí window.afxHandleScan(kód): stránka, která sken umí zpracovat sama,
    // vrátí true. Platí stejné pojistky jako u čtečky „na klávesnici" níže.
    window.afxHandleScan = function (code) {
        if (locked || document.getElementById('posShiftGate')) { return false; }
        code = String(code || '').trim();
        if (code.length < 2) { return false; }
        handleScan(code);
        return true;
    };

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

    // ── dlaždice „Další možnosti": vždy otevřený jen jeden panel, aktivní dlaždice zvýrazněná ──
    var tiles = Array.prototype.slice.call(document.querySelectorAll('.pos-tile[data-panel]'));
    function tilePanel(t) { return document.getElementById(t.dataset.panel); }
    function syncTiles() {
        tiles.forEach(function (t) { var p = tilePanel(t); t.classList.toggle('active', !!p && p.style.display !== 'none'); });
    }
    tiles.forEach(function (t) {
        // capture: zavřít ostatní panely dřív, než vlastní handler dlaždice přepne ten svůj
        t.addEventListener('click', function () {
            tiles.forEach(function (o) { if (o !== t) { var p = tilePanel(o); if (p) p.style.display = 'none'; } });
        }, true);
        var p = tilePanel(t);
        if (p && window.MutationObserver) { new MutationObserver(syncTiles).observe(p, { attributes: true, attributeFilter: ['style'] }); }
    });
    // ruční položka nemá vlastní handler — přepíná se tady
    document.getElementById('posManualTile').addEventListener('click', function () {
        var show = $manualForm.style.display === 'none';
        $manualForm.style.display = show ? '' : 'none';
        if (show) { setTimeout(function () { $manualName.focus(); }, 40); }
    });

    // na PC rovnou do vyhledávání (čtečka funguje i bez toho); na dotykovém zařízení ne — vyskočila by klávesnice
    if (!window.matchMedia('(pointer: coarse)').matches && !$search.value && !document.getElementById('posShiftGate')) {
        setTimeout(function () { $search.focus({ preventScroll: true }); }, 120);
    }

    render();
})();
</script>
<?php endif; ?>

<?php /* okno „Vystavit fakturu k prodeji" (v3.71.0) — sdílené s druhou stránkou */ ?>
<?php require_once 'includes/modals/invoice_after_sale_modal.php'; ?>

<?php require_once 'includes/footer.php'; ?>
