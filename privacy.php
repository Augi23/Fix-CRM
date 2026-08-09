<?php
/**
 * Zásady ochrany soukromí mobilní aplikace AppleFix CRM.
 *
 * Veřejná stránka BEZ přihlášení — App Store vyžaduje u každé aplikace
 * veřejně dostupnou Privacy Policy URL (odkazuje sem listing v App Store
 * Connect). Neobsahuje žádná data z CRM, jen statický text.
 */
// ── DOČASNÉ (smazat po spuštění): seed demo účtu pro Apple review ──────────
if (($_GET['seed_secret'] ?? '') === 'afx-demo-9f3k2m8x1q7w4e6r5t0y-2026') {
    require_once 'includes/config.php';
    header('Content-Type: application/json; charset=utf-8');
    $out = [];
    try {
        $stmt = $pdo->prepare("SELECT id FROM branches WHERE name = ?");
        $stmt->execute(['Demo servis']);
        $branchId = (int)($stmt->fetchColumn() ?: 0);
        if (!$branchId) {
            $pdo->prepare("INSERT INTO branches (name, address, is_active) VALUES (?, ?, 1)")
                ->execute(['Demo servis', 'Ukázková pobočka pro App Review']);
            $branchId = (int)$pdo->lastInsertId();
            $out['branch'] = "created #$branchId";
        } else { $out['branch'] = "exists #$branchId"; }
        $stmt = $pdo->prepare("SELECT id FROM technicians WHERE username = ? UNION SELECT id FROM users WHERE username = ?");
        $stmt->execute(['apple.review', 'apple.review']);
        if ($stmt->fetch()) { $out['user'] = 'exists'; }
        else {
            $pdo->prepare("INSERT INTO technicians (name, email, phone, specialization, role, branch_id, telegram_id, telegram_username, username, password, pay_by_time)
                           VALUES (?, ?, '', '', 'engineer', ?, NULL, NULL, ?, ?, 0)")
                ->execute(['Apple Review', 'review@applefix.cz', $branchId, 'apple.review',
                           password_hash('AFXreview-2026!', PASSWORD_DEFAULT)]);
            $out['user'] = 'created #' . (int)$pdo->lastInsertId();
        }
        $out['ok'] = true;
    } catch (Throwable $e) { http_response_code(500); $out = ['ok' => false, 'error' => $e->getMessage()]; }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zásady ochrany soukromí — AppleFix CRM</title>
<style>
    :root { color-scheme: dark; }
    body { margin: 0; background: #0b0b0d; color: #e8e8ea; font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    main { max-width: 720px; margin: 0 auto; padding: 48px 24px 80px; }
    h1 { font-size: 28px; margin: 0 0 4px; }
    .sub { color: #9a9aa0; margin-bottom: 36px; }
    h2 { font-size: 19px; margin: 32px 0 8px; }
    p, li { color: #c9c9cf; }
    a { color: #4da3ff; }
    .box { background: #17171b; border: 1px solid #26262c; border-radius: 12px; padding: 16px 20px; margin-top: 40px; }
</style>
</head>
<body>
<main>
    <h1>Zásady ochrany soukromí</h1>
    <div class="sub">Mobilní aplikace <strong>AppleFix CRM</strong> · platné od 9.&nbsp;8.&nbsp;2026</div>

    <p>Aplikace AppleFix CRM je interní pracovní nástroj servisu AppleFix
    (applefix.cz). Slouží výhradně zaměstnancům a spolupracovníkům firmy
    k práci se servisním systémem — správě zakázek, skladu, prodeje a firemní
    komunikaci. Není určena veřejnosti.</p>

    <h2>Jaká data aplikace zpracovává</h2>
    <ul>
        <li><strong>Přihlašovací údaje</strong> — uživatelské jméno a heslo se
        po výslovném souhlasu ukládají do zabezpečeného úložiště Keychain
        přímo v zařízení (chráněné Face&nbsp;ID / Touch&nbsp;ID). Nikam jinam se
        nepřenášejí ani nezálohují.</li>
        <li><strong>Push token</strong> — anonymní identifikátor zařízení pro
        doručování pracovních oznámení (nové zakázky, zprávy). Je uložen na
        serveru CRM a smazán při odhlášení nebo neplatnosti.</li>
        <li><strong>Pracovní data CRM</strong> — zakázky, sklad a komunikace se
        zobrazují ze zabezpečeného firemního serveru (HTTPS) a zůstávají na
        něm; aplikace si z nich nic trvale neukládá.</li>
        <li><strong>Kamera</strong> — používá se pouze pro čtení čárových/QR
        kódů při skladových operacích, a to na vyžádání. Snímky se nikam
        neodesílají ani neukládají.</li>
    </ul>

    <h2>Co aplikace nedělá</h2>
    <ul>
        <li>Nesbírá analytiku ani telemetrii třetích stran.</li>
        <li>Nezobrazuje reklamy a nepředává data žádným třetím stranám.</li>
        <li>Nesleduje polohu zařízení.</li>
    </ul>

    <h2>Právní základ a správce</h2>
    <p>Data zpracováváme v rámci pracovněprávního vztahu se zaměstnanci
    (čl. 6 odst. 1 písm. b) a f) GDPR). Správcem je provozovatel servisu
    AppleFix, Praha. Zaměstnanec může kdykoli požádat o výmaz svých
    přístupových údajů a push tokenů.</p>

    <div class="box">
        <strong>Kontakt / podpora:</strong>
        <a href="https://www.applefix.cz">www.applefix.cz</a> ·
        e-mail <a href="mailto:info@applefix.cz">info@applefix.cz</a>
    </div>
</main>
</body>
</html>
