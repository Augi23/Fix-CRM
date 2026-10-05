<?php
/**
 * Chytrá upozornění — akce stránky Upozornění.
 *
 *  Každý přihlášený:  prefs (osobní nastavení), test (zkušební upozornění),
 *                     read (označit přečtené)
 *  Jen vedení:        config (pravidla a kanály), run (kontrola teď / nanečisto),
 *                     token (nový klíč pro externí cron)
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
afxNotifyEnsureSchema();
$canManage = function_exists('crmCanManageSettings') && crmCanManageSettings();
$myKey = afxNotifyMyKey();

switch ((string)($_POST['action'] ?? '')) {
    case 'prefs': {
        if ($myKey === '') { $reply(false, 'Účet bez vazby na zaměstnance.'); }
        [$ok, $msg] = afxNotifySavePrefs($myKey, [
            'reminder_minutes' => (string)($_POST['reminder_minutes'] ?? ''),
            'evening_before'   => $_POST['evening_before'] ?? 0,
            'ch_telegram'      => $_POST['ch_telegram'] ?? 0,
            'ch_push'          => $_POST['ch_push'] ?? 0,
            'ch_email'         => $_POST['ch_email'] ?? 0,
            'ch_sms'           => $_POST['ch_sms'] ?? 0,
            'muted'            => (array)($_POST['muted'] ?? []),
        ]);
        $reply($ok, $msg);
    }

    case 'test': {
        $r = afxNotifyRecipient($myKey) ?? afxNotifyRecipient((string)crmStaffKey());
        if (!$r) { $reply(false, 'Pro tvůj účet nenacházím kontakt — chybí vazba na zaměstnance.'); }
        // test jde mimo ztlumení a deduplikaci (jedinečný klíč)
        $ok = afxNotifyDeliver($r, 'test', bin2hex(random_bytes(6)), 'Zkušební upozornění',
            "Takhle ti budou chodit upozornění z CRM.\nKanály, předstih a co chceš dostávat si nastavíš na stránce **Upozornění**.",
            ['url' => 'upozorneni.php', 'force_channels' => true]);
        $chan = '';
        try {
            $st = $pdo->prepare("SELECT channels, error FROM smart_notify_log WHERE recipient_key = ? AND rule = 'test' ORDER BY id DESC LIMIT 1");
            $st->execute([$r['key']]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $map = ['crm' => 'CRM', 'telegram' => 'Telegram', 'push' => 'appka (push)', 'email' => 'e-mail', 'sms' => 'SMS'];
            $chan = implode(', ', array_map(static fn($c) => $map[$c] ?? $c, array_filter(explode(',', (string)($row['channels'] ?? '')))));
            if (!empty($row['error'])) { $chan .= ' — chyba: ' . $row['error']; }
        } catch (Throwable $e) { /* jen informace */ }
        $reply($ok, $ok ? ('Odesláno: ' . ($chan ?: 'CRM')) : 'Nepodařilo se odeslat.');
    }

    case 'read': {
        afxNotifyMarkRead(afxNotifyMyKeys(), (int)($_POST['id'] ?? 0));
        $reply(true);
    }

    case 'config': {
        if (!$canManage) { http_response_code(403); $reply(false, 'Nastavení upozornění mění jen vedení.'); }
        $in = json_decode((string)($_POST['config'] ?? ''), true);
        if (!is_array($in)) { $reply(false, 'Neplatná data.'); }
        [$ok, $msg] = afxNotifySaveConfig($in);
        if ($ok && function_exists('crmAuditLog')) {
            crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Chytrá upozornění — nastavení']);
        }
        $reply($ok, $msg);
    }

    case 'run': {
        if (!$canManage) { http_response_code(403); $reply(false, 'Jen pro vedení.'); }
        $dry = afxNotifyBool($_POST['dry'] ?? 1);
        @set_time_limit(120);
        if (!$dry) { set_setting('smart_notify_last_trigger', 'ručně'); }
        $res = afxNotifyRun(null, $dry);
        if (isset($res['skipped'])) { $reply(false, $res['skipped']); }
        $reply(true, ($dry ? 'Nanečisto: ' : 'Odesláno: ') . $res['sent'] . ' upozornění', ['items' => $res['items'], 'counts' => $res['counts']]);
    }

    case 'token': {
        if (!$canManage) { http_response_code(403); $reply(false, 'Jen pro vedení.'); }
        $reply(true, 'Vygenerován nový klíč — starý přestal platit.', ['token' => afxNotifyTickToken(true)]);
    }
}

$reply(false, 'Neznámá akce.');
