<?php
/**
 * BANKA — návrat z AUTORIZACE ÚČTU u KB (redirect_uri).
 *
 * Jednatel potvrdil rozsah přístupu a vybral účty; KB nás sem poslala zpět
 * s authorization code (platí jen 2 minuty). Kód hned vyměníme za refresh token
 * (platí 12 měsíců) a access token, uložíme je a stáhneme seznam účtů, aby si
 * obsluha v Nastavení rovnou vybrala, který účet CRM sleduje.
 *
 * Refresh token je nejcennější údaj celého napojení — nikde se nevypisuje ani
 * neloguje, ukládá se přímo do nastavení CRM.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/kb_api.php';

/* Stejný důvod jako u registrace: návrat z banky je požadavek z jiné domény, takže se
   přihlašovací cookie nemusí poslat. Stačí, že autorizace právě probíhá (uložený state);
   samotný kód je bez našeho client_secret k ničemu a vyměnit se dá jen pro naši aplikaci. */
$__prihlaseny = (!empty($_SESSION['user_id']) || !empty($_SESSION['tech_id'])) && crmCanManageSettings();
$__probiha = trim((string)get_setting('kb_auth_state', '')) !== '';
if (!$__prihlaseny && !$__probiha) {
    header('Location: ../login.php');
    exit;
}

$back = '../settings.php?tab=banka';
$in = array_merge($_GET, $_POST);   // banka může vrátit parametry GETem i POSTem
$fail = function (string $msg) use ($back): void {
    // do session I do nastavení: když se banka vrátila POSTem, prohlížeč nemusel poslat
    // přihlašovací cookie a session hláška by se ztratila (uživatel by nevěděl proč)
    $_SESSION['kb_connect_error'] = $msg;
    set_setting('kb_connect_msg', 'CHYBA: ' . $msg);
    set_setting('kb_connect_msg_at', date('Y-m-d H:i:s'));
    header('Location: ' . $back . '&kb=error');
    exit;
};

// banka může vrátit i chybu místo kódu
// co přišlo, zapsat do historie — aby se případná chyba dala dohledat bez hádání
$__popis = [];
foreach ($in as $k => $v) { $__popis[] = $k . '(' . (is_string($v) ? strlen($v) : '?') . ')'; }
crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
    'summary' => 'Návrat z autorizace účtu (' . $_SERVER['REQUEST_METHOD'] . '): '
        . ($__popis ? implode(', ', $__popis) : 'BEZ PARAMETRŮ')]);

if (!empty($in['error'])) {
    $fail('Banka autorizaci nedokončila: ' . htmlspecialchars((string)$in['error']
        . ' ' . (string)($in['error_description'] ?? ''), ENT_QUOTES, 'UTF-8'));
}

$state = (string)($in['state'] ?? '');
$expected = (string)get_setting('kb_auth_state', '');
if ($state !== '' && $expected !== '' && !hash_equals($expected, $state)) {
    $fail('Návrat z banky nesouhlasí s požadavkem, který CRM odeslalo (neplatný state). Spusť autorizaci znovu.');
}

$code = (string)($in['code'] ?? '');
if ($code === '') {
    $fail('Banka se vrátila bez autorizačního kódu. Přišlo: '
        . ($__popis ? implode(', ', $__popis) : 'nic') . '. Spusť autorizaci znovu.');
}
set_setting('kb_auth_state', '');   // až teď, po ověření obsahu

try {
    kbExchangeAuthCode($code);
} catch (Throwable $e) {
    $fail($e->getMessage());
}

// hned zkusit seznam účtů — obsluha si pak jen vybere, který CRM sleduje
$accounts = [];
try {
    $res = kbAdaaGet('/accounts');
    foreach (($res['content'] ?? $res) as $a) {
        if (!is_array($a)) { continue; }
        $accounts[] = [
            'accountId' => (string)($a['accountId'] ?? $a['id'] ?? ''),
            'iban' => (string)($a['iban'] ?? ''),
            'currency' => (string)($a['currency'] ?? 'CZK'),
        ];
    }
} catch (Throwable $e) {
    $_SESSION['kb_connect_error'] = 'Tokeny jsou uložené, ale seznam účtů se nepodařilo stáhnout ('
        . $e->getMessage() . '). Zkus v Nastavení tlačítko „Otestovat spojení".';
}

// jediný účet vybrat sám — u víc účtů necháme volbu na člověku
if (count($accounts) === 1 && $accounts[0]['accountId'] !== '') {
    set_setting('kb_account_id', $accounts[0]['accountId']);
}
$_SESSION['kb_accounts'] = $accounts;

crmAuditLog('banka.napojeni', [
    'entity_type' => 'bank', 'entity_label' => 'KB',
    'summary' => 'Přístup k účtu autorizován (' . kbApiEnv() . ') — CRM získalo refresh token (platí 12 měsíců)'
        . (count($accounts) === 1 ? ', vybraný účet ' . ($accounts[0]['iban'] ?: $accounts[0]['accountId']) : '')
        . '. Souhlas je potřeba obnovit do 12 měsíců.',
]);

set_setting('kb_connect_msg_at', date('Y-m-d H:i:s'));
$__ok = 'Napojení na banku je hotové — CRM má refresh token (platí 12 měsíců). '
    . (count($accounts) > 1 ? 'Vyber níže účet, který má CRM sledovat.' : 'Můžeš rovnou zkusit Synchronizovat v modulu Banka.');
$_SESSION['kb_connect_ok'] = $__ok;
set_setting('kb_connect_msg', $__ok);
header('Location: ' . $back . '&kb=connected');
