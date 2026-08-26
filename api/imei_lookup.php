<?php
/** Doplnění údajů o zařízení podle IMEI (naskladnění produktu, v3.61.0).
 *  POST { imei, csrf_token } → { ok, info:{…}, brand, error, source }
 *  Dotaz je placený → odpověď se cachuje, viz includes/imei_info.php. */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/imei_info.php';
require_once '../includes/rate_limit.php';
if (ob_get_length()) { ob_clean(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => __('unauthorized')], JSON_UNESCAPED_UNICODE); exit;
}
// stejné právo jako naskladnění — kredit u iFreeiCloud stojí peníze
if (!function_exists('crmCanManageProducts') || !crmCanManageProducts()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Nemáš oprávnění naskladňovat produkty.'], JSON_UNESCAPED_UNICODE); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => __('csrf_token_invalid')], JSON_UNESCAPED_UNICODE); exit;
}

// strop na počet dotazů: každý neopakovaný dotaz stojí kredit, takže ani
// omylem spuštěná smyčka nesmí vysát účet (naskladnění je práce v řádu kusů/hod)
checkApiRateLimit('imei_lookup', 60, 3600);

$res = afxImeiInfoLookup((string)($_POST['imei'] ?? ''));
echo json_encode([
    'ok' => $res['ok'],
    'source' => $res['source'],
    'brand' => $res['brand'],
    'error' => $res['error'],
    'info' => $res['info'],
    'checked_at' => $res['checked_at'] ?? '',
], JSON_UNESCAPED_UNICODE);
