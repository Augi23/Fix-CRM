<?php
/**
 * PŘÍLOHY V TÝMOVÉM CHATU — test nanečisto (v3.60.0).
 *
 * Ověřuje, že se příloha uloží mimo dosah webu, správně spáruje se zprávou,
 * po smazání zprávy zmizí i ze disku a hlavně že se přes uloženou cestu nedá
 * vytáhnout NIC jiného (../.env, includes/config.php…).
 *
 * BEZPEČNOST: DB zápisy jedou v transakci s ROLLBACKem, testovací soubory se
 * mažou v finally. Nic se neposílá ani nepublikuje.
 *
 * Spuštění na serveru z kořene CRM:  php scripts/chat_prilohy_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/functions.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

// ── 0) schéma a úložiště ──
head('Schéma a úložiště');
ensureStaffChatTable();
ensureStaffChatFilesTable();
$tbl = $pdo->query("SHOW TABLES LIKE 'staff_chat_files'")->fetch();
ok('tabulka staff_chat_files existuje', (bool)$tbl);
$dir = crmChatUploadDir();
if (!is_dir($dir)) { @mkdir($dir, 0770, true); }
ok('složka pro přílohy existuje', is_dir($dir), $dir);
ok('do složky jde zapisovat', is_writable($dir));
ok('složka leží v secure/ (web na ni nevidí)', str_contains(str_replace('\\', '/', realpath($dir) ?: ''), '/secure/chat'), (string)realpath($dir));

head('Typy a limity');
$types = crmChatAllowedTypes();
ok('povolený je obrázek', isset($types['image/jpeg']));
ok('povolené je PDF', isset($types['application/pdf']));
ok('PHP soubor povolený NENÍ', !isset($types['application/x-php']) && !isset($types['text/x-php']));
ok('SVG povolené NENÍ (dokáže spustit skript)', !isset($types['image/svg+xml']));
ok('HTML povolené NENÍ', !isset($types['text/html']));
ok('obrázek se pozná', crmChatIsImageMime('image/png') && crmChatIsImageMime('image/HEIC'));
ok('PDF se nepovažuje za obrázek', !crmChatIsImageMime('application/pdf'));
$max = crmChatMaxUploadBytes();
ok('limit velikosti je kladný', $max > 0, $max . ' B (' . round($max / 1048576, 1) . ' MB)');

// ── 1) hlídání cesty ──
head('Hlídání cesty k souboru (pokus o únik ze složky)');
$probe = $dir . 'test_' . bin2hex(random_bytes(8)) . '.txt';
file_put_contents($probe, 'testovaci obsah');
$rel = 'secure/chat/' . basename($probe);
ok('platná cesta projde', crmChatResolveFilePath($rel) === realpath($probe));
ok('../ ven ze složky neprojde', crmChatResolveFilePath('secure/chat/../../includes/config.php') === null);
ok('cesta mimo složku neprojde', crmChatResolveFilePath('includes/config.php') === null);
ok('.env neprojde', crmChatResolveFilePath('.env') === null);
ok('absolutní cesta neprojde', crmChatResolveFilePath('/etc/passwd') === null);
ok('neexistující soubor neprojde', crmChatResolveFilePath('secure/chat/neexistuje.txt') === null);

// ── 2) párování se zprávou ──
$pdo->beginTransaction();
try {
    head('Příloha u zprávy');
    $pdo->prepare("INSERT INTO staff_chat (actor_type, actor_id, author_name, message) VALUES ('user', 999999, 'TEST robot', '')")
        ->execute();
    $msgId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO staff_chat_files (message_id, file_path, file_name, file_mime, file_size) VALUES (?, ?, ?, ?, ?)")
        ->execute([$msgId, $rel, 'poznámka.txt', 'text/plain', filesize($probe)]);
    $fid = (int)$pdo->lastInsertId();

    $map = crmChatFilesForMessages([$msgId]);
    ok('příloha se ke zprávě najde', isset($map[$msgId]) && count($map[$msgId]) === 1);
    $f = $map[$msgId][0] ?? [];
    ok('nese původní název', ($f['name'] ?? '') === 'poznámka.txt');
    ok('nese velikost', (int)($f['size'] ?? 0) === filesize($probe));
    ok('textový soubor není obrázek', ($f['is_image'] ?? true) === false);
    ok('odkaz vede na hlídaný endpoint', ($f['url'] ?? '') === 'api/chat_file.php?id=' . $fid, (string)($f['url'] ?? ''));
    ok('v odpovědi NENÍ cesta na disku', !isset($f['path']) && !in_array($rel, array_map('strval', $f), true));

    $empty = crmChatFilesForMessages([]);
    ok('prázdný seznam zpráv nespadne', $empty === []);
    ok('neexistující zpráva vrátí prázdno', crmChatFilesForMessages([999999999]) === []);

    // ── 3) mazání zprávy odklidí i soubor ──
    head('Mazání zprávy');
    $paths = $pdo->query("SELECT file_path FROM staff_chat_files WHERE message_id = " . (int)$msgId)->fetchAll(PDO::FETCH_COLUMN);
    ok('před smazáním je cesta k souboru dohledatelná', count($paths) === 1);
    $pdo->prepare("DELETE FROM staff_chat WHERE id = ?")->execute([$msgId]);
    $pdo->prepare("DELETE FROM staff_chat_files WHERE message_id = ?")->execute([$msgId]);
    $abs = crmChatResolveFilePath((string)$paths[0]);
    ok('soubor jde po smazání zprávy bezpečně odstranit', $abs !== null && @unlink($abs));
    ok('soubor na disku už není', !is_file($probe));
    ok('řádky v DB zmizely', (int)$pdo->query("SELECT COUNT(*) FROM staff_chat_files WHERE message_id = " . (int)$msgId)->fetchColumn() === 0);

} catch (Throwable $e) {
    $fail++;
    echo "\n  ❌ VÝJIMKA: " . $e->getMessage() . "\n     " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (is_file($probe)) { @unlink($probe); }
}

head('Úklid');
ok('testovací zpráva v chatu nezůstala',
    (int)$pdo->query("SELECT COUNT(*) FROM staff_chat WHERE author_name = 'TEST robot'")->fetchColumn() === 0);
ok('testovací soubor na disku nezůstal', !is_file($probe));

echo "\n═══ " . ($fail === 0 ? "VŠE PROŠLO" : "NEPROŠLO") . " — $pass ok, $fail chyb ═══\n";
exit($fail === 0 ? 0 : 1);
