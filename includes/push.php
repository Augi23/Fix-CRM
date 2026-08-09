<?php
/**
 * APNs push sender — token-based auth (.p8 key over HTTP/2).
 *
 * Konfigurace z .env (na serveru, mimo git):
 *   APNS_KEY_PATH   = /secure/path/AuthKey_XXXXXXXXXX.p8
 *   APNS_KEY_ID     = XXXXXXXXXX      (10 znaků)
 *   APNS_TEAM_ID    = 43MLSD9WKF      (Team ID)
 *   APNS_BUNDLE_ID  = cloud.applefix.crm
 *   APNS_ENV        = production | sandbox   (development build = sandbox)
 *
 * Použití:
 *   require_once includes/push.php;
 *   pushToUser($pdo, $userId, 'Nová zakázka', 'APFAZ2600990 – iPhone 15', ['data'=>['url'=>'view_order.php?id=123']]);
 *   pushToAll($pdo, 'Nová zpráva', '...', ['sound'=>'chat.caf'], [$excludeUserId]);
 */

/** Hodnota konfigurace: nejdřív z nastavení CRM (system_settings), pak .env. */
function apnsCfg(string $settingKey, string $envKey, string $default = ''): string {
    if (function_exists('get_setting_with_fallback')) {
        return (string) get_setting_with_fallback($settingKey, $default, $envKey);
    }
    $v = getenv($envKey);
    return ($v !== false && $v !== '') ? (string)$v : $default;
}

/** Privátní klíč (.p8 PEM): z nastavení (apns_key_pem), jinak ze souboru dle .env. */
function apnsKeyPem(): string {
    if (function_exists('get_setting')) {
        $pem = trim((string) get_setting('apns_key_pem', ''));
        if ($pem !== '') return $pem;
    }
    $path = (string) getenv('APNS_KEY_PATH');
    if ($path !== '' && is_readable($path)) return (string) file_get_contents($path);
    return '';
}

/** Vytvoří tabulku push_tokens, pokud chybí (nezávisle na migračním runneru). */
function ensurePushTokensTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `push_tokens` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `device_token` VARCHAR(200) NOT NULL,
            `platform` VARCHAR(20) NOT NULL DEFAULT 'ios',
            `apns_env` VARCHAR(10) NULL,
            `app_version` VARCHAR(40) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_device_token` (`device_token`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Migrace stávající tabulky: naučené APNs prostředí per token
        // (kabelové dev buildy = sandbox, TestFlight/App Store = production).
        $col = $pdo->query("SHOW COLUMNS FROM `push_tokens` LIKE 'apns_env'")->fetch();
        if (!$col) $pdo->exec("ALTER TABLE `push_tokens` ADD COLUMN `apns_env` VARCHAR(10) NULL AFTER `platform`");
        $done = true;
    } catch (Throwable $e) { /* ignore */ }
}

function apnsConfigured(): bool {
    return apnsCfg('apns_key_id', 'APNS_KEY_ID') !== ''
        && apnsCfg('apns_team_id', 'APNS_TEAM_ID') !== ''
        && apnsCfg('apns_bundle_id', 'APNS_BUNDLE_ID', 'cloud.applefix.crm') !== ''
        && trim(apnsKeyPem()) !== '';
}

/** Vytvoří (a ~50 min cachuje) podepsaný APNs JWT. */
function apnsJwt(): ?string {
    static $cached = null;
    static $cachedAt = 0;
    if ($cached !== null && (time() - $cachedAt) < 3000) return $cached;

    $key = apnsKeyPem();
    if ($key === '') return null;
    $pkey = openssl_pkey_get_private($key);
    if (!$pkey) return null;

    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $header = $b64(json_encode(['alg' => 'ES256', 'kid' => apnsCfg('apns_key_id', 'APNS_KEY_ID')]));
    $claims = $b64(json_encode(['iss' => apnsCfg('apns_team_id', 'APNS_TEAM_ID'), 'iat' => time()]));
    $input  = $header . '.' . $claims;

    $der = '';
    if (!openssl_sign($input, $der, $pkey, OPENSSL_ALGO_SHA256)) return null;
    $raw = apnsDerToRaw($der);           // ES256 vyžaduje raw R||S (64 B), openssl vrací DER
    if ($raw === null) return null;

    $cached = $input . '.' . $b64($raw);
    $cachedAt = time();
    return $cached;
}

/** Převod DER ECDSA podpisu na raw 64B (R||S) pro ES256. */
function apnsDerToRaw(string $der): ?string {
    $o = 0;
    if (($der[$o] ?? '') !== "\x30") return null;      // SEQUENCE
    $o += 2;
    if (($der[$o] ?? '') !== "\x02") return null;      // INTEGER R
    $o += 1;
    $rLen = ord($der[$o]); $o += 1;
    $r = substr($der, $o, $rLen); $o += $rLen;
    if (($der[$o] ?? '') !== "\x02") return null;      // INTEGER S
    $o += 1;
    $sLen = ord($der[$o]); $o += 1;
    $s = substr($der, $o, $sLen);

    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    if (strlen($r) !== 32 || strlen($s) !== 32) return null;
    return $r . $s;
}

/** APNs host pro dané prostředí. */
function apnsHostFor(string $env): string {
    return $env === 'production' ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com';
}

/** Jeden HTTP/2 request na APNs. Vrací [http_code, reason] (reason z JSON těla, např. BadDeviceToken). */
function apnsCurlOne(string $env, string $token, string $payload, array $headers): array {
    $ch = curl_init(apnsHostFor($env) . "/3/device/$token");
    curl_setopt_array($ch, [
        CURLOPT_HTTP_VERSION  => CURL_HTTP_VERSION_2_0,
        CURLOPT_POST          => true,
        CURLOPT_POSTFIELDS    => $payload,
        CURLOPT_HTTPHEADER    => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT       => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $reason = '';
    if (is_string($body) && $body !== '') {
        $j = json_decode($body, true);
        if (is_array($j) && isset($j['reason'])) $reason = (string)$j['reason'];
    }
    return [$code, $reason];
}

/**
 * Nízkoúrovňové odeslání na dané tokeny. Vrací [token => http_code].
 *
 * Prostředí per token: kabelové dev buildy mají sandbox tokeny, TestFlight/App
 * Store buildy produkční. Server zkusí naučené prostředí tokenu (sloupec
 * apns_env), při BadDeviceToken zkusí druhé a výsledek si k tokenu uloží —
 * flotila s mixem buildů tak funguje bez ručního přepínání APNS_ENV.
 */
function apnsSend(array $tokens, array $aps, array $custom = [], ?string $collapseId = null): array {
    if (!apnsConfigured()) return [];
    $jwt = apnsJwt();
    if (!$jwt) return [];

    $bundle = apnsCfg('apns_bundle_id', 'APNS_BUNDLE_ID', 'cloud.applefix.crm');
    $defaultEnv = apnsCfg('apns_env', 'APNS_ENV', 'sandbox') === 'production' ? 'production' : 'sandbox';
    $payload = json_encode(array_merge(['aps' => $aps], $custom));
    $tokens = array_values(array_unique($tokens));

    global $pdo;
    $db = ($pdo instanceof PDO) ? $pdo : null;

    // Naučená prostředí tokenů jedním dotazem.
    $envMap = [];
    if ($db && $tokens) {
        try {
            $ph = implode(',', array_fill(0, count($tokens), '?'));
            $stmt = $db->prepare("SELECT device_token, apns_env FROM push_tokens WHERE device_token IN ($ph)");
            $stmt->execute($tokens);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (in_array($r['apns_env'], ['production', 'sandbox'], true)) $envMap[$r['device_token']] = $r['apns_env'];
            }
        } catch (Throwable $e) { /* ignore */ }
    }

    $headers = [
        'authorization: bearer ' . $jwt,
        'apns-topic: ' . $bundle,
        'apns-push-type: alert',
        'content-type: application/json',
    ];
    if ($collapseId) $headers[] = 'apns-collapse-id: ' . substr($collapseId, 0, 64);

    $results = [];
    $invalid = [];
    foreach ($tokens as $tok) {
        $primary = $envMap[$tok] ?? $defaultEnv;
        [$code, $reason] = apnsCurlOne($primary, $tok, $payload, $headers);

        // Token patří do druhého prostředí? Zkus ho tam a nauč se výsledek.
        if ($code === 400 && $reason === 'BadDeviceToken') {
            $other = $primary === 'production' ? 'sandbox' : 'production';
            [$code2, $reason2] = apnsCurlOne($other, $tok, $payload, $headers);
            if ($code2 === 200 || $code2 === 410 || $reason2 !== 'BadDeviceToken') {
                $code = $code2;
                $reason = $reason2;
                if ($code2 === 200 && $db) {
                    try {
                        $db->prepare("UPDATE push_tokens SET apns_env = ? WHERE device_token = ?")
                           ->execute([$other, $tok]);
                    } catch (Throwable $e) { /* ignore */ }
                }
            }
        } elseif ($code === 200 && $db && !isset($envMap[$tok])) {
            // První úspěch bez naučeného prostředí → zapamatuj primární.
            try {
                $db->prepare("UPDATE push_tokens SET apns_env = ? WHERE device_token = ?")
                   ->execute([$primary, $tok]);
            } catch (Throwable $e) { /* ignore */ }
        }

        $results[$tok] = $code;
        // Smaž jen skutečně mrtvé tokeny: odregistrované (410), nebo neplatné v obou prostředích.
        if ($code === 410 || ($code === 400 && $reason === 'BadDeviceToken')) $invalid[] = $tok;
    }

    if ($invalid && $db) {
        try {
            $ph = implode(',', array_fill(0, count($invalid), '?'));
            $db->prepare("DELETE FROM push_tokens WHERE device_token IN ($ph)")->execute($invalid);
        } catch (Throwable $e) { /* ignore */ }
    }
    return $results;
}

/** Sestaví aps payload a odešle na tokeny. */
function pushToTokens(array $tokens, string $title, string $body, array $opts = []): void {
    if (!$tokens) return;
    $aps = [
        'alert' => ['title' => $title, 'body' => $body],
        'sound' => $opts['sound'] ?? 'default',
    ];
    if (isset($opts['badge']))     $aps['badge'] = (int)$opts['badge'];
    if (!empty($opts['thread']))   $aps['thread-id'] = (string)$opts['thread'];
    apnsSend($tokens, $aps, $opts['data'] ?? [], $opts['collapse'] ?? null);
}

/** Push konkrétnímu uživateli (na všechna jeho zařízení). */
function pushToUser(PDO $pdo, int $userId, string $title, string $body, array $opts = []): void {
    if (!apnsConfigured() || $userId <= 0) return;
    ensurePushTokensTable($pdo);
    try {
        $stmt = $pdo->prepare("SELECT device_token FROM push_tokens WHERE user_id = ? AND platform = 'ios'");
        $stmt->execute([$userId]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return; }
    pushToTokens($tokens, $title, $body, $opts);
}

/** Push všem přihlášeným zařízením (volitelně kromě uvedených uživatelů). */
function pushToAll(PDO $pdo, string $title, string $body, array $opts = [], array $excludeUserIds = []): void {
    if (!apnsConfigured()) return;
    ensurePushTokensTable($pdo);
    try {
        $rows = $pdo->query("SELECT device_token, user_id FROM push_tokens WHERE platform = 'ios'")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return; }
    $exclude = array_map('intval', $excludeUserIds);
    $tokens = [];
    foreach ($rows as $r) {
        if (!in_array((int)$r['user_id'], $exclude, true)) $tokens[] = $r['device_token'];
    }
    pushToTokens($tokens, $title, $body, $opts);
}
