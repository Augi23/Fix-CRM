<?php
/**
 * KLIENTSKÁ SEKCE — jedna osoba, víc záznamů zákazníka (test, v3.77.0).
 *
 * Proč: reklamace se některým klientům v portálu vůbec neukazovaly. CRM
 * zákazníky neslučuje — nová reklamace „s novým klientem", import nebo
 * rezervace z webu založí další řádek customers se stejným telefonem —
 * a portál bral jen JEDEN řádek (ten, ke kterému sedl PIN zakázky).
 * Test hlídá, že se pro REKLAMACE za jednu osobu považují záznamy se stejným
 * celým telefonem (po číslicích, s předvolbou), že e-mail ani podobné číslo
 * (+421…) nikoho nesloučí, že zakázky/doklady zůstávají přísně podle záznamu
 * prokázaného PINem, že PIN sedí na zakázku pod duplicitním záznamem a váže
 * relaci na TEN záznam, a že rezervace z webu PIN pro portál nedává.
 *
 * Běží bez databáze — paměťová napodobenina PDO nad třemi tabulkami.
 * Spuštění z kořene CRM:  php scripts/klient_identita_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
if (session_status() === PHP_SESSION_NONE) { $_SESSION = []; }

$CUSTOMERS = [
    120  => ['id' => 120,  'first_name' => 'Jan',  'last_name' => 'Novák', 'phone' => '+420 777 123 456', 'email' => 'jan@example.cz', 'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    987  => ['id' => 987,  'first_name' => 'Jan',  'last_name' => 'Novak', 'phone' => '777123456',        'email' => '',               'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    1500 => ['id' => 1500, 'first_name' => 'J.',   'last_name' => 'Novák', 'phone' => '',                 'email' => 'JAN@EXAMPLE.CZ', 'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    555  => ['id' => 555,  'first_name' => 'Eva',  'last_name' => 'Cizí',  'phone' => '777123457',        'email' => 'eva@example.cz', 'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    777  => ['id' => 777,  'first_name' => 'Petr', 'last_name' => 'Krátký','phone' => '12345',            'email' => '',               'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    421  => ['id' => 421,  'first_name' => 'Ján',  'last_name' => 'Slovák','phone' => '+421 777 123 456', 'email' => '',               'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    900  => ['id' => 900,  'first_name' => 'Bez',  'last_name' => 'Čísla', 'phone' => '000 000 000',      'email' => '',               'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    901  => ['id' => 901,  'first_name' => 'Taky', 'last_name' => 'Bez',   'phone' => '000000000',        'email' => '',               'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    999  => ['id' => 999,  'first_name' => 'Útok', 'last_name' => 'Z webu','phone' => '00420777123456',   'email' => '',               'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    905  => ['id' => 905,  'first_name' => 'Jana', 'last_name' => 'SK',    'phone' => '0905 123 456',     'email' => '',               'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
    450  => ['id' => 450,  'first_name' => 'Karel','last_name' => 'Kolega','phone' => '777 123 456',      'email' => '',               'company' => '', 'customer_type' => 'private', 'preferred_language' => 'cs'],
];
$ORDERS = [
    1 => ['id' => 1, 'customer_id' => 120, 'order_code' => 'APFAZ2600485', 'legacy_code' => null, 'pin_code' => '1234', 'created_at' => '2026-08-01 10:00:00'],
    2 => ['id' => 2, 'customer_id' => 987, 'order_code' => 'APFAZ2600490', 'legacy_code' => 'Z-15', 'pin_code' => '5678', 'created_at' => '2026-08-20 10:00:00'],
    3 => ['id' => 3, 'customer_id' => 555, 'order_code' => 'APFAZ2600499', 'legacy_code' => null, 'pin_code' => '1234', 'created_at' => '2026-08-21 10:00:00'],
    4 => ['id' => 4, 'customer_id' => 987, 'order_code' => 'APFAZ2600500', 'legacy_code' => null, 'pin_code' => '9999', 'created_at' => '2026-08-22 10:00:00',
          'status' => 'Stornováno', 'created_by_name' => 'Web (applefix.cz)', 'pin_unverified' => 1],
    5 => ['id' => 5, 'customer_id' => 999, 'order_code' => 'APFAZ2600501', 'legacy_code' => null, 'pin_code' => '1111', 'created_at' => '2026-08-23 10:00:00',
          'status' => 'Přijato z RepairPluginu', 'created_by_name' => 'Web (applefix.cz)', 'pin_unverified' => 1],
    6 => ['id' => 6, 'customer_id' => 905, 'order_code' => 'APFAZ2600502', 'legacy_code' => null, 'pin_code' => '4321', 'created_at' => '2026-08-24 10:00:00'],
];

function digits(string $s): string { return preg_replace('/\D+/', '', $s) ?: ''; }

class FakeStmt {
    private array $rows = []; private int $i = 0;
    public function __construct(private FakePdo $db, private string $sql) {}
    public function execute(array $p = []): bool { $this->rows = $this->db->run($this->sql, $p); $this->i = 0; return true; }
    public function fetch($mode = null) { return $this->rows[$this->i++] ?? false; }
    public function fetchAll($mode = null): array { return $this->rows; }
    public function fetchColumn() { $r = $this->rows[0] ?? null; return $r ? reset($r) : false; }
}
class FakePdo {
    public function __construct(private array $customers, private array $orders) {}
    public function prepare(string $sql): FakeStmt { return new FakeStmt($this, $sql); }
    public function query(string $sql): FakeStmt { $s = new FakeStmt($this, $sql); $s->execute([]); return $s; }
    private function withCustomer(array $o): array {
        $c = $this->customers[$o['customer_id']];
        return array_merge(['order_id' => $o['id']], $o, ['first_name' => $c['first_name'], 'last_name' => $c['last_name'],
            'phone' => $c['phone'], 'email' => $c['email'], 'company' => $c['company'], 'customer_type' => $c['customer_type'], 'preferred_language' => $c['preferred_language']]);
    }
    public function run(string $sql, array $p): array {
        if (str_contains($sql, "SELECT REGEXP_REPLACE('a1'")) return [['x' => '1']];
        if (str_contains($sql, 'SELECT phone, email FROM customers WHERE id = ?')) { $c = $this->customers[$p[0]] ?? null; return $c ? [['phone' => $c['phone'], 'email' => $c['email']]] : []; }
        if (str_contains($sql, 'SELECT phone, first_name, last_name FROM customers WHERE id = ?')) { $c = $this->customers[$p[0]] ?? null; return $c ? [['phone' => $c['phone'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name']]] : []; }
        if (str_contains($sql, 'SELECT phone FROM customers WHERE id = ?')) { $c = $this->customers[$p[0]] ?? null; return $c ? [['phone' => $c['phone']]] : []; }
        if (str_contains($sql, 'LOWER(email) = ?') && str_contains($sql, 'id <> ?')) {
            return array_values(array_filter($this->customers, fn($c) => $c['id'] !== $p[0] && strtolower($c['email']) === $p[1]));
        }
        if (str_contains($sql, 'REGEXP_REPLACE(COALESCE(phone') && str_contains($sql, 'id <> ?')) {
            $key = ltrim($p[1], '%');
            return array_values(array_filter($this->customers, fn($c) => $c['id'] !== $p[0] && str_ends_with(digits($c['phone']), $key)));
        }
        if (str_contains($sql, 'SELECT id, phone FROM customers WHERE id <> ? AND phone IS NOT NULL')) {
            return array_values(array_filter($this->customers, fn($c) => $c['id'] !== $p[0] && $c['phone'] !== ''));
        }
        if (str_contains($sql, "phone IS NOT NULL AND phone <> ''")) {
            $r = array_values(array_filter($this->customers, fn($c) => $c['phone'] !== '')); usort($r, fn($a, $b) => $a['id'] <=> $b['id']); return $r;
        }
        if (str_contains($sql, 'LOWER(email) = LOWER(?)')) {
            $r = array_values(array_filter($this->customers, fn($c) => strtolower($c['email']) === strtolower($p[0]))); usort($r, fn($a, $b) => $a['id'] <=> $b['id']); return $r;
        }
        $pinOk = fn($o) => !str_contains($sql, 'pin_unverified,0) = 0') || (int)($o['pin_unverified'] ?? 0) === 0;
        if (str_contains($sql, 'UPPER(o.order_code) = UPPER(?)')) {
            foreach ($this->orders as $o) if ((strtoupper($o['order_code']) === strtoupper($p[0]) || strtoupper((string)$o['legacy_code']) === strtoupper($p[1])) && $pinOk($o)) return [$this->withCustomer($o)];
            return [];
        }
        if (str_contains($sql, 'WHERE o.id = ?')) { $o = $this->orders[$p[0]] ?? null; return ($o && $pinOk($o)) ? [$this->withCustomer($o)] : []; }
        if (str_contains($sql, 'FROM orders o WHERE o.customer_id IN')) {
            $r = array_values(array_filter($this->orders, fn($o) => in_array($o['customer_id'], $p, true) && $pinOk($o)));
            usort($r, fn($a, $b) => strcmp($b['created_at'], $a['created_at'])); return $r;
        }
        if (str_contains($sql, 'GROUP BY customer_id')) {
            $out = [];
            foreach ($this->orders as $o) {
                if (!in_array($o['customer_id'], $p, true)) continue;
                $c = $o['customer_id']; $out[$c] = $out[$c] ?? ['customer_id' => $c, 'n' => 0, 'ok' => 0];
                $out[$c]['n']++; if ((int)($o['pin_unverified'] ?? 0) === 0) $out[$c]['ok']++;
            }
            return array_values($out);
        }
        throw new RuntimeException('Nezaslepený dotaz: ' . $sql);
    }
}
$pdo = new FakePdo($CUSTOMERS, $ORDERS);
require_once dirname(__DIR__) . '/klient/includes/auth.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

head('Kanonický telefon (celé číslo s předvolbou)');
ok('„+420 777 123 456" → 420777123456', clientPhoneCanonical('+420 777 123 456') === '420777123456', clientPhoneCanonical('+420 777 123 456'));
ok('„00420777123456" → 420777123456', clientPhoneCanonical('00420777123456') === '420777123456');
ok('„777 123 456" (bez předvolby) → 420777123456', clientPhoneCanonical('777 123 456') === '420777123456');
ok('slovenské +421 777 123 456 je JINÉ číslo', clientPhoneCanonical('+421 777 123 456') === '421777123456' && clientPhoneCanonical('+421 777 123 456') !== clientPhoneCanonical('777123456'));
ok('krátké číslo se nepáruje (12345 → prázdno)', clientPhoneCanonical('12345') === '');
ok('placeholder 000000000 se nepáruje', clientPhoneCanonical('000 000 000') === '');
ok('vymyšlené 123456789 se nepáruje', clientPhoneCanonical('123456789') === '');

head('Sourozenecké záznamy (jen stejný celý telefon)');
$ids = clientSiblingCustomerIds($pdo, 120);
ok('120 Novák (+420 777…) najde 987 Novak (777123456, bez háčku) a NIC jiného', $ids === [120, 987], json_encode($ids));
ok('kolega se stejným firemním číslem, ale jiným příjmením (450) se NEpřibalí', !in_array(450, $ids, true));
ok('klíč jména: „Novák" = „novak", bez příjmení se vezme jméno', clientNameKey('Jan', 'Novák') === 'novak' && clientNameKey('Jan', '—') === 'jan' && clientNameKey('', '') === '');
ok('záznam 999 z webu (stejné číslo, jen neověřená rezervace) se NEpřibalí', !in_array(999, $ids, true));
ok('SQL kanonizace: nejdřív „00", pak 9 → 420', preg_match("/CASE WHEN LENGTH\(\(CASE WHEN .*LIKE '00%'/", clientPhoneCanonicalSql('c.phone')) === 1, clientPhoneCanonicalSql('c.phone'));
ok('stejný e-mail (1500) NEslučuje', !in_array(1500, $ids, true));
ok('slovenský +421 (421) NEslučuje', !in_array(421, $ids, true));
ok('cizí číslo 777123457 (555) NEslučuje', !in_array(555, $ids, true));
ok('placeholder 000000000 nespojí dva lidi', clientSiblingCustomerIds($pdo, 900) === [900], json_encode(clientSiblingCustomerIds($pdo, 900)));
ok('bez použitelného telefonu = jen on', clientSiblingCustomerIds($pdo, 777) === [777]);

head('Podmínka vlastnictví reklamace pro SQL');
$own = clientComplaintOwnerSql($pdo, 120, 'c');
ok('customer_id IN (2×?)', str_contains($own['sql'], 'c.customer_id IN (?,?)'), $own['sql']);
ok('reklamace k JEHO zakázce jen bez zákazníka nebo s jeho záznamem', str_contains($own['sql'], "c.order_id IN (SELECT o.id FROM orders o WHERE o.customer_id = ?) AND (c.customer_id IS NULL OR c.customer_id IN (?,?))"), $own['sql']);
ok('import bez zákazníka: přesná shoda celého čísla (ne LIKE)', str_contains($own['sql'], "c.customer_id IS NULL AND (CASE WHEN") && !str_contains($own['sql'], "LIKE ?"), $own['sql']);
ok('parametry: 120,987 | 120 | 120,987 | 420777123456', $own['params'] === [120, 987, 120, 120, 987, '420777123456'], json_encode($own['params']));
$own9 = clientComplaintOwnerSql($pdo, 900, 'c');
ok('placeholder telefon → bez větve importu', !str_contains($own9['sql'], 'IS NULL AND (CASE') && $own9['params'] === [900, 900, 900], json_encode($own9['params']));

head('Přihlášení: PIN sedí i pod duplicitním záznamem a váže se na něj');
$r = clientLookupCustomerAndOrders($pdo, '+420 777 123 456');
ok('telefon s předvolbou najde oba záznamy (120 i 987)', isset($r['customers'][120]) && isset($r['customers'][987]), json_encode(array_keys($r['customers'])));
ok('slovenský záznam 421 mezi kandidáty NENÍ', !isset($r['customers'][421]));
$codes = array_map(fn($o) => $o['order_code'], $r['orders']);
ok('zakázky obou záznamů (APFAZ2600485 i APFAZ2600490)', in_array('APFAZ2600485', $codes, true) && in_array('APFAZ2600490', $codes, true), json_encode($codes));
ok('cizí zakázka APFAZ2600499 mezi nimi není', !in_array('APFAZ2600499', $codes, true));
ok('rezervace z webu s neověřeným PINem (APFAZ2600500, i po stornu) PIN nedává', !in_array('APFAZ2600500', $codes, true), json_encode($codes));
$matched = null; foreach ($r['orders'] as $o) { if (hash_equals($o['pin_code'], '5678')) $matched = $o; }
ok('PIN 5678 sedí na zakázku 2 pod záznamem 987', $matched !== null && (int)$matched['customer_id'] === 987);
ok('login.php pak přihlásí záznam 987 (vlastníka zakázky), ne 120', $matched !== null && isset($r['customers'][(int)$matched['customer_id']]) && (int)$r['customers'][(int)$matched['customer_id']]['id'] === 987);
$pin9999 = false; foreach ($r['orders'] as $o) { if (hash_equals($o['pin_code'], '9999')) $pin9999 = true; }
ok('„PIN" z webové rezervace (9999) nepřihlásí', !$pin9999);

head('Neověřený PIN z webu neprojde ani přes číslo zakázky');
$r = clientLookupCustomerAndOrders($pdo, 'APFAZ2600500');
ok('kód webové zakázky → žádná zakázka k ověření PINu', $r['orders'] === [] && $r['customer'] === null, json_encode(count($r['orders'])));
$r = clientLookupCustomerAndOrders($pdo, '4');
ok('interní číslo webové zakázky → totéž', $r['orders'] === [] && $r['customer'] === null);
$r = clientLookupCustomerAndOrders($pdo, '00420777123456');
ok('útočníkův záznam 999 (jen neověřená rezervace) nemá čím PIN prokázat', !array_filter($r['orders'], fn($o) => (int)$o['customer_id'] === 999));

head('Přihlášení telefonem, který kanonizace odmítá (10 číslic)');
$r = clientLookupCustomerAndOrders($pdo, '0905 123 456');
ok('slovenský klient 905 se přihlásí prostou shodou číslic', isset($r['customers'][905]) && count($r['orders']) === 1, json_encode(array_keys($r['customers'])));

head('Přihlášení číslem zakázky (PIN musí sedět právě na ni)');
$r = clientLookupCustomerAndOrders($pdo, 'apfaz2600490');
ok('kód zakázky (i malými písmeny) → zakázka 2, klient 987', ($r['matched_order']['id'] ?? 0) === 2 && (int)($r['customer']['id'] ?? 0) === 987, json_encode($r['matched_order']['id'] ?? null));
ok('k ověření PINu je jen tahle jedna zakázka', count($r['orders']) === 1 && (int)$r['orders'][0]['id'] === 2);
$r = clientLookupCustomerAndOrders($pdo, 'Z-15');
ok('starý kód z importu se najde taky', ($r['matched_order']['id'] ?? 0) === 2, json_encode($r['matched_order']['id'] ?? null));
$r = clientLookupCustomerAndOrders($pdo, 'jan@example.cz');
ok('e-mail → kandidáti 120 i 1500 (oba mají ten e-mail), zakázka jen 120', isset($r['customers'][120]) && isset($r['customers'][1500]) && count($r['orders']) === 1, json_encode(array_keys($r['customers'])));
$r = clientLookupCustomerAndOrders($pdo, 'nikdo@example.cz');
ok('neznámý e-mail → nic', $r['customer'] === null && $r['orders'] === []);

echo "\n" . ($fail === 0 ? "✅ Vše prošlo" : "❌ NEPROŠLO") . ": $pass ok, $fail chyb\n";
exit($fail === 0 ? 0 : 1);
