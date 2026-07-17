<?php
/**
 * BANKA — klient KB API (Komerční banka, developers.kb.cz) pro modul Banka.
 * ADAA v2 (pohyby, zůstatky) přes OAuth2 refresh token + API klíč z portálu.
 * Money-S3 styl: pohyby se stahují do lokální tabulky bank_transactions a
 * příchozí platby se automaticky párují s fakturami podle VS + částky.
 *
 * Nastavení (system_settings, spravuje Nastavení → Banka):
 *   kb_env (sandbox|prod), kb_api_key_adaa, kb_api_key_oauth,
 *   kb_client_id, kb_client_secret, kb_refresh_token, kb_account_id,
 *   kb_access_token (+_expires) — cache, kb_last_sync_at, kb_sync_from
 */

function kbApiEnv(): string {
    return get_setting('kb_env', 'sandbox') === 'prod' ? 'prod' : 'sandbox';
}

function kbApiConfigured(): bool {
    return get_setting('kb_client_id', '') !== ''
        && get_setting('kb_client_secret', '') !== ''
        && get_setting('kb_refresh_token', '') !== ''
        && get_setting('kb_api_key_adaa', '') !== ''
        && get_setting('kb_api_key_oauth', '') !== '';
}

function kbOauthBase(): string {
    return kbApiEnv() === 'prod'
        ? 'https://api-gateway.kb.cz/oauth2/v3'
        : 'https://api-gateway.kb.cz/sandbox/oauth2/v3';
}

function kbAdaaBase(): string {
    return kbApiEnv() === 'prod'
        ? 'https://api-gateway.kb.cz/adaa/v2'
        : 'https://api-gateway.kb.cz/sandbox/adaa/v2';
}

/** HTTP volání s hlavičkami KB (curl, JSON). Vrací [ok, httpCode, dataOrNull, rawBody]. */
function kbHttp(string $method, string $url, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body; }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) { return [false, 0, null, $err]; }
    $data = json_decode((string)$raw, true);
    return [$code >= 200 && $code < 300, $code, $data, (string)$raw];
}

/** Access token — obnova refresh tokenem (client_secret_post).
 *  POZOR: get_setting má statickou per-request cache, kterou set_setting NEinvaliduje —
 *  čerstvý token i případný rotovaný refresh token se proto drží ve statických
 *  proměnných TÉTO funkce, jinak by každá stránka syncu dělala nový OAuth exchange
 *  se STARÝM refresh tokenem (a při rotaci tokenů zabila celý grant). */
function kbAccessToken(bool $forceRefresh = false): string {
    static $freshToken = null, $freshExp = 0, $freshRefresh = null;
    if (!$forceRefresh && $freshToken !== null && $freshExp > time() + 20) { return $freshToken; }

    $cached = (string)get_setting('kb_access_token', '');
    $exp = (int)get_setting('kb_access_token_expires', '0');
    if (!$forceRefresh && $freshToken === null && $cached !== '' && $exp > time() + 20) {
        $freshToken = $cached;
        $freshExp = $exp;
        return $cached;
    }

    [$ok, $code, $data, $raw] = kbHttp('POST', kbOauthBase() . '/access_token',
        ['Content-Type: application/x-www-form-urlencoded', 'apiKey: ' . get_setting('kb_api_key_oauth', '')],
        http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $freshRefresh ?? get_setting('kb_refresh_token', ''),
            'client_id' => get_setting('kb_client_id', ''),
            'client_secret' => get_setting('kb_client_secret', ''),
        ]));
    if (!$ok || empty($data['access_token'])) {
        throw new Exception('KB: obnova přístupového tokenu selhala (HTTP ' . $code . '). '
            . 'Zkontroluj client_id/secret a platnost refresh tokenu (obnovuje se 1× ročně). '
            . mb_substr(preg_replace('/[^\x20-\x7E]+/', ' ', (string)$raw), 0, 160));
    }
    $freshToken = (string)$data['access_token'];
    $freshExp = time() + (int)($data['expires_in'] ?? 180);
    set_setting('kb_access_token', $freshToken);
    set_setting('kb_access_token_expires', (string)$freshExp);
    if (!empty($data['refresh_token'])) {
        $freshRefresh = (string)$data['refresh_token'];
        set_setting('kb_refresh_token', $freshRefresh);
    }
    return $freshToken;
}

/** GET na ADAA s auth hlavičkami; při 401 jednou obnoví token a zkusí znovu. */
function kbAdaaGet(string $path, array $query = []): array {
    $url = kbAdaaBase() . $path . ($query ? ('?' . http_build_query($query)) : '');
    $mk = static fn(string $token): array => [
        'apiKey: ' . get_setting('kb_api_key_adaa', ''),
        'Authorization: Bearer ' . $token,
        'x-correlation-id: ' . bin2hex(random_bytes(16)),
        'Accept: application/json',
    ];
    [$ok, $code, $data, $raw] = kbHttp('GET', $url, $mk(kbAccessToken()));
    if ($code === 401) {
        [$ok, $code, $data, $raw] = kbHttp('GET', $url, $mk(kbAccessToken(true)));
    }
    if (!$ok) {
        throw new Exception('KB ADAA ' . $path . ' selhalo (HTTP ' . $code . '): '
            . mb_substr(preg_replace('/[^\x20-\x7E]+/', ' ', (string)$raw), 0, 160));
    }
    return is_array($data) ? $data : [];
}

/** Lokální zrcadlo bankovních pohybů. */
function ensureBankTables(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_transactions (
            id INT NOT NULL AUTO_INCREMENT,
            entry_ref VARCHAR(150) NOT NULL,
            env VARCHAR(8) NOT NULL DEFAULT 'prod',
            account_id VARCHAR(64) NOT NULL DEFAULT '',
            booking_date DATE NULL DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT 'CZK',
            direction ENUM('in','out') NOT NULL DEFAULT 'in',
            counterparty_name VARCHAR(190) NULL DEFAULT NULL,
            counterparty_account VARCHAR(64) NULL DEFAULT NULL,
            vs VARCHAR(20) NULL DEFAULT NULL,
            ss VARCHAR(20) NULL DEFAULT NULL,
            ks VARCHAR(10) NULL DEFAULT NULL,
            message VARCHAR(255) NULL DEFAULT NULL,
            matched_invoice_id INT NULL DEFAULT NULL,
            match_status ENUM('none','auto','manual','review') NOT NULL DEFAULT 'none',
            raw MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_bank_entry (env, account_id, entry_ref),
            KEY idx_bank_date (booking_date),
            KEY idx_bank_vs (vs),
            KEY idx_bank_match (match_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('ensureBankTables: ' . $e->getMessage()); }
}

/**
 * Stažení nových pohybů z KB + automatické párování s fakturami.
 * Interval hlídá volající (KB účtuje dle frekvence — 61 min zdarma tier).
 * Vrací ['fetched', 'new', 'matched', 'review'].
 */
function kbSyncTransactions(): array {
    global $pdo;
    ensureBankTables();
    $accountId = (string)get_setting('kb_account_id', '');
    if ($accountId === '') { throw new Exception('Není vybraný účet — Nastavení → Banka.'); }

    // od posledního syncu s 3denním překryvem (pozdní zaúčtování); poprvé 30 dní
    $from = (string)get_setting('kb_last_sync_at', '');
    $fromDate = $from !== '' ? date('Y-m-d', strtotime($from) - 3 * 86400) : date('Y-m-d', time() - 30 * 86400);

    $env = kbApiEnv();
    $ins = $pdo->prepare("INSERT IGNORE INTO bank_transactions
            (entry_ref, env, account_id, booking_date, amount, currency, direction,
             counterparty_name, counterparty_account, vs, ss, ks, message, raw)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $fetched = 0; $new = 0; $skippedStorno = 0; $hitPageCap = false;
    for ($page = 0; $page < 50; $page++) {
        $data = kbAdaaGet('/accounts/' . rawurlencode($accountId) . '/transactions',
            ['fromDate' => $fromDate, 'page' => $page, 'size' => 100]);
        $txs = $data['content'] ?? (is_array($data) && isset($data[0]) ? $data : []);
        if (!$txs) break;
        foreach ($txs as $t) {
            $fetched++;
            // storna a nezaúčtované (pending) pohyby NEukládat — storno by se přes abs()
            // tvářilo jako nová platba a mohlo znovu „zaplatit" fakturu; pending se
            // stáhne příštím syncem, až bude zaúčtovaný (3denní překryv ho zachytí)
            if (!empty($t['reversalIndicator'])) { $skippedStorno++; continue; }
            $txStatus = strtoupper(trim((string)($t['status'] ?? '')));
            if ($txStatus !== '' && !str_starts_with($txStatus, 'BOOK')) { $skippedStorno++; continue; }

            $amtRaw = $t['amount'] ?? [];
            $amount = (float)($amtRaw['value'] ?? $amtRaw['amount'] ?? 0);
            $currency = (string)($amtRaw['currency'] ?? 'CZK');
            $credit = in_array(strtoupper((string)($t['creditDebitIndicator'] ?? '')), ['CREDIT', 'CRDT'], true);
            $cp = $t['counterParty'] ?? [];
            $refs = $t['references'] ?? [];
            $cpAcc = trim((string)($cp['accountNo'] ?? ''));
            if ($cpAcc !== '' && !empty($cp['bankCode'])) { $cpAcc .= '/' . $cp['bankCode']; }
            elseif ($cpAcc === '') { $cpAcc = (string)($cp['iban'] ?? ''); }
            $msg = trim((string)($refs['receiver'] ?? $t['additionalTransactionInformation'] ?? ''));
            $ref = (string)($t['entryReference'] ?? '');
            if ($ref === '') {
                // pojistka bez reference: hash JEN ze stabilních polí (celý JSON obsahuje
                // volatilní lastUpdated/status → duplicitní řádky a zdvojené příjmy)
                $ref = 'h-' . md5(implode('|', [(string)($t['bookingDate'] ?? ''), (string)$amount,
                    $currency, $credit ? 'C' : 'D', (string)($refs['variable'] ?? ''), $cpAcc, $msg]));
            }
            $ins->execute([
                mb_substr($ref, 0, 150), $env, $accountId,
                !empty($t['bookingDate']) ? date('Y-m-d', strtotime((string)$t['bookingDate'])) : null,
                abs($amount), $currency, $credit ? 'in' : 'out',
                mb_substr(trim((string)($cp['name'] ?? '')), 0, 190) ?: null,
                mb_substr($cpAcc, 0, 64) ?: null,
                mb_substr(trim((string)($refs['variable'] ?? '')), 0, 20) ?: null,
                mb_substr(trim((string)($refs['specific'] ?? '')), 0, 20) ?: null,
                mb_substr(trim((string)($refs['constant'] ?? '')), 0, 10) ?: null,
                mb_substr($msg, 0, 255) ?: null,
                json_encode($t, JSON_UNESCAPED_UNICODE),
            ]);
            if ($ins->rowCount() === 1) { $new++; }
        }
        $last = isset($data['last']) ? (bool)$data['last'] : (count($txs) < 100);
        if ($last) break;
        if ($page === 49) { $hitPageCap = true; }
    }

    [$matched, $review] = kbAutoMatchInvoices();
    // při dosažení stropu stránek NEposouvat značku syncu — zbytek okna se
    // dostáhne příště (INSERT IGNORE duplicity ošetří)
    if (!$hitPageCap) { set_setting('kb_last_sync_at', date('Y-m-d H:i:s')); }
    return ['fetched' => $fetched, 'new' => $new, 'matched' => $matched, 'review' => $review,
        'skipped_storno' => $skippedStorno, 'partial' => $hitPageCap];
}

/**
 * Auto-párování: příchozí nespárovaná platba, jejíž VS odpovídá VS/číslu
 * NEzaplacené faktury:
 *   částka sedí (±1 Kč na zaokrouhlení) → faktura PAID + pohyb 'auto'
 *   částka nesedí → pohyb 'review' (žlutě, k ručnímu prověření)
 */
function kbAutoMatchInvoices(): array {
    global $pdo;
    $matched = 0; $review = 0;
    // KRITICKÉ: párovat JEN pohyby AKTUÁLNÍHO prostředí a účtu — sandbox/starý účet
    // nesmí nikdy „zaplatit" ostrou fakturu penězi, které nikdy nepřišly.
    // Jen CZK — cizí měnu nelze porovnávat s Kč fakturou.
    $txq = $pdo->prepare("SELECT * FROM bank_transactions
        WHERE direction = 'in' AND match_status = 'none' AND currency = 'CZK'
          AND env = ? AND account_id = ? AND vs IS NOT NULL AND vs != ''");
    $txq->execute([kbApiEnv(), (string)get_setting('kb_account_id', '')]);
    $txs = $txq->fetchAll(PDO::FETCH_ASSOC);

    foreach ($txs as $tx) {
        $vs = (string)$tx['vs'];
        // kandidáti: přesná shoda VS/čísla + shoda po odstranění nečíselných znaků
        // (QR platba posílá jen číslice — nečíselná řada faktur by se jinak nikdy nespárovala)
        $st = $pdo->prepare("SELECT id, invoice_number, total_amount, status FROM invoices
            WHERE invoice_type = 'invoice' AND (
                variable_symbol = ? OR invoice_number = ?
                OR RIGHT(REGEXP_REPLACE(COALESCE(variable_symbol, ''), '[^0-9]', ''), 10) = ?
                OR RIGHT(REGEXP_REPLACE(COALESCE(invoice_number, ''), '[^0-9]', ''), 10) = ?
            ) ORDER BY id DESC LIMIT 20");
        $st->execute([$vs, $vs, $vs, $vs]);
        $cands = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$cands) continue;

        // preference: nezaplacená se SEDÍCÍ částkou (±1 Kč) > nezaplacená > cokoli
        $exact = null; $unpaid = null;
        foreach ($cands as $c) {
            $isUnpaid = in_array((string)$c['status'], ['issued', 'overdue'], true);
            $amountOk = abs((float)$c['total_amount'] - (float)$tx['amount']) <= 1.0;
            if ($isUnpaid && $amountOk && $exact === null) { $exact = $c; }
            if ($isUnpaid && $unpaid === null) { $unpaid = $c; }
        }

        if ($exact !== null) {
            $pdo->prepare("UPDATE invoices SET status = 'paid', payment_date = ? WHERE id = ?")
                ->execute([(string)$tx['booking_date'] ?: date('Y-m-d'), (int)$exact['id']]);
            $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = ?, match_status = 'auto' WHERE id = ?")
                ->execute([(int)$exact['id'], (int)$tx['id']]);
            crmAuditLog('banka.match', [
                'entity_type' => 'invoice', 'entity_id' => (int)$exact['id'], 'entity_label' => (string)$exact['invoice_number'],
                'summary' => 'Faktura ' . $exact['invoice_number'] . ' automaticky spárována s platbou '
                    . formatMoney((float)$tx['amount']) . ' (VS ' . $vs . ', ' . ($tx['counterparty_name'] ?: 'bez názvu') . ') a označena ZAPLACENO',
            ]);
            $matched++;
        } else {
            // VS sedí, ale částka ne / faktura už je zaplacená → jen žlutě k prověření
            $suggest = $unpaid ?? $cands[0];
            $pdo->prepare("UPDATE bank_transactions SET matched_invoice_id = ?, match_status = 'review' WHERE id = ?")
                ->execute([(int)$suggest['id'], (int)$tx['id']]);
            $review++;
        }
    }
    return [$matched, $review];
}

/** České číslo účtu („[prefix-]číslo/kód") → IBAN CZ. Prázdné/nečitelné → ''. */
function crmCzAccountToIban(string $acc): string {
    $acc = trim($acc);
    if ($acc === '') return '';
    if (str_starts_with(strtoupper($acc), 'CZ')) {
        // už IBAN — ale ověřit délku a kontrolní součet, ať překlep nedojde až do QR
        $iban = strtoupper(preg_replace('/\s+/', '', $acc));
        if (strlen($iban) !== 24 || !preg_match('/^CZ\d{22}$/', $iban)) { return ''; }
        $num = substr($iban, 4) . '1235' . substr($iban, 2, 2);   // C=12, Z=35 + kontrolní číslice
        $mod = 0;
        foreach (str_split($num) as $d) { $mod = ($mod * 10 + (int)$d) % 97; }
        return $mod === 1 ? $iban : '';
    }
    if (!preg_match('/^(?:(\d{0,6})-)?(\d{2,10})\/(\d{4})$/', preg_replace('/\s+/', '', $acc), $m)) { return ''; }
    $bban = $m[3] . str_pad($m[1], 6, '0', STR_PAD_LEFT) . str_pad($m[2], 10, '0', STR_PAD_LEFT);
    // mod 97-10: BBAN + „CZ00" (C=12, Z=35), spočítat postupně (čísla přes int rozsah)
    $num = $bban . '123500';
    $mod = 0;
    foreach (str_split($num) as $d) { $mod = ($mod * 10 + (int)$d) % 97; }
    return 'CZ' . str_pad((string)(98 - $mod), 2, '0', STR_PAD_LEFT) . $bban;
}

/** SPAYD řetězec QR platby pro fakturu (standard ČBA; jen ASCII znaky). */
function afxSpaydForInvoice(array $invoice): string {
    $iban = crmCzAccountToIban((string)get_setting('acc_bank_account', ''));
    if ($iban === '') return '';
    $amount = number_format((float)$invoice['total_amount'], 2, '.', '');
    $vs = preg_replace('/\D+/', '', (string)($invoice['variable_symbol'] ?: $invoice['invoice_number']));
    $msg = 'FAKTURA ' . (string)$invoice['invoice_number'];
    $msg = strtoupper(preg_replace('/[^A-Za-z0-9 .,\/-]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $msg) ?: $msg));
    $parts = ['SPD*1.0', 'ACC:' . $iban, 'AM:' . $amount, 'CC:CZK'];
    if ($vs !== '') { $parts[] = 'X-VS:' . mb_substr($vs, 0, 10); }
    $parts[] = 'MSG:' . mb_substr($msg, 0, 60);
    return implode('*', $parts);
}
