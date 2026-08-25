<?php
/**
 * VEŘEJNÝ SBĚR NÁVŠTĚVNOSTI (v3.58.0) — applefix.cz a applefix.click.
 * Vloží se na web jako 1×1 obrázek:  <img src="https://admin.applefix.cloud/api/hit.php?s=cz">
 *
 * Bez cookies a bez ukládání IP adresy: unikátní návštěvník se pozná podle otisku
 * (IP + prohlížeč) osoleného tajemstvím platným JEN pro daný den — napříč dny se
 * návštěvy spojit nedají. Roboti se nepočítají. Odpověď je vždy průhledný pixel,
 * i když se zápis nepovede: měření nesmí nikdy rozbít cizí web.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// pixel se pošle vždy a hned; ať se web nezdržuje čekáním na databázi
$pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
header('Content-Type: image/gif');
header('Content-Length: ' . strlen($pixel));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');   // volá se z cizích domén (oba weby)
header('Timing-Allow-Origin: *');
echo $pixel;
if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }

try {
    $site = (string)($_GET['s'] ?? '');
    if (!isset(AFX_WEB_SITES[$site])) { exit; }

    $ua = mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 300);
    if (afxWebVisitIsBot($ua)) { exit; }

    // Neregistrovat vlastní náhledy z CRM (admin si prohlíží web) ani prefetch
    $purpose = strtolower((string)($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? ''));
    if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'preview')) { exit; }

    ensureWebVisitsSchema();

    $day = date('Y-m-d');
    // Za proxy (Caddy) je skutečná adresa v X-Forwarded-For; bereme jen první článek.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $fwd = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($fwd !== '') { $ip = trim(explode(',', $fwd)[0]); }
    $hash = substr(hash('sha256', afxWebVisitSalt($day) . '|' . $ip . '|' . $ua), 0, 32);

    // 1) unikátní návštěvník dne? (INSERT IGNORE → 1 = ano, poprvé)
    $isNew = false;
    try {
        $ins = $pdo->prepare("INSERT IGNORE INTO web_visit_uniques (visit_date, site, visitor_hash) VALUES (?, ?, ?)");
        $ins->execute([$day, $site, $hash]);
        $isNew = $ins->rowCount() > 0;
    } catch (Throwable $e) { error_log('hit uniques: ' . $e->getMessage()); }

    // 2) denní počítadlo (atomicky, bez závodu dvou návštěv naráz)
    $pdo->prepare("INSERT INTO web_visits (site, visit_date, hits, visitors) VALUES (?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE hits = hits + 1, visitors = visitors + VALUES(visitors)")
        ->execute([$site, $day, $isNew ? 1 : 0]);

    // 3) úklid otisků starších 45 dnů (občas, ať to nezdržuje každou návštěvu)
    if (random_int(1, 500) === 1) {
        $pdo->exec("DELETE FROM web_visit_uniques WHERE visit_date < DATE_SUB(CURDATE(), INTERVAL 45 DAY)");
    }
} catch (Throwable $e) {
    error_log('api/hit.php: ' . $e->getMessage());
}
