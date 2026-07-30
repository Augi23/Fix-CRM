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
            $pdo->prepare("UPDATE invoices SET status = IF(date_due < CURDATE(), 'overdue', 'issued'), payment_date = NULL
                WHERE id = ? AND status = 'paid'")->execute([$invId]);
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
 * Automaticky (a tedy bez člověka) se faktura označí ZAPLACENO jen tehdy, když
 * o tom není pochyb — VS sedí právě JEDNÉ nezaplacené faktuře, částka odpovídá
 * a platba nemohla přijít dřív, než faktura vznikla. Cokoli jiného skončí
 * „k prověření" s vysvětlením proč. Zásada: raději nechat člověka rozhodnout,
 * než tvrdit, že peníze dorazily.
 *
 * Vrací [spárováno, k prověření].
 */
function kbAutoMatchInvoices(?string $env = null, ?string $accountId = null): array {
    global $pdo;
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
          AND env = ? AND account_id = ? AND vs IS NOT NULL AND vs != ''
          AND (booking_date IS NULL OR booking_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY))
        ORDER BY booking_date, id");
    $txq->execute([$env, $accountId]);
    $txs = $txq->fetchAll(PDO::FETCH_ASSOC);

    // kandidáti: přesná shoda VS/čísla + shoda po odstranění nečíselných znaků
    // (QR platba posílá jen číslice — nečíselná řada faktur by se jinak nikdy nespárovala)
    $cq = $pdo->prepare("SELECT i.id, i.invoice_number, i.total_amount, i.status, i.date_issue, i.payment_method,
            (SELECT COUNT(*) FROM bank_transactions b
              WHERE b.matched_invoice_id = i.id AND b.match_status IN ('auto','manual') AND b.id <> ?) AS jine_platby
        FROM invoices i
        WHERE i.invoice_type = 'invoice' AND i.status <> 'cancelled' AND (
            i.variable_symbol = ? OR i.invoice_number = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(i.variable_symbol, ''), '[^0-9]', ''), 10) = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(i.invoice_number, ''), '[^0-9]', ''), 10) = ?
        ) ORDER BY i.id DESC LIMIT 20");
    $claim = $pdo->prepare("UPDATE invoices SET status = 'paid', payment_date = ?
        WHERE id = ? AND status IN ('issued', 'overdue')");
    $mark = $pdo->prepare("UPDATE bank_transactions
        SET matched_invoice_id = ?, match_status = ?, matched_at = NOW(), match_note = ? WHERE id = ?");

    foreach ($txs as $tx) {
        $vs = (string)$tx['vs'];
        $vsDigits = afxVsDigits($vs);
        $amount = (float)$tx['amount'];
        $wasReview = (string)$tx['match_status'] === 'review';
        $payDate = (string)$tx['booking_date'] ?: date('Y-m-d');

        $cq->execute([(int)$tx['id'], $vs, $vs, $vsDigits, $vsDigits]);
        $cands = $cq->fetchAll(PDO::FETCH_ASSOC);
        if (!$cands) { continue; }   // VS nikam nepatří — necháváme nespárované, ať jde vidět

        // vhodní kandidáti pro AUTOMATICKÉ zaplacení
        $fit = []; $reason = '';
        foreach ($cands as $c) {
            $total = (float)$c['total_amount'];
            // tolerance na haléřové zaokrouhlení, ale u drobných částek se musí trefit přesně
            $tol = $total >= 100 ? 1.0 : 0.0;
            if (!in_array((string)$c['status'], ['issued', 'overdue'], true)) {
                $reason = $reason ?: 'faktura ' . $c['invoice_number'] . ' už není nezaplacená (' . $c['status'] . ')';
                continue;
            }
            if ((int)$c['jine_platby'] > 0) {
                $reason = $reason ?: 'na faktuře ' . $c['invoice_number'] . ' už je navázaná jiná platba';
                continue;
            }
            if (abs($total - $amount) > $tol) {
                $reason = $reason ?: ($amount < $total
                    ? 'přišlo méně, než je na faktuře ' . $c['invoice_number'] . ' (' . formatMoney($amount) . ' z ' . formatMoney($total) . ')'
                    : 'přišlo více, než je na faktuře ' . $c['invoice_number'] . ' (' . formatMoney($amount) . ' místo ' . formatMoney($total) . ')');
                continue;
            }
            // platba nemůže být starší než faktura (jinak by se dnešní VS trefil do
            // faktury vystavené později); 5 dní tolerance na zálohy a datum zaúčtování
            if (!empty($c['date_issue']) && !empty($tx['booking_date'])
                && strtotime((string)$tx['booking_date']) < strtotime((string)$c['date_issue']) - 5 * 86400) {
                $reason = $reason ?: 'platba je starší než faktura ' . $c['invoice_number'];
                continue;
            }
            $fit[] = $c;
        }

        if (count($fit) === 1) {
            $inv = $fit[0];
            // nárok na fakturu se uplatňuje ATOMICKY — když ji mezitím zaplatil někdo
            // jiný (druhý sync, ruční párování), UPDATE nic nezmění a platba jde k prověření
            $claim->execute([$payDate, (int)$inv['id']]);
            if ($claim->rowCount() === 1) {
                $mark->execute([(int)$inv['id'], 'auto', 'VS i částka sedí', (int)$tx['id']]);
                crmAuditLog('banka.match', [
                    'entity_type' => 'invoice', 'entity_id' => (int)$inv['id'], 'entity_label' => (string)$inv['invoice_number'],
                    'summary' => 'Faktura ' . $inv['invoice_number'] . ' automaticky spárována s platbou '
                        . formatMoney($amount) . ' (VS ' . $vs . ', ' . ($tx['counterparty_name'] ?: 'bez názvu') . ') a označena ZAPLACENO',
                ]);
                $matched++;
                continue;
            }
            $reason = 'faktura ' . $inv['invoice_number'] . ' byla mezitím zaplacena jinou platbou';
        } elseif (count($fit) > 1) {
            $reason = 'VS ' . $vs . ' sedí na víc nezaplacených faktur ('
                . implode(', ', array_map(fn($c) => (string)$c['invoice_number'], array_slice($fit, 0, 3))) . ')';
        }

        // k prověření: nabídne se nejpravděpodobnější faktura, ale NIC se neoznačí zaplacené
        $suggest = $fit[0] ?? null;
        foreach ($cands as $c) {
            if ($suggest === null && in_array((string)$c['status'], ['issued', 'overdue'], true)) { $suggest = $c; }
        }
        $suggest = $suggest ?? $cands[0];
        $mark->execute([(int)$suggest['id'], 'review', mb_substr($reason ?: 'nejednoznačná platba', 0, 255), (int)$tx['id']]);
        if (!$wasReview) { $review++; }
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
