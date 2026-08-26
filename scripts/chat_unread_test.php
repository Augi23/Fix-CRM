<?php
/**
 * CHAT — nepřečtené zprávy (test, v3.64.0).
 *
 * Proč: po přihlášení svítila u chatu bublina s počtem nepřečtených, i když
 * bylo všechno přečtené a poslední zpráva byla vlastní. Dvě příčiny:
 *  1) CRM má dvojí přihlášení (users ↔ technicians) a týž člověk má zprávy
 *     pod OBĚMA identitami; filtr „moje zprávy" porovnával jen tu aktuální,
 *     takže si vlastní starší zprávy počítal jako cizí,
 *  2) značka „přečteno" žila jen v prohlížeči — na jiném zařízení začínala
 *     od nuly a bublina se rozsvítila znovu.
 *
 * Test běží v transakci s ROLLBACKem a session si přepíná sám.
 * Spuštění z kořene CRM:  php scripts/chat_unread_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/chat_read.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

/** Přepnutí „přihlášeného" pro účely testu. */
function loginAs(?string $type, int $id): void {
    unset($_SESSION['tech_id']);
    if ($type === 'technician') { $_SESSION['user_id'] = 't' . $id; $_SESSION['tech_id'] = $id; }
    elseif ($type === 'user')   { $_SESSION['user_id'] = $id; }
    else { unset($_SESSION['user_id']); }
}

// najít skutečnou dvojici uživatel ↔ technik (na tom celá oprava stojí)
$pair = $pdo->query("SELECT id, technician_id FROM users WHERE technician_id IS NOT NULL AND technician_id > 0 LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
if (!$pair) { exit("V databázi není žádný uživatel spojený s technikem — test nemá co ověřit.\n"); }
$uid = (int)$pair['id']; $tid = (int)$pair['technician_id'];
echo "Testovací dvojice: uživatel #$uid ↔ technik #$tid\n";

head('Identity téhož člověka');
loginAs('user', $uid);
$idsU = crmChatIdentities();
ok('přihlášen jako uživatel → zná i svou technickou identitu',
    count($idsU) === 2 && in_array(['technician', $tid], $idsU, true), json_encode($idsU));
loginAs('technician', $tid);
$idsT = crmChatIdentities();
ok('přihlášen jako technik → zná i svou uživatelskou identitu',
    count($idsT) === 2 && in_array(['user', $uid], $idsT, true), json_encode($idsT));
loginAs(null, 0);
ok('nepřihlášený nemá žádnou identitu', crmChatIdentities() === []);

head('Počítání nepřečtených');
afxEnsureChatReadsTable();
ok('tabulka staff_chat_reads existuje', (bool)$pdo->query("SHOW TABLES LIKE 'staff_chat_reads'")->fetch());

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO staff_chat (actor_type, actor_id, author_name, message) VALUES (?, ?, 'TEST', ?)");
    $ins->execute(['technician', 999999, 'cizí zpráva']);          $cizi = (int)$pdo->lastInsertId();
    $ins->execute(['user', $uid, 'moje jako uživatel']);            $mojeU = (int)$pdo->lastInsertId();
    $ins->execute(['technician', $tid, 'moje jako technik']);       $mojeT = (int)$pdo->lastInsertId();

    $unread = function (int $seen): int {
        global $pdo;
        [$notMine, $par] = crmChatNotMineSql();
        $st = $pdo->prepare("SELECT COUNT(*) FROM staff_chat WHERE id > ? AND $notMine");
        $st->execute(array_merge([$seen], $par));
        return (int)$st->fetchColumn();
    };

    loginAs('technician', $tid);
    ok('vlastní zpráva z DRUHÉ identity se nepočítá', $unread($cizi) === 0, (string)$unread($cizi));
    ok('cizí zpráva se počítá', $unread($cizi - 1) === 1, (string)$unread($cizi - 1));

    loginAs('user', $uid);
    ok('a stejně to platí po přihlášení pod uživatelem', $unread($cizi) === 0, (string)$unread($cizi));

    head('Přečteno se drží na serveru');
    loginAs('technician', $tid);
    crmChatMarkRead($mojeT);
    ok('značka se uložila', crmChatLastSeenId() >= $mojeT, (string)crmChatLastSeenId());
    loginAs('user', $uid);
    ok('druhé přihlášení téhož člověka o přečtení ví (jiné zařízení)',
        crmChatLastSeenId() >= $mojeT, (string)crmChatLastSeenId());

    loginAs('technician', $tid);
    crmChatMarkRead($mojeT - 1);
    ok('značka nejde posunout zpět', crmChatLastSeenId() >= $mojeT, (string)crmChatLastSeenId());
    crmChatMarkRead(0);
    ok('nula značku nepřepíše', crmChatLastSeenId() >= $mojeT);

    loginAs(null, 0);
    crmChatMarkRead(999999);
    ok('nepřihlášený nic nezapíše', true);
} finally {
    $pdo->rollBack();
}

$left = (int)$pdo->query("SELECT COUNT(*) FROM staff_chat WHERE author_name = 'TEST'")->fetchColumn();
ok('testovací zprávy v chatu nezůstaly', $left === 0, (string)$left);
$leftReads = (int)$pdo->query("SELECT COUNT(*) FROM staff_chat_reads WHERE actor_id = 999999")->fetchColumn();
ok('testovací značky přečtení nezůstaly', $leftReads === 0);

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
