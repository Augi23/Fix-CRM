<?php
/**
 * UPLOAD FOTEK PRO 360° PROHLÍDKU (náhrada/alternativa 360° videa).
 * Fotky z točny (8–48 kusů, jedna otáčka) se uloží do media/products/360/<safe>-photos/;
 * dispatcher na serveru (cron augi) z nich vyrobí průhledné snímky + focus.json
 * do eshopu public/produkty-360/<kód>/ — viz AppleFix-eshop/scripts/photos_to_360.py.
 *
 * POST multipart: photos[] (soubory JPEG/PNG/WebP/HEIC), code (kód produktu),
 *                 csrf_token | token (sdílený product_image_token)
 * Nový upload NAHRAZUJE celou předchozí sadu (složka se vyprázdní).
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

function afx_p360_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
/** Smaže složku sady i s obsahem (jen soubory, sada je plochá) — úklid stagingu a staré sady. */
function afx_p360_rmdir(string $d): void {
    foreach ((array)scandir($d) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        if (is_file($d . '/' . $f)) { @unlink($d . '/' . $f); }
    }
    @rmdir($d);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { afx_p360_fail('Jen POST.', 405); }

// POST tělo přes post_max_size → PHP ho celé TIŠE zahodí ($_POST i $_FILES prázdné)
// a bez téhle kontroly by request spadl až na CSRF/token větvi s matoucím „Neplatný token.“
if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $mb = (int)round(((int)$_SERVER['CONTENT_LENGTH']) / 1048576);
    afx_p360_fail("Sada je moc velká ({$mb} MB) — server přijme najednou max "
        . ini_get('post_max_size') . '. Zmenši fotky, nebo jich vyber míň.', 413);
}

// ── Autorizace: stejná jako upload_product_video.php (session+CSRF NEBO sdílený token) ──
$sessionOk = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id']))
    && validateCsrfToken((string)($_POST['csrf_token'] ?? ''))
    && crmCanManageProducts();
if (!$sessionOk) {
    if ((!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && isset($_POST['csrf_token'])) {
        // rozlišit vypršelou session od chybějícího oprávnění — jinak vedení hledá chybu
        // v přihlášení, zatímco skutečný důvod je, že uživatel nesmí spravovat produkty
        if (!crmCanManageProducts()) {
            afx_p360_fail('Nahrávat 360° fotky produktů nemáš oprávnění — potřebuješ právo „Sklad – spravovat" (přidá ho vedení v Nastavení).', 403);
        }
        afx_p360_fail('Přihlášení vypršelo — obnov stránku (⌘R) a zkus fotky znovu.', 403);
    }
    $token = (string)($_POST['token'] ?? ($_SERVER['HTTP_X_AFX_TOKEN'] ?? ''));
    $expected = (string)get_setting('product_image_token', '');
    if ($expected === '' || !hash_equals($expected, $token)) { afx_p360_fail('Neplatný token.', 403); }
}

$code = trim((string)($_POST['code'] ?? ''));
if ($code === '') { afx_p360_fail('Chybí kód produktu.'); }
// Kód teče verbatim do sidecaru .code → odtud do cest na disku v dispatcheru (jako augi).
// Whitelist: bez lomítek, bez úvodní tečky (žádná absolutní cesta ani ../ = žádný traversal
// při rename/rmtree v photos_to_360.py). Znakově shodné s eshop složkou produkty-360/<kód>/.
if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,63}$/', $code)) {
    afx_p360_fail('Neplatný kód produktu — povolená jsou jen písmena, číslice, tečka, pomlčka a podtržítko (1–64 znaků, ne na začátku tečka).');
}
$safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $code);
$safe = trim($safe, '._-');
if ($safe === '') { $safe = 'produkt-' . substr(md5($code), 0, 8); }

if (empty($_FILES['photos']) || !is_array($_FILES['photos']['name'] ?? null)) {
    afx_p360_fail('Nepřišly žádné fotky (pole photos[]).');
}
$names = $_FILES['photos']['name'];
$tmps  = $_FILES['photos']['tmp_name'];
$errs  = $_FILES['photos']['error'];
$sizes = $_FILES['photos']['size'];
$count = count($names);
// PHP nad max_file_uploads soubory TIŠE zahazuje (v $_FILES prostě chybí, žádná chyba) —
// klient posílá, kolik fotek vybral; nesoulad = ořezaná sada → radši odmítnout celou.
$expectedCount = (int)($_POST['expected_count'] ?? 0);
if ($expectedCount > 0 && $expectedCount !== $count) {
    afx_p360_fail("Server přijal jen $count z $expectedCount fotek (limit PHP max_file_uploads = "
        . (int)ini_get('max_file_uploads') . ") — sada by byla neúplná. Zkus to znovu za ~5 minut, nebo nahraj míň fotek.");
}
if ($count < 8)  { afx_p360_fail("Na 360° prohlídku je potřeba aspoň 8 fotek dokola (přišlo $count)."); }
if ($count > 48) { afx_p360_fail("Příliš mnoho fotek ($count) — maximum je 48."); }

// validace všech souborů PŘED zápisem (žádná půlka sady na disku)
$allowedExt = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'heic' => 'heic', 'heif' => 'heic'];
$items = [];
for ($i = 0; $i < $count; $i++) {
    $n = (string)$names[$i];
    if ((int)$errs[$i] !== UPLOAD_ERR_OK) { afx_p360_fail("Nahrání „{$n}“ selhalo (kód " . (int)$errs[$i] . ') — zkus to znovu.'); }
    if ((int)$sizes[$i] > 40 * 1024 * 1024) { afx_p360_fail("Fotka „{$n}“ je přes 40 MB."); }
    $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
    if (!isset($allowedExt[$ext])) { afx_p360_fail("„{$n}“: povolené jsou jen JPEG/PNG/WebP/HEIC."); }
    $tmp = (string)$tmps[$i];
    if (!is_uploaded_file($tmp)) { afx_p360_fail('Neplatný upload.'); }
    // obsahová kontrola: JPEG/PNG/WebP přes getimagesize, HEIC přes magic bytes
    // (finfo starších libmagic vrací pro HEIC application/octet-stream — na MIME
    // se nespoléhá, jinak by prošly libovolné bajty s příponou .heic)
    if ($allowedExt[$ext] === 'heic') {
        $fh = @fopen($tmp, 'rb');
        $head = $fh ? (string)fread($fh, 16) : '';
        if ($fh) { fclose($fh); }
        $brand = substr($head, 8, 4);
        if (substr($head, 4, 4) !== 'ftyp'
            || !in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true)) {
            afx_p360_fail("„{$n}“ nevypadá jako HEIC fotka.");
        }
    } elseif (@getimagesize($tmp) === false) {
        afx_p360_fail("„{$n}“ není čitelný obrázek.");
    }
    $items[] = ['name' => $n, 'tmp' => $tmp, 'ext' => $allowedExt[$ext]];
}

// pořadí = podle NÁZVU souboru (IMG_0001… = pořadí focení na točně)
usort($items, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

$base360 = __DIR__ . '/../media/products/360';
$dir     = $base360 . '/' . $safe . '-photos';

// STAGING: sada se skládá stranou a do živé složky se překlopí až celá. Zápis rovnou
// do $dir by při selhání v půlce (plný disk) nebo při dvou souběžných uploadech téhož
// kusu nechal smíchané torzo — a dispatcher nemá jak poznat, že jde o zmetek.
// Přípona .tmp-<uniq> se ZÁMĚRNĚ nekryje s globem dispatcheru (*-photos).
$stage = $dir . '.tmp-' . bin2hex(random_bytes(6));
if (!@mkdir($stage, 0775, true)) { afx_p360_fail('Nelze vytvořit složku pro fotky.', 500); }

// úklid staré rozpracované/odložené sady (SIGKILL v půlce, spadlý PHP proces): starší než 24 h.
// Glob je jen hrubé síto — název se MUSÍ ověřit přesným tvarem. Kód „X-photos.tmp-a" projde
// whitelistem a jeho ŽIVÁ složka „X-photos.tmp-a-photos" by na glob sedla → tichá ztráta cizí sady.
$reStale = '/^' . preg_quote($safe, '/') . '-photos\.(tmp|old)-[0-9a-f]{12}$/';
foreach ((array)glob($base360 . '/' . $safe . '-photos.{tmp,old}-*', GLOB_BRACE) as $stale) {
    if (!preg_match($reStale, basename($stale))) { continue; }
    if (is_dir($stale) && time() - (int)@filemtime($stale) > 86400) { afx_p360_rmdir($stale); }
}

$saved = 0;
foreach ($items as $k => $it) {
    $dest = sprintf('%s/%03d.%s', $stage, $k + 1, $it['ext']);
    if (!move_uploaded_file($it['tmp'], $dest)) {
        afx_p360_rmdir($stage);   // živá sada zůstává nedotčená
        afx_p360_fail("Uložení „{$it['name']}“ selhalo.", 500);
    }
    @chmod($dest, 0664);
    $saved++;
}
// Sidecar s PŘESNÝM kódem (název složky je sanitizovaný) + čerstvé mtime = signál dispatcheru.
// Zápis se MUSÍ ověřit: je to jediné, na co dispatcher gateuje. Prázdný sidecar (plný disk →
// open projde, zápis nezapíše nic) by znamenal sadu zpracovanou pod názvem složky místo kódu —
// tedy 360°, která se nikdy neobjeví, a v CRM věčné „zpracovává se" bez jediné chyby v logu.
$wrote = @file_put_contents($stage . '/.code', $code);
if ($wrote !== strlen($code)) {
    afx_p360_rmdir($stage);
    afx_p360_fail('Nepodařilo se dokončit sadu (došlo místo na disku?). Předchozí 360° zůstala beze změny.', 500);
}
@chmod($stage . '/.code', 0664);

// překlopení: stará pryč, nová na její místo (rename v rámci jednoho filesystému)
$old = $dir . '.old-' . bin2hex(random_bytes(6));
if (is_dir($dir) && !@rename($dir, $old)) {
    afx_p360_rmdir($stage);
    afx_p360_fail('Nelze nahradit předchozí sadu fotek.', 500);
}
if (!@rename($stage, $dir)) {
    if (is_dir($old)) { @rename($old, $dir); }   // vrátit původní sadu
    afx_p360_rmdir($stage);
    afx_p360_fail('Nelze nasadit novou sadu fotek.', 500);
}
if (is_dir($old)) { afx_p360_rmdir($old); }

// POSLEDNÍ UPLOAD VYHRÁVÁ: starší 360° video téhož kódu smazat (dispatcher by ho sice
// ignoroval, ale bez smazání se od fotek nejde nikdy vrátit k videu a status_360 by
// z jeho mtime mohl počítat stav). Až PO úspěšném uložení celé sady fotek.
foreach (['mp4', 'mov', 'webm', 'm4v'] as $ve) { @unlink($base360 . '/' . $safe . '.' . $ve); }
@unlink($base360 . '/' . $safe . '.code');

if (function_exists('crmAuditLog')) {
    crmAuditLog('product_360.photos', ['entity_type' => 'product', 'entity_label' => $code,
        'summary' => "Nahráno $saved fotek pro 360° prohlídku $code"]);
}
echo json_encode(['success' => true, 'count' => $saved, 'code' => $code], JSON_UNESCAPED_UNICODE);
