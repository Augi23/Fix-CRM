<?php
/**
 * Zákazníci pro vlastní e-shop (applefix.click) — ČTENÍ SEZNAMU + ZÁPIS PROFILU.
 *
 * Doplněk k api/eshop_customer.php (ten umí jen profil JEDNOHO klienta dle e-mailu).
 * Sem chodí dvě různé role, obě server-to-server s feed tokenem — e-shop si sám hlídá,
 * KDO smí co (administrace kohokoli, přihlášený zákazník výhradně sám sebe podle
 * e-mailu ze své session; e-mail nikdy nebere z těla requestu od klienta).
 *
 * GET  ?q=&limit=&offset=      → { ok, total, customers:[…] }   (výpis pro administraci)
 * POST email=…&first_name=…    → { ok, created, customer:{…} }  (uložení/založení profilu)
 *
 * ZÁMĚRNĚ NEMĚNÍ: email (identita klienta), card_token, loyalty_points, created_at.
 * Zdroj pravdy zůstává CRM — e-shop si žádná zákaznická data neukládá.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function afx_ec_out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function afx_ec_fail(string $msg, int $code = 400): void { afx_ec_out(['ok' => false, 'error' => $msg], $code); }

// ── auth (shodně s api/eshop_customer.php) ────────────────────────────────────
$remote    = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$isLocal   = in_array($remote, ['127.0.0.1', '::1', ''], true);
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && crmCanManageProducts();
$expected  = crmEshopFeedToken();
$provided  = (string)($_REQUEST['token'] ?? ($_SERVER['HTTP_X_FEED_TOKEN'] ?? ''));
$tokenOk   = ($expected !== '' && $provided !== '' && hash_equals($expected, $provided));
if (!$isLocal && !$sessionOk && !$tokenOk) { afx_ec_fail('forbidden', 403); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** Jeden řádek klienta pro e-shop (bez věrnostních a interních polí). */
function afx_ec_row(array $c): array {
    return [
        'id'            => (int)$c['id'],
        'customer_type' => (string)($c['customer_type'] ?? 'private'),
        'first_name'    => (string)($c['first_name'] ?? ''),
        'last_name'     => (string)($c['last_name'] ?? ''),
        'company'       => (string)($c['company'] ?? ''),
        'ico'           => (string)($c['ico'] ?? ''),
        'dic'           => (string)($c['dic'] ?? ''),
        'phone'         => (string)($c['phone'] ?? ''),
        'email'         => (string)($c['email'] ?? ''),
        'address'       => (string)($c['address'] ?? ''),
        'created_at'    => (string)($c['created_at'] ?? ''),
    ];
}

// ── GET: výpis / hledání (administrace e-shopu) ───────────────────────────────
if ($method === 'GET') {
    $q      = trim((string)($_GET['q'] ?? ''));
    $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    $where = '';
    $params = [];
    if ($q !== '') {
        // hledá napříč jménem, firmou, e-mailem i telefonem (číslo bez mezer)
        $like = '%' . $q . '%';
        $where = "WHERE (CONCAT_WS(' ', first_name, last_name) LIKE ? OR company LIKE ?
                         OR email LIKE ? OR REPLACE(phone, ' ', '') LIKE ?)";
        $digits = '%' . preg_replace('/\s+/', '', $q) . '%';
        $params = [$like, $like, $like, $digits];
    }
    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM customers $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $st = $pdo->prepare("SELECT id, customer_type, first_name, last_name, company, ico, dic,
                                    phone, email, address, created_at
                             FROM customers $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $st->execute($params);
        $rows = array_map('afx_ec_row', $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        error_log('eshop_customers GET: ' . $e->getMessage());
        afx_ec_fail('db', 500);
    }
    afx_ec_out(['ok' => true, 'total' => $total, 'customers' => $rows]);
}

if ($method !== 'POST') { afx_ec_fail('Jen GET nebo POST.', 405); }

// ── POST: uložení profilu (upsert podle e-mailu) ──────────────────────────────
$email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    afx_ec_fail('Neplatný e-mail — podle něj se klient páruje.');
}

/** Ořez na délku sloupce (utf8-safe), ať MySQL v strict módu nespadne na příliš dlouhé hodnotě. */
$cut = static function (string $v, int $max): string { return mb_substr(trim($v), 0, $max); };

$type = (string)($_POST['customer_type'] ?? 'private');
if (!in_array($type, ['private', 'company'], true)) { $type = 'private'; }

$in = [
    'customer_type' => $type,
    'first_name'    => $cut((string)($_POST['first_name'] ?? ''), 50),
    'last_name'     => $cut((string)($_POST['last_name'] ?? ''), 50),
    'company'       => $cut((string)($_POST['company'] ?? ''), 100),
    'ico'           => $cut(preg_replace('/[^0-9]/', '', (string)($_POST['ico'] ?? '')), 20),
    'dic'           => $cut((string)($_POST['dic'] ?? ''), 20),
    // telefon: povolit + a číslice (mezery pryč), ať se dá volat a párovat
    'phone'         => $cut(preg_replace('/[^0-9+]/', '', (string)($_POST['phone'] ?? '')), 20),
    'address'       => $cut((string)($_POST['address'] ?? ''), 500),
];

// firma musí mít název; u soukromé osoby chceme aspoň jedno jméno, ať výpis není prázdný
if ($in['customer_type'] === 'company' && $in['company'] === '') {
    afx_ec_fail('U firmy je potřeba vyplnit název firmy.');
}
if ($in['customer_type'] === 'private' && $in['first_name'] === '' && $in['last_name'] === '') {
    afx_ec_fail('Vyplň prosím jméno nebo příjmení.');
}
if ($in['dic'] !== '' && !preg_match('/^[A-Za-z]{2}[0-9A-Za-z]{2,18}$/', $in['dic'])) {
    afx_ec_fail('DIČ nemá správný tvar (např. CZ12345678).');
}

// Volitelné `id` (posílá administrace): v CRM je ~30 e-mailů vedených u víc klientů a bez id
// by úprava vždy trefila NEJNOVĚJŠÍ řádek, ne ten, který si obsluha otevřela. E-mail musí
// k id sedět — zákaznická cesta id neposílá a upsertuje podle e-mailu ze své session.
$idIn = (int)($_POST['id'] ?? 0);
try {
    if ($idIn > 0) {
        $find = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND email = ? LIMIT 1");
        $find->execute([$idIn, $email]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if (!$existing) { afx_ec_fail('Klient s tímto ID a e-mailem v CRM není.', 404); }
    } else {
        $find = $pdo->prepare("SELECT * FROM customers WHERE email = ? ORDER BY id DESC LIMIT 1");
        $find->execute([$email]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
    }

    if ($existing) {
        $st = $pdo->prepare("UPDATE customers SET customer_type = ?, first_name = ?, last_name = ?,
                                    company = ?, ico = ?, dic = ?, phone = ?, address = ?
                             WHERE id = ?");
        $st->execute([$in['customer_type'], $in['first_name'], $in['last_name'], $in['company'],
                      $in['ico'], $in['dic'], $in['phone'], $in['address'], (int)$existing['id']]);
        $id = (int)$existing['id'];
        $created = false;
    } else {
        // Klient dosud v CRM není (přihlásil se přes Apple/Google, ale ještě nenakoupil) —
        // vyplněný profil má smysl uložit: příště se pokladna předvyplní sama.
        $st = $pdo->prepare("INSERT INTO customers (customer_type, first_name, last_name, company,
                                    ico, dic, phone, email, address, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $st->execute([$in['customer_type'], $in['first_name'], $in['last_name'], $in['company'],
                      $in['ico'], $in['dic'], $in['phone'], $email, $in['address']]);
        $id = (int)$pdo->lastInsertId();
        $created = true;
    }

    $get = $pdo->prepare("SELECT id, customer_type, first_name, last_name, company, ico, dic,
                                 phone, email, address, created_at FROM customers WHERE id = ?");
    $get->execute([$id]);
    $row = afx_ec_row($get->fetch(PDO::FETCH_ASSOC) ?: []);
} catch (Throwable $e) {
    error_log('eshop_customers POST: ' . $e->getMessage());
    afx_ec_fail('Uložení se nepovedlo.', 500);
}

if (function_exists('crmAuditLog')) {
    $who = trim((string)($_POST['actor'] ?? ''));   // e-shop posílá „zákazník" / jméno admina
    crmAuditLog('eshop_customer.save', [
        'entity_type' => 'customers', 'entity_id' => $id,
        'entity_label' => trim($row['first_name'] . ' ' . $row['last_name']) ?: $row['email'],
        'summary' => ($created ? 'Založen' : 'Upraven') . ' profil zákazníka ' . $row['email']
                     . ' z e-shopu' . ($who !== '' ? ' (' . mb_substr($who, 0, 60) . ')' : ''),
    ]);
}

afx_ec_out(['ok' => true, 'created' => $created, 'customer' => $row]);
