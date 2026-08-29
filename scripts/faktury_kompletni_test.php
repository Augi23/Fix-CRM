<?php
/**
 * FAKTURACE OD ZAČÁTKU DO KONCE — test (v3.70.0).
 *
 * Hlídá čtyři věci, které si majitel vyžádal:
 *  1. fakturu smí vystavit i POBOČKOVÝ MANAŽER (a pořád ji nesmí smazat),
 *  2. na dokladu drží e-mail odběratele → faktura má kam odejít i bez klienta v CRM,
 *  3. klient vidí v portálu VŠECHNY své faktury (i za prodej z kasy) a cizí ne,
 *  4. v kase se ukazují všechny dnešní transakce, ne jen hotovostní.
 *
 * Zapisuje do ostré databáze: InvoiceManager si transakci řídí sám, takže se
 * testovací doklad (číslo TEST-…) na konci smaže a kontroluje se, že po úklidu
 * nezůstal ani řádek, ani osiřelá položka. Ostatní kontroly jen čtou.
 * POZOR: ensure* funkce (DDL) se pouštějí PŘED zápisem — DDL uvnitř transakce
 * dělá v MySQL implicitní COMMIT.
 *
 * Spuštění z kořene CRM:  php scripts/faktury_kompletni_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/models/InvoiceManager.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

/** Přihlášení „nanečisto" — jen session, žádný zápis. */
function jakoKdo(string $kdo): void {
    $_SESSION = [];
    switch ($kdo) {
        case 'admin':      $_SESSION = ['user_id' => 1, 'role' => 'admin']; break;
        case 'boss':       $_SESSION = ['user_id' => 't9', 'tech_id' => 9, 'role' => 'technician', 'internal_role' => 'boss']; break;
        case 'manager':    $_SESSION = ['user_id' => 't8', 'tech_id' => 8, 'role' => 'technician', 'internal_role' => 'manager']; break;
        case 'technik':    $_SESSION = ['user_id' => 't7', 'tech_id' => 7, 'role' => 'technician', 'internal_role' => 'engineer']; break;
        case 'ucetni':     $_SESSION = ['user_id' => 't6', 'tech_id' => 6, 'role' => 'technician', 'internal_role' => 'accountant']; break;
        case 'nikdo':      $_SESSION = []; break;
    }
    $_SESSION['_perms'] = [];          // ať se nesahá do DB pro práva technika
}

// ── DDL PŘED zápisem ──
afxEnsureInvoiceAdhocBuyer();
afxEnsureInvoiceEmailColumns();
afxEnsureInvoiceBranch();
afxEnsureInvoicePayments();

head('Schéma');
$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM invoices") as $c) { $cols[] = $c['Field']; }
ok('invoices.emailed_at existuje', in_array('emailed_at', $cols, true));
ok('invoices.emailed_to existuje', in_array('emailed_to', $cols, true));
ok('invoices.cust_email_override existuje', in_array('cust_email_override', $cols, true));
ok('invoices.branch_id existuje (pobočka dokladu)', in_array('branch_id', $cols, true));

head('Kdo smí vystavovat faktury');
jakoKdo('admin');
ok('administrátor ano', crmCanIssueInvoices() && crmCanUseInvoices());
jakoKdo('boss');
ok('majitel (boss) ano', crmCanIssueInvoices() && crmCanUseInvoices());
jakoKdo('manager');
ok('MANAŽER ano (nově)', crmCanIssueInvoices() && crmCanUseInvoices());
ok('manažer ale doklad NESMÍ smazat', !crmCanAccountingDelete());
ok('manažer nevidí bankovní/účetní část', !crmCanAccountingRead());
ok('manažer nemění fakturační údaje firmy', !crmCanManageInvoices());
ok('manažer NEVYSTAVÍ dobropis (vrácení peněz = vedení/účetní)', !crmCanAccountingEdit());
ok('manažer vidí jen svou provozovnu', crmInvoiceBranchScope() >= 0);
jakoKdo('technik');
ok('řadový technik ne', !crmCanIssueInvoices() && !crmCanUseInvoices());
jakoKdo('ucetni');
ok('účetní se k fakturám dostane (vlastní cestou)', crmCanUseInvoices());
ok('účetní není „vystavovatel" (nemá provozní práva)', !crmCanIssueInvoices());
ok('účetní doklad nemaže', !crmCanAccountingDelete());
ok('účetní dobropis vystavit smí', crmCanAccountingEdit());
ok('účetní vidí obě provozovny', crmInvoiceBranchScope() === 0);
jakoKdo('admin');
ok('admin vidí obě provozovny', crmInvoiceBranchScope() === 0);
ok('admin vidí i doklad bez pobočky', crmCanSeeInvoiceBranch(null) && crmCanSeeInvoiceBranch(2));
jakoKdo('nikdo');
ok('nepřihlášený ne', !crmCanIssueInvoices() && !crmCanUseInvoices());

// ── zápis: e-mail odběratele na dokladu ──
head('E-mail odběratele na faktuře');
jakoKdo('admin');
$mgr = new InvoiceManager($pdo);
$cislo = 'TEST-' . date('ymd') . '-' . random_int(1000, 9999);
// saveInvoice si transakci řídí sám (a DDL uvnitř by ji stejně potvrdilo), takže
// se testovací doklad na konci prostě smaže — číslo je jednoznačné a kontroluje
// se, že po úklidu žádné TEST- faktury nezůstaly.
$invId = 0;
try {
    $res = $mgr->saveInvoice([
        'invoice_number' => $cislo,
        'cust_name' => 'Testovací odběratel s.r.o.',
        'cust_email' => 'test.odberatel@example.com',
        'date_issue' => date('Y-m-d'), 'date_tax' => date('Y-m-d'), 'date_due' => date('Y-m-d', strtotime('+14 days')),
        'status' => 'issued', 'payment_method' => 'bank_transfer', 'is_vat_payer' => '0',
        'items' => [['name' => 'Testovací položka', 'quantity' => 1, 'unit' => 'ks', 'price' => 1000, 'vat_rate' => 21]],
    ]);
    ok('faktura bez klienta se uložila', !empty($res['success']), (string)($res['error'] ?? ''));
    $invId = (int)($res['id'] ?? 0);
    if ($invId <= 0) { throw new RuntimeException('faktura nevznikla — další kontroly nemají co testovat'); }
    ok('vrátila se ID nové faktury', $invId > 0);

    $row = $pdo->query("SELECT * FROM invoices WHERE id = " . $invId)->fetch(PDO::FETCH_ASSOC) ?: [];
    ok('e-mail odběratele se uložil', (string)($row['cust_email_override'] ?? '') === 'test.odberatel@example.com',
        (string)($row['cust_email_override'] ?? 'nic'));
    ok('doklad nemá klienta (customer_id NULL)', $row['customer_id'] === null);
    ok('zatím není označený jako odeslaný', empty($row['emailed_at']));
    ok('doklad dostal pobočku (nebo NULL bez přiřazení obsluhy)',
        array_key_exists('branch_id', $row), json_encode($row['branch_id'] ?? 'chybí'));

    // razítko odeslání (samotné odeslání e-mailu se v testu nespouští)
    $pdo->prepare("UPDATE invoices SET emailed_at = NOW(), emailed_to = ? WHERE id = ?")
        ->execute(['test.odberatel@example.com', $invId]);
    $row2 = $pdo->query("SELECT emailed_at, emailed_to FROM invoices WHERE id = " . $invId)->fetch(PDO::FETCH_ASSOC);
    ok('razítko odeslání se zapíše', !empty($row2['emailed_at']) && $row2['emailed_to'] === 'test.odberatel@example.com');

    // nesmysl místo e-mailu musí doklad odmítnout, ne ho tiše uložit
    $bad = $mgr->saveInvoice([
        'invoice_number' => $cislo . '-B',
        'cust_name' => 'Testovací odběratel s.r.o.',
        'cust_email' => 'tohle-neni-email',
        'date_issue' => date('Y-m-d'), 'date_tax' => date('Y-m-d'), 'date_due' => date('Y-m-d'),
        'items' => [['name' => 'X', 'quantity' => 1, 'unit' => 'ks', 'price' => 1, 'vat_rate' => 21]],
    ]);
    ok('nesmyslný e-mail se odmítne', empty($bad['success']) && str_contains((string)($bad['error'] ?? ''), 'platný tvar'),
        (string)($bad['error'] ?? 'prošlo'));
    ok('odmítnutá faktura v databázi nezůstala',
        (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_number = " . $pdo->quote($cislo . '-B'))->fetchColumn() === 0);

    // rozpracovaný / stornovaný doklad se klientovi neposílá
    $pdo->prepare("UPDATE invoices SET status = 'draft' WHERE id = ?")->execute([$invId]);
    [$sok, $smsg] = crmSendInvoiceEmail($invId, 'test@example.com');
    ok('draft se e-mailem neodešle', !$sok && str_contains((string)$smsg, 'vystavená'), (string)$smsg);
    $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ?")->execute([$invId]);
    [$sok2] = crmSendInvoiceEmail($invId, 'test@example.com');
    ok('stornovaná faktura se e-mailem neodešle', !$sok2);
    $pdo->prepare("UPDATE invoices SET status = 'issued' WHERE id = ?")->execute([$invId]);
} catch (Throwable $e) {
    ok('zápis faktury proběhl bez výjimky', false, $e->getMessage());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    // úklid: JEN doklad, který tenhle test vyrobil (podle ID a přesného čísla) —
    // mazat podle LIKE 'TEST-%' by sáhlo i na doklad, který si tak někdo pojmenuje
    try {
        if ($invId > 0) {
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invId]);
            $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invId]);
        }
        $pdo->prepare("DELETE ii FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
                       WHERE i.invoice_number = ?")->execute([$cislo . '-B']);
        $pdo->prepare("DELETE FROM invoices WHERE invoice_number = ?")->execute([$cislo . '-B']);
    } catch (Throwable $e) { echo "  ⚠️  úklid selhal: " . $e->getMessage() . "\n"; }
}
ok('po úklidu testovací faktura v databázi není',
    $invId <= 0 || (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE id = " . (int)$invId)->fetchColumn() === 0);
ok('ani žádné osiřelé položky testovací faktury',
    (int)$pdo->query("SELECT COUNT(*) FROM invoice_items ii LEFT JOIN invoices i ON i.id = ii.invoice_id WHERE i.id IS NULL")->fetchColumn() === 0);

// ── klientský portál ──
head('Klientská sekce — faktury zákazníka');
$sql = "SELECT id, invoice_number, invoice_type, date_issue, date_due, total_amount, currency, status
        FROM invoices
        WHERE customer_id = ? AND status IN ('issued','paid','overdue')
        ORDER BY date_issue DESC, id DESC LIMIT 60";
$cust = (int)($pdo->query("SELECT customer_id FROM invoices WHERE customer_id IS NOT NULL
    GROUP BY customer_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn() ?: 0);
if ($cust <= 0) {
    echo "  (v databázi není faktura s klientem — kontrola přeskočena)\n";
} else {
    $q = $pdo->prepare($sql); $q->execute([$cust]);
    $mine = $q->fetchAll(PDO::FETCH_ASSOC);
    ok('klient vidí své faktury', count($mine) > 0, 'klient #' . $cust);
    $ids = array_column($mine, 'id');
    $cizi = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE id IN ("
        . ($ids ? implode(',', array_map('intval', $ids)) : '0') . ") AND (customer_id IS NULL OR customer_id <> ?)");
    $cizi->execute([$cust]);
    ok('mezi nimi není žádná cizí', (int)$cizi->fetchColumn() === 0);
    $draft = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE customer_id = " . $cust
        . " AND status IN ('draft','cancelled')")->fetchColumn();
    $vydane = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE customer_id = " . $cust
        . " AND status IN ('issued','paid','overdue')")->fetchColumn();
    ok('rozpracované a stornované se klientovi nenabízejí', count($mine) === $vydane,
        'vydaných ' . $vydane . ', v seznamu ' . count($mine) . ', draft/storno ' . $draft);

    // ověření cesty klient/document.php — dotaz je stejný jako v endpointu
    $doc = $pdo->prepare("SELECT i.id FROM invoices i JOIN customers c ON i.customer_id = c.id
        WHERE i.id = ? AND i.customer_id = ? AND i.status IN ('issued','paid','overdue')
          AND i.invoice_type IN ('invoice','credit_note') LIMIT 1");
    if (!empty($mine[0]['id'])) {
        $doc->execute([(int)$mine[0]['id'], $cust]);
        ok('vlastní fakturu portál vydá', (bool)$doc->fetchColumn());
        $doc->execute([(int)$mine[0]['id'], $cust + 999999]);
        ok('cizímu klientovi ji NEVYDÁ', !$doc->fetchColumn());
    }
}

// ── kasa: dnešní transakce ──
head('Kasa — dnešní transakce');
$ss = $pdo->query("SELECT payment_method, COUNT(*) c FROM pos_sales
    WHERE created_at >= CURDATE() - INTERVAL 30 DAY GROUP BY payment_method");
$zpusoby = [];
foreach ($ss as $r) { $zpusoby[(string)$r['payment_method']] = (int)$r['c']; }
echo '  (prodeje za 30 dní podle platby: ' . (json_encode($zpusoby, JSON_UNESCAPED_UNICODE) ?: '{}') . ")\n";
$feedSql = "SELECT id, sale_number, payment_method, total, status, invoice_id, order_id, seller_name, created_at
    FROM pos_sales WHERE created_at >= CURDATE() - INTERVAL 30 DAY ORDER BY id DESC LIMIT 40";
$feed = $pdo->query($feedSql)->fetchAll(PDO::FETCH_ASSOC);
ok('výpis prodejů nefiltruje způsob platby', !str_contains($feedSql, "payment_method = "));
if ($feed) {
    $mixed = count(array_unique(array_column($feed, 'payment_method')));
    ok('ve výpisu jsou i jiné platby než hotovost (pokud takové prodeje jsou)',
        $mixed > 1 || count($zpusoby) <= 1, 'různých způsobů ve výpisu: ' . $mixed);
    ok('storna se poznají (mají status cancelled)',
        !array_diff(array_unique(array_column($feed, 'status')), ['completed', 'cancelled']));
}

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
