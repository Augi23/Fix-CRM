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
            match_status ENUM('none','auto','manual','review','ignored') NOT NULL DEFAULT 'none',
            matched_at DATETIME NULL DEFAULT NULL,
            match_note VARCHAR(255) NULL DEFAULT NULL,
            tx_status VARCHAR(16) NULL DEFAULT NULL,
            is_reversal TINYINT(1) NOT NULL DEFAULT 0,
            reversal_done TINYINT(1) NOT NULL DEFAULT 0,
            raw MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_bank_entry (env, account_id, entry_ref),
            KEY idx_bank_date (booking_date),
            KEY idx_bank_vs (vs),
            KEY idx_bank_match (match_status),
            KEY idx_bank_amount (amount),
            KEY idx_bank_invoice (matched_invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('ensureBankTables: ' . $e->getMessage()); }

    // Dorovnání starší tabulky (migrace 036) — deploy nasadí kód dřív, než doběhne
    // run_migrations.php, a kód bez těchto sloupců by mezitím padal.
    foreach ([
        "ALTER TABLE bank_transactions MODIFY COLUMN match_status ENUM('none','auto','manual','review','ignored') NOT NULL DEFAULT 'none'",
        "ALTER TABLE bank_transactions ADD COLUMN matched_at DATETIME NULL DEFAULT NULL",
        "ALTER TABLE bank_transactions ADD COLUMN match_note VARCHAR(255) NULL DEFAULT NULL",
        "ALTER TABLE bank_transactions ADD COLUMN tx_status VARCHAR(16) NULL DEFAULT NULL",
        "ALTER TABLE bank_transactions ADD COLUMN is_reversal TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE bank_transactions ADD COLUMN reversal_done TINYINT(1) NOT NULL DEFAULT 0",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* sloupec už existuje */ }
    }
}

/** Částka, měna a směr pohybu z odpovědi ADAA.
 *  Vrací [částka (vždy kladná) | null když je nečitelná, měna, 'in'|'out'].
 *  Směr primárně z creditDebitIndicator (CREDIT/CRDT vs DEBIT/DBIT); když ho banka
 *  nepošle, rozhodne znaménko částky — jinak by se všechny příjmy uložily jako výdaje
 *  a párování by nenašlo vůbec nic. */
function kbTxAmount(array $t): array {
    $raw = $t['amount'] ?? null;
    if (is_array($raw)) {
        $val = $raw['value'] ?? $raw['amount'] ?? null;
        $cur = (string)($raw['currency'] ?? '');
    } else {
        $val = $raw;
        $cur = (string)($t['currency'] ?? '');
    }
    $ind = strtoupper(trim((string)($t['creditDebitIndicator'] ?? '')));
    $dir = in_array($ind, ['CREDIT', 'CRDT'], true) ? 'in'
        : (in_array($ind, ['DEBIT', 'DBIT'], true) ? 'out' : null);
    if ($val === null || !is_numeric($val)) { return [null, $cur ?: 'CZK', $dir ?? 'in']; }
    $val = (float)$val;
    return [abs($val), $cur ?: 'CZK', $dir ?? ($val >= 0 ? 'in' : 'out')];
}

/** Je pohyb podle banky zaúčtovaný? (ISO 20022: BOOK = zaúčtováno, PDNG = čeká,
 *  CANC/RJCT/RVSD = zrušeno.) Prázdný stav bereme jako zaúčtovaný. */
function kbTxBooked(string $status): bool {
    $status = strtoupper(trim($status));
    return $status === '' || str_starts_with($status, 'BOOK');
}
function kbTxCancelled(string $status): bool {
    $status = strtoupper(trim($status));
    return $status !== '' && (str_starts_with($status, 'CANC') || str_starts_with($status, 'RJCT') || str_starts_with($status, 'RVSD'));
}

/** Naučené účty klientů (migrace 038) — pojistka, když kód předběhne migraci. */
function afxEnsureCustomerBankAccounts(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS customer_bank_accounts (
            id INT NOT NULL AUTO_INCREMENT,
            customer_id INT NOT NULL,
            account VARCHAR(64) NOT NULL,
            matched_count INT NOT NULL DEFAULT 1,
            first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_customer_account (customer_id, account),
            KEY idx_cba_account (account)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { error_log('afxEnsureCustomerBankAccounts: ' . $e->getMessage()); }
}

/** Zapamatování „z tohoto účtu platí tenhle klient" — učí se z RUČNÍHO párování.
 *  Slouží jen k návrhům u plateb bez VS, nikdy k automatickému zaplacení. */
function afxLearnCustomerAccount(int $customerId, string $account): void {
    global $pdo;
    afxEnsureCustomerBankAccounts();
    $account = trim($account);
    if ($customerId <= 0 || $account === '') { return; }
    try {
        $pdo->prepare("INSERT INTO customer_bank_accounts (customer_id, account)
            VALUES (?, ?) ON DUPLICATE KEY UPDATE matched_count = matched_count + 1, last_seen = NOW()")
            ->execute([$customerId, mb_substr($account, 0, 64)]);
    } catch (Throwable $e) { error_log('afxLearnCustomerAccount: ' . $e->getMessage()); }
}

/** Posledních 10 číslic z VS/čísla faktury — jediný tvar, na kterém se shodne
 *  QR platba (SPAYD dovolí max 10 znaků) i párovač. Dřív QR posílala PRVNÍCH 10
 *  číslic, ale párovač porovnával POSLEDNÍCH 10, takže platba z QR kódu u delšího
 *  čísla faktury nikdy nesedla. */
function afxVsDigits(string $value): string {
    $digits = preg_replace('/\D+/', '', $value);
    return $digits === '' ? '' : substr($digits, -10);
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
    $fetched = 0; $new = 0; $skippedStorno = 0; $hitPageCap = false;
    for ($page = 0; $page < 50; $page++) {
        $data = kbAdaaGet('/accounts/' . rawurlencode($accountId) . '/transactions',
            ['fromDate' => $fromDate, 'page' => $page, 'size' => 100]);
        $txs = $data['content'] ?? (is_array($data) && isset($data[0]) ? $data : []);
        if (!$txs) break;
        $res = kbIngestTransactions($txs, $accountId, $env);
        $fetched += $res['fetched']; $new += $res['new']; $skippedStorno += $res['skipped'];
        $last = isset($data['last']) ? (bool)$data['last'] : (count($txs) < 100);
        if ($last) break;
        if ($page === 49) { $hitPageCap = true; }
    }

    // POŘADÍ JE DŮLEŽITÉ: nejdřív vrácené a zrušené platby (aby faktura zaplacená
    // penězi, které se mezitím vrátily, spadla zpět mezi nezaplacené), teprve pak párování.
    $reverted = kbApplyReversals($env, $accountId);
    [$matched, $review] = kbAutoMatchInvoices($env, $accountId);
    // při dosažení stropu stránek NEposouvat značku syncu — zbytek okna se
    // dostáhne příště (duplicity ošetří UNIQUE klíč)
    if (!$hitPageCap) { set_setting('kb_last_sync_at', date('Y-m-d H:i:s')); }
    return ['fetched' => $fetched, 'new' => $new, 'matched' => $matched, 'review' => $review,
        'reverted' => $reverted, 'skipped_storno' => $skippedStorno, 'partial' => $hitPageCap];
}

/**
 * Uložení jedné dávky pohybů z ADAA do lokálního zrcadla (bank_transactions).
 * Oddělené od stahování schválně: díky tomu jde celý řetězec „pohyb → párování"
 * otestovat offline nad testovacími daty (scripts/kb_test_parovani.php), aniž by
 * se sahalo na banku. Vrací ['fetched','new','skipped'].
 */
function kbIngestTransactions(array $txs, string $accountId, ?string $env = null): array {
    global $pdo;
    ensureBankTables();
    $env = $env ?? kbApiEnv();
    // ON DUPLICATE (ne INSERT IGNORE): už uložený pohyb se nesmí přepsat jinou částkou,
    // ale MUSÍ se u něj aktualizovat stav od banky — dřív zaúčtovaná platba může být
    // později zrušená a s INSERT IGNORE by se to k nám nikdy nedostalo.
    $ins = $pdo->prepare("INSERT INTO bank_transactions
            (entry_ref, env, account_id, booking_date, amount, currency, direction,
             counterparty_name, counterparty_account, vs, ss, ks, message, tx_status, is_reversal, raw)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE tx_status = VALUES(tx_status), is_reversal = VALUES(is_reversal), raw = VALUES(raw)");
    $updStatus = $pdo->prepare("UPDATE bank_transactions SET tx_status = ?
        WHERE env = ? AND account_id = ? AND entry_ref = ?");

    $fetched = 0; $new = 0; $skipped = 0;
    // Pojistka proti slepení dvou stejných plateb téhož dne (viz níže). Počítadlo platí
    // pro jednu dávku — díky tomu vyjdou při opakovaném stažení TYTÉŽ klíče a nevznikne
    // duplicitní platba. (Krajní případ: dvě naprosto shodné platby bez reference
    // rozdělené hranicí stránky se pořád můžou slít v jednu — tam pomůže až
    // párování podle skutečné reference, kterou banka u běžných plateb posílá.)
    $refSeen = [];
    foreach ($txs as $t) {
        $fetched++;
        $txStatus = strtoupper(trim((string)($t['status'] ?? '')));
        $isReversal = !empty($t['reversalIndicator']) ? 1 : 0;
        [$amount, $currency, $direction] = kbTxAmount($t);
        // nečitelná nebo nulová částka: raději neuložit nic než uložit 0 Kč, která by
        // se tvářila jako platba (a při párování mohla „vyrovnat" fakturu)
        if ($amount === null || $amount <= 0) { $skipped++; continue; }

        $cp = $t['counterParty'] ?? [];
        $refs = $t['references'] ?? [];
        $cpAcc = trim((string)($cp['accountNo'] ?? ''));
        if ($cpAcc !== '' && !empty($cp['bankCode'])) { $cpAcc .= '/' . $cp['bankCode']; }
        elseif ($cpAcc === '') { $cpAcc = (string)($cp['iban'] ?? ''); }
        $msg = trim((string)($refs['receiver'] ?? $t['additionalTransactionInformation'] ?? ''));
        $ref = trim((string)($t['entryReference'] ?? ''));
        if ($ref === '') {
            // pojistka bez reference: hash JEN ze stabilních polí (celý JSON obsahuje
            // volatilní lastUpdated/status → duplicitní řádky a zdvojené příjmy).
            // Dvě SKUTEČNĚ různé platby téhož dne se stejnou částkou i VS by ale dostaly
            // stejný klíč a druhá by zmizela — proto se v rámci dávky ještě čísluje.
            $ref = 'h-' . md5(implode('|', [(string)($t['bookingDate'] ?? ''), (string)($t['valueDate'] ?? ''),
                (string)$amount, $currency, $direction, (string)($refs['variable'] ?? ''),
                (string)($refs['specific'] ?? ''), (string)($refs['endToEndIdentification'] ?? ''), $cpAcc, $msg]));
            $refSeen[$ref] = ($refSeen[$ref] ?? 0) + 1;
            if ($refSeen[$ref] > 1) { $ref .= '-' . $refSeen[$ref]; }
        }

        // nezaúčtovaný pohyb (PDNG) se neukládá — dorazí příštím syncem, až ho banka
        // zaúčtuje (3denní překryv ho zachytí). Když u nás ale už z dřívějška JE,
        // zapíše se aspoň nový stav, aby se poznalo zrušení dřív zaúčtované platby.
        if (!$isReversal && !kbTxBooked($txStatus)) {
            $updStatus->execute([mb_substr($txStatus, 0, 16), $env, $accountId, mb_substr($ref, 0, 150)]);
            $skipped++;
            continue;
        }

        $ins->execute([
            mb_substr($ref, 0, 150), $env, $accountId,
            !empty($t['bookingDate']) ? date('Y-m-d', strtotime((string)$t['bookingDate'])) : null,
            $amount, $currency, $direction,
            mb_substr(trim((string)($cp['name'] ?? '')), 0, 190) ?: null,
            mb_substr($cpAcc, 0, 64) ?: null,
            mb_substr(trim((string)($refs['variable'] ?? '')), 0, 20) ?: null,
            mb_substr(trim((string)($refs['specific'] ?? '')), 0, 20) ?: null,
            mb_substr(trim((string)($refs['constant'] ?? '')), 0, 10) ?: null,
            mb_substr($msg, 0, 255) ?: null,
            mb_substr($txStatus, 0, 16) ?: null,
            $isReversal,
            json_encode($t, JSON_UNESCAPED_UNICODE),
        ]);
        // rowCount(): 1 = nový řádek, 2 = aktualizovaný stávající, 0 = beze změny
        if ($ins->rowCount() === 1) { $new++; }
    }
    return ['fetched' => $fetched, 'new' => $new, 'skipped' => $skipped];
}

/**
 * Vrácené a zrušené platby (storno). Musí běžet PŘED párováním.
 *
 * Dvě situace, obě znamenají „peníze na účtu nezůstaly":
 *   a) přišel pohyb s příznakem storna (reversalIndicator),
 *   b) dřív zaúčtovaná platba má nově stav CANC/RJCT/RVSD.
 * Když taková platba už zaplatila fakturu, faktura se vrací mezi nezaplacené
 * a pohyb jde „k prověření" — automat ho podruhé nespáruje.
 * Vrací počet vrácených faktur.
 */
function kbApplyReversals(?string $env = null, ?string $accountId = null): int {
    global $pdo;
    $env = $env ?? kbApiEnv();
    $accountId = $accountId ?? (string)get_setting('kb_account_id', '');
    $reverted = 0;

    $unpay = function (array $tx, string $why) use ($pdo, &$reverted): void {
        $invId = (int)($tx['matched_invoice_id'] ?? 0);
        if ($invId > 0 && in_array((string)$tx['match_status'], ['auto', 'manual'], true)) {
            $st = $pdo->prepare("SELECT invoice_number FROM invoices WHERE id = ?");
            $st->execute([$invId]);
            $num = (string)$st->fetchColumn();
            // platba se vrátila → smazat její evidenci a přepočítat stav faktury
            // (allowUnpay: tady peníze prokazatelně na účtu nejsou)
            afxInvoiceRemoveBankPayment((int)$tx['id']);
            afxInvoiceRecalcPaid($invId, true);
            crmAuditLog('banka.storno', [
                'entity_type' => 'invoice', 'entity_id' => $invId, 'entity_label' => $num,
                'summary' => 'Faktura ' . $num . ' vrácena mezi NEZAPLACENÉ — ' . $why . ' ('
                    . formatMoney((float)$tx['amount']) . ', VS ' . ((string)$tx['vs'] ?: '—') . ')',
            ]);
            $reverted++;
        }
        // 'ignored', ne 'review': vrácené peníze už na účtu nejsou, takže tahle platba
        // nesmí být znovu kandidátem automatického párování (ani když storno dorazí
        // ve stejné dávce jako původní platba, tedy dřív, než se stihla spárovat)
        $pdo->prepare("UPDATE bank_transactions SET match_status = 'ignored', matched_invoice_id = NULL,
            match_note = ? WHERE id = ?")->execute([mb_substr($why, 0, 255), (int)$tx['id']]);
    };

    // a) storno pohyb → dohledat původní platbu (stejná částka, VS i protistrana)
    $rq = $pdo->prepare("SELECT * FROM bank_transactions
        WHERE env = ? AND account_id = ? AND is_reversal = 1 AND reversal_done = 0");
    $rq->execute([$env, $accountId]);
    foreach ($rq->fetchAll(PDO::FETCH_ASSOC) as $rev) {
        $oq = $pdo->prepare("SELECT * FROM bank_transactions
            WHERE env = ? AND account_id = ? AND is_reversal = 0 AND id <> ?
              AND ABS(amount - ?) < 0.01
              AND COALESCE(vs,'') = COALESCE(?,'')
              AND COALESCE(counterparty_account,'') = COALESCE(?,'')
              AND booking_date BETWEEN DATE_SUB(?, INTERVAL 45 DAY) AND ?
            ORDER BY (match_status IN ('auto','manual')) DESC, booking_date DESC LIMIT 1");
        $oq->execute([$env, $accountId, (int)$rev['id'], (float)$rev['amount'], $rev['vs'], $rev['counterparty_account'],
            (string)$rev['booking_date'] ?: date('Y-m-d'), (string)$rev['booking_date'] ?: date('Y-m-d')]);
        $orig = $oq->fetch(PDO::FETCH_ASSOC);
        if ($orig) { $unpay($orig, 'platba byla vrácena (storno v bance)'); }
        $pdo->prepare("UPDATE bank_transactions SET reversal_done = 1, match_status = 'ignored',
            match_note = 'Storno platby' WHERE id = ?")->execute([(int)$rev['id']]);
    }

    // b) platba, kterou banka dodatečně zrušila (CANC/RJCT/RVSD), a přitom zaplatila fakturu
    $cq = $pdo->prepare("SELECT * FROM bank_transactions
        WHERE env = ? AND account_id = ? AND is_reversal = 0 AND match_status IN ('auto','manual')
          AND tx_status IS NOT NULL AND tx_status <> ''");
    $cq->execute([$env, $accountId]);
    foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $tx) {
        if (kbTxCancelled((string)$tx['tx_status'])) {
            $unpay($tx, 'banka platbu dodatečně zrušila (stav ' . (string)$tx['tx_status'] . ')');
        }
    }
    return $reverted;
}

/**
 * Auto-párování příchozích plateb s fakturami.
 *
 * Rozhoduje podle jednoho pravidla: AUTOMATICKY se zapíše jen platba, u které není
 * pochyb, komu patří — variabilní symbol musí sednout PRÁVĚ JEDNÉ otevřené faktuře,
 * platba nesmí být starší než faktura a nesmí přijít víc, než kolik na faktuře zbývá.
 * Částečná platba se zapíše a faktuře zůstane zbytek k úhradě; zaplacená je teprve
 * tehdy, když ji platby pokryjí celou.
 *
 * Všechno ostatní jde „k prověření" — ale s konkrétním návrhem a důvodem:
 *   • přeplatek (nejčastěji jde o platbu za dvě faktury nebo zálohu),
 *   • VS sedící víc fakturám,
 *   • číslo faktury nalezené jen ve zprávě pro příjemce,
 *   • platba bez VS z účtu, který CRM zná od dřívějšího ručního párování,
 *   • částka odpovídající SOUČTU víc otevřených faktur téhož klienta.
 *
 * Vrací [spárováno, k prověření].
 */
function kbAutoMatchInvoices(?string $env = null, ?string $accountId = null): array {
    global $pdo;
    afxEnsureInvoicePayments();
    afxEnsureCustomerBankAccounts();
    $matched = 0; $review = 0;
    $env = $env ?? kbApiEnv();
    $accountId = $accountId ?? (string)get_setting('kb_account_id', '');

    // KRITICKÉ: párovat JEN pohyby AKTUÁLNÍHO prostředí a účtu — sandbox/starý účet
    // nesmí nikdy „zaplatit" ostrou fakturu penězi, které nikdy nepřišly.
    // Jen CZK — cizí měnu nelze porovnávat s korunovou fakturou.
    // 'ignored' se nebere NIKDY: co účetní odpárovala, automat nesmí vrátit zpět.
    // Platby starší než 180 dní se automaticky nepárují — číselné řady faktur se
    // v dalších letech opakují a starý VS by mohl sednout na novou fakturu.
    $txq = $pdo->prepare("SELECT * FROM bank_transactions
        WHERE direction = 'in' AND currency = 'CZK' AND is_reversal = 0
          AND match_status IN ('none', 'review')
          AND env = ? AND account_id = ?
          AND (booking_date IS NULL OR booking_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY))
        ORDER BY booking_date, id");
    $txq->execute([$env, $accountId]);
    $txs = $txq->fetchAll(PDO::FETCH_ASSOC);

    // kandidáti podle VS: přesná shoda VS/čísla + shoda po odstranění nečíselných znaků
    // (QR platba posílá jen číslice — nečíselná řada faktur by se jinak nikdy nespárovala)
    $byVs = $pdo->prepare("SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, i.status,
                                  i.date_issue, i.payment_method, i.customer_id
        FROM invoices i
        WHERE i.invoice_type = 'invoice' AND i.status <> 'cancelled' AND (
            i.variable_symbol = ? OR i.invoice_number = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(i.variable_symbol, ''), '[^0-9]', ''), 10) = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(i.invoice_number, ''), '[^0-9]', ''), 10) = ?
        ) ORDER BY i.id DESC LIMIT 20");
    // otevřené faktury klienta (pro platby bez VS podle naučeného účtu)
    $byCustomer = $pdo->prepare("SELECT i.id, i.invoice_number, i.total_amount, i.paid_amount, i.status,
                                        i.date_issue, i.payment_method, i.customer_id
        FROM invoices i
        WHERE i.invoice_type = 'invoice' AND i.status IN ('issued','overdue') AND i.customer_id = ?
        ORDER BY i.id DESC LIMIT 20");
    $accToCustomer = $pdo->prepare("SELECT customer_id FROM customer_bank_accounts
        WHERE account = ? ORDER BY matched_count DESC LIMIT 2");
    $mark = $pdo->prepare("UPDATE bank_transactions
        SET matched_invoice_id = ?, match_status = ?, matched_at = NOW(), match_note = ? WHERE id = ?");

    $remainingOf = static function (array $c): float {
        $total = round((float)$c['total_amount'], 2);
        $paid = round((float)($c['paid_amount'] ?? 0), 2);
        if ((string)$c['status'] === 'paid' && $paid <= 0) { $paid = $total; }
        return max(0.0, round($total - $paid, 2));
    };

    foreach ($txs as $tx) {
        $vs = trim((string)($tx['vs'] ?? ''));
        $vsDigits = afxVsDigits($vs);
        $amount = (float)$tx['amount'];
        $wasReview = (string)$tx['match_status'] === 'review';
        $payDate = (string)$tx['booking_date'] ?: date('Y-m-d');
        // pohyb bez skutečné bankovní reference (klíč jsme si museli dopočítat) NESMÍ
        // uzavřít fakturu automaticky — u něj nelze s jistotou vylučit, že jde o duplikát
        $hasRealRef = !str_starts_with((string)$tx['entry_ref'], 'h-');

        // ── 1) kdo je kandidát a jak jsme ho našli ────────────────────────────
        $cands = []; $source = '';
        if ($vs !== '') {
            $byVs->execute([$vs, $vs, $vsDigits, $vsDigits]);
            $cands = $byVs->fetchAll(PDO::FETCH_ASSOC);
            if ($cands) { $source = 'vs'; }
        }
        if (!$cands) {
            // číslo faktury napsané ve zprávě pro příjemce („uhrada faktury c. 2026007")
            $msg = (string)($tx['message'] ?? '');
            foreach (array_slice(array_reverse(preg_split('/\D+/', $msg, -1, PREG_SPLIT_NO_EMPTY) ?: []), 0, 4) as $num) {
                if (strlen((string)$num) < 4) { continue; }
                $byVs->execute([$num, $num, afxVsDigits((string)$num), afxVsDigits((string)$num)]);
                $found = $byVs->fetchAll(PDO::FETCH_ASSOC);
                if ($found) { $cands = $found; $source = 'zpráva'; break; }
            }
        }
        if (!$cands && trim((string)($tx['counterparty_account'] ?? '')) !== '') {
            // platba bez VS z účtu, který známe od dřívějšího ručního párování
            $accToCustomer->execute([trim((string)$tx['counterparty_account'])]);
            $custIds = array_map('intval', $accToCustomer->fetchAll(PDO::FETCH_COLUMN));
            if (count($custIds) === 1) {
                $byCustomer->execute([$custIds[0]]);
                $cands = $byCustomer->fetchAll(PDO::FETCH_ASSOC);
                if ($cands) { $source = 'účet'; }
            }
        }
        if (!$cands) { continue; }   // nemáme se čeho chytit — pohyb zůstává nespárovaný

        // ── 2) které z nich vůbec můžou platbu přijmout ───────────────────────
        $open = []; $reason = '';
        foreach ($cands as $c) {
            $rem = $remainingOf($c);
            if (!in_array((string)$c['status'], ['issued', 'overdue'], true) || $rem <= 0) {
                $reason = $reason ?: 'faktura ' . $c['invoice_number'] . ' je už uhrazená';
                continue;
            }
            // platba nemůže být starší než faktura (jinak by se dnešní VS trefil do
            // faktury vystavené později); 5 dní tolerance na zálohy a datum zaúčtování
            if (!empty($c['date_issue']) && !empty($tx['booking_date'])
                && strtotime((string)$tx['booking_date']) < strtotime((string)$c['date_issue']) - 5 * 86400) {
                $reason = $reason ?: 'platba je starší než faktura ' . $c['invoice_number'];
                continue;
            }
            $open[] = $c + ['zbyva' => $rem];
        }

        // ── 3) rozhodnutí ────────────────────────────────────────────────────
        if (count($open) === 1 && $source === 'vs') {
            $inv = $open[0];
            $rem = (float)$inv['zbyva'];
            $tol = afxPayTolerance((float)$inv['total_amount']);
            if ($amount > $rem + $tol) {
                $reason = 'přišlo víc, než na faktuře ' . $inv['invoice_number'] . ' zbývá ('
                    . formatMoney($amount) . ' proti ' . formatMoney($rem) . ') — může jít o platbu za víc faktur';
            } else {
                $closes = $amount >= $rem - $tol;
                if ($closes && !$hasRealRef) {
                    $reason = 'platba bez bankovní reference by fakturu ' . $inv['invoice_number']
                        . ' uzavřela — potvrď ručně';
                } else {
                    if (afxInvoiceAddPayment((int)$inv['id'], $amount, 'bank', $payDate, (int)$tx['id'],
                            'Automatické párování — VS ' . $vs)) {
                        // pojistka proti souběhu: kdyby mezitím fakturu (do)platil někdo
                        // jiný, platba by ji přeplatila → radši ji zrušit a nechat člověku
                        $after = afxInvoiceRecalcPaid((int)$inv['id']);
                        if ($after['paid'] > $after['total'] + $tol) {
                            afxInvoiceRemoveBankPayment((int)$tx['id']);
                            $reason = 'faktura ' . $inv['invoice_number'] . ' byla mezitím uhrazena jinou platbou';
                        } else {
                            $note = $closes
                                ? 'Doplatek — faktura ' . $inv['invoice_number'] . ' uhrazena'
                                : 'Částečná platba — na faktuře ' . $inv['invoice_number'] . ' zbývá '
                                  . formatMoney($after['remaining']);
                            $mark->execute([(int)$inv['id'], 'auto', mb_substr($note, 0, 255), (int)$tx['id']]);
                            crmAuditLog('banka.match', [
                                'entity_type' => 'invoice', 'entity_id' => (int)$inv['id'],
                                'entity_label' => (string)$inv['invoice_number'],
                                'summary' => 'Faktura ' . $inv['invoice_number'] . ' — automaticky navázána platba '
                                    . formatMoney($amount) . ' (VS ' . $vs . ', ' . ($tx['counterparty_name'] ?: 'bez názvu') . '); '
                                    . ($closes ? 'označena ZAPLACENO' : 'zbývá ' . formatMoney($after['remaining'])),
                            ]);
                            $matched++;
                            continue;
                        }
                    } else {
                        $reason = 'platba už je k faktuře ' . $inv['invoice_number'] . ' navázaná';
                    }
                }
            }
        } elseif (count($open) > 1 && $source === 'vs') {
            $reason = 'VS ' . $vs . ' sedí na víc otevřených faktur ('
                . implode(', ', array_map(fn($c) => (string)$c['invoice_number'], array_slice($open, 0, 3))) . ')';
        } elseif ($open && $source === 'zpráva') {
            $reason = 'platba nemá VS, číslo faktury ' . $open[0]['invoice_number']
                . ' je jen ve zprávě pro příjemce — potvrď ručně';
        } elseif ($open && $source === 'účet') {
            $reason = 'platba bez VS z účtu, ze kterého dřív platil tenhle klient — nabízím fakturu '
                . $open[0]['invoice_number'] . ' (zbývá ' . formatMoney((float)$open[0]['zbyva']) . ')';
        }

        // nápověda u přeplatku: neodpovídá částka SOUČTU dvou otevřených faktur klienta?
        $suggest = $open[0] ?? $cands[0];
        if (!empty($suggest['customer_id'])) {
            $pair = kbFindInvoiceCombination($pdo, (int)$suggest['customer_id'], $amount);
            if ($pair) { $reason .= ' · částka odpovídá součtu faktur ' . implode(' + ', $pair); }
        }

        $mark->execute([(int)$suggest['id'], 'review', mb_substr($reason ?: 'nejednoznačná platba', 0, 255), (int)$tx['id']]);
        if (!$wasReview) { $review++; }
    }
    return [$matched, $review];
}

/** Neodpovídá částka součtu dvou nebo tří otevřených faktur téhož klienta? (nápověda
 *  k prověření u sdružených platebních příkazů — firemní klienti platí několik faktur
 *  jedním převodem). Vrací čísla faktur, nebo [] když nic nesedí. */
function kbFindInvoiceCombination(PDO $pdo, int $customerId, float $amount): array {
    if ($customerId <= 0 || $amount <= 0) { return []; }
    $q = $pdo->prepare("SELECT invoice_number, (total_amount - paid_amount) zbyva FROM invoices
        WHERE customer_id = ? AND invoice_type = 'invoice' AND status IN ('issued','overdue')
          AND (total_amount - paid_amount) > 0 ORDER BY id DESC LIMIT 8");
    $q->execute([$customerId]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (abs(((float)$rows[$i]['zbyva'] + (float)$rows[$j]['zbyva']) - $amount) <= 1.0) {
                return [(string)$rows[$i]['invoice_number'], (string)$rows[$j]['invoice_number']];
            }
            for ($k = $j + 1; $k < $n; $k++) {
                if (abs(((float)$rows[$i]['zbyva'] + (float)$rows[$j]['zbyva'] + (float)$rows[$k]['zbyva']) - $amount) <= 1.0) {
                    return [(string)$rows[$i]['invoice_number'], (string)$rows[$j]['invoice_number'], (string)$rows[$k]['invoice_number']];
                }
            }
        }
    }
    return [];
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
    // U částečně zaplacené faktury musí QR nabídnout ZBYTEK k úhradě, ne celou částku —
    // jinak by klient na upomínce zaplatil znovu všechno a vznikl přeplatek.
    $info = afxInvoicePaymentInfo($invoice);
    $pay = $info['partial'] && $info['remaining'] > 0 ? $info['remaining'] : (float)$invoice['total_amount'];
    $amount = number_format($pay, 2, '.', '');
    // VS musí mít stejný tvar, jaký hledá párovač (posledních 10 číslic) — jinak by
    // se platba z QR kódu u delšího čísla faktury nikdy nespárovala
    $vs = afxVsDigits((string)($invoice['variable_symbol'] ?: $invoice['invoice_number']));
    $msg = 'FAKTURA ' . (string)$invoice['invoice_number'];
    $msg = strtoupper(preg_replace('/[^A-Za-z0-9 .,\/-]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $msg) ?: $msg));
    $parts = ['SPD*1.0', 'ACC:' . $iban, 'AM:' . $amount, 'CC:CZK'];
    if ($vs !== '') { $parts[] = 'X-VS:' . $vs; }
    $parts[] = 'MSG:' . mb_substr($msg, 0, 60);
    return implode('*', $parts);
}
