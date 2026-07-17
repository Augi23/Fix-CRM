<?php
/**
 * APPLE WALLET — generátor .pkpass věrnostní karty (storeCard).
 * URL: wallet/apple_pass.php?t=<card_token>
 *
 * Vyžaduje (nahráno v Nastavení → Integrace → Apple Wallet, uloženo v /secure/wallet):
 *   - apple_cert.p12  … Pass Type ID certifikát + privátní klíč (export z Keychain)
 *   - apple_wwdr.pem  … Apple WWDR mezicertifikát (G4) v PEM
 *   - nastavení: wallet_apple_pass_type_id, wallet_apple_team_id, wallet_apple_p12_pass
 *
 * Barkód karty = QR s odkazem crmCardScanUrl() → recepce ho naskenuje a otevře klienta.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';

function afx_pass_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

if (!crmWalletAppleReady()) {
    afx_pass_fail('Apple Wallet zatím není nakonfigurováno. Nahraj Pass Type certifikát v Nastavení → Integrace.', 503);
}

$token = trim((string)($_GET['t'] ?? ''));
$custId = crmCustomerIdByCardToken($token);
if ($custId <= 0) { afx_pass_fail('Karta nenalezena.', 404); }
$card = crmClientCardData($custId);
if (!$card) { afx_pass_fail('Karta nenalezena.', 404); }

if (!class_exists('ZipArchive')) { afx_pass_fail('Na serveru chybí ZipArchive.', 500); }

$dir       = crmWalletCertDir();
$p12Path   = $dir . '/apple_cert.p12';
$wwdrPath  = $dir . '/apple_wwdr.pem';
$p12Pass   = get_setting('wallet_apple_p12_pass', '');
$passTypeId= get_setting('wallet_apple_pass_type_id', '');
$teamId    = get_setting('wallet_apple_team_id', '');
$company   = get_setting('company_name', 'AppleFix');

// ── Rozbal .p12 na cert + klíč ──
$p12 = @file_get_contents($p12Path);
$certs = [];
if (!$p12 || !openssl_pkcs12_read($p12, $certs, $p12Pass)) {
    afx_pass_fail('Nepodařilo se otevřít Apple certifikát (.p12) — zkontroluj heslo.', 500);
}
$certPem = $certs['cert'];
$keyPem  = $certs['pkey'];

// ── pass.json ──
$serial = $card['token'];
$pass = [
    'formatVersion'      => 1,
    'passTypeIdentifier' => $passTypeId,
    'teamIdentifier'     => $teamId,
    'organizationName'   => $company,
    'serialNumber'       => $serial,
    'description'        => $company . ' — věrnostní karta',
    'logoText'           => $company,
    'foregroundColor'    => 'rgb(255,255,255)',
    'backgroundColor'    => 'rgb(20,22,40)',
    'labelColor'         => 'rgb(150,170,255)',
    'barcodes'           => [[
        'format'          => 'PKBarcodeFormatQR',
        'message'         => crmCardScanUrl($serial),
        'messageEncoding' => 'iso-8859-1',
        'altText'         => $serial,
    ]],
    'storeCard' => [
        'headerFields' => [[
            'key' => 'points', 'label' => 'BODY', 'value' => (string)(int)$card['points'],
        ]],
        'primaryFields' => [[
            'key' => 'name', 'label' => 'ČLEN', 'value' => $card['name'],
        ]],
        'secondaryFields' => [[
            'key' => 'devices', 'label' => 'ZAŘÍZENÍ U NÁS', 'value' => (string)(int)$card['devices_total'],
        ], [
            'key' => 'active', 'label' => 'V SERVISU', 'value' => (string)(int)$card['devices_active'],
        ]],
        'auxiliaryFields' => [[
            'key' => 'since', 'label' => 'ČLEN OD', 'value' => (string)$card['since'],
        ]],
        'backFields' => [[
            'key' => 'about', 'label' => 'O kartě',
            'value' => 'Ukaž QR kód na recepci a hned tě najdeme. Za každou opravu získáváš věrnostní body.',
        ], [
            'key' => 'id', 'label' => 'Číslo karty', 'value' => $serial,
        ]],
    ],
];
$passJson = json_encode($pass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ── Obrázky (icon.png povinný) ──
$assets = [];
$iconSrc = __DIR__ . '/../assets/img/app-icon-192.png';
$logoSrc = __DIR__ . '/../assets/img/applefix-logo.png';
if (is_file($iconSrc)) { $bin = file_get_contents($iconSrc); $assets['icon.png'] = $bin; $assets['icon@2x.png'] = $bin; }
if (is_file($logoSrc)) { $bin = file_get_contents($logoSrc); $assets['logo.png'] = $bin; $assets['logo@2x.png'] = $bin; }
if (!isset($assets['icon.png'])) { afx_pass_fail('Chybí icon.png pro pass.', 500); }

// ── manifest.json (SHA1 každého souboru) ──
$files = ['pass.json' => $passJson] + $assets;
$manifest = [];
foreach ($files as $name => $bin) { $manifest[$name] = sha1($bin); }
$manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES);

// ── signature (PKCS7 detached podpis manifest.json) ──
$tmp = sys_get_temp_dir();
$manifestFile = tempnam($tmp, 'afxman');
$sigFile = tempnam($tmp, 'afxsig');
file_put_contents($manifestFile, $manifestJson);
$extra = is_file($wwdrPath) ? ['extracerts' => $wwdrPath] : [];
$ok = openssl_pkcs7_sign(
    $manifestFile, $sigFile, $certPem, $keyPem, [],
    PKCS7_BINARY | PKCS7_DETACHED, ($extra['extracerts'] ?? null)
);
if (!$ok) { @unlink($manifestFile); @unlink($sigFile); afx_pass_fail('Podpis passu selhal.', 500); }

// openssl_pkcs7_sign zapisuje SMIME (PEM); Apple chce holý DER. Vytáhneme DER blok.
$smime = file_get_contents($sigFile);
@unlink($manifestFile); @unlink($sigFile);
$signature = afx_smime_to_der($smime);
if ($signature === null) { afx_pass_fail('Nepodařilo se převést podpis do DER.', 500); }

// ── Sestav .pkpass zip ──
$zipPath = tempnam($tmp, 'afxpkpass');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) { afx_pass_fail('ZIP se nepodařilo vytvořit.', 500); }
$zip->addFromString('pass.json', $passJson);
$zip->addFromString('manifest.json', $manifestJson);
$zip->addFromString('signature', $signature);
foreach ($assets as $name => $bin) { $zip->addFromString($name, $bin); }
$zip->close();

$out = file_get_contents($zipPath);
@unlink($zipPath);

header('Content-Type: application/vnd.apple.pkpass');
header('Content-Disposition: attachment; filename="applefix-karta.pkpass"');
header('Content-Length: ' . strlen($out));
echo $out;

/**
 * Z S/MIME výstupu openssl_pkcs7_sign (PKCS7_DETACHED) vytáhne binární DER podpisu.
 * Detached S/MIME má sekci `application/x-pkcs7-signature` (smime.p7s) s base64 tělem
 * mezi hlavičkami a uzavírací MIME hranicí. Cílíme přímo na ni, s bezpečnými fallbacky.
 */
function afx_smime_to_der(string $smime): ?string {
    // 1) Klasický PEM blok (starší openssl)
    if (preg_match('/-----BEGIN PKCS7-----(.+?)-----END PKCS7-----/s', $smime, $m)) {
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        return $der !== false ? $der : null;
    }
    // 2) Přesná p7s sekce: base64 za hlavičkami smime.p7s až po prázdný řádek nebo MIME hranici
    if (preg_match('~x-pkcs7-signature.*?\r?\n\r?\n([A-Za-z0-9+/=\r\n]+)~s', $smime, $m)) {
        $b64 = preg_replace('/\s+/', '', $m[1]);
        // odstranit případný zbytek MIME hranice („------...") na konci
        $b64 = preg_replace('/[^A-Za-z0-9+\/=].*$/s', '', $b64);
        $der = base64_decode($b64, true);
        if ($der !== false && strlen($der) > 50) return $der;
    }
    // 3) Nejdelší base64 blok mezi prázdnými řádky (poslední záchrana)
    $parts = preg_split("/\r?\n\r?\n/", $smime);
    $best = '';
    foreach ($parts as $p) {
        $c = preg_replace('/\s+/', '', $p);
        if ($c !== '' && preg_match('~^[A-Za-z0-9+/=]+$~', $c) && strlen($c) > strlen($best)) {
            $best = $c;
        }
    }
    if ($best === '') return null;
    $der = base64_decode($best, true);
    return $der !== false ? $der : null;
}
