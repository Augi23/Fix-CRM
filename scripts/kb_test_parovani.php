<?php
/**
 * BANKA — nanečisto: ověření párování plateb s fakturami bez volání banky.
 *
 * Proč: napojení na KB čeká na certifikát a autorizaci jednatele, ale logika,
 * která označuje faktury jako ZAPLACENÉ, musí být prověřená DŘÍV, než přes ni
 * potečou skutečné peníze. Skript proto vezme testovací pohyby ve tvaru odpovědi
 * KB ADAA API, prožene je stejným kódem jako ostrý sync a porovná výsledek
 * s očekáváním.
 *
 * BEZPEČNOST: všechno běží v jedné transakci, která se na konci VŽDY vrátí zpět
 * (ROLLBACK). V databázi po skriptu nezůstane ani testovací faktura, ani pohyb.
 * Testovací pohyby se navíc ukládají do vlastního prostředí (env='test'), takže
 * se nemíchají s ostrými ani sandboxovými daty.
 *
 * Spuštění (na serveru, z kořene CRM):
 *   php scripts/kb_test_parovani.php
 *   php scripts/kb_test_parovani.php scripts/fixtures/kb_adaa_pohyby.json
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/kb_api.php';

const TEST_ENV = 'test';
const TEST_ACCOUNT = 'TEST-ACCOUNT';

$fixtureFile = $argv[1] ?? ($root . '/scripts/fixtures/kb_adaa_pohyby.json');
if (!is_file($fixtureFile)) { exit("Chybí soubor s testovacími pohyby: $fixtureFile\n"); }
$fixture = json_decode((string)file_get_contents($fixtureFile), true);
if (!is_array($fixture)) { exit("Testovací pohyby nejsou platný JSON: $fixtureFile\n"); }

// soubor smí být buď holé pole pohybů, nebo {invoices:[…], transactions:[…], expect:[…]}
$txs      = $fixture['transactions'] ?? $fixture;
$invoices = $fixture['invoices'] ?? [];
$expect   = $fixture['expect'] ?? [];

// Data v testovacích pohybech se zapisují relativně („@-5" = před pěti dny), aby test
// nezestárl — párování má časové hranice (platba nesmí být starší než faktura, starší
// než půl roku se nepáruje automaticky) a pevná data by ho po pár měsících rozbila.
$relDate = function ($v) {
    if (!is_string($v) || $v === '' || $v[0] !== '@') { return $v; }
    return date('Y-m-d', strtotime(((int)substr($v, 1)) . ' days'));
};
foreach ($txs as &$t) {
    foreach (['bookingDate', 'valueDate'] as $k) {
        if (isset($t[$k])) { $t[$k] = $relDate($t[$k]); }
    }
}
unset($t);

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function green(string $s): string { return "\033[32m$s\033[0m"; }
function red(string $s): string { return "\033[31m$s\033[0m"; }
function yellow(string $s): string { return "\033[33m$s\033[0m"; }

ensureBankTables();

$pdo->beginTransaction();
$failures = 0;

try {
    // ── testovací faktury ─────────────────────────────────────────────────────
    $customerId = (int)$pdo->query("SELECT id FROM customers ORDER BY id LIMIT 1")->fetchColumn();
    if ($customerId <= 0) { throw new Exception('V databázi není žádný klient — test potřebuje aspoň jednoho.'); }

    // date_issue_offset = kolik dní zpět byla faktura vystavena (výchozí 15) — testy
    // časových hranic potřebují fakturu vystavenou AŽ PO platbě
    $insInv = $pdo->prepare("INSERT INTO invoices
        (invoice_number, variable_symbol, customer_id, date_issue, date_tax, date_due,
         total_amount, status, invoice_type, currency, notes)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 14 DAY), ?, ?, ?, 'CZK', 'TEST — párování banky')");
    $invIds = [];
    foreach ($invoices as $inv) {
        $issue = date('Y-m-d', strtotime(((int)($inv['date_issue_offset'] ?? -15)) . ' days'));
        $insInv->execute([
            (string)$inv['invoice_number'],
            (string)($inv['variable_symbol'] ?? $inv['invoice_number']),
            $customerId, $issue, $issue, $issue,
            (float)$inv['total_amount'],
            (string)($inv['status'] ?? 'issued'),
            (string)($inv['invoice_type'] ?? 'invoice'),
        ]);
        $invIds[(string)$inv['invoice_number']] = (int)$pdo->lastInsertId();
    }
    out('Testovací faktury: ' . count($invIds) . ' (' . implode(', ', array_keys($invIds)) . ')');

    // ── stažení pohybů (stejný kód jako ostrý sync, jen bez volání banky) ──────
    $ing = kbIngestTransactions($txs, TEST_ACCOUNT, TEST_ENV);
    out(sprintf('Pohyby: přijato %d, uloženo %d, přeskočeno (storno/nezaúčtované) %d',
        $ing['fetched'], $ing['new'], $ing['skipped']));

    // podruhé — musí být idempotentní (žádný nový řádek), jinak by se platby zdvojily
    $again = kbIngestTransactions($txs, TEST_ACCOUNT, TEST_ENV);
    if ($again['new'] !== 0) {
        $failures++;
        out(red(sprintf('CHYBA: opakované stažení stejných pohybů přidalo %d nových řádků — hrozí dvojí zaplacení faktury.', $again['new'])));
    } else {
        out(green('Opakované stažení nepřidalo nic (dedup funguje).'));
    }

    // ── storna a vrácené platby, pak párování (stejné pořadí jako v ostrém syncu) ──
    $reverted = kbApplyReversals(TEST_ENV, TEST_ACCOUNT);
    [$matched, $review] = kbAutoMatchInvoices(TEST_ENV, TEST_ACCOUNT);
    out(sprintf('Storna: %d faktur vráceno mezi nezaplacené', $reverted));
    out(sprintf('Párování: automaticky spárováno %d, k prověření %d', $matched, $review));

    // druhý průchod párováním musí být bez efektu (jinak by se stav rozjížděl)
    [$m2, $r2] = kbAutoMatchInvoices(TEST_ENV, TEST_ACCOUNT);
    if ($m2 !== 0) {
        $failures++;
        out(red("CHYBA: druhý průchod párováním zaplatil dalších $m2 faktur — párování není idempotentní."));
    } else {
        out(green('Druhý průchod párováním už nic nezaplatil (idempotentní).'));
    }
    out();

    // ── výsledek po jednotlivých pohybech ─────────────────────────────────────
    $q = $pdo->prepare("SELECT t.entry_ref, t.booking_date, t.amount, t.currency, t.direction, t.vs,
                               t.match_status, i.invoice_number, i.status inv_status
        FROM bank_transactions t LEFT JOIN invoices i ON i.id = t.matched_invoice_id
        WHERE t.env = ? AND t.account_id = ? ORDER BY t.id");
    $q->execute([TEST_ENV, TEST_ACCOUNT]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);

    printf("%-26s %-6s %10s %-4s %-10s %-9s %-12s %s\n",
        'reference', 'směr', 'částka', 'měna', 'VS', 'párování', 'faktura', 'stav faktury');
    out(str_repeat('-', 104));
    foreach ($rows as $r) {
        printf("%-26s %-6s %10.2f %-4s %-10s %-9s %-12s %s\n",
            mb_substr((string)$r['entry_ref'], 0, 26), $r['direction'],
            (float)$r['amount'], $r['currency'], (string)($r['vs'] ?? '—'),
            $r['match_status'], (string)($r['invoice_number'] ?? '—'), (string)($r['inv_status'] ?? '—'));
    }
    out();

    // ── kontrola očekávání ────────────────────────────────────────────────────
    if ($expect) {
        out('Kontrola očekávaných výsledků:');
        $byRef = [];
        foreach ($rows as $r) { $byRef[(string)$r['entry_ref']] = $r; }

        foreach ($expect as $e) {
            $what = (string)($e['popis'] ?? $e['name'] ?? '');
            $ok = true; $detail = '';

            if (isset($e['entry_ref'])) {
                $ref = (string)$e['entry_ref'];
                $row = $byRef[$ref] ?? null;
                if (isset($e['ulozeno'])) {
                    $shouldExist = (bool)$e['ulozeno'];
                    if ($shouldExist !== ($row !== null)) {
                        $ok = false;
                        $detail = $shouldExist ? 'pohyb se vůbec neuložil' : 'pohyb se uložil, ačkoli neměl';
                    }
                }
                if ($ok && $row && isset($e['match_status']) && (string)$row['match_status'] !== (string)$e['match_status']) {
                    $ok = false; $detail = 'párování je „' . $row['match_status'] . '", čekáno „' . $e['match_status'] . '"';
                }
                if ($ok && $row && isset($e['faktura'])) {
                    $got = (string)($row['invoice_number'] ?? '');
                    if ($got !== (string)$e['faktura']) {
                        $ok = false; $detail = 'spárováno s „' . ($got ?: '—') . '", čekáno „' . $e['faktura'] . '"';
                    }
                }
            }

            if (isset($e['faktura_stav'])) {
                $iv = $pdo->prepare("SELECT status FROM invoices WHERE invoice_number = ?");
                $iv->execute([(string)$e['faktura']]);
                $got = (string)$iv->fetchColumn();
                if ($got !== (string)$e['faktura_stav']) {
                    $ok = false; $detail = 'faktura je ve stavu „' . $got . '", čekáno „' . $e['faktura_stav'] . '"';
                }
            }

            if ($ok) { out('  ' . green('OK  ') . $what); }
            else { $failures++; out('  ' . red('CHYBA ') . $what . ' → ' . $detail); }
        }
        out();
    }

    // ── faktury po testu ──────────────────────────────────────────────────────
    if ($invIds) {
        out('Faktury po zpracování plateb:');
        $iq = $pdo->prepare("SELECT i.invoice_number, i.total_amount, i.status, i.payment_date,
                                    COALESCE(SUM(t.amount), 0) zaplaceno, COUNT(t.id) plateb
            FROM invoices i LEFT JOIN bank_transactions t
                 ON t.matched_invoice_id = i.id AND t.match_status IN ('auto','manual')
            WHERE i.id IN (" . implode(',', array_fill(0, count($invIds), '?')) . ")
            GROUP BY i.id ORDER BY i.invoice_number");
        $iq->execute(array_values($invIds));
        printf("  %-14s %10s %10s %-9s %s\n", 'faktura', 'vystaveno', 'zaplaceno', 'stav', 'plateb');
        foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mismatch = abs((float)$r['zaplaceno'] - (float)$r['total_amount']) > 1.0 && (string)$r['status'] === 'paid';
            printf("  %-14s %10.2f %10.2f %-9s %d%s\n", $r['invoice_number'], (float)$r['total_amount'],
                (float)$r['zaplaceno'], $r['status'], (int)$r['plateb'],
                $mismatch ? '  ' . yellow('← zaplacená, ale částka nesedí!') : '');
            if ($mismatch) { $failures++; }
        }
        out();
    }
} catch (Throwable $e) {
    $failures++;
    out(red('VÝJIMKA: ' . $e->getMessage()));
} finally {
    // VŽDY zpět — test nesmí po sobě v ostré databázi nic nechat
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    out('Databáze vrácena do původního stavu (rollback).');
}

out($failures === 0 ? green('HOTOVO — všechny kontroly prošly.') : red("HOTOVO — neprošlo kontrol: $failures"));
exit($failures === 0 ? 0 : 1);
