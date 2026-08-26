<?php
/**
 * PRODÁVAJÍCÍ Z DŘÍVĚJŠKA — test (v3.63.0).
 *
 * Hlídá hlavně to, co je u výkupu citlivé: přenést se smí JEN údaje o osobě.
 * Vykupovaná věc, částka ani ověření totožnosti se kopírovat nesmí — každý
 * výkupní list musí popisovat svůj vlastní předmět a mít vlastní ověření
 * podle § 8 zák. č. 253/2008 Sb.
 *
 * Vše v transakci s ROLLBACKem. Spuštění z kořene CRM:
 *   php scripts/doc_seller_reuse_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/documents.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

// stejná pravidla, jaká používá api/doc_seller_lookup.php
function reusableFields(): array {
    $out = [];
    foreach (crmDocTypes() as $cfg) {
        foreach (($cfg['sections'] ?? []) as $sec) {
            foreach (($sec['fields'] ?? []) as $f) {
                $n = (string)($f['n'] ?? '');
                if ($n !== '' && str_starts_with($n, 'customer_')) { $out[$n] = true; }
            }
        }
    }
    unset($out['customer_id_verified']);
    return array_keys($out);
}

head('Co se smí přenést');
$reuse = reusableFields();
foreach (['customer_name', 'customer_phone', 'customer_address', 'customer_pid',
          'customer_birth', 'customer_id_doc', 'customer_id_issuer', 'customer_biz_ico'] as $f) {
    ok("přenáší se $f", in_array($f, $reuse, true));
}
head('Co se přenést NESMÍ');
foreach (['item_description' => 'popis věci', 'item_model' => 'model věci', 'item_serial' => 'sériové číslo věci',
          'item_price' => 'výkupní částka', 'item_state' => 'stav věci', 'item_accessories' => 'příslušenství',
          'sign_place_date' => 'místo a datum podpisu', 'sign_payment' => 'způsob výplaty',
          'loan_amount' => 'částka půjčky', 'due_date' => 'splatnost'] as $f => $popis) {
    ok("NEpřenáší se $popis ($f)", !in_array($f, $reuse, true));
}
ok('NEpřenáší se ověření totožnosti (dělá ho konkrétní pracovník)',
    !in_array('customer_id_verified', $reuse, true));

// ── data ──
head('Hledání a přenos na skutečných datech');
ensureCrmDocumentsTable();
$mark = 'TESTVYKUP' . bin2hex(random_bytes(3));
$pdo->beginTransaction();
try {
    $fields = [
        'customer_name' => $mark . ' Novák', 'customer_phone' => '+420 777 123 456',
        'customer_email' => 'test@example.com', 'customer_address' => 'Testovací 1, Praha',
        'customer_pid' => '900101/1234', 'customer_birth' => '1.1.1990',
        'customer_id_type' => 'Občanský průkaz', 'customer_id_doc' => '123456789',
        'customer_id_issuer' => 'MČ Praha 8', 'customer_id_valid' => '1.1.2030',
        'customer_id_verified' => 'Jan Augustin',
        'item_description' => 'iPhone 12', 'item_model' => 'iPhone 12', 'item_serial' => '353036118781852',
        'item_price' => '5000', 'item_state' => 'Poškrábaný displej', 'sign_payment' => 'hotově',
    ];
    $st = $pdo->prepare("INSERT INTO crm_documents (doc_type, doc_number, doc_date, customer_name, customer_phone,
            customer_email, subject, price, lang, payload, created_by)
        VALUES ('vykup', ?, CURDATE(), ?, ?, ?, 'iPhone 12', '5000', 'cs', ?, 'test')");
    $docNo = '999' . random_int(100000, 999999);
    $st->execute([$docNo, $fields['customer_name'], $fields['customer_phone'], $fields['customer_email'],
        // payload = rovnou mapa polí (viz crmGetDocument), ne obálka {"fields":…}
        json_encode($fields, JSON_UNESCAPED_UNICODE)]);
    $docId = (int)$pdo->lastInsertId();

    $doc = crmGetDocument($docId);
    ok('doklad se načte i s poli', $doc !== null && ($doc['fields']['customer_pid'] ?? '') === '900101/1234');

    // co by endpoint vrátil k přenosu
    $take = [];
    foreach ($reuse as $n) {
        $v = trim((string)($doc['fields'][$n] ?? ''));
        if ($v !== '') { $take[$n] = $v; }
    }
    ok('přenese se jméno, telefon i rodné číslo',
        ($take['customer_name'] ?? '') !== '' && ($take['customer_phone'] ?? '') !== '' && ($take['customer_pid'] ?? '') === '900101/1234');
    ok('NEPŘENESE se popis věci', !isset($take['item_description']));
    ok('NEPŘENESE se výkupní částka', !isset($take['item_price']));
    ok('NEPŘENESE se sériové číslo věci', !isset($take['item_serial']));
    ok('NEPŘENESE se ověření totožnosti', !isset($take['customer_id_verified']));

    // hledání podle jména a podle telefonu i s mezerami
    $find = function (string $q) use ($pdo): array {
        $like = '%' . $q . '%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $sql = "SELECT id, customer_name FROM crm_documents
                WHERE doc_type IN ('vykup','zastava') AND COALESCE(customer_name,'') <> ''
                  AND (customer_name LIKE ? OR customer_phone LIKE ? OR customer_email LIKE ?";
        $par = [$like, $like, $like];
        if (strlen($digits) >= 6) {
            $sql .= " OR REPLACE(REPLACE(REPLACE(COALESCE(customer_phone,''), ' ', ''), '+', ''), '-', '') LIKE ?";
            $par[] = '%' . $digits . '%';
        }
        $sql .= ") ORDER BY id DESC LIMIT 60";
        $s = $pdo->prepare($sql); $s->execute($par);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    ok('najde se podle jména', count($find($mark)) === 1);
    ok('najde se podle telefonu s mezerami', count($find('777 123 456')) >= 1);
    ok('najde se podle holých číslic telefonu', count($find('777123456')) >= 1);
    ok('najde se podle e-mailu', count($find('test@example.com')) >= 1);
    ok('cizí dotaz nic nevrátí', count($find('ZCELANESMYSLNYDOTAZXYZ')) === 0);

    // dvě prodeje téhož člověka se v nabídce slučují do jedné položky
    $st->execute([$docNo . '1', $fields['customer_name'], $fields['customer_phone'], $fields['customer_email'],
        // payload = rovnou mapa polí (viz crmGetDocument), ne obálka {"fields":…}
        json_encode($fields, JSON_UNESCAPED_UNICODE)]);
    $rows = $find($mark);
    $people = [];
    foreach ($rows as $r) { $people[mb_strtolower((string)$r['customer_name'], 'UTF-8')] = true; }
    ok('dva doklady téhož člověka = jedna nabídka', count($rows) === 2 && count($people) === 1);
} finally {
    $pdo->rollBack();
}
ok('testovací doklady v databázi nezůstaly',
    (int)$pdo->query("SELECT COUNT(*) FROM crm_documents WHERE customer_name LIKE 'TESTVYKUP%'")->fetchColumn() === 0);

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
