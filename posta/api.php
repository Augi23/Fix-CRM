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
        // Vlastní složky chodí jako folder[ckey]; vestavěné mají i svoje sloupce,
        // ale do JSON se ukládají taky, ať je mapování na jednom místě.
        'folders_json' => (static function () use ($clean): string {
            $in = $_POST['folder'] ?? [];
            if (!is_array($in)) { return '[]'; }
            $out = [];
            foreach (crmMailCategoryMeta() as $k => $m) {
                if (!array_key_exists($k, $in)) { continue; }
                $out[$k] = $clean((string)$in[$k], (string)($m['folder'] ?: 'INBOX'));
            }
            return json_encode($out, JSON_UNESCAPED_UNICODE);
        })(),
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
                folder_customer=?, folder_offer=?, folder_robot=?, folders_json=?, use_ai=? WHERE id=?')
                ->execute([$acc['email'], $acc['imap_host'], $acc['imap_port'], $acc['imap_secure'], $acc['username'], $acc['password'],
                    $acc['folder_customer'], $acc['folder_offer'], $acc['folder_robot'], $acc['folders_json'], $acc['use_ai'], $id]);
        } else {
            $pdo->prepare('INSERT INTO mail_sort_accounts (email, imap_host, imap_port, imap_secure, username, password,
                folder_customer, folder_offer, folder_robot, folders_json, use_ai, enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,0)')
                ->execute([$acc['email'], $acc['imap_host'], $acc['imap_port'], $acc['imap_secure'], $acc['username'], $acc['password'],
                    $acc['folder_customer'], $acc['folder_offer'], $acc['folder_robot'], $acc['folders_json'], $acc['use_ai']]);
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

case 'save_category': {
    $key = strtolower(trim((string)($_POST['ckey'] ?? '')));
    $label = trim((string)($_POST['label'] ?? ''));
    $folder = trim(str_replace(['"', "\r", "\n", '*', '%'], '', (string)($_POST['folder'] ?? '')));
    if ($label === '') { $reply(false, 'Zadej název složky.'); }
    // klíč se odvodí z názvu (Účetnictví → ucetnictvi) a už se nikdy nemění —
    // visí na něm pravidla i historie v logu
    if ($key === '') {
        $tr = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string)$tr));
        $key = trim($key, '_');
    }
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $key)) { $reply(false, 'Z názvu nejde odvodit klíč — použij písmena a číslice.'); }
    $existing = crmMailCategoryMeta()[$key] ?? null;
    if ($existing && !empty($existing['builtin'])) {
        // vestavěné jdou přejmenovat, ale ne smazat ani překlíčovat
        $pdo->prepare('UPDATE mail_sort_categories SET label=?, folder_default=? WHERE ckey=?')
            ->execute([$label, $folder ?: (string)$existing['folder'], $key]);
        $reply(true, 'Složka upravena.');
    }
    $pdo->prepare('INSERT INTO mail_sort_categories (ckey, label, one_label, icon, color, folder_default, position, builtin)
        VALUES (?,?,?,?,?,?,?,0)
        ON DUPLICATE KEY UPDATE label=VALUES(label), folder_default=VALUES(folder_default),
                                icon=VALUES(icon), color=VALUES(color)')
        ->execute([$key, $label, $label,
            trim((string)($_POST['icon'] ?? '')) ?: 'fa-folder',
            trim((string)($_POST['color'] ?? '')) ?: '#8e8e93',
            $folder ?: $label, (int)($_POST['position'] ?? 100)]);
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Třídění pošty — složka ' . $label]);
    $reply(true, 'Složka uložena.', ['ckey' => $key]);
}

case 'delete_category': {
    $key = strtolower(trim((string)($_POST['ckey'] ?? '')));
    $meta = crmMailCategoryMeta()[$key] ?? null;
    if (!$meta) { $reply(false, 'Složka neexistuje.'); }
    if (!empty($meta['builtin'])) { $reply(false, 'Vestavěnou složku smazat nejde — jen přejmenovat.'); }
    // Pravidla na smazanou složku by tiše přestala fungovat, proto jdou pryč s ní.
    $n = (int)$pdo->query('SELECT COUNT(*) FROM mail_sort_rules WHERE category = ' . $pdo->quote($key))->fetchColumn();
    $pdo->prepare('DELETE FROM mail_sort_rules WHERE category = ?')->execute([$key]);
    $pdo->prepare('DELETE FROM mail_sort_categories WHERE ckey = ? AND builtin = 0')->execute([$key]);
    crmAuditLog('settings.update', ['entity_type' => 'settings', 'summary' => 'Třídění pošty — smazána složka ' . $meta['label']]);
    $reply(true, $n > 0 ? ('Složka smazána i s ' . $n . ' pravidly.') : 'Složka smazána.');
}

case 'add_rule': {
    $pattern = strtolower(trim((string)($_POST['pattern'] ?? '')));
    $cat = (string)($_POST['category'] ?? '');
    if (!crmMailCategoryExists($cat)) { $reply(false, 'Vyber složku.'); }
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
