<?php
/**
 * PODKLADY PRO ÚČETNÍ — rozcestník sestav za období + samotné tiskové sestavy.
 *
 * Bez parametru ?sestava= se zobrazí rozcestník v CRM (výběr období a provozovny
 * + dlaždice sestav). S parametrem ?sestava=<klíč> se vrátí SAMOSTATNÁ tisková
 * stránka bez CRM shellu, kterou si účetní vytiskne do PDF — stejný postup jako
 * u print_invoice.php / print_receipt.php (žádná PDF knihovna).
 *
 * Přístup: účetní (crmCanAccountingRead, vzniká v jiném balíku) nebo vedení
 * (crmCanManageInvoices) — viz afxUcetniCanRead().
 *
 * Stránka je pouze ČTECÍ: nic nezapisuje ani nemění schéma. Data se berou z
 * invoices, invoice_items, invoice_payments, pos_sales, pos_sale_items,
 * pos_cash_movements a bank_transactions; když některá tabulka chybí, sestava
 * zůstane prázdná s vysvětlením místo fatální chyby.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once __DIR__ . '/includes/ucetni_reports.php';

$defs = afxUcetniReportDefs();
$sestava = trim((string)($_GET['sestava'] ?? ''));
$period = afxUcetniResolvePeriod($_GET);

// ÚČETNÍ VIDÍ OBĚ PROVOZOVNY: účetnictví se vede za firmu, ne za pobočku.
// afxUcetniResolveBranch() by ji ale přes afxUcetniForcedBranchId() zamkla na
// branch_id z karty zaměstnance (sloupec je povinný, pro účetní jen formální)
// a sestavy by TIŠE vynechaly druhou pobočku — Kniha faktur bez poloviny faktur
// je horší než žádná. Proto tady pro roli účetní pobočku řešíme sami: výchozí
// je „všechny provozovny" a výběr z URL se jen validuje proti seznamu poboček.
if (crmIsAccountant()) {
    $branchId = 0;
    $__rawBranch = (int)($_GET['pobocka'] ?? 0);
    if ($__rawBranch > 0) {
        foreach (afxUcetniBranchList() as $__b) {
            if ((int)$__b['id'] === $__rawBranch) { $branchId = $__rawBranch; break; }
        }
    }
} else {
    $branchId = afxUcetniResolveBranch($_GET['pobocka'] ?? 0);
}

// ═════════════════════════════════════════════════════════════════════════════
// A) TISKOVÁ SESTAVA
// ═════════════════════════════════════════════════════════════════════════════
if ($sestava !== '' && isset($defs[$sestava])) {
    // Tisková stránka nesmí projít přes header.php (ten vykresluje celý CRM shell),
    // takže si přihlášení i oprávnění hlídá sama — jinak by byla veřejná.
    if ((empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) || !afxUcetniCanRead()) {
        http_response_code(403);
        die('Přístup odepřen — účetní podklady smí otevřít jen vedení a účetní.');
    }

    $def = $defs[$sestava];
    $title = (string)$def['nazev'];
    $orient = (string)$def['sirka'];

    switch ($sestava) {

        // ── 1) Kniha vydaných faktur ─────────────────────────────────────────
        case 'kniha':
            $d = afxUcetniDataKniha($period['from'], $period['to'], $branchId);
            afxUcetniPrintOpen($title, $period, $branchId, $orient, 'Datum vystavení');
            afxUcetniPrintNotes($d['pozn'], 'warn');
            if (!$d['rows']) {
                afxUcetniPrintEmpty('Za zvolené období nebyla vystavena žádná faktura.');
            } else { ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Číslo</th><th>Vystaveno</th><th>DUZP</th><th>Splatnost</th>
                        <th>Odběratel</th><th>IČO</th><th>Zakázka</th>
                        <th class="num">Částka</th><th class="num">Uhrazeno</th><th class="num">Zbývá</th>
                        <th>Stav</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($d['rows'] as $r): $storno = $r['status'] === 'cancelled'; ?>
                    <tr>
                        <td class="nowrap<?php echo $storno ? ' rp-strike' : ''; ?>"><b><?php echo e($r['invoice_number']); ?></b><?php if ((string)($r['supplier'] ?? 'sro') === 'ico'): ?> <span style="font-size:9px;font-weight:700;color:#7c3aed;border:1px solid #7c3aed;border-radius:6px;padding:0 4px;vertical-align:middle;" title="Fakturu vystavila OSVČ — do účetnictví s.r.o. nepatří">OSVČ</span><?php endif; ?></td>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['date_issue'])); ?></td>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['date_tax'])); ?></td>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['date_due'])); ?></td>
                        <td><?php echo e($r['cust_name']); ?></td>
                        <td class="nowrap"><?php echo e($r['cust_ico'] !== '' ? $r['cust_ico'] : '—'); ?></td>
                        <td class="nowrap"><?php echo $r['order_id'] ? '#' . (int)$r['order_id'] : '—'; ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['total_amount'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['paid_calc'])); ?></td>
                        <td class="num<?php echo $r['remaining'] > 0.005 && !$storno ? ' rp-neg' : ''; ?>"><?php echo e(afxUcetniMoney($r['remaining'])); ?></td>
                        <td class="nowrap">
                            <?php echo afxUcetniStatusTag((string)$r['status']); ?>
                            <?php if ($r['po_splatnosti'] && $r['status'] !== 'overdue'): ?>
                                <span class="rp-tag rp-tag--bad">po splatnosti</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7">Celkem <?php echo (int)$d['soucty']['pocet']; ?> faktur (bez stornovaných)</td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['celkem'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['uhrazeno'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['zbyva'])); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <?php if ($d['soucty']['storno_pocet'] > 0): ?>
                <div class="report-note report-note--warn">
                    Stornovaných faktur v období: <b><?php echo (int)$d['soucty']['storno_pocet']; ?></b>
                    v hodnotě <b><?php echo e(afxUcetniMoney($d['soucty']['storno_castka'])); ?> Kč</b>.
                    V řádcích jsou přeškrtnuté a do součtů nevstupují.
                </div>
            <?php endif; ?>
            <div class="report-note">Dobropisy (opravné daňové doklady) v této knize nejsou — mají vlastní sestavu „Dobropisy za období“, aby se stejná částka nezapočítala dvakrát.</div>
            <?php }
            afxUcetniPrintClose($title, $period, $branchId);
            exit;

        // ── 2) Přehled úhrad faktur ──────────────────────────────────────────
        case 'uhrady':
            $d = afxUcetniDataUhrady($period['from'], $period['to'], $branchId);
            afxUcetniPrintOpen($title, $period, $branchId, $orient, 'Datum platby');
            afxUcetniPrintNotes($d['pozn']);
            if (!$d['rows']) {
                afxUcetniPrintEmpty('Za zvolené období nebyla zaevidována žádná úhrada faktury.');
            } else { ?>
            <div class="report-cards">
                <?php foreach (['bank', 'cash', 'card', 'other'] as $k):
                    if (!isset($d['soucty']['dle_zpusobu'][$k])) { continue; } ?>
                    <div class="report-card">
                        <div class="rp-k"><?php echo e(afxUcetniPayKind($k)); ?></div>
                        <div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['dle_zpusobu'][$k])); ?></div>
                        <div class="rp-s">Kč</div>
                    </div>
                <?php endforeach; ?>
                <div class="report-card">
                    <div class="rp-k">Celkem přijato</div>
                    <div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['celkem'])); ?></div>
                    <div class="rp-s"><?php echo (int)$d['soucty']['pocet']; ?> plateb</div>
                </div>
            </div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Datum platby</th><th>Faktura</th><th>VS</th><th>Odběratel</th>
                        <th>Protistrana</th><th>Způsob</th><th>Poznámka</th><th class="num">Částka</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($d['rows'] as $r):
                    $protistrana = trim((string)($r['bank_name'] ?? ''));
                    if ($protistrana === '') { $protistrana = (string)$r['cust_name']; }
                ?>
                    <tr>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['paid_on'])); ?></td>
                        <td class="nowrap"><b><?php echo e($r['invoice_number']); ?></b></td>
                        <td class="nowrap"><?php echo e($r['variable_symbol'] ?: ($r['bank_vs'] ?: '—')); ?></td>
                        <td><?php echo e($r['cust_name']); ?></td>
                        <td>
                            <?php echo e($protistrana); ?>
                            <?php if (!empty($r['bank_acc'])): ?><div class="sub"><?php echo e($r['bank_acc']); ?></div><?php endif; ?>
                        </td>
                        <td class="nowrap"><?php echo e(afxUcetniPayKind((string)$r['kind'])); ?></td>
                        <td class="sub"><?php echo e($r['note'] ?: ''); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['amount'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7">Celkem <?php echo (int)$d['soucty']['pocet']; ?> plateb</td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['celkem'])); ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php }
            afxUcetniPrintClose($title, $period, $branchId);
            exit;

        // ── 3) Neuhrazené pohledávky k datu ──────────────────────────────────
        case 'pohledavky':
            $d = afxUcetniDataPohledavky($period['to'], $branchId);
            afxUcetniPrintOpen($title, $period, $branchId, $orient, 'Stav k datu');
            afxUcetniPrintNotes($d['pozn'], 'warn');
            echo '<div class="report-note">Stav <b>ke dni ' . e(afxUcetniDate($period['to']))
                . '</b>: započítány jsou jen platby došlé do tohoto data, i kdyby faktura byla uhrazená později. '
                . 'Koncepty a stornované faktury se do pohledávek nepočítají.</div>';
            if (!$d['rows']) {
                afxUcetniPrintEmpty('K ' . afxUcetniDate($period['to']) . ' nebyly evidovány žádné neuhrazené pohledávky.');
            } else { ?>
            <div class="report-cards">
                <?php foreach ($d['soucty']['nazvy_kosu'] as $key => $label): ?>
                    <div class="report-card">
                        <div class="rp-k"><?php echo e($label); ?></div>
                        <div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['kose'][$key])); ?></div>
                        <div class="rp-s"><?php echo (int)$d['soucty']['kose_pocet'][$key]; ?> faktur</div>
                    </div>
                <?php endforeach; ?>
                <div class="report-card">
                    <div class="rp-k">Celkem k inkasu</div>
                    <div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['celkem'])); ?></div>
                    <div class="rp-s"><?php echo (int)$d['soucty']['pocet']; ?> faktur</div>
                </div>
            </div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Číslo</th><th>Odběratel</th><th>IČO</th><th>Vystaveno</th><th>Splatnost</th>
                        <th class="num">Dní po splatnosti</th>
                        <th class="num">Částka</th><th class="num">Uhrazeno k datu</th><th class="num">Zbývá</th>
                        <th>Stáří</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($d['rows'] as $r): ?>
                    <tr>
                        <td class="nowrap"><b><?php echo e($r['invoice_number']); ?></b></td>
                        <td><?php echo e($r['cust_name']); ?></td>
                        <td class="nowrap"><?php echo e($r['cust_ico'] !== '' ? $r['cust_ico'] : '—'); ?></td>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['date_issue'])); ?></td>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['date_due'])); ?></td>
                        <td class="num<?php echo $r['dni_po'] > 0 ? ' rp-neg' : ''; ?>"><?php echo $r['dni_po'] > 0 ? (int)$r['dni_po'] : '—'; ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['total_amount'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['paid_calc'])); ?></td>
                        <td class="num"><b><?php echo e(afxUcetniMoney($r['remaining'])); ?></b></td>
                        <td class="nowrap">
                            <span class="rp-tag <?php echo $r['kos'] === 'do' ? 'rp-tag--info' : ($r['kos'] === 'd60p' ? 'rp-tag--bad' : 'rp-tag--warn'); ?>">
                                <?php echo e($d['soucty']['nazvy_kosu'][$r['kos']]); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="8">Celkem neuhrazeno k <?php echo e(afxUcetniDate($period['to'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['celkem'])); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <?php }
            afxUcetniPrintClose($title, $period, $branchId);
            exit;

        // ── 4) Dobropisy za období ───────────────────────────────────────────
        case 'dobropisy':
            $d = afxUcetniDataDobropisy($period['from'], $period['to'], $branchId);
            afxUcetniPrintOpen($title, $period, $branchId, $orient, 'Datum vystavení');
            afxUcetniPrintNotes($d['pozn'], 'warn');
            echo '<div class="report-note report-note--warn">Dobropis je v CRM uložený s <b>kladnou</b> částkou '
                . '(kopie původní faktury). Do účetnictví patří jako <b>snížení výnosu</b>, tedy s opačným znaménkem.</div>';
            if (!$d['rows']) {
                afxUcetniPrintEmpty('Za zvolené období nebyl vystaven žádný dobropis.');
            } else { ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Číslo</th><th>Vystaveno</th><th>DUZP</th><th>K faktuře</th>
                        <th>Odběratel</th><th>IČO</th><th>Důvod / poznámka</th>
                        <th class="num">Částka</th><th>Stav</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($d['rows'] as $r): $storno = $r['status'] === 'cancelled'; ?>
                    <tr>
                        <td class="nowrap<?php echo $storno ? ' rp-strike' : ''; ?>"><b><?php echo e($r['invoice_number']); ?></b></td>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['date_issue'])); ?></td>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['date_tax'])); ?></td>
                        <td class="nowrap">
                            <?php echo e($r['parent_number'] ?: '—'); ?>
                            <?php if (!empty($r['parent_issue'])): ?><div class="sub"><?php echo e(afxUcetniDate($r['parent_issue'])); ?></div><?php endif; ?>
                        </td>
                        <td><?php echo e($r['cust_name']); ?></td>
                        <td class="nowrap"><?php echo e($r['cust_ico'] !== '' ? $r['cust_ico'] : '—'); ?></td>
                        <td class="sub"><?php echo e(mb_substr((string)$r['notes'], 0, 160)); ?></td>
                        <td class="num rp-neg">−<?php echo e(afxUcetniMoney($r['total_amount'])); ?></td>
                        <td class="nowrap"><?php echo afxUcetniStatusTag((string)$r['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7">Celkem <?php echo (int)$d['soucty']['pocet']; ?> dobropisů (bez stornovaných) — snížení výnosu</td>
                        <td class="num rp-neg">−<?php echo e(afxUcetniMoney($d['soucty']['celkem'])); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <?php }
            afxUcetniPrintClose($title, $period, $branchId);
            exit;

        // ── 5) Tržby z pokladny po dnech ─────────────────────────────────────
        case 'kasa':
            $d = afxUcetniDataKasa($period['from'], $period['to'], $branchId);
            afxUcetniPrintOpen($title, $period, $branchId, $orient, 'Datum prodeje');
            afxUcetniPrintNotes($d['pozn']);
            if (!$d['rows']) {
                afxUcetniPrintEmpty('Za zvolené období nebyl na kase vystaven žádný doklad.');
            } else { $maIco = ((float)($d['soucty']['faktura_ico'] ?? 0)) > 0; ?>
            <div class="report-cards">
                <div class="report-card"><div class="rp-k">Hotovost</div><div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['hotovost'])); ?></div><div class="rp-s">Kč</div></div>
                <div class="report-card"><div class="rp-k">Karta</div><div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['karta'])); ?></div><div class="rp-s">Kč</div></div>
                <div class="report-card"><div class="rp-k">Faktura s.r.o.</div><div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['faktura'])); ?></div><div class="rp-s">Kč</div></div>
                <?php if ($maIco): ?><div class="report-card"><div class="rp-k">Faktura IČO (OSVČ)</div><div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['faktura_ico'])); ?></div><div class="rp-s">Kč</div></div><?php endif; ?>
                <div class="report-card"><div class="rp-k">Celkem</div><div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['celkem'])); ?></div><div class="rp-s"><?php echo (int)$d['soucty']['doklady']; ?> dokladů</div></div>
            </div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Den</th><th class="num">Dokladů</th>
                        <th class="num">Hotovost</th><th class="num">Karta</th><th class="num">Faktura s.r.o.</th>
                        <?php if ($maIco): ?><th class="num">Faktura IČO</th><?php endif; ?>
                        <th class="num">Celkem</th><th class="num">Z toho s použitým zbožím</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($d['rows'] as $r): ?>
                    <tr>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['den'])); ?></td>
                        <td class="num"><?php echo (int)$r['doklady']; ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['hotovost'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['karta'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['faktura'])); ?></td>
                        <?php if ($maIco): ?><td class="num"><?php echo e(afxUcetniMoney($r['faktura_ico'] ?? 0)); ?></td><?php endif; ?>
                        <td class="num"><b><?php echo e(afxUcetniMoney($r['celkem'])); ?></b></td>
                        <td class="num"><?php echo (int)$r['pouzite_doklady'] > 0 ? (int)$r['pouzite_doklady'] : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Celkem</td>
                        <td class="num"><?php echo (int)$d['soucty']['doklady']; ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['hotovost'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['karta'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['faktura'])); ?></td>
                        <?php if ($maIco): ?><td class="num"><?php echo e(afxUcetniMoney($d['soucty']['faktura_ico'])); ?></td><?php endif; ?>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['celkem'])); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <?php if ($d['soucty']['storno_pocet'] > 0): ?>
                <div class="report-note report-note--warn">
                    Stornovaných dokladů v období: <b><?php echo (int)$d['soucty']['storno_pocet']; ?></b>
                    v hodnotě <b><?php echo e(afxUcetniMoney($d['soucty']['storno_castka'])); ?> Kč</b> — do tržeb nevstupují.
                    Doklad se v CRM nemaže, jen stornuje, takže je dohledatelný v pokladně.
                </div>
            <?php endif; ?>
            <div class="report-note">Prodej použitého zboží musí být na účtence zřetelně označen (§ 16 zák. č. 634/1992 Sb.) — sloupec ukazuje, kolik dokladů v daný den takovou položku obsahovalo.</div>
            <?php }
            afxUcetniPrintClose($title, $period, $branchId);
            exit;

        // ── 6) Přijaté zálohy a jejich zúčtování ─────────────────────────────
        case 'zalohy':
            $d = afxUcetniDataZalohy($period['from'], $period['to'], $branchId);
            afxUcetniPrintOpen($title, $period, $branchId, $orient, 'Datum přijetí');
            afxUcetniPrintNotes($d['pozn'], 'warn');
            ?>
            <div class="report-cards">
                <div class="report-card"><div class="rp-k">Přijaté zálohy</div><div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['prijato'])); ?></div><div class="rp-s"><?php echo (int)$d['soucty']['pocet']; ?> plateb</div></div>
                <div class="report-card"><div class="rp-k">Zúčtováno do <?php echo e(afxUcetniDate($period['to'])); ?></div><div class="rp-v rp-pos"><?php echo e(afxUcetniMoney($d['soucty']['zuctovano'])); ?></div><div class="rp-s">plnění už nastalo → výnos</div></div>
                <div class="report-card"><div class="rp-k">Závazek k <?php echo e(afxUcetniDate($period['to'])); ?></div><div class="rp-v rp-neg"><?php echo e(afxUcetniMoney($d['soucty']['zavazek'])); ?></div><div class="rp-s">nezúčtované zálohy — NENÍ výnos</div></div>
            </div>
            <?php if (!$d['rows']) {
                afxUcetniPrintEmpty('Za zvolené období nebyla rozpoznána žádná přijatá záloha.');
            } else { ?>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Datum</th><th>Zdroj</th><th>Popis</th><th>Klient</th>
                        <th>Doklad</th><th>DUZP</th><th>Zúčtování</th><th class="num">Částka</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($d['rows'] as $r): ?>
                    <tr>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['datum'])); ?></td>
                        <td class="nowrap"><?php echo e($r['zdroj']); ?></td>
                        <td class="sub"><?php echo e($r['popis']); ?></td>
                        <td><?php echo e($r['klient']); ?></td>
                        <td class="nowrap"><?php echo e($r['doklad'] !== '' ? $r['doklad'] : '—'); ?></td>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['duzp'])); ?></td>
                        <td class="nowrap">
                            <?php if ($r['zuctovano']): ?>
                                <span class="rp-tag rp-tag--ok">zúčtováno</span>
                            <?php else: ?>
                                <span class="rp-tag rp-tag--warn">závazek</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?php echo e(afxUcetniMoney($r['castka'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="7">Celkem přijaté zálohy</td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['prijato'])); ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php }

            if ($d['faktury']) { ?>
                <div class="report-section-title">Kontrolní seznam: faktury s položkou „záloha“ vystavené v období</div>
                <div class="report-note">Tento seznam je jen pro kontrolu a <b>do součtu přijatých záloh nevstupuje</b> — jinak by se stejná koruna počítala dvakrát (jednou jako vystavený doklad, podruhé jako došlá platba).</div>
                <table class="report-table">
                    <thead>
                        <tr><th>Číslo</th><th>Vystaveno</th><th>DUZP</th><th>Odběratel</th>
                            <th class="num">Částka</th><th class="num">Uhrazeno</th><th>Stav</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($d['faktury'] as $r): ?>
                        <tr>
                            <td class="nowrap"><b><?php echo e($r['invoice_number']); ?></b></td>
                            <td class="nowrap"><?php echo e(afxUcetniDate($r['date_issue'])); ?></td>
                            <td class="nowrap"><?php echo e(afxUcetniDate($r['date_tax'])); ?></td>
                            <td><?php echo e($r['cust_name']); ?></td>
                            <td class="num"><?php echo e(afxUcetniMoney($r['total_amount'])); ?></td>
                            <td class="num"><?php echo e(afxUcetniMoney($r['paid_amount'])); ?></td>
                            <td class="nowrap"><?php echo afxUcetniStatusTag((string)$r['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php }
            afxUcetniPrintClose($title, $period, $branchId);
            exit;

        // ── 7) Bankovní pohyby za období ─────────────────────────────────────
        case 'banka':
            $d = afxUcetniDataBanka($period['from'], $period['to'], $branchId);
            afxUcetniPrintOpen($title, $period, $branchId, $orient, 'Datum zaúčtování');
            afxUcetniPrintNotes($d['pozn'], 'warn');
            if (!$d['rows']) {
                afxUcetniPrintEmpty('Za zvolené období nejsou stažené žádné bankovní pohyby.');
            } else {
                $matchLabels = ['none' => 'Nespárováno', 'auto' => 'Spárováno automaticky',
                    'manual' => 'Spárováno ručně', 'review' => 'K prověření', 'ignored' => 'Ignorováno'];
            ?>
            <div class="report-cards">
                <div class="report-card"><div class="rp-k">Příjmy</div><div class="rp-v rp-pos"><?php echo e(afxUcetniMoney($d['soucty']['prijem'])); ?></div><div class="rp-s">Kč</div></div>
                <div class="report-card"><div class="rp-k">Výdaje</div><div class="rp-v rp-neg"><?php echo e(afxUcetniMoney($d['soucty']['vydaj'])); ?></div><div class="rp-s">Kč</div></div>
                <div class="report-card"><div class="rp-k">Rozdíl</div><div class="rp-v"><?php echo e(afxUcetniMoney($d['soucty']['prijem'] - $d['soucty']['vydaj'])); ?></div><div class="rp-s">Kč</div></div>
                <div class="report-card"><div class="rp-k">Pohybů</div><div class="rp-v"><?php echo (int)$d['soucty']['pocet']; ?></div><div class="rp-s"><?php echo (int)$d['soucty']['nesparovano']; ?> příchozích bez faktury</div></div>
            </div>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Datum</th><th>Protistrana</th><th>Účet protistrany</th><th>VS</th>
                        <th>Zpráva</th><th class="num">Částka</th><th>Spárovaná faktura</th><th>Stav párování</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($d['rows'] as $r): $out = (string)$r['direction'] === 'out'; ?>
                    <tr>
                        <td class="nowrap"><?php echo e(afxUcetniDate($r['booking_date'])); ?></td>
                        <td>
                            <?php echo e($r['counterparty_name'] ?: '—'); ?>
                            <?php if ((int)($r['is_reversal'] ?? 0) === 1): ?><span class="rp-tag rp-tag--bad">storno</span><?php endif; ?>
                        </td>
                        <td class="nowrap sub"><?php echo e($r['counterparty_account'] ?: '—'); ?></td>
                        <td class="nowrap"><?php echo e($r['vs'] ?: '—'); ?></td>
                        <td class="sub"><?php echo e(mb_substr((string)$r['message'], 0, 90)); ?></td>
                        <td class="num <?php echo $out ? 'rp-neg' : 'rp-pos'; ?>">
                            <?php echo $out ? '−' : '+'; ?><?php echo e(afxUcetniMoney($r['amount'])); ?>
                        </td>
                        <td class="nowrap"><?php echo e($r['invoice_number'] ?: '—'); ?></td>
                        <td class="nowrap sub"><?php echo e($matchLabels[(string)$r['match_status']] ?? (string)$r['match_status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5">Celkem <?php echo (int)$d['soucty']['pocet']; ?> pohybů — příjmy <?php echo e(afxUcetniMoney($d['soucty']['prijem'])); ?> / výdaje <?php echo e(afxUcetniMoney($d['soucty']['vydaj'])); ?></td>
                        <td class="num"><?php echo e(afxUcetniMoney($d['soucty']['prijem'] - $d['soucty']['vydaj'])); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            <?php }
            afxUcetniPrintClose($title, $period, $branchId);
            exit;
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// B) ROZCESTNÍK V CRM
// ═════════════════════════════════════════════════════════════════════════════
require_once 'includes/header.php';

if (!afxUcetniCanRead()) {
    echo '<div class="alert alert-danger">' . __('access_denied') . '</div>';
    require_once 'includes/footer.php';
    exit;
}

$branches = afxUcetniBranchList();
// Účetní vynucenou pobočku nemá (viz resolve nahoře) — selektor jí zůstává aktivní.
$forcedBranch = crmIsAccountant() ? 0 : afxUcetniForcedBranchId();
$rok = (int)date('Y');
$roky = range($rok + 1, $rok - 5);

// Odkaz na pokladní knihu vede na stránku z jiného balíku — dokud nasazená není,
// nesmí rozcestník nabízet mrtvý odkaz (účetní by skončila na chybové stránce).
$pokladniKnihaSoubor = null;
foreach (['pokladni_kniha.php', 'pokladni-kniha.php', 'ucetni_pokladni_kniha.php'] as $kand) {
    if (is_file(__DIR__ . '/' . $kand)) { $pokladniKnihaSoubor = $kand; break; }
}

/** Adresa tiskové sestavy se zachovaným obdobím a pobočkou. */
$sestavaUrl = static function (string $key) use ($period, $branchId): string {
    return 'ucetni_sestavy.php?' . http_build_query(array_merge(
        ['sestava' => $key], $period['query'], ['pobocka' => $branchId]
    ));
};
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-file-lines me-2"></i>Podklady pro účetní</h2>
</div>
<?php
// Stejná lišta záložek jako na Fakturách a Bance (Faktury | Banka | Podklady) —
// jednak konzistence, jednak jediná navigace, přes kterou se na tuhle stránku
// dá doklikat (dřív nebyla odkázaná odnikud a účetní musela psát URL ručně).
require __DIR__ . '/includes/accounting_tabs.php';
?>

<div class="glass-panel p-3 border-secondary mb-4">
    <form method="get" action="ucetni_sestavy.php" class="row g-3 align-items-end" id="ucetniObdobiForm">
        <div class="col-sm-6 col-lg-3">
            <label class="form-label small text-white-75 mb-1">Období</label>
            <select name="obdobi" id="ucetniObdobiMod" class="form-select">
                <option value="mesic" <?php echo $period['mode'] === 'mesic' ? 'selected' : ''; ?>>Měsíc</option>
                <option value="ctvrtleti" <?php echo $period['mode'] === 'ctvrtleti' ? 'selected' : ''; ?>>Čtvrtletí</option>
                <option value="rok" <?php echo $period['mode'] === 'rok' ? 'selected' : ''; ?>>Rok</option>
                <option value="vlastni" <?php echo $period['mode'] === 'vlastni' ? 'selected' : ''; ?>>Vlastní rozsah</option>
            </select>
        </div>

        <div class="col-sm-6 col-lg-3" data-obdobi="mesic">
            <label class="form-label small text-white-75 mb-1">Měsíc</label>
            <input type="month" name="m" class="form-control" value="<?php echo e($period['mode'] === 'mesic' ? substr($period['from'], 0, 7) : date('Y-m')); ?>">
        </div>

        <div class="col-6 col-lg-2" data-obdobi="ctvrtleti">
            <label class="form-label small text-white-75 mb-1">Čtvrtletí</label>
            <select name="q" class="form-select">
                <?php for ($i = 1; $i <= 4; $i++): ?>
                    <option value="<?php echo $i; ?>" <?php echo ($period['mode'] === 'ctvrtleti' && (int)ceil((int)date('n', strtotime($period['from'])) / 3) === $i) ? 'selected' : ''; ?>><?php echo $i; ?>. čtvrtletí</option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="col-6 col-lg-2" data-obdobi="ctvrtleti rok">
            <label class="form-label small text-white-75 mb-1">Rok</label>
            <select name="r" class="form-select">
                <?php foreach ($roky as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo (int)substr($period['from'], 0, 4) === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-lg-2" data-obdobi="vlastni">
            <label class="form-label small text-white-75 mb-1">Od</label>
            <input type="date" name="od" class="form-control" value="<?php echo e($period['from']); ?>">
        </div>
        <div class="col-6 col-lg-2" data-obdobi="vlastni">
            <label class="form-label small text-white-75 mb-1">Do</label>
            <input type="date" name="do" class="form-control" value="<?php echo e($period['to']); ?>">
        </div>

        <div class="col-sm-6 col-lg-3">
            <label class="form-label small text-white-75 mb-1">Provozovna</label>
            <select name="pobocka" class="form-select" <?php echo $forcedBranch > 0 ? 'disabled' : ''; ?>>
                <option value="0" <?php echo $branchId === 0 ? 'selected' : ''; ?>>Všechny provozovny</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?php echo (int)$b['id']; ?>" <?php echo $branchId === (int)$b['id'] ? 'selected' : ''; ?>><?php echo e($b['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($forcedBranch > 0): ?>
                <div class="form-text text-white-50">Vidíte podklady jen za svou provozovnu.</div>
            <?php endif; ?>
        </div>

        <div class="col-sm-6 col-lg-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-rotate me-1"></i>Nastavit období</button>
        </div>
    </form>

    <div class="mt-3 small text-white-75">
        <i class="fas fa-calendar-check me-1 text-info"></i>
        Vybráno: <b><?php echo e($period['label']); ?></b>
        (<?php echo e(afxUcetniDate($period['from'])); ?> – <?php echo e(afxUcetniDate($period['to'])); ?>)
        · <b><?php echo e(afxUcetniBranchLabel($branchId)); ?></b>
    </div>
</div>

<div class="alert alert-info bg-info bg-opacity-10 border-info border-opacity-25 text-white-75 small">
    <i class="fas fa-file-pdf me-1"></i>
    Sestava se otevře jako tisková stránka. PDF z ní uděláte tiskem (<b>Ctrl/⌘ + P</b>) a volbou cíle
    <b>„Uložit jako PDF“</b>. Sestavy jsou podklad z CRM, ne účetní doklad.
</div>

<div class="row g-3">
    <?php foreach ($defs as $key => $def): ?>
    <div class="col-md-6 col-xl-4">
        <div class="glass-panel p-3 border-secondary h-100 d-flex flex-column">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas <?php echo e($def['ikona']); ?> text-info"></i>
                <h6 class="mb-0"><?php echo e($def['nazev']); ?></h6>
            </div>
            <p class="small text-white-50 flex-grow-1 mb-3"><?php echo e($def['popis']); ?></p>
            <div class="d-flex gap-2">
                <a href="<?php echo e($sestavaUrl($key)); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-primary flex-grow-1">
                    <i class="fas fa-up-right-from-square me-1"></i>Otevřít sestavu
                </a>
                <button type="button" class="btn btn-sm btn-outline-light" title="Náhled v okně"
                        onclick="ucetniNahled('<?php echo e($sestavaUrl($key)); ?>', '<?php echo e($def['nazev']); ?>')">
                    <i class="fas fa-eye"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="col-md-6 col-xl-4">
        <div class="glass-panel p-3 border-secondary h-100 d-flex flex-column">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-book-open text-white-50"></i>
                <h6 class="mb-0">Pokladní kniha</h6>
            </div>
            <p class="small text-white-50 flex-grow-1 mb-3">
                Souvislá řada pokladních dokladů (PPD/VPD) s počátečním a konečným zůstatkem hotovosti
                za každou provozovnu. Vede se v samostatné sekci CRM.
            </p>
            <?php if ($pokladniKnihaSoubor !== null): ?>
                <a href="<?php echo e($pokladniKnihaSoubor); ?>" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-arrow-right me-1"></i>Přejít na pokladní knihu
                </a>
            <?php else: ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>Připravuje se</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    // Přepínání polí podle zvoleného typu období. Skrytá pole se ZAKÁŽOU (disabled),
    // aby se neodeslala do URL — jinak by v adrese zůstal starý měsíc u vlastního
    // rozsahu a odkaz na sestavu by se dal špatně přečíst i nasdílet.
    var form = document.getElementById('ucetniObdobiForm');
    var mod = document.getElementById('ucetniObdobiMod');
    if (!form || !mod) { return; }

    function prepni() {
        var val = mod.value;
        form.querySelectorAll('[data-obdobi]').forEach(function (box) {
            var patri = box.getAttribute('data-obdobi').split(' ').indexOf(val) !== -1;
            box.style.display = patri ? '' : 'none';
            box.querySelectorAll('input, select').forEach(function (f) { f.disabled = !patri; });
        });
    }
    mod.addEventListener('change', prepni);
    prepni();
})();

function ucetniNahled(url, nazev) {
    // Náhled v univerzálním modálu CRM; když tam z nějakého důvodu není, otevře se okno.
    if (typeof openUniversalPreview === 'function') {
        openUniversalPreview(url, nazev);
    } else {
        window.open(url, '_blank', 'noopener');
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
