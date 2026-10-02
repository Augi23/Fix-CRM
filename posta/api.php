<?php
/**
 * Třídění pošty — akce ze stránky trideni-posty.php (jen administrátor).
 * Hesla ke schránkám se nikdy nevrací do prohlížeče; prázdné pole = ponechat uložené.
 */
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/lib.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');

$reply = static function (bool $ok, string $message = '', array $extra = []): void {
    echo json_encode(['success' => $ok, 'message' => $message] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
};

if (empty($_SESSION['user_id']) || !crmCanManageSettings()) {
    http_response_code(403);
    $reply(false, 'Jen administrátor.');
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    $reply(false, __('csrf_token_invalid'));
}

@set_time_limit(180);
crmMailEnsureSchema();
$action = (string)($_POST['action'] ?? '');
$id = (int)($_POST['id'] ?? 0);

/** Údaje schránky z formuláře (heslo: prázdné = uložené). */
$accountFromPost = static function (?array $existing): array {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $secure = in_array($_POST['imap_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? (string)$_POST['imap_secure'] : 'ssl';
    $pass = (string)($_POST['password'] ?? '');
    $clean = static fn(string $f, string $def): string => trim(str_replace(['"', "\r", "\n", '*', '%'], '', $f)) ?: $def;
    return [
        'id' => $existing['id'] ?? 0,
        'email' => $email,
        'imap_host' => trim((string)($_POST['imap_host'] ?? '')) ?: 'imap.forpsi.com',
        'imap_port' => max(1, (int)($_POST['imap_port'] ?? 993)),
        'imap_secure' => $secure,
        'username' => trim((string)($_POST['username'] ?? '')) ?: $email,
        'password' => $pass !== '' ? $pass : (string)($existing['password'] ?? ''),
        'folder_customer' => $clean((string)($_POST['folder_customer'] ?? ''), 'INBOX'),
        'folder_offer' => $clean((string)($_POST['folder_offer'] ?? ''), 'Nabídky'),
        'folder_robot' => $clean((string)($_POST['folder_robot'] ?? ''), 'Roboti'),
        'use_ai' => !empty($_POST['use_ai']) ? 1 : 0,
    ];
};

switch ($action) {

case 'test': {
    $acc = $accountFromPost($id ? crmMailAccount($id) : null);
    if (!filter_var($acc['email'], FILTER_VALIDATE_EMAIL)) { $reply(false, 'Zadej platný e-mail.'); }
    if ($acc['password'] === '') { $reply(false, 'Zadej heslo ke schránce.'); }
    try {
        $imap = crmMailConnect($acc);
        $box = $imap->select('INBOX', true);
        $info = 'Spojení funguje — zpráv v Doručené poště: ' . $box['exists'] . '. '
            . 'Složky se založí jako „' . $imap->folderPath($acc['folder_offer']) . '" a „' . $imap->folderPath($acc['folder_robot']) . '".';
        $imap->logout();
        $reply(true, $info);
    } catch (Throwable $e) {
        $reply(false, $e->getMessage());
    }
}

case 'save': {
    $existing = $id ? crmMailAccount($id) : null;
    if ($id && !$existing) { $reply(false, 'Schránka nenalezena.'); }
    $acc = $accountFromPost($existing);
    if (!filter_var($acc['email'], FILTER_VALIDATE_EMAIL)) { $reply(false, 'Zadej platný e-mail.'); }
    if ($acc['password'] === '') { $reply(false, 'Zadej heslo ke schránce.'); }
    try {
        if ($existing) {
            $pdo->prepare('UPDATE mail_sort_accounts SET email=?, imap_host=?, imap_port=?, imap_secure=?, username=?, password=?,
                folder_customer=?, folder_offer=?, folder_robot=?, use_ai=? WHERE id=?')
                ->execute([$acc['email'], $acc['imap_host'], $acc['imap_port'], $acc['imap_secure'], $acc['username'], $acc['password'],
                    $acc['folder_customer'], $acc['folder_offer'], $acc['folder_robot'], $acc['use_ai'], $id]);
        } else {
            $pdo->prepare('INSERT INTO mail_sort_accounts (email, imap_host, imap_port, imap_secure, username, password,
                folder_customer, folder_offer, folder_robot, use_ai, enabled) VALUES (?,?,?,?,?,?,?,?,?,?,0)')
                ->execute([$acc['email'], $acc['imap_host'], $acc['imap_port'], $acc['imap_secure'], $acc['username'], $acc['password'],
                    $acc['folder_customer'], $acc['folder_offer'], $acc['folder_robot'], $acc['use_ai']]);
            $id = (int)$pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        $reply(false, str_contains($e->getMessage(), 'Duplicate') ? 'Tahle schránka už v třídění je.' : 'Uložení selhalo.');
    }
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Třídění pošty — uložena schránka ' . $acc['email']]);
    $reply(true, 'Uloženo.', ['id' => $id]);
}

case 'delete': {
    $acc = crmMailAccount($id);
    if (!$acc) { $reply(false, 'Schránka nenalezena.'); }
    $pdo->prepare('DELETE FROM mail_sort_log WHERE account_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM mail_sort_accounts WHERE id = ?')->execute([$id]);
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Třídění pošty — odebrána schránka ' . $acc['email']]);
    $reply(true, 'Schránka odebrána z třídění. Složky a pošta na serveru zůstávají.');
}

case 'preview': {
    $acc = crmMailAccount($id);
    if (!$acc) { $reply(false, 'Schránka nenalezena.'); }
    try {
        $rows = crmMailPreview($acc, 30);
        $reply(true, '', ['rows' => $rows]);
    } catch (Throwable $e) {
        $reply(false, $e->getMessage());
    }
}

case 'enable': {
    $acc = crmMailAccount($id);
    if (!$acc) { $reply(false, 'Schránka nenalezena.'); }
    $on = !empty($_POST['enabled']);
    if ($on) {
        $days = (int)($_POST['backfill_days'] ?? 0);
        $days = in_array($days, [0, 1, 7, 30, 90], true) ? $days : 0;
        // znovu od začátku: dotřídí poštu za zvolené dny, pak už jen nové
        $pdo->prepare('UPDATE mail_sort_accounts SET enabled = 1, last_uid = 0, uidvalidity = 0, backfill_days = ? WHERE id = ?')->execute([$days, $id]);
        $acc = crmMailAccount($id);
        $r = crmMailSortAccount($acc, ['limit' => 300]);
        crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Třídění pošty — zapnuto pro ' . $acc['email'] . ($days ? ' (dotřídění ' . $days . ' dní)' : '')]);
        if ($r['error'] !== null) { $reply(false, 'Zapnuto, ale první běh selhal: ' . $r['error']); }
        $reply(true, $r['processed'] > 0 ? 'Zapnuto — hned roztříděno ' . $r['processed'] . ' zpráv, přesunuto ' . $r['moved'] . '.' : 'Zapnuto — od teď se třídí každá nová zpráva.');
    }
    $pdo->prepare('UPDATE mail_sort_accounts SET enabled = 0 WHERE id = ?')->execute([$id]);
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Třídění pošty — vypnuto pro ' . $acc['email']]);
    $reply(true, 'Třídění vypnuto.');
}

case 'run': {
    $acc = crmMailAccount($id);
    if (!$acc) { $reply(false, 'Schránka nenalezena.'); }
    if (!(int)$acc['enabled']) { $reply(false, 'Nejdřív třídění zapni.'); }
    $r = crmMailSortAccount($acc);
    if ($r['error'] !== null) { $reply(false, $r['error']); }
    $reply(true, $r['processed'] > 0 ? 'Roztříděno ' . $r['processed'] . ' zpráv, přesunuto ' . $r['moved'] . '.' : 'Žádná nová pošta.');
}

case 'reclassify': {
    $cat = (string)($_POST['category'] ?? '');
    $learn = in_array($_POST['learn'] ?? '', ['sender', 'domain', 'none'], true) ? (string)$_POST['learn'] : 'sender';
    [$ok, $msg] = crmMailReclassify((int)($_POST['log_id'] ?? 0), $cat, $learn);
    $reply($ok, $msg);
}

case 'add_rule': {
    $pattern = strtolower(trim((string)($_POST['pattern'] ?? '')));
    $cat = (string)($_POST['category'] ?? '');
    if (!in_array($cat, CRM_MAIL_CATEGORIES, true)) { $reply(false, 'Vyber kategorii.'); }
    if (str_starts_with($pattern, '@')) {
        $dom = substr($pattern, 1);
        if (!preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/', $dom)) { $reply(false, 'Neplatná doména.'); }
        if (crmMailDomainIn($dom, crmMailFreemailDomains())) { $reply(false, 'Na osobní domény (gmail, seznam…) nejde dát pravidlo — píšou z nich zákazníci. Zadej celou adresu.'); }
    } elseif (!filter_var($pattern, FILTER_VALIDATE_EMAIL)) {
        $reply(false, 'Zadej e-mail (jan@firma.cz) nebo doménu (@firma.cz).');
    }
    crmMailSaveRule($pattern, $cat);
    $reply(true, 'Pravidlo uloženo.');
}

case 'delete_rule': {
    $pdo->prepare('DELETE FROM mail_sort_rules WHERE id = ?')->execute([(int)($_POST['rule_id'] ?? 0)]);
    $reply(true, 'Pravidlo smazáno.');
}

default:
    $reply(false, 'Neznámá akce.');
}
