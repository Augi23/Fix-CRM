<?php
/**
 * Výdej přílohy týmového chatu (v3.60.0).
 *
 * Soubory leží v secure/chat/, kam web nevidí (Caddy vrací na /secure* 403),
 * takže je nelze stáhnout jinak než přes tenhle endpoint — a ten pustí dál
 * jen přihlášeného zaměstnance. Náhodné jméno souboru je až druhá pojistka.
 *
 * GET ?id=<id přílohy>[&dl=1]   dl=1 → vynutí stažení místo zobrazení
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

$actor = crmChatActor();
if (!isset($_SESSION['user_id']) || $actor === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit(__('unauthorized'));
}
ensureStaffChatFilesTable();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Chybí id'); }

$st = $pdo->prepare("SELECT file_path, file_name, file_mime, file_size FROM staff_chat_files WHERE id = ? LIMIT 1");
$st->execute([$id]);
$f = $st->fetch(PDO::FETCH_ASSOC);
if (!$f) { http_response_code(404); exit('Příloha neexistuje'); }

// cesta musí zůstat uvnitř secure/chat — pojistka proti ../ v uloženém záznamu
$path = crmChatResolveFilePath((string)$f['file_path']);
if ($path === null) {
    http_response_code(404);
    exit('Soubor už na serveru není');
}

$mime = (string)$f['file_mime'];
$allowed = crmChatAllowedTypes();
if (!isset($allowed[strtolower($mime)])) { $mime = 'application/octet-stream'; }

// v prohlížeči zobrazovat jen to, co je bezpečné (obrázky, PDF, video, zvuk);
// všechno ostatní se stahuje — nikdy se nesmí vykreslit jako HTML/SVG
$inlineOk = crmChatIsImageMime($mime)
    || $mime === 'application/pdf'
    || strncmp($mime, 'video/', 6) === 0
    || strncmp($mime, 'audio/', 6) === 0;
$forceDownload = !empty($_GET['dl']) || !$inlineOk;

$name = (string)$f['file_name'];
$asciiName = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'priloha';
$asciiName = str_replace(['"', '\\'], '_', $asciiName);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline')
    . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; media-src \'self\'; object-src \'none\'');
header('Cache-Control: private, max-age=600');
header('Accept-Ranges: none');

readfile($path);
