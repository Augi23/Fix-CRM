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

/** Nízkoúrovňové odeslání na dané tokeny. Vrací [token => http_code]. */
function apnsSend(array $tokens, array $aps, array $custom = [], ?string $collapseId = null): array {
    if (!apnsConfigured()) return [];
    $jwt = apnsJwt();
    if (!$jwt) return [];

    $bundle = apnsCfg('apns_bundle_id', 'APNS_BUNDLE_ID', 'cloud.applefix.crm');
    $host = (apnsCfg('apns_env', 'APNS_ENV', 'sandbox') === 'production')
        ? 'https://api.push.apple.com'
        : 'https://api.sandbox.push.apple.com';
    $payload = json_encode(array_merge(['aps' => $aps], $custom));

    $results = [];
    $invalid = [];
    foreach (array_values(array_unique($tokens)) as $tok) {
        $headers = [
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . $bundle,
            'apns-push-type: alert',
            'content-type: application/json',
        ];
        if ($collapseId) $headers[] = 'apns-collapse-id: ' . substr($collapseId, 0, 64);

        $ch = curl_init("$host/3/device/$tok");
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION  => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST          => true,
            CURLOPT_POSTFIELDS    => $payload,
            CURLOPT_HTTPHEADER    => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT       => 10,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $results[$tok] = $code;
        if ($code === 410 || $code === 400) $invalid[] = $tok;   // Unregistered / BadDeviceToken
    }

    // Úklid neplatných tokenů.
    if ($invalid) {
        global $pdo;
        if ($pdo instanceof PDO) {
            try {
                $ph = implode(',', array_fill(0, count($invalid), '?'));
                $pdo->prepare("DELETE FROM push_tokens WHERE device_token IN ($ph)")->execute($invalid);
            } catch (Throwable $e) { /* ignore */ }
        }
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
