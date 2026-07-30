<?php
/**
 * Banka — uložení nastavení KB API (jen administrátor).
 * Citlivé hodnoty (client_secret, refresh_token): prázdné pole = zachovat stávající.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || !crmCanManageSettings()) {
    echo json_encode(['success' => false, 'message' => 'Jen administrátor.']); exit;
}
if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => __('csrf_token_invalid')]); exit;
}

$plain = ['kb_env', 'kb_api_key_adaa', 'kb_api_key_oauth', 'kb_api_key_client_reg',
          'kb_client_id', 'kb_account_id', 'kb_base_url'];
$secret = ['kb_client_secret', 'kb_refresh_token'];

// PŘEPNUTÍ PROSTŘEDÍ = jiná banka: client_id, secret, refresh token i software statement
// ze sandboxu v produkci neplatí (a naopak). Kdyby zůstaly, tvářilo by se napojení jako
// hotové a synchronizace by padala na nesrozumitelné chyby — proto se zahodí.
$envSwitch = false;
if (isset($_POST['kb_env'])) {
    $newEnv = trim((string)$_POST['kb_env']) === 'prod' ? 'prod' : 'sandbox';
    $envSwitch = $newEnv !== (get_setting('kb_env', 'sandbox') === 'prod' ? 'prod' : 'sandbox');
}
if ($envSwitch) {
    foreach (['kb_client_id', 'kb_client_secret', 'kb_refresh_token', 'kb_software_statement',
              'kb_software_statement_at', 'kb_account_id', 'kb_last_sync_at'] as $k) {
        set_setting($k, '');
    }
}

foreach ($plain as $k) {
    if (isset($_POST[$k])) {
        $v = trim((string)$_POST[$k]);
        if ($k === 'kb_env') { $v = $v === 'prod' ? 'prod' : 'sandbox'; }
        set_setting($k, mb_substr($v, 0, 4000));
    }
}
foreach ($secret as $k) {
    $v = trim((string)($_POST[$k] ?? ''));
    if ($v !== '') { set_setting($k, mb_substr($v, 0, 4000)); }   // prázdné = beze změny
}
// změna přihlášení = zahodit cache tokenu
set_setting('kb_access_token', '');
set_setting('kb_access_token_expires', '0');

crmAuditLog('settings.update', [
    'entity_type' => 'settings', 'entity_label' => 'Banka (KB)',
    'summary' => 'Změněno nastavení napojení na KB API (' . get_setting('kb_env', 'sandbox') . ')',
]);
echo json_encode(['success' => true, 'env_switched' => $envSwitch,
    'message' => $envSwitch
        ? 'Uloženo. Prostředí se změnilo, takže přihlašovací údaje k bance byly vymazány — napojení projdi znovu (kroky 1 a 2).'
        : 'Uloženo.'], JSON_UNESCAPED_UNICODE);
