<?php
/**
 * Banka — synchronizace pohybů z KB (ADAA) + auto-párování faktur.
 *   action=sync (default) — stáhne nové pohyby (throttle 61 min; admin může force=1)
 *   action=test           — ověří spojení a vrátí seznam účtů (pro Nastavení → Banka)
 * KB účtuje podle frekvence dotazů — throttle drží tarif „do 50 volání zdarma / 100 Kč".
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/kb_api.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    echo json_encode(['success' => false, 'message' => __('unauthorized')]); exit;
}
if (!crmCanManageInvoices()) {
    echo json_encode(['success' => false, 'message' => 'Banka je jen pro vedení (admin, Boss).']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}
if (!kbApiConfigured()) {
    echo json_encode(['success' => false, 'message' => 'KB API není nastavené — Nastavení → Banka.']); exit;
}

$action = (string)($_POST['action'] ?? 'sync');

try {
    if ($action === 'test') {
        $accounts = kbAdaaGet('/accounts');
        $list = [];
        foreach (($accounts['content'] ?? $accounts) as $a) {
            if (!is_array($a)) continue;
            $list[] = [
                'accountId' => (string)($a['accountId'] ?? $a['id'] ?? ''),
                'iban' => (string)($a['iban'] ?? ''),
                'currency' => (string)($a['currency'] ?? 'CZK'),
            ];
        }
        echo json_encode(['success' => true, 'accounts' => $list], JSON_UNESCAPED_UNICODE); exit;
    }

    // throttle: KB tarif — min. 61 minut mezi syncy (admin smí vynutit)
    $last = (string)get_setting('kb_last_sync_at', '');
    $force = !empty($_POST['force']) && crmCanManageSettings();
    if (!$force && $last !== '' && (time() - strtotime($last)) < 61 * 60) {
        $wait = (int)ceil((61 * 60 - (time() - strtotime($last))) / 60);
        echo json_encode(['success' => false, 'throttled' => true,
            'message' => 'Poslední synchronizace proběhla před chvílí — další za ~' . $wait . ' min (šetří tarif KB API).']); exit;
    }

    $res = kbSyncTransactions();
    crmAuditLog('banka.sync', [
        'entity_type' => 'bank', 'entity_label' => 'KB',
        'summary' => 'Synchronizace banky: ' . $res['new'] . ' nových pohybů, '
            . $res['matched'] . ' faktur automaticky zaplaceno, ' . $res['review'] . ' k prověření',
    ]);
    echo json_encode(['success' => true] + $res, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('kb_sync: ' . $e->getMessage());
    $msg = ($e instanceof PDOException)
        ? 'Databázová chyba — synchronizace neproběhla.'
        : $e->getMessage();   // KB chyby jsou už v helperu ořezané a bez citlivého obsahu
    echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
}
