<?php
/**
 * Rozpis služeb — zápis a mazání.
 *
 * Veškerá oprávnění (vidí na pobočku? smí cizí zápis? není po uzávěrce?) řeší
 * rozpis/lib.php, ne tenhle soubor — na endpoint se dá poslat cokoli a UI se dá
 * obejít, takže kontrola musí být na jednom místě u dat.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/lib.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

$reply = static function (bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(['success' => $ok, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['user_id']) && empty($_SESSION['tech_id'])) {
    http_response_code(403);
    $reply(false, __('unauthorized'));
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    $reply(false, 'Neplatný bezpečnostní token — načti stránku znovu.');
}

switch ((string)($_POST['action'] ?? '')) {
    case 'save': {
        $branchId = (int)($_POST['branch_id'] ?? 0);
        // Bez vybraného člověka se zapisuje přihlášený — běžný případ „zapíšu sám sebe".
        $techId = (int)($_POST['tech_id'] ?? 0) ?: afxShiftCurrentTechId();
        [$ok, $msg] = afxShiftSave(
            $branchId,
            $techId,
            (string)($_POST['work_date'] ?? ''),
            (string)($_POST['time_from'] ?? ''),
            (string)($_POST['time_to'] ?? ''),
            (string)($_POST['note'] ?? ''),
            (int)($_POST['entry_id'] ?? 0)      // 0 = nový zápis, jinak úprava existujícího
        );
        if ($ok && function_exists('crmAuditLog')) {
            crmAuditLog('settings.update', ['entity_type' => 'settings',
                'summary' => 'Rozpis služeb — ' . (string)($_POST['work_date'] ?? '')]);
        }
        $reply($ok, $msg);
    }

    case 'delete': {
        [$ok, $msg] = afxShiftDelete((int)($_POST['id'] ?? 0));
        $reply($ok, $msg);
    }
}

$reply(false, 'Neznámá akce.');
