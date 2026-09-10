<?php
/** Výdej příloh týmového chatu. Soubory leží v secure/chat — ta složka je na
 *  serveru blokovaná napřímo (Caddy 403), takže JEDINÁ cesta k souboru vede
 *  tudy: s přihlášením zaměstnance, přes id v staff_chat_files a s cestou
 *  ověřenou crmChatResolveFilePath (žádné ../ ven ze secure/chat).
 *  ?dl=1 vynutí stažení; jinak se obrázky/PDF/video/audio ukazují inline.
 *  Range se podporuje kvůli přetáčení videa v <video> přehrávači. */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();

$actor = crmChatActor();
if (!isset($_SESSION['user_id']) || $actor === null) {
    http_response_code(403); exit('Forbidden');
}
ensureStaffChatFilesTable();

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

try {
    $st = $pdo->prepare('SELECT file_path, file_name, file_mime FROM staff_chat_files WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
} catch (Throwable $e) { $row = null; }
if (!$row) { http_response_code(404); exit('Not found'); }

$abs = crmChatResolveFilePath((string)$row['file_path']);
if ($abs === null) { http_response_code(404); exit('Not found'); }

// MIME jen z whitelistu (uložený při uploadu); cokoli mimo → obecný binární typ
$mime = strtolower((string)$row['file_mime']);
if (!isset(crmChatAllowedTypes()[$mime])) { $mime = 'application/octet-stream'; }

$inline = crmChatIsImageMime($mime) || in_array($mime, [
    'application/pdf', 'video/mp4', 'video/quicktime',
    'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav',
], true);
if (!empty($_GET['dl'])) { $inline = false; }

$name = (string)$row['file_name'];
$fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'soubor';

$size = (int)filesize($abs);
$start = 0; $end = $size - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') { $start = (int)$m[1]; }
    if ($m[2] !== '') { $end = min((int)$m[2], $size - 1); }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));

$fp = fopen($abs, 'rb');
if ($fp === false) { http_response_code(500); exit; }
fseek($fp, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fp)) {
    $chunk = fread($fp, (int)min(131072, $left));
    if ($chunk === false || $chunk === '') { break; }
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fp);
exit;
