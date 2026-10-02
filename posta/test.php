<?php
/**
 * TŘÍDĚNÍ POŠTY — test klasifikátoru a čtení MIME (bez DB a bez sítě).
 * Spuštění z kořene CRM:  php posta/test.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Jen z příkazové řádky.\n"); }
require_once __DIR__ . '/lib.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✅ $what\n"; }
    else { $fail++; echo "  ❌ $what" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
}
function head(string $t): void { echo "\n── $t ──\n"; }

/** Sestaví zprávu z hlaviček a těla a roztřídí ji. */
function classify(string $headers, string $body, array $ctx = []): array {
    $env = crmMailEnvelope(str_replace("\n", "\r\n", trim($headers)) . "\r\n", str_replace("\n", "\r\n", $body));
    return crmMailClassifyHeuristic($env, $ctx) + ['env' => $env];
}
function expect(string $name, string $cat, array $r): void {
    ok($name . ' → ' . $cat, $r['category'] === $cat, 'vyšlo ' . $r['category'] . ' (' . $r['reason'] . ') ' . json_encode($r['scores']));
}

head('Zákazníci');
expect('Dotaz na opravu z Gmailu', 'customer', classify(
"From: Petr Novák <petr.novak@gmail.com>
To: servis@applefix.cz
Subject: Oprava displeje iPhone 13
Content-Type: text/plain; charset=utf-8",
"Dobrý den,\nmám prasklý displej na iPhone 13. Kolik by stála oprava a jak dlouho trvá?\nDěkuji, Petr"));

expect('Odpověď v konverzaci z firemní domény', 'customer', classify(
"From: Jana Malá <jana@stavby-mala.cz>
Subject: Re: Zakázka 2026-0412
In-Reply-To: <abc@applefix.cz>
References: <abc@applefix.cz>
Content-Type: text/plain; charset=utf-8",
"Dobrý den, v pátek odpoledne si MacBook vyzvednu. Díky"));

expect('Kontaktní formulář z webu (odeslal WordPress)', 'customer', classify(
"From: WordPress <wordpress@applefix.cz>
Reply-To: karel.dvorak@seznam.cz
Subject: Nová zpráva z kontaktního formuláře
Content-Type: text/plain; charset=utf-8",
"Jméno: Karel Dvořák\nZpráva: Nejde mi nabíjet iPad, máte volno zítra?"));

expect('Klient z CRM, i když píše z firemní domény', 'customer', classify(
"From: Ing. Tomáš Bílý <bily@firma-xyz.cz>
Subject: faktura
Content-Type: text/plain",
"Posílám údaje pro fakturu.", ['is_crm_customer' => true]));

expect('Krátký e-mail bez signálů', 'customer', classify(
"From: anna@centrum.cz
Subject: dotaz
Content-Type: text/plain; charset=utf-8",
"Máte otevřeno i v sobotu?"));

expect('Rusky píšící zákazník', 'customer', classify(
"From: Ivan <ivan.petrov@mail.ru>
Subject: =?utf-8?B?0KDQtdC80L7QvdGCIGlQaG9uZQ==?=
Content-Type: text/plain; charset=utf-8",
"Здравствуйте! Сколько стоит ремонт экрана iPhone 12?"));

head('Nabídky');
expect('Newsletter e-shopu s List-Unsubscribe', 'offer', classify(
"From: Alza.cz <newsletter@news.alza.cz>
Subject: Black Friday: slevy až 50 % jen dnes!
List-Unsubscribe: <https://alza.cz/unsub?x=1>
Precedence: bulk
Content-Type: text/html; charset=utf-8",
"<html><body><h1>Výprodej</h1><p>Akční ceny na iPhony. Nakupte hned.</p><a href='#'>Odhlásit odběr</a></body></html>"));

expect('Mailchimp kampaň dodavatele dílů', 'offer', classify(
"From: Dily Pro <info@dilypro.cz>
Subject: Novinky v nabídce: displeje OLED za nejlepší ceny
X-Mailer: Mailchimp Mailer
X-Campaign: mailchimp123
List-Unsubscribe: <mailto:unsub@dilypro.cz>
Content-Type: text/plain; charset=utf-8",
"Nabízíme nové displeje pro iPhone 15. Velkoobchodní ceník v příloze. Pro odhlášení klikněte zde."));

expect('Cold e-mail: nabídka SEO a marketingu', 'offer', classify(
"From: Martin Prodejce <martin@web-seo-agentura.cz>
Subject: Nabídka spolupráce - SEO a PPC kampaně
Content-Type: text/plain; charset=utf-8",
"Dobrý den, rádi bychom vám nabídli tvorbu webu na míru, SEO optimalizaci a PPC kampaně. Zviditelníme vaši firmu. Nabízíme první měsíc zdarma. Zrušit odběr: odpovězte STOP."));

head('Roboti');
expect('Google bezpečnostní upozornění', 'robot', classify(
"From: Google <no-reply@accounts.google.com>
Subject: Bezpečnostní upozornění
Auto-Submitted: auto-generated
Content-Type: text/plain; charset=utf-8",
"Do vašeho účtu se přihlásilo nové zařízení."));

expect('Heureka měsíční report', 'robot', classify(
"From: Heureka.cz <reporty@heureka.cz>
Subject: Měsíční přehled vašeho obchodu – září
Content-Type: text/html; charset=utf-8",
"<p>Statistiky návštěvnosti a konverzí za září.</p>"));

expect('Zásilkovna: sledování zásilky', 'robot', classify(
"From: Zásilkovna <noreply@zasilkovna.cz>
Subject: Vaše zásilka Z 123 456 je na cestě
Content-Type: text/plain; charset=utf-8",
"Zásilka byla předána dopravci."));

expect('Nedoručitelná zpráva', 'robot', classify(
"From: Mail Delivery System <MAILER-DAEMON@forpsi.com>
Subject: Undelivered Mail Returned to Sender
Content-Type: multipart/report; report-type=delivery-status; boundary=\"b1\"",
"--b1\nContent-Type: text/plain\n\nThis is the mail system.\n--b1--"));

expect('Forpsi: expirace domény', 'robot', classify(
"From: FORPSI <info@forpsi.com>
Subject: Upozornění na expiraci domény applefix.cz
Content-Type: text/plain; charset=utf-8",
"Vaše doména brzy vyprší, prodlužte ji."));

head('Pravidla');
expect('Pravidlo na adresu přebíjí heuristiku', 'customer', classify(
"From: newsletter@news.alza.cz
Subject: Akce
List-Unsubscribe: <x>",
"sleva", ['rules' => ['newsletter@news.alza.cz' => 'customer']]));
expect('Pravidlo na doménu platí i pro subdomény', 'offer', classify(
"From: x@mail.dodavatel.cz
Subject: Dobrý den",
"text", ['rules' => ['@dodavatel.cz' => 'offer']]));

head('MIME a hlavičky');
$env = crmMailEnvelope(
    "From: =?UTF-8?Q?Ji=C5=99=C3=AD_=C5=A0imek?= <JIRI@Example.CZ>\r\nSubject: =?windows-1250?Q?P=F8=EDli=9A_=9Elu=9Dou=E8k=FD?=\r\n"
    . "Content-Type: multipart/alternative; boundary=\"AB\"\r\n",
    "--AB\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode("Ahoj, oprava hotová?")) . "--AB\r\nContent-Type: text/html\r\n\r\n<b>x</b>\r\n--AB--\r\n");
ok('Jméno odesílatele dekódované (UTF-8 Q)', $env['from_name'] === 'Jiří Šimek', $env['from_name']);
ok('E-mail malými písmeny', $env['from_email'] === 'jiri@example.cz', $env['from_email']);
ok('Předmět z windows-1250', $env['subject'] === 'Příliš žluťoučký', $env['subject']);
ok('Text z base64 text/plain', $env['text'] === 'Ahoj, oprava hotová?', $env['text']);

$env = crmMailEnvelope("Content-Type: text/html; charset=iso-8859-2\r\nContent-Transfer-Encoding: quoted-printable\r\n",
    "<p>Dobr=FD den,<br>m=E1m dotaz &amp; prosbu</p>");
ok('HTML + quoted-printable + ISO-8859-2', $env['text'] === "Dobrý den,\nmám dotaz & prosbu", json_encode($env['text'], JSON_UNESCAPED_UNICODE));

$env = crmMailEnvelope("Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n",
    substr(base64_encode(str_repeat('abc ', 50)), 0, 61));   // useknuté BODY<0.N>
ok('Useknuté base64 nepadá', str_starts_with($env['text'], 'abc abc'), $env['text']);

ok('Freemail doména se pozná', crmMailDomainIn('seznam.cz', crmMailFreemailDomains()));
ok('Subdoména služby se pozná', crmMailDomainIn('mail.accounts.google.com', crmMailServiceDomains()));
ok('UTF7-IMAP název složky', (new CrmImap('x'))->encodeName('Nabídky') === 'Nab&AO0-dky', (new CrmImap('x'))->encodeName('Nabídky'));

echo "\nVýsledek: $pass OK, $fail chyb\n";
exit($fail > 0 ? 1 : 0);
