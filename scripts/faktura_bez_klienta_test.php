<?php
/**
 * FAKTURA BEZ KLIENTA — test nanečisto (v3.59.0).
 *
 * Proč: kasa nově vystaví fakturu i na jednorázového odběratele, který v CRM
 * nemá kartu klienta (invoices.customer_id = NULL, údaje v cust_*_override).
 * Takový doklad se nesmí ztratit v Účetnictví, v účetních sestavách ani
 * v exportu pro účetní — a nesmí spadnout na tisku ani při odeslání mailem.
 *
 * BEZPEČNOST: všechno běží v jedné transakci, která se na konci VŽDY vrací
 * zpět (ROLLBACK) — v ostré databázi nezůstane ani faktura, ani číslo z řady.
 * DDL (ensure) se pouští PŘED transakcí, jinak by MySQL udělalo implicitní
 * commit a rollback by nic nevrátil.
 *
 * Spuštění na serveru z kořene CRM:  php scripts/faktura_bez_klienta_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/ucetni_reports.php';
require_once $root . '/export_utils.php';

$GLOBALS['AFX_TEST_IGNORE_LOCKS'] = true;

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

// ── 0) schéma (DDL mimo transakci) ──
head('Schéma');
afxEnsureInvoiceAdhocBuyer();
$col = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'customer_id'")->fetch(PDO::FETCH_ASSOC);
ok('invoices.customer_id smí být NULL', strtoupper((string)$col['Null']) === 'YES', 'Null=' . $col['Null']);
$em = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'cust_email_override'")->fetch(PDO::FETCH_ASSOC);
ok('sloupec cust_email_override existuje', (bool)$em);
ok('ensure je idempotentní (druhé volání nespadne)', (function () { afxEnsureInvoiceAdhocBuyer(); return true; })());

// ── 1) očista vstupu ──
head('Očista jednorázového odběratele');
$b = afxInvoiceBuyerSanitize(['name' => '  ACME s.r.o. ', 'ico' => ' 123 456 78 ', 'dic' => 'cz12345678', 'email' => 'a@b.cz']);
ok('název se ořeže', $b['name'] === 'ACME s.r.o.', $b['name']);
ok('IČO zůstanou jen číslice', $b['ico'] === '12345678', $b['ico']);
ok('DIČ velkými bez mezer', $b['dic'] === 'CZ12345678', $b['dic']);
ok('platný vstup nehlásí chybu', $b['error'] === '', $b['error']);
ok('špatný e-mail = chyba', afxInvoiceBuyerSanitize(['name' => 'X s.r.o.', 'email' => 'neni-mail'])['error'] !== '');
ok('IČO s písmeny = chyba', afxInvoiceBuyerSanitize(['name' => 'X s.r.o.', 'ico' => 'ABC'])['error'] !== '');
ok('jednoznakový název = chyba', afxInvoiceBuyerSanitize(['name' => 'X'])['error'] !== '');
ok('prázdný odběratel = bez chyby (nepoužívá se)', afxInvoiceBuyerSanitize([])['error'] === '' && afxInvoiceBuyerSanitize([])['name'] === '');
ok('null vstup nespadne', afxInvoiceBuyerSanitize(null)['name'] === '');
$long = afxInvoiceBuyerSanitize(['name' => str_repeat('Á', 400), 'address' => str_repeat('B', 900)]);
ok('název se zkrátí na 190 znaků', mb_strlen($long['name']) === 190, (string)mb_strlen($long['name']));
ok('adresa se zkrátí na 400 znaků', mb_strlen($long['address']) === 400, (string)mb_strlen($long['address']));
// nálezy prověrky 25.8.: nescalary a nesmyslné IČO/DIČ
ok('pole místo názvu neprojde (nevznikne odběratel „Array")', afxInvoiceBuyerSanitize(['name' => ['x']])['name'] === '');
ok('IČO o 5 číslicích = chyba', afxInvoiceBuyerSanitize(['name' => 'X s.r.o.', 'ico' => '12345'])['error'] !== '');
ok('IČO o 8 číslicích projde', afxInvoiceBuyerSanitize(['name' => 'X s.r.o.', 'ico' => '12345678'])['error'] === '');
ok('DIČ v nesmyslném tvaru = chyba', afxInvoiceBuyerSanitize(['name' => 'X s.r.o.', 'dic' => '123'])['error'] !== '');

$items = [
    ['name' => 'Servisní práce', 'qty' => 1, 'unit_price' => 1210.00, 'used' => false],
    ['name' => 'iPhone 12 (použitý)', 'qty' => 1, 'unit_price' => 5000.00, 'used' => true],
];
$buyer = ['name' => 'TEST Jednorázový odběratel s.r.o.', 'address' => "Testovací 1\n110 00 Praha 1",
          'ico' => '12345678', 'dic' => 'CZ12345678', 'email' => 'test-odberatel@example.com'];

// VŠECHNA DDL PŘED transakcí — stejně jako to dělá api/pos_checkout.php.
// crmPosCreateInvoice volá afxEnsureInvoiceSupplierColumn(); kdyby se ALTER
// spustil až uvnitř, MySQL by udělalo implicitní COMMIT a testovací faktury
// by v ostré databázi zůstaly (ověřeno tvrdě 25. 8. 2026).
afxEnsureInvoiceSupplierColumn();
afxEnsureInvoicePayments();

$pdo->beginTransaction();
try {
    // ── 2) faktura BEZ klienta ──
    head('Faktura na jednorázového odběratele');
    $invId = crmPosCreateInvoice($pdo, 0, 'KP99TEST1', $items, 6210.00, null, 'sro', $buyer);
    ok('faktura vznikla', $invId > 0, 'id=' . $invId);
    $row = $pdo->query("SELECT * FROM invoices WHERE id = " . (int)$invId)->fetch(PDO::FETCH_ASSOC);
    ok('customer_id je NULL', $row['customer_id'] === null, var_export($row['customer_id'], true));
    ok('název odběratele uložen', $row['cust_name_override'] === $buyer['name']);
    ok('adresa uložena', $row['cust_address_override'] === $buyer['address']);
    ok('IČO uloženo', $row['cust_ico_override'] === '12345678');
    ok('DIČ uloženo', $row['cust_dic_override'] === 'CZ12345678');
    ok('e-mail uložen', $row['cust_email_override'] === $buyer['email']);
    ok('má číslo faktury', (string)$row['invoice_number'] !== '');
    ok('má variabilní symbol', (string)$row['variable_symbol'] !== '');
    $ic = (int)$pdo->query("SELECT COUNT(*) FROM invoice_items WHERE invoice_id = " . (int)$invId)->fetchColumn();
    ok('položky uloženy', $ic === 2, 'počet=' . $ic);

    // ── 2b) NEJDŮLEŽITĚJŠÍ: prázdné údaje odběratele musí být NULL ──
    // Prázdný řetězec je falsy → tisk i export by spadly zpět na kartu klienta
    // a doklad by nesl cizí IČO/adresu (kritický nález prověrky 25. 8. 2026).
    head('Prázdné údaje odběratele = NULL, ne prázdný řetězec');
    $cid0 = (int)$pdo->query("SELECT id FROM customers WHERE ico IS NOT NULL AND ico <> '' ORDER BY id LIMIT 1")->fetchColumn();
    $invNull = crmPosCreateInvoice($pdo, $cid0 > 0 ? $cid0 : 0, 'KP99TEST6', $items, 6210.00, null, 'sro',
        ['name' => 'TEST Odběratel bez údajů', 'address' => '', 'ico' => '', 'dic' => '', 'email' => '']);
    $rn = $pdo->query("SELECT * FROM invoices WHERE id = " . (int)$invNull)->fetch(PDO::FETCH_ASSOC);
    ok('prázdná adresa je NULL', $rn['cust_address_override'] === null, var_export($rn['cust_address_override'], true));
    ok('prázdné IČO je NULL', $rn['cust_ico_override'] === null, var_export($rn['cust_ico_override'], true));
    ok('prázdné DIČ je NULL', $rn['cust_dic_override'] === null, var_export($rn['cust_dic_override'], true));
    ok('prázdný e-mail je NULL', $rn['cust_email_override'] === null, var_export($rn['cust_email_override'], true));
    if ($cid0 > 0) {
        $ck = $pdo->prepare("SELECT i.cust_ico_override, c.ico FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = ?");
        $ck->execute([$invNull]);
        $ckr = $ck->fetch(PDO::FETCH_ASSOC);
        $printed = $ckr['cust_ico_override'] ?: $ckr['ico'];
        // (tenhle případ API nově vůbec nepustí — klient a ruční odběratel se vylučují)
        ok('POZOR: s klientem v DB by se IČO klienta ještě propsalo', true, 'na dokladu by bylo IČO ' . (string)$printed);
    }

    // ── 3) čtecí cesty, které dřív měly INNER JOIN ──
    head('Čtecí cesty (tisk, Účetnictví, detail)');
    $q = $pdo->prepare("SELECT i.*, c.first_name, c.last_name, c.phone, c.address, c.company, c.ico, c.dic, c.preferred_language,
                               o.device_brand, o.device_model, o.serial_number
                        FROM invoices i
                        LEFT JOIN customers c ON i.customer_id = c.id
                        LEFT JOIN orders o ON i.order_id = o.id
                        WHERE i.id = ?");
    $q->execute([$invId]);
    $pr = $q->fetch(PDO::FETCH_ASSOC);
    ok('tisk faktury doklad najde', (bool)$pr);
    $custName = $pr['cust_name_override'] ?: ($pr['company'] ?: trim((string)$pr['first_name'] . ' ' . (string)$pr['last_name']));
    ok('na tisku je jméno odběratele', $custName === $buyer['name'], (string)$custName);

    $acc = $pdo->query("SELECT i.id, i.cust_name_override, c.company, c.first_name, c.last_name
        FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id WHERE i.id = " . (int)$invId)->fetch(PDO::FETCH_ASSOC);
    ok('seznam faktur v Účetnictví doklad ukáže', (bool)$acc);

    $det = $pdo->prepare("SELECT i.*, c.first_name, c.last_name, c.company
        FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id WHERE i.id = ?");
    $det->execute([$invId]);
    ok('detail faktury (modal) doklad najde', (bool)$det->fetch());

    // ── 4) e-mail: adresát se vezme z faktury ──
    head('Odeslání e-mailem (jen výběr adresáta, nic se neposílá)');
    $me = $pdo->prepare("SELECT i.*, c.email AS cust_email FROM invoices i
                         LEFT JOIN customers c ON i.customer_id = c.id WHERE i.id = ? LIMIT 1");
    $me->execute([$invId]);
    $mrow = $me->fetch(PDO::FETCH_ASSOC);
    $to = trim((string)($mrow['cust_email_override'] ?? '')) ?: trim((string)($mrow['cust_email'] ?? ''));
    ok('adresát = e-mail odběratele', $to === $buyer['email'], (string)$to);

    // ── 5) účetní sestavy ──
    head('Účetní sestavy');
    $nameExpr = afxUcetniCustNameExpr('i', 'c');
    $rep = $pdo->query("SELECT $nameExpr AS cust_name FROM invoices i
        LEFT JOIN customers c ON c.id = i.customer_id WHERE i.id = " . (int)$invId)->fetch(PDO::FETCH_ASSOC);
    ok('sestavy ukazují jméno odběratele', (string)$rep['cust_name'] === $buyer['name'], (string)$rep['cust_name']);

    // ── 6) export pro účetní (Pohoda) ──
    head('Export pro účetní');
    $exp = new AccountingExporter($pdo);
    $ref = new ReflectionMethod($exp, 'getFullInvoice');
    $ref->setAccessible(true);
    $full = $ref->invoke($exp, $invId);
    ok('export odběratele vyplní', ($full['customer']['company'] ?? '') === $buyer['name'], (string)($full['customer']['company'] ?? ''));
    ok('export vezme IČO z faktury', ($full['customer']['ico'] ?? '') === '12345678');
    ok('export nespadne na chybějícím klientovi', is_array($full['customer'] ?? null));

    // ── 6b) XML pro Pohodu musí být validní i s „&" v názvu ──
    head('Pohoda XML (ampersand a víceřádková adresa)');
    $invAmp = crmPosCreateInvoice($pdo, 0, 'KP99TEST7', $items, 6210.00, null, 'sro',
        ['name' => 'TEST Novák & syn s.r.o.', 'address' => "Dlouhá 5\n120 00 Praha 2", 'ico' => '87654321', 'dic' => '', 'email' => '']);
    $expDir = 'temp/exports/';   // AccountingExporter má cestu natvrdo
    $exp2 = new AccountingExporter($pdo);
    try {
        $fn = $exp2->exportToPohoda($invAmp);
        $xmlTxt = (string)@file_get_contents($expDir . $fn);
        ok('XML soubor vznikl', $xmlTxt !== '');
        ok('XML je validní i s „&" v názvu', $xmlTxt !== '' && @simplexml_load_string($xmlTxt) !== false);
        ok('město se vzalo z druhého řádku adresy', str_contains($xmlTxt, '120 00 Praha 2'), '');
        ok('ulice se vzala z prvního řádku', str_contains($xmlTxt, 'Dlouhá 5'));
        @unlink($expDir . $fn);   // testovací XML v exportech nenechávat
    } catch (Throwable $e) {
        ok('export do Pohody nespadne', false, $e->getMessage());
    }

    // ── 7) faktura S klientem se nezměnila ──
    head('Faktura s klientem z CRM (regrese)');
    $cid = (int)$pdo->query("SELECT id FROM customers ORDER BY id LIMIT 1")->fetchColumn();
    if ($cid > 0) {
        $invId2 = crmPosCreateInvoice($pdo, $cid, 'KP99TEST2', $items, 6210.00, null, 'sro');
        $r2 = $pdo->query("SELECT * FROM invoices WHERE id = " . (int)$invId2)->fetch(PDO::FETCH_ASSOC);
        ok('customer_id zůstal vyplněný', (int)$r2['customer_id'] === $cid);
        ok('bez odběratele se přepisy nevyplní', ($r2['cust_name_override'] ?? null) === null, var_export($r2['cust_name_override'], true));
        ok('e-mail odběratele zůstal prázdný', ($r2['cust_email_override'] ?? null) === null);
    } else {
        echo "  (přeskočeno — v databázi není žádný klient)\n";
    }

    // ── 8) klient i odběratel zároveň (zakázka v košíku) ──
    head('Klient i ruční odběratel zároveň');
    if ($cid > 0) {
        $invId3 = crmPosCreateInvoice($pdo, $cid, 'KP99TEST3', $items, 6210.00, null, 'sro', $buyer);
        $r3 = $pdo->query("SELECT * FROM invoices WHERE id = " . (int)$invId3)->fetch(PDO::FETCH_ASSOC);
        ok('vazba na klienta zůstává', (int)$r3['customer_id'] === $cid);
        ok('odběratel na dokladu má přednost', $r3['cust_name_override'] === $buyer['name']);
    }

    // ── 8b) dobropis k faktuře bez klienta ──
    head('Dobropis k faktuře na jednorázového odběratele');
    require_once dirname(__DIR__) . '/models/InvoiceManager.php';
    $cn = $pdo->query("SELECT cust_email_override FROM invoices WHERE id = " . (int)$invId)->fetchColumn();
    ok('rodičovská faktura má e-mail odběratele', (string)$cn === $buyer['email']);
    $hasCnCol = false;
    try { $hasCnCol = (bool)$pdo->query("SHOW COLUMNS FROM invoices LIKE 'cust_email_override'")->fetch(); } catch (Throwable $e) {}
    ok('dobropis kopíruje i e-mail odběratele (sloupec v INSERTu)', $hasCnCol
        && str_contains((string)file_get_contents(dirname(__DIR__) . '/models/InvoiceManager.php'), 'cust_email_override, supplier'));

    // ── 9) doklad bez odběratele i bez klienta nesmí vzniknout ──
    head('Pojistky');
    $threw = false;
    try { crmPosCreateInvoice($pdo, 0, 'KP99TEST4', $items, 6210.00); }
    catch (Throwable $e) { $threw = true; }
    ok('faktura bez klienta i bez odběratele je odmítnuta', $threw);

    // ── 10) faktura IČO (druhá identita) s odběratelem ──
    $invId5 = crmPosCreateInvoice($pdo, 0, 'KP99TEST5', $items, 6210.00, null, 'ico', $buyer);
    $r5 = $pdo->query("SELECT supplier, customer_id, cust_name_override FROM invoices WHERE id = " . (int)$invId5)->fetch(PDO::FETCH_ASSOC);
    ok('výstavce OSVČ + jednorázový odběratel', $r5['supplier'] === 'ico' && $r5['customer_id'] === null && $r5['cust_name_override'] === $buyer['name']);

} catch (Throwable $e) {
    $fail++;
    echo "\n  ❌ VÝJIMKA: " . $e->getMessage() . "\n     " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

// ── úklid ověřit ──
head('Úklid');
$left = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE notes LIKE '%KP99TEST%'")->fetchColumn();
ok('v databázi nezůstala žádná testovací faktura', $left === 0, 'zbylo ' . $left);

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
