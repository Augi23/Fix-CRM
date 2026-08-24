<?php
/**
 * Smazání jednoho produktu (e-shop) — pojistka na omylem naimportovaný řádek.
 * Pozor: pokud kus zůstává v souboru appky, další import ho vrátí.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanManageProducts()) {
    echo json_encode(['success' => false, 'message' => 'Mazání produktů smí jen vedení (admin, Boss, manažer).']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Chybí ID produktu.']); exit;
}

ensureProductsTable();
ensureSkladBranchSchema();

/**
 * Úklid 360° po smazaném produktu: zdroj v CRM (fotky z točny / video + sidecar) a hotové
 * snímky v eshopu. Kód se sanitizuje stejně jako v uploadu; cesty se navíc ověří proti
 * kořenům (realpath), aby ani podvržený kód v DB neposlal mazání mimo strom.
 */
function afxDeleteProduct360Sources(string $code): void {
    if ($code === '') { return; }
    // Sanitizace MUSÍ být shodná s uploadem (upload_product_360_photos.php / _video.php),
    // jinak úklid buď nic nenajde, nebo sáhne na cizí sadu. Whitelist se tu nepoužívá jako
    // vstupní brána (videa jdou nahrát i s kódem mimo něj) — na $safe traversal nejde,
    // preg_replace vyhodí lomítka; syrový $code se ověřuje až pro eshopovou větev.
    $safe = trim(preg_replace('/[^A-Za-z0-9._-]/', '_', $code), '._-');
    if ($safe === '') { $safe = 'produkt-' . substr(md5($code), 0, 8); }

    $rmTree = static function (string $dir, string $root): void {
        $real = realpath($dir);
        if ($real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) { return; }
        foreach ((array)scandir($real) as $f) {
            if ($f === '.' || $f === '..') { continue; }
            if (is_file($real . '/' . $f)) { @unlink($real . '/' . $f); }
        }
        @rmdir($real);
    };

    // 1) zdroj v CRM — živá sada i případné staging/odložené zbytky po přerušeném uploadu
    $base = realpath(__DIR__ . '/../media/products/360');
    if ($base !== false) {
        $dirs = array_merge(
            [$base . '/' . $safe . '-photos'],
            (array)glob($base . '/' . $safe . '-photos.{tmp,old}-*', GLOB_BRACE)
        );
        $reStale = '/^' . preg_quote($safe, '/') . '-photos(\.(tmp|old)-[0-9a-f]{12})?$/';
        foreach ($dirs as $d) {
            if (!preg_match($reStale, basename($d))) { continue; }   // ne cizí složka
            $rmTree($d, $base);
        }
        foreach (['mp4', 'mov', 'webm', 'm4v'] as $ve) { @unlink($base . '/' . $safe . '.' . $ve); }
        @unlink($base . '/' . $safe . '.code');

        // 2) Hotové snímky v eshopu vlastní `augi`; www-data tam mazat NESMÍ (@unlink by jen
        //    tiše selhal a e-shop by dál ukazoval 360° smazaného kusu). Necháme značku a
        //    úklid provede dispatcher, který pod augi běží z cronu.
        if (preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,63}$/', $code)) {
            @file_put_contents($base . '/' . $safe . '.delete', $code);
            @chmod($base . '/' . $safe . '.delete', 0664);
        } else {
            error_log('delete_product: 360° snímky pro kód ' . $code . ' nelze uklidit (kód mimo whitelist)');
        }
    }
}

try {
    $st = $pdo->prepare("SELECT id, product_code, title FROM products WHERE id = ?");
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Produkt nenalezen.']); exit;
    }
    // Pobočková pojistka: produkt smí smazat jen zaměstnanec JEHO pobočky (admin/Boss vždy).
    if (!crmCanModifyBranchStock(crmProductBranchId($id))) {
        echo json_encode(['success' => false, 'message' => 'Tento produkt patří jiné pobočce — smazat ho smí jen její zaměstnanci.']); exit;
    }
    // Kus držený nevyřízenou objednávkou z e-shopu se mazat nesmí: objednávku by
    // pak nešlo ani vyzvednout, ani zrušit (rezervace by ukazovala na nic).
    if (function_exists('afxProductReservationBlock')) {
        $resBlock = afxProductReservationBlock($id);
        if ($resBlock !== '') { echo json_encode(['success' => false, 'message' => $resBlock], JSON_UNESCAPED_UNICODE); exit; }
    }
    $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
    // 360° zdroje žijí JEN na disku (vazba = kód produktu, žádné pole v DB) — bez úklidu
    // by tu zůstala sada fotek v plném rozlišení (stovky MB) navždy a při novém naskladnění
    // téhož sériového čísla by se stará prohlídka tiše přilepila k jinému kusu.
    afxDeleteProduct360Sources((string)$p['product_code']);
    crmAuditLog('products.delete', [
        'entity_type' => 'products', 'entity_id' => (int)$p['id'], 'entity_label' => (string)$p['title'],
        'summary' => 'Smazán produkt „' . $p['title'] . '" (' . $p['product_code'] . ') ze skladu e-shopu',
    ]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('delete_product: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Smazání selhalo.']);
}
