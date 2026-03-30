<?php
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
ob_clean();

header('Content-Type: application/json; charset=utf-8');

function normalizeText(?string $text): string {
    $text = (string) $text;
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text ?? '');
    return trim((string) $text);
}

function loadHtmlDocument(string $html): ?DOMXPath {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $html = function_exists('mb_convert_encoding')
        ? mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')
        : $html;
    if (!@$doc->loadHTML($html)) {
        libxml_clear_errors();
        return null;
    }
    $xpath = new DOMXPath($doc);
    libxml_clear_errors();
    return $xpath;
}

function extractHiddenFields(string $html): array {
    $xpath = loadHtmlDocument($html);
    if (!$xpath) return [];

    $fields = [];
    foreach ($xpath->query('//input[@type="hidden"]') as $input) {
        $name = $input->getAttribute('name');
        if ($name !== '') {
            $fields[$name] = $input->getAttribute('value');
        }
    }

    return $fields;
}

function extractNodeTextById(string $html, string $id): string {
    $xpath = loadHtmlDocument($html);
    if (!$xpath) return '';

    $node = $xpath->query('//*[@id="' . $id . '"]')->item(0);
    return $node ? normalizeText($node->textContent) : '';
}

function classifyPoliceResult(string $message): string {
    $lower = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);

    if ($lower === '') {
        return 'unknown';
    }

    if (str_contains($lower, 'nebyl nalezen')) {
        return 'not_found';
    }

    if (str_contains($lower, 'byl nalezen')) {
        return 'found';
    }

    return 'unknown';
}

function curlRequest(string $url, ?array $postFields = null, ?string $cookieJar = null, bool $jsonResponse = false): array {
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'code' => 0,
            'body' => '',
            'json' => null,
            'error' => 'Server nemá k dispozici cURL.'
        ];
    }

    $ch = curl_init($url);
    $headers = [
        'Accept: ' . ($jsonResponse ? 'application/json, text/javascript, */*;q=0.8' : 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
        'Accept-Language: cs-CZ,cs;q=0.9,en;q=0.8',
    ];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Repair CRM IMEI checker/1.0',
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($cookieJar) {
        $options[CURLOPT_COOKIEJAR] = $cookieJar;
        $options[CURLOPT_COOKIEFILE] = $cookieJar;
    }

    if ($postFields !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($postFields);
        $options[CURLOPT_HTTPHEADER][] = $jsonResponse ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/x-www-form-urlencoded';
        $options[CURLOPT_REFERER] = $url;
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = null;
    if ($jsonResponse && is_string($body) && $body !== '') {
        $decoded = json_decode($body, true);
    }

    return [
        'ok' => $body !== false && $code >= 200 && $code < 400,
        'code' => $code,
        'body' => $body !== false ? $body : '',
        'json' => $decoded,
        'error' => $error,
    ];
}

function normalizeIfreeicloudResult(array $data): array {
    $status = 'unknown';
    $message = '';

    if (isset($data['success']) && $data['success'] === true) {
        $status = 'success';
    } elseif (isset($data['success']) && $data['success'] === false) {
        $status = 'error';
    }

    if (!empty($data['response']) && is_string($data['response'])) {
        $message = trim($data['response']);
    } elseif (!empty($data['message']) && is_string($data['message'])) {
        $message = trim($data['message']);
    } elseif (!empty($data['error']) && is_string($data['error'])) {
        $message = trim($data['error']);
    }

    return [
        'success' => $status === 'success' || $status === 'error' || $message !== '',
        'status' => $status,
        'message' => $message,
        'object' => (!empty($data['object']) && is_array($data['object'])) ? $data['object'] : null,
        'raw' => $data,
    ];
}

function ifreeicloudSummary(?array $object = null): string {
    if (!$object) return '';

    $map = [
        'model' => 'Model',
        'network' => 'Network',
        'imei' => 'IMEI',
        'imei2' => 'IMEI2',
        'meid' => 'MEID',
        'serial' => 'Serial',
        'warrantyStatus' => 'Warranty',
        'estPurchaseDate' => 'Purchase date',
        'technicalSupport' => 'Technical support',
        'repairCoverage' => 'Repairs/service coverage',
        'replaced' => 'Replaced by Apple',
        'fmiOn' => 'Find My iPhone',
        'lostMode' => 'Lost mode',
        'usaBlockStatus' => 'US block status',
        'simLock' => 'SIM lock',
        'isAppleDevice' => 'Apple device',
    ];

    $rows = [];
    foreach ($map as $key => $label) {
        if (!array_key_exists($key, $object)) continue;
        $value = $object[$key];
        if (is_bool($value)) {
            $value = $value ? 'Ano' : 'Ne';
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($value === null || $value === '') {
            continue;
        }
        $rows[] = $label . ': ' . (string) $value;
    }

    return implode("\n", $rows);
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Pro ověření IMEI se nejprve přihlas.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode([
        'success' => false,
        'message' => 'Neplatný CSRF token.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawImei = preg_replace('/\D+/', '', (string) ($_POST['imei'] ?? ''));
$imei = substr($rawImei, 0, 14);

if (strlen($imei) < 14) {
    echo json_encode([
        'success' => false,
        'message' => 'IMEI musí mít alespoň 14 číslic.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$endpoint = 'https://aplikace.policie.gov.cz/patrani-mobily/';
$cookieJar = tempnam(sys_get_temp_dir(), 'imei_police_');

if ($cookieJar === false) {
    echo json_encode([
        'success' => false,
        'message' => 'Nepodařilo se připravit pracovní prostor pro ověření.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$ifreeicloudKey = trim((string) get_setting('ifreeicloud_api_key', getenv('IFREEICLOUD_API_KEY') ?: ''));
$ifreeicloudService = (int) get_setting('ifreeicloud_service_id', getenv('IFREEICLOUD_SERVICE_ID') ?: 205);

try {
    $initial = curlRequest($endpoint, null, $cookieJar);
    if (!$initial['ok']) {
        throw new RuntimeException('Nepodařilo se načíst stránku Policie ČR.');
    }

    $postFields = extractHiddenFields($initial['body']);
    $postFields['ctl00$Application$tbImei'] = $imei;
    $postFields['ctl00$Application$Button1'] = 'Vyhledat';
    $postFields['__EVENTTARGET'] = $postFields['__EVENTTARGET'] ?? '';
    $postFields['__EVENTARGUMENT'] = $postFields['__EVENTARGUMENT'] ?? '';

    $response = curlRequest($endpoint, $postFields, $cookieJar);
    if (!$response['ok']) {
        throw new RuntimeException('Vyhledání na webu Policie ČR selhalo.');
    }

    $message = extractNodeTextById($response['body'], 'ctl00_Application_Label1');
    if ($message === '') {
        $message = normalizeText($response['body']);
    }

    $status = classifyPoliceResult($message);
    $success = $status !== 'unknown' || $message !== '';

    $ifreeicloud = [
        'configured' => $ifreeicloudKey !== '',
        'success' => false,
        'status' => 'unknown',
        'message' => '',
        'http_code' => 0,
        'service_id' => $ifreeicloudService,
        'summary' => '',
        'object' => null,
    ];

    if ($ifreeicloudKey !== '') {
        $ifreeicloudPayload = [
            'service' => $ifreeicloudService,
            'imei' => $imei,
            'key' => $ifreeicloudKey,
        ];
        $ifreeicloudResponse = curlRequest('https://api.ifreeicloud.co.uk', $ifreeicloudPayload, null, true);
        $ifreeicloud['http_code'] = $ifreeicloudResponse['code'];
        if ($ifreeicloudResponse['ok'] && is_array($ifreeicloudResponse['json'])) {
            $normalized = normalizeIfreeicloudResult($ifreeicloudResponse['json']);
            $ifreeicloud['success'] = $normalized['success'];
            $ifreeicloud['status'] = $normalized['status'];
            $ifreeicloud['message'] = $normalized['message'];
            $ifreeicloud['object'] = $normalized['object'];
            $ifreeicloud['summary'] = ifreeicloudSummary($normalized['object']);
            $ifreeicloud['raw'] = $ifreeicloudResponse['json'];
        } else {
            $ifreeicloud['message'] = $ifreeicloudResponse['error'] ?: 'Ověření přes iFreeiCloud selhalo.';
        }
    } else {
        $ifreeicloud['message'] = 'iFreeiCloud API klíč není nastavený.';
    }

    echo json_encode([
        'success' => $success,
        'status' => $status,
        'imei' => $imei,
        'message' => $message,
        'police' => [
            'success' => $success,
            'status' => $status,
            'message' => $message,
        ],
        'ifreeicloud' => $ifreeicloud,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'police' => [
            'success' => false,
            'status' => 'unknown',
            'message' => $e->getMessage(),
        ],
        'ifreeicloud' => [
            'configured' => $ifreeicloudKey !== '',
            'success' => false,
            'status' => 'unknown',
            'message' => 'iFreeiCloud kontrola nebyla provedena.',
            'service_id' => $ifreeicloudService,
        ],
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (is_file($cookieJar)) {
        @unlink($cookieJar);
    }
}
