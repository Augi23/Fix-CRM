<?php
/**
 * BANKA — zpracování NÁVRATU Z BANKY (jeden kód pro obě adresy).
 *
 * Proč společně: KB se vrací buď na `registrationBackUri` (ze software statementu),
 * nebo — když statement není, což je v sandboxu běžné — přímo na `redirect_uri`
 * z požadavku. Rozlišovat to podle adresy nejde, takže se rozhoduje podle OBSAHU:
 *   • přišel salt + encryptedData  → odpověď na REGISTRACI APLIKACE (client_id/secret)
 *   • přišel code                  → odpověď na AUTORIZACI ÚČTU (výměna za tokeny)
 *
 * Přesně tohle napojení dvakrát rozbilo: banka poslala výsledek registrace na adresu
 * pro autorizaci, ta ho nepoznala a zahodila ho bez jediného záznamu.
 */

/** Vytáhne parametr bez ohledu na velikost písmen a na to, jak mu banka říká. */
function kbPickParam(array $in, array $names): string {
    foreach ($in as $k => $v) {
        if (is_string($v) && in_array(strtolower((string)$k), $names, true)) { return $v; }
    }
    return '';
}

/** Popis toho, co přišlo — jen názvy a délky, NIKDY hodnoty (jsou v nich tajné údaje). */
function kbDescribeParams(array $in): string {
    $out = [];
    foreach ($in as $k => $v) { $out[] = $k . '(' . (is_string($v) ? strlen($v) : '?') . ')'; }
    return $out ? implode(', ', $out) : 'BEZ PARAMETRŮ';
}

/**
 * Zpracuje návrat z banky a přesměruje do Nastavení → Banka s výsledkem.
 * Vždy skončí přesměrováním (funkce se nevrací).
 */
function kbHandleBankReturn(string $back): void {
    $in = array_merge($_GET, $_POST);
    $popis = kbDescribeParams($in);

    // ZÁZNAM VŽDY a jako první — ať je i případné selhání dohledatelné
    crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
        'summary' => 'Návrat z banky na ' . basename((string)($_SERVER['SCRIPT_NAME'] ?? '?'))
            . ' (' . (string)($_SERVER['REQUEST_METHOD'] ?? '?') . '): ' . $popis]);

    $fail = static function (string $msg) use ($back): void {
        // hláška do session I do nastavení: při návratu POSTem z cizí domény prohlížeč
        // nepošle přihlašovací cookie a session hláška by se ztratila
        $_SESSION['kb_connect_error'] = $msg;
        set_setting('kb_connect_msg', 'CHYBA: ' . $msg);
        set_setting('kb_connect_msg_at', date('Y-m-d H:i:s'));
        header('Location: ' . $back . '&kb=error');
        exit;
    };
    $done = static function (string $msg, string $tag) use ($back): void {
        $_SESSION['kb_connect_ok'] = $msg;
        set_setting('kb_connect_msg', $msg);
        set_setting('kb_connect_msg_at', date('Y-m-d H:i:s'));
        header('Location: ' . $back . '&kb=' . $tag);
        exit;
    };

    if ($err = kbPickParam($in, ['error'])) {
        $fail('Banka hlásí chybu: ' . htmlspecialchars($err . ' '
            . kbPickParam($in, ['error_description', 'errordescription']), ENT_QUOTES, 'UTF-8'));
    }

    $state = kbPickParam($in, ['state', 'relaystate']);
    $salt  = kbPickParam($in, ['salt', 'iv', 'initializationvector']);
    $enc   = kbPickParam($in, ['encrypteddata', 'encrypted_data', 'data', 'response']);
    $code  = kbPickParam($in, ['code', 'authorization_code', 'authorizationcode']);

    // ── A) odpověď na REGISTRACI APLIKACE ─────────────────────────────────────
    if ($salt !== '' && $enc !== '') {
        $expected = (string)get_setting('kb_reg_state', '');
        if ($state !== '' && $expected !== '' && !hash_equals($expected, $state)) {
            $fail('Návrat z registrace nesouhlasí s požadavkem, který CRM odeslalo (state). Spusť registraci znovu.');
        }
        $reg = kbDecryptRegistration($salt, $enc);
        if (!$reg) {
            $fail('Zašifrovanou odpověď banky se nepodařilo rozšifrovat — šifrovací klíč se musel '
                . 'mezi odesláním a návratem změnit. Spusť registraci aplikace znovu.');
        }
        if (empty($reg['client_id']) || empty($reg['client_secret'])) {
            $fail('V odpovědi banky chybí client_id nebo client_secret. Obsahovala: '
                . implode(', ', array_slice(array_keys($reg), 0, 12)));
        }
        set_setting('kb_reg_state', '');
        set_setting('kb_client_id', (string)$reg['client_id']);
        set_setting('kb_client_secret', (string)$reg['client_secret']);
        // nová registrace = jiná aplikace → starý token neplatí
        set_setting('kb_refresh_token', '');
        set_setting('kb_access_token', '');
        set_setting('kb_access_token_expires', '0');
        crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
            'summary' => 'Aplikace zaregistrována u KB (' . kbApiEnv() . ') — client_id '
                . (string)$reg['client_id'] . ($state === '' ? ' (banka nevrátila state; pravost potvrdilo dešifrování)' : '')]);
        $done('Aplikace je u banky zaregistrovaná (client_id ' . (string)$reg['client_id']
            . '). Pokračuj tlačítkem „2. Autorizovat přístup k účtu".', 'registered');
    }

    // ── B) odpověď na AUTORIZACI ÚČTU ─────────────────────────────────────────
    if ($code !== '') {
        $expected = (string)get_setting('kb_auth_state', '');
        if ($state !== '' && $expected !== '' && !hash_equals($expected, $state)) {
            $fail('Návrat z autorizace nesouhlasí s požadavkem, který CRM odeslalo (state). Spusť autorizaci znovu.');
        }
        set_setting('kb_auth_state', '');
        try {
            kbExchangeAuthCode($code);
        } catch (Throwable $e) {
            $fail($e->getMessage());
        }

        $accounts = [];
        try {
            $res = kbAdaaGet('/accounts');
            foreach (($res['content'] ?? $res) as $a) {
                if (!is_array($a)) { continue; }
                $accounts[] = ['accountId' => (string)($a['accountId'] ?? $a['id'] ?? ''),
                               'iban' => (string)($a['iban'] ?? ''),
                               'currency' => (string)($a['currency'] ?? 'CZK')];
            }
        } catch (Throwable $e) {
            set_setting('kb_connect_msg', 'Tokeny jsou uložené, ale seznam účtů se nepodařilo stáhnout: '
                . $e->getMessage() . ' Zkus v Nastavení „Načíst účty / otestovat".');
            set_setting('kb_connect_msg_at', date('Y-m-d H:i:s'));
        }
        if (count($accounts) === 1 && $accounts[0]['accountId'] !== '') {
            set_setting('kb_account_id', $accounts[0]['accountId']);
        }
        $_SESSION['kb_accounts'] = $accounts;

        crmAuditLog('banka.napojeni', ['entity_type' => 'bank', 'entity_label' => 'KB',
            'summary' => 'Přístup k účtu autorizován (' . kbApiEnv() . ') — CRM má refresh token (12 měsíců)'
                . (count($accounts) === 1 ? ', účet ' . ($accounts[0]['iban'] ?: $accounts[0]['accountId']) : '')]);
        $done('Napojení na banku je hotové — CRM má refresh token (platí 12 měsíců). '
            . (count($accounts) > 1 ? 'Vyber níže účet, který má CRM sledovat.'
                                    : 'Zkus Synchronizovat v modulu Banka.'), 'connected');
    }

    // ── C) nic použitelného ───────────────────────────────────────────────────
    $fail('Banka se vrátila, ale bez použitelných údajů. Přišlo: ' . $popis
        . '. Když se to opakuje, pošli tento výpis na api@kb.cz — obsahuje jen názvy parametrů, ne jejich obsah.');
}
