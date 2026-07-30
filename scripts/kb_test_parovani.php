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
 * BEZPEČNOST (tři vrstvy, protože jedna nestačila):
 *   1) všechno běží v transakci, která se na konci VŽDY vrátí zpět (ROLLBACK),
 *   2) po rollbacku ještě proběhne ruční úklid — MySQL při DDL uvnitř transakce
 *      udělá implicitní COMMIT a testovací data by v ostré databázi zůstala,
 *   3) na konci se ověří, že nezůstal ani jeden testovací záznam (jinak test selže).
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

// POZOR — TVRDÁ LEKCE: jakýkoli CREATE TABLE / ALTER TABLE uprostřed transakce vyvolá
// v MySQL implicitní COMMIT, a testovací data tím propadnou do ostré databáze (stalo se).
// Proto se všechny pomocné DDL kontroly musí zavolat TEĎ, PŘED otevřením transakce.
ensureBankTables();
afxEnsureInvoicePayments();

const TEST_NOTE = 'TEST — párování banky';

/** Úklid po testu — druhá pojistka k rollbacku (viz komentář výše). */
function afxTestCleanup(PDO $pdo): array {
    $ids = $pdo->query("SELECT id FROM invoices WHERE notes = '" . TEST_NOTE . "'")->fetchAll(PDO::FETCH_COLUMN);
    $n = ['faktury' => 0, 'platby' => 0, 'pohyby' => 0];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $n['platby'] = (int)$pdo->exec("DELETE FROM invoice_payments WHERE invoice_id IN ($in)");
        $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($in)");
        $n['faktury'] = (int)$pdo->exec("DELETE FROM invoices WHERE id IN ($in)");
    }
    $n['pohyby'] = (int)$pdo->exec("DELETE FROM bank_transactions WHERE env = '" . TEST_ENV . "'");
    return $n;
}

// zbytky po případném dřívějším přerušeném běhu
$pre = afxTestCleanup($pdo);
if (array_sum($pre) > 0) {
    out(yellow('Uklizeny zbytky po předchozím běhu: ' . json_encode($pre, JSON_UNESCAPED_UNICODE)));
}

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
        VALUES (?, ?, ?, ?, ?, DATE_ADD(?, INTERVAL 14 DAY), ?, ?, ?, 'CZK', '" . TEST_NOTE . "')");
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

    // ── částečné platby (evidence plateb, ne párování) ────────────────────────
    out('Částečné platby:');
    $insInv->execute(['TEST-CAST-01', 'TEST-CAST-01', $customerId, date('Y-m-d', strtotime('-10 days')),
        date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('-10 days')), 8500.00, 'issued', 'invoice']);
    $partId = (int)$pdo->lastInsertId();
    $invIds['TEST-CAST-01'] = $partId;
    $check = function (string $what, float $expPaid, string $expStatus) use ($pdo, $partId, &$failures) {
        $r = $pdo->prepare("SELECT paid_amount, status FROM invoices WHERE id = ?");
        $r->execute([$partId]);
        $row = $r->fetch(PDO::FETCH_ASSOC);
        $ok = abs((float)$row['paid_amount'] - $expPaid) < 0.01 && (string)$row['status'] === $expStatus;
        if ($ok) { out('  ' . green('OK  ') . $what); }
        else {
            $failures++;
            out('  ' . red('CHYBA ') . $what . ' → zaplaceno ' . number_format((float)$row['paid_amount'], 2)
                . ' / stav ' . $row['status'] . ' (čekáno ' . number_format($expPaid, 2) . ' / ' . $expStatus . ')');
        }
    };

    afxInvoiceAddPayment($partId, 3000.00, 'bank', date('Y-m-d'), null, 'TEST 1. splátka');
    $check('první splátka 3 000 z 8 500 → faktura zůstává nezaplacená', 3000.00, 'issued');

    afxInvoiceAddPayment($partId, 5000.00, 'bank', date('Y-m-d'), null, 'TEST 2. splátka');
    $check('druhá splátka 5 000 (celkem 8 000) → pořád nezaplacená', 8000.00, 'issued');

    afxInvoiceAddPayment($partId, 500.00, 'cash', date('Y-m-d'), null, 'TEST doplatek hotově');
    $check('doplatek 500 hotově (celkem 8 500) → ZAPLACENO', 8500.00, 'paid');

    // odebrání jedné bankovní platby smí fakturu vrátit mezi nezaplacené.
    // VLASTNÍ pohyb schválně — kdyby se použil některý z pohybů výše, smazala by se
    // s ním i platba faktury, ke které patří, a zbytek testu by měřil nesmysl.
    $pdo->prepare("INSERT INTO bank_transactions (entry_ref, env, account_id, booking_date, amount, currency,
            direction, counterparty_name, vs, match_status, tx_status)
        VALUES ('TEST-CAST-TX', ?, ?, CURDATE(), 5000.00, 'CZK', 'in', 'Test splátka', 'TEST-CAST-01', 'manual', 'BOOK')")
        ->execute([TEST_ENV, TEST_ACCOUNT]);
    $txRow = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE invoice_payments SET bank_transaction_id = ? WHERE invoice_id = ? AND amount = 5000 LIMIT 1")
        ->execute([$txRow, $partId]);
    afxInvoiceRemoveBankPayment($txRow);
    $check('odpárování platby 5 000 → zpět nezaplacená, zaplaceno 3 500', 3500.00, 'issued');

    // navýšení částky faktury, na které už visí platby → není celá uhrazená
    $pdo->prepare("UPDATE invoice_payments SET amount = 9500 WHERE invoice_id = ? AND amount = 3000")->execute([$partId]);
    afxInvoiceRecalcPaid($partId, true);
    $check('platby 10 000 na fakturu 8 500 → ZAPLACENO (přeplatek stavu nebrání)', 10000.00, 'paid');
    $pdo->prepare("UPDATE invoices SET total_amount = 12000 WHERE id = ?")->execute([$partId]);
    afxInvoiceRecalcPaid($partId, true);
    $check('navýšení faktury na 12 000 → zpět nezaplacená (platby ji nekryjí)', 10000.00, 'issued');
    // zpět do původního stavu pro následné výpisy
    $pdo->prepare("UPDATE invoices SET total_amount = 8500 WHERE id = ?")->execute([$partId]);
    $pdo->prepare("UPDATE invoice_payments SET amount = 3000 WHERE invoice_id = ? AND amount = 9500")->execute([$partId]);
    afxInvoiceRecalcPaid($partId, true);

    $info = afxInvoicePaymentInfo($pdo->query("SELECT * FROM invoices WHERE id = $partId")->fetch(PDO::FETCH_ASSOC));
    if (abs($info['remaining'] - 5000.00) < 0.01 && $info['partial']) {
        out('  ' . green('OK  ') . 'zbytek k úhradě 5 000 Kč a příznak „částečně zaplaceno"');
    } else {
        $failures++;
        out('  ' . red('CHYBA ') . 'zbytek/příznak nesedí: ' . json_encode($info, JSON_UNESCAPED_UNICODE));
    }
    out();

    // ── celková kontrola konzistence ──────────────────────────────────────────
    // Nejdůležitější pravidlo celého modulu: faktura označená jako ZAPLACENÁ musí mít
    // v evidenci platby, které ji pokrývají, a uložená částka musí sedět na jejich součet.
    if ($invIds) {
        $cons = $pdo->prepare("SELECT i.invoice_number, i.total_amount, i.status, i.paid_amount,
                COALESCE((SELECT SUM(p.amount) FROM invoice_payments p WHERE p.invoice_id = i.id), 0) skutecne
            FROM invoices i WHERE i.id IN (" . implode(',', array_fill(0, count($invIds), '?')) . ")");
        $cons->execute(array_values($invIds));
        $bad = 0;
        foreach ($cons->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (abs((float)$r['paid_amount'] - (float)$r['skutecne']) > 0.01) {
                $bad++;
                out('  ' . red('CHYBA ') . 'faktura ' . $r['invoice_number'] . ': uložená částka '
                    . number_format((float)$r['paid_amount'], 2) . ' ≠ součet plateb ' . number_format((float)$r['skutecne'], 2));
            }
            if ((string)$r['status'] === 'paid'
                && (float)$r['skutecne'] < (float)$r['total_amount'] - afxPayTolerance((float)$r['total_amount'])) {
                $bad++;
                out('  ' . red('CHYBA ') . 'faktura ' . $r['invoice_number'] . ' je ZAPLACENÁ, ale došlo jen '
                    . number_format((float)$r['skutecne'], 2) . ' z ' . number_format((float)$r['total_amount'], 2));
            }
        }
        $failures += $bad;
        out($bad === 0 ? '  ' . green('OK  ') . 'evidence plateb je konzistentní se stavem všech faktur' : '');
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
    // druhá pojistka: kdyby transakci rozbil implicitní commit (DDL), uklidí se ručně
    $post = afxTestCleanup($pdo);
    if (array_sum($post) > 0) {
        out(yellow('Rollback nestačil (v transakci proběhlo DDL) — testovací data smazána ručně: '
            . json_encode($post, JSON_UNESCAPED_UNICODE)));
    }
    $zbytky = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE notes = '" . TEST_NOTE . "'")->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE env = '" . TEST_ENV . "'")->fetchColumn();
    if ($zbytky > 0) {
        $failures++;
        out(red("POZOR: v databázi zůstalo $zbytky testovacích záznamů — smaž je ručně!"));
    } else {
        out('Databáze je v původním stavu (žádná testovací data nezůstala).');
    }
}

out($failures === 0 ? green('HOTOVO — všechny kontroly prošly.') : red("HOTOVO — neprošlo kontrol: $failures"));
exit($failures === 0 ? 0 : 1);
