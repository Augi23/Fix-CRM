<?php
/**
 * Kontrola SN/IMEI v databázi odcizených mobilů Policie ČR — PHP port
 * pcr_check_imei() z naskladňovací Mac appky (ASP.NET postback:
 * GET pro tokeny → POST s IMEI → výsledek ve spanu Label1).
 * Vrací ['status' => clean|stolen|unknown|notimei|error, 'imei', 'text'].
 * Chyba/neurčitost NIKDY neblokuje naskladnění — jen se uloží a zobrazí.
 */

function afxPcrCheckImei(string $raw, int $timeout = 8): array {
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) < 14) {   // IMEI má 14–15 číslic; SN (např. Apple) kontrolovat nelze
        return ['status' => 'notimei', 'imei' => $digits,
            'text' => 'Zadané SN/IMEI není platné IMEI mobilního telefonu (14–15 číslic) – '
                . 'kontrolu odcizení v databázi PČR nelze provést.'];
    }
    $q = substr($digits, 0, 14);
    $url = 'https://aplikace.policie.gov.cz/patrani-mobily/';
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko)';

    try {
        if (!function_exists('curl_init')) { throw new RuntimeException('cURL není dostupné.'); }
        $jar = tempnam(sys_get_temp_dir(), 'pcr_');

        $fetch = static function (?array $post) use ($url, $ua, $jar, $timeout): string {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout + 4,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_USERAGENT => $ua,
                CURLOPT_ENCODING => '',
                CURLOPT_COOKIEJAR => $jar,
                CURLOPT_COOKIEFILE => $jar,
            ];
            if ($post !== null) {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
                $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            unset($ch);   // curl_close je od PHP 8.0 no-op
            if ($body === false) { throw new RuntimeException($err ?: 'požadavek selhal'); }
            return (string)$body;
        };

        $page = $fetch(null);
        $tok = static function (string $name) use ($page): string {
            return preg_match('/id="' . preg_quote($name, '/') . '" value="([^"]*)"/', $page, $m) ? $m[1] : '';
        };
        $resp = $fetch([
            '__VIEWSTATE' => $tok('__VIEWSTATE'),
            '__VIEWSTATEGENERATOR' => $tok('__VIEWSTATEGENERATOR'),
            '__EVENTVALIDATION' => $tok('__EVENTVALIDATION'),
            'ctl00$Application$tbImei' => $q,
            'ctl00$Application$Button1' => 'Vyhledat',
        ]);
        @unlink($jar);

        $text = '';
        if (preg_match('/id="ctl00_Application_Label1"[^>]*>(.*?)<\/span>/s', $resp, $m)) {
            $text = trim(html_entity_decode(preg_replace('/<[^>]+>/', ' ', $m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = trim(preg_replace('/\s+/u', ' ', $text));
        }
        $low = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        // POZOR: „byl nalezen" je podřetězec „nebyl nalezen" → testovat NEJDŘÍV negativ!
        if (str_contains($low, 'nebyl nalezen')) { return ['status' => 'clean', 'imei' => $q, 'text' => $text]; }
        if (str_contains($low, 'byl nalezen')) { return ['status' => 'stolen', 'imei' => $q, 'text' => $text]; }
        return ['status' => 'unknown', 'imei' => $q,
            'text' => $text !== '' ? $text : 'Databáze PČR nevrátila jednoznačnou odpověď.'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'imei' => $q,
            'text' => 'Kontrolu v databázi PČR se nepodařilo provést (chyba připojení): ' . $e->getMessage()];
    }
}
