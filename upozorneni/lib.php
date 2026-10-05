<?php
/**
 * CHYTRÁ UPOZORNĚNÍ — jádro (pravidla, příjemci, doručení, plánovač).
 *
 * Co to dělá: několikrát za minutu se „tikne" (poor-man's cron z notify_poll,
 * tiskového agenta pobočky, systémový cron nebo externí cron přes tick.php)
 * a každé pravidlo se podívá, jestli má něco poslat. Pravidla jsou časová
 * („zítra nikdo není zapsaný — kontrola ve 14:00 a 20:00") nebo událostní
 * („vedení ti změnilo směnu"), viz afxNotifyRules().
 *
 * PROČ TO NEPOSÍLÁ DVAKRÁT: každá zpráva má deduplikační klíč (pravidlo +
 * věc + den + příjemce). Před odesláním se klíč zapíše do smart_notify_log
 * s UNIQUE indexem — souběžné tiky (dva prohlížeče, cron a tiskový agent
 * naráz) si tak zprávu nerozdělí a nepošlou ji dvakrát.
 *
 * PROČ OKNA, NE PŘESNÉ ČASY: tik nechodí na vteřinu přesně (v noci třeba
 * jen z cronu po 5 min). Pravidlo je „splatné" od svého času do konce okna
 * (afxNotifyDue) — zpoždění tiku tedy nic nezahodí, a když server stál
 * celé dopoledne, nepřijde odpoledne připomínka na směnu, co už začala.
 *
 * KANÁLY: upozornění v CRM (vždy), Telegram, iOS push (appka), e-mail, SMS.
 * Každý se dá vypnout globálně (vedení) i osobně (každý zaměstnanec sám).
 * SMS jen u kritických věcí a jen když ji vedení výslovně zapne — stojí peníze.
 *
 * Doručení NIKDY neshodí CRM: vše je v try/catch, chyba se jen zapíše do logu.
 */

require_once dirname(__DIR__) . '/rozpis/lib.php';

const AFX_NOTIFY_CONFIG_KEY = 'smart_notify_config';

/* ═══════════════════════════════════════════════════════════════════════════
   SCHÉMA
   ═══════════════════════════════════════════════════════════════════════════ */

function afxNotifyEnsureSchema(): void
{
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) { return; }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS smart_notify_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dedupe_key VARCHAR(190) NOT NULL,
            rule VARCHAR(40) NOT NULL,
            recipient_key VARCHAR(40) NOT NULL DEFAULT '',
            recipient_name VARCHAR(120) NOT NULL DEFAULT '',
            level VARCHAR(10) NOT NULL DEFAULT 'info',
            title VARCHAR(190) NOT NULL DEFAULT '',
            body TEXT NULL,
            url VARCHAR(255) NOT NULL DEFAULT '',
            channels VARCHAR(120) NOT NULL DEFAULT '',
            error VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dedupe (dedupe_key),
            KEY idx_rule_time (rule, created_at),
            KEY idx_recipient_time (recipient_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS smart_notify_inbox (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            staff_key VARCHAR(40) NOT NULL,
            rule VARCHAR(40) NOT NULL DEFAULT '',
            level VARCHAR(10) NOT NULL DEFAULT 'info',
            title VARCHAR(190) NOT NULL DEFAULT '',
            body TEXT NULL,
            url VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_staff_unread (staff_key, read_at),
            KEY idx_staff_id (staff_key, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS smart_notify_prefs (
            staff_key VARCHAR(40) NOT NULL,
            reminder_minutes INT NULL DEFAULT NULL,
            evening_before TINYINT(1) NOT NULL DEFAULT 1,
            ch_telegram TINYINT(1) NOT NULL DEFAULT 1,
            ch_push TINYINT(1) NOT NULL DEFAULT 1,
            ch_email TINYINT(1) NOT NULL DEFAULT 0,
            ch_sms TINYINT(1) NOT NULL DEFAULT 1,
            muted VARCHAR(500) NOT NULL DEFAULT '',
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (staff_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('afxNotifyEnsureSchema: ' . $e->getMessage());
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   PRAVIDLA A NASTAVENÍ
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Katalog pravidel. Parametry: int | time („19:00") | times („14:00, 20:00")
 * | bool | day (mon…sun). Výchozí hodnoty jsou nastavené tak, aby systém dával
 * smysl hned po nasazení a nikoho nezahltil.
 */
function afxNotifyRules(): array
{
    return [
        // ── Rozpis služeb ────────────────────────────────────────────────────
        'shift_reminder' => [
            'group' => 'shift', 'icon' => 'fa-bell', 'default' => true,
            'title' => 'Připomínka před začátkem směny',
            'desc'  => 'Zaměstnanci přijde s předstihem připomínka: kde a od kolika, kdo je na směně s ním, otvírací doba a krátký briefing pobočky (zakázky k vydání, čekající díly, nepřevzatá pokladna). Předstih si každý může nastavit sám.',
            'audience' => 'Zaměstnanec na směně',
            'params' => [
                'minutes' => ['type' => 'int', 'label' => 'Výchozí předstih (min)', 'default' => 60, 'min' => 10, 'max' => 360],
                'brief'   => ['type' => 'bool', 'label' => 'Přidat briefing pobočky', 'default' => true],
            ],
        ],
        'shift_evening' => [
            'group' => 'shift', 'icon' => 'fa-moon', 'default' => true,
            'title' => 'Večer předem: „zítra jdeš do práce"',
            'desc'  => 'Den předem večer shrnutí zítřejší směny, ať se nikdo ráno nediví. Osobně se dá vypnout.',
            'audience' => 'Zaměstnanec se zítřejší směnou',
            'params' => [
                'time' => ['type' => 'time', 'label' => 'Kdy poslat', 'default' => '19:00'],
            ],
        ],
        'shift_uncovered' => [
            'group' => 'shift', 'icon' => 'fa-user-slash', 'default' => true, 'sms' => true,
            'title' => 'Na zítřek není nikdo zapsaný',
            'desc'  => 'Kontrola ve zvolených časech den předem. Vedení a manažer pobočky dostanou upozornění i s návrhem, kdo v ten den obvykle pracuje. Zaměstnancům pobočky přijde výzva, ať se zapíšou do půlnoci. Ráno se navíc hlídá, jestli někdo není zapsaný na DNEŠEK.',
            'audience' => 'Boss, administrátoři, manažer pobočky (+ výzva zaměstnancům)',
            'params' => [
                'times'        => ['type' => 'times', 'label' => 'Kontroly den předem', 'default' => '14:00, 20:00'],
                'appeal_staff' => ['type' => 'bool', 'label' => 'Výzva zaměstnancům pobočky', 'default' => true],
                'morning'      => ['type' => 'time', 'label' => 'Ranní kontrola dneška', 'default' => '07:30'],
                'sms_last'     => ['type' => 'bool', 'label' => 'Poslední kontrolu poslat vedení i SMS', 'default' => false],
            ],
        ],
        'shift_gap' => [
            'group' => 'shift', 'icon' => 'fa-hourglass-half', 'default' => true,
            'title' => 'Zítra je část otvírací doby bez obsluhy',
            'desc'  => 'Někdo zapsaný je, ale směny nepokryjí celou otvírací dobu (např. otevírá se v 10:00 a první směna začíná ve 12:00).',
            'audience' => 'Boss, administrátoři, manažer pobočky',
            'params' => [
                'time'         => ['type' => 'time', 'label' => 'Kdy zkontrolovat', 'default' => '17:00'],
                'min_gap'      => ['type' => 'int', 'label' => 'Hlásit díry od (min)', 'default' => 30, 'min' => 5, 'max' => 600],
                'appeal_staff' => ['type' => 'bool', 'label' => 'Výzva i zaměstnancům', 'default' => false],
            ],
        ],
        'shift_week' => [
            'group' => 'shift', 'icon' => 'fa-calendar-week', 'default' => true,
            'title' => 'Výhled na příští týden',
            'desc'  => 'Jednou týdně přehled příštího týdne: nepokryté a děravé dny, hodiny podle lidí a varování před přetížením (moc hodin, moc dní v kuse). Zaměstnancům výzva k zápisu do dnů, kde chybí lidi.',
            'audience' => 'Vedení (souhrn) + zaměstnanci (výzva)',
            'params' => [
                'day'       => ['type' => 'day', 'label' => 'Den', 'default' => 'thu'],
                'time'      => ['type' => 'time', 'label' => 'Čas', 'default' => '12:00'],
                'max_hours' => ['type' => 'int', 'label' => 'Varovat nad hodin/týden', 'default' => 48, 'min' => 10, 'max' => 90],
                'max_days'  => ['type' => 'int', 'label' => 'Varovat nad dní v kuse', 'default' => 6, 'min' => 2, 'max' => 7],
            ],
        ],
        'shift_change' => [
            'group' => 'shift', 'icon' => 'fa-arrows-rotate', 'default' => true,
            'title' => 'Změna v rozpisu',
            'desc'  => 'Když ti směnu zapíše, upraví nebo smaže někdo jiný (vedení), hned se to dozvíš. Změny na dnešek a zítřek (hlavně odhlášení) jdou i vedení — s tím, jak pak den vypadá. Když se po hlášení „nikdo není zapsaný" někdo zapíše, vedení dostane „vyřešeno".',
            'audience' => 'Dotčený zaměstnanec, vedení',
            'params' => [],
        ],
        'shift_noshow' => [
            'group' => 'shift', 'icon' => 'fa-user-clock', 'default' => true,
            'title' => 'Nástup na směnu nezaznamenán',
            'desc'  => 'Když se zaměstnanec po začátku směny nepřihlásí do CRM ani nepřevezme pokladnu, nejdřív dostane jemné připomenutí on, později vedení — i s informací, kdo jiný je teď na pobočce.',
            'audience' => 'Zaměstnanec, pak manažer pobočky a vedení',
            'params' => [
                'minutes'  => ['type' => 'int', 'label' => 'Připomenout po (min)', 'default' => 15, 'min' => 5, 'max' => 120],
                'escalate' => ['type' => 'int', 'label' => 'Vedení po (min, 0 = ne)', 'default' => 40, 'min' => 0, 'max' => 240],
            ],
        ],
        // ── Pokladna ─────────────────────────────────────────────────────────
        'pos_open' => [
            'group' => 'pos', 'icon' => 'fa-cash-register', 'default' => true,
            'title' => 'Pokladna není převzatá',
            'desc'  => 'Po začátku první směny dne se zkontroluje, jestli někdo převzal pokladnu pobočky — bez převzetí nejde markovat.',
            'audience' => 'Zaměstnanci, kteří jsou právě na směně',
            'params' => [
                'minutes' => ['type' => 'int', 'label' => 'Po začátku směny (min)', 'default' => 15, 'min' => 5, 'max' => 180],
            ],
        ],
        'pos_close' => [
            'group' => 'pos', 'icon' => 'fa-lock-open', 'default' => true,
            'title' => 'Pokladna zůstala otevřená',
            'desc'  => 'Po konci poslední směny je pokladna pořád převzatá — chybí uzávěrka. Jde tomu, kdo ji převzal, a vedení.',
            'audience' => 'Kdo pokladnu převzal + vedení',
            'params' => [
                'minutes' => ['type' => 'int', 'label' => 'Po konci směny (min)', 'default' => 30, 'min' => 5, 'max' => 240],
            ],
        ],
        // ── Zakázky, reklamace, sklad, peníze ───────────────────────────────
        'orders_stale' => [
            'group' => 'biz', 'icon' => 'fa-hourglass-end', 'default' => true,
            'title' => 'Zakázky bez pohybu',
            'desc'  => 'Ranní přehled zakázek, u kterých se dlouho nezměnil stav. Technik dostane svoje, manažer svou pobočku, vedení souhrn. U „čeká na díl" se toleruje dvojnásobek.',
            'audience' => 'Přidělený technik, manažer pobočky, vedení',
            'params' => [
                'time'     => ['type' => 'time', 'label' => 'Kdy', 'default' => '09:00'],
                'days'     => ['type' => 'int', 'label' => 'Bez pohybu déle než (dní)', 'default' => 5, 'min' => 1, 'max' => 60],
                'weekdays' => ['type' => 'bool', 'label' => 'Jen v pracovní dny', 'default' => true],
            ],
        ],
        'orders_pickup' => [
            'group' => 'biz', 'icon' => 'fa-box-open', 'default' => true,
            'title' => 'Hotové zakázky si nikdo nevyzvedl',
            'desc'  => 'Týdenní seznam hotových zakázek, které čekají na vyzvednutí déle než zvolený počet dní — i s telefonem klienta, ať jde rovnou zavolat.',
            'audience' => 'Manažer pobočky, vedení',
            'params' => [
                'day'  => ['type' => 'day', 'label' => 'Den', 'default' => 'mon'],
                'time' => ['type' => 'time', 'label' => 'Čas', 'default' => '10:00'],
                'days' => ['type' => 'int', 'label' => 'Čeká déle než (dní)', 'default' => 14, 'min' => 3, 'max' => 120],
            ],
        ],
        'complaints_deadline' => [
            'group' => 'biz', 'icon' => 'fa-scale-balanced', 'default' => true,
            'title' => 'Reklamace a zákonná lhůta 30 dní',
            'desc'  => 'Spotřebitelskou reklamaci je nutné vyřídit do 30 dnů. Denně se hlídají otevřené reklamace, kterým lhůta dochází nebo už uplynula.',
            'audience' => 'Vedení + technik, který reklamaci řeší',
            'params' => [
                'time'      => ['type' => 'time', 'label' => 'Kdy', 'default' => '09:15'],
                'warn_days' => ['type' => 'int', 'label' => 'Hlídat od stáří (dní)', 'default' => 20, 'min' => 1, 'max' => 29],
            ],
        ],
        'invoices_overdue' => [
            'group' => 'biz', 'icon' => 'fa-file-invoice-dollar', 'default' => true,
            'title' => 'Faktury po splatnosti',
            'desc'  => 'Týdenní přehled nezaplacených faktur po splatnosti s dlužnou částkou.',
            'audience' => 'Vedení, účetní',
            'params' => [
                'day'  => ['type' => 'day', 'label' => 'Den', 'default' => 'mon'],
                'time' => ['type' => 'time', 'label' => 'Čas', 'default' => '09:00'],
            ],
        ],
        'stock_low' => [
            'group' => 'biz', 'icon' => 'fa-boxes-stacked', 'default' => true,
            'title' => 'Docházející díly na skladě',
            'desc'  => 'Díly pod minimálním stavem. Posílá se jen tehdy, když do seznamu přibude něco nového — žádné každodenní opakování téhož.',
            'audience' => 'Vedení, manažer pobočky',
            'params' => [
                'time' => ['type' => 'time', 'label' => 'Kdy', 'default' => '08:30'],
            ],
        ],
        'backup_failed' => [
            'group' => 'sys', 'icon' => 'fa-database', 'default' => true,
            'title' => 'Záloha CRM selhala',
            'desc'  => 'Automatická záloha hlásí chybu, nebo neproběhla déle než den.',
            'audience' => 'Administrátoři a Boss',
            'params' => [],
        ],
    ];
}

const AFX_NOTIFY_GROUPS = [
    'shift' => ['Rozpis služeb', 'fa-calendar-days'],
    'pos'   => ['Pokladna', 'fa-cash-register'],
    'biz'   => ['Zakázky, reklamace, sklad, faktury', 'fa-briefcase'],
    'sys'   => ['Systém', 'fa-server'],
];

const AFX_NOTIFY_DAYS = ['mon' => 'pondělí', 'tue' => 'úterý', 'wed' => 'středa', 'thu' => 'čtvrtek',
                         'fri' => 'pátek', 'sat' => 'sobota', 'sun' => 'neděle'];

/** Globální nastavení (kanály, tichý režim…) — výchozí hodnoty. */
function afxNotifyGlobalDefaults(): array
{
    return [
        'enabled'     => true,
        'ch_telegram' => true,
        'ch_push'     => true,
        'ch_email'    => false,
        'ch_sms'      => false,
        'quiet_from'  => '22:00',   // Telegram v noci tiše (bez zvuku), nic se nezahazuje
        'quiet_to'    => '07:00',
        'admin_email' => '',
        'admin_phone' => '',
        'base_url'    => '',
        'tick_token'  => '',
    ];
}

/** Načtené nastavení = výchozí hodnoty + uložené změny. */
function afxNotifyConfig(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) { return $cache; }
    $raw = [];
    try {
        global $pdo;
        $st = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $st->execute([AFX_NOTIFY_CONFIG_KEY]);
        $raw = json_decode((string)$st->fetchColumn(), true) ?: [];
    } catch (Throwable $e) { $raw = []; }

    $cfg = ['global' => afxNotifyGlobalDefaults(), 'rules' => []];
    foreach ((array)($raw['global'] ?? []) as $k => $v) {
        if (array_key_exists($k, $cfg['global'])) { $cfg['global'][$k] = $v; }
    }
    foreach (afxNotifyRules() as $id => $rule) {
        $r = ['enabled' => (bool)$rule['default']];
        foreach ($rule['params'] as $p => $def) { $r[$p] = $def['default']; }
        foreach ((array)($raw['rules'][$id] ?? []) as $k => $v) {
            if ($k === 'enabled' || isset($rule['params'][$k])) { $r[$k] = $v; }
        }
        $cfg['rules'][$id] = $r;
    }
    return $cache = $cfg;
}

/** Uloží nastavení (po validaci každého pole). */
function afxNotifySaveConfig(array $input): array
{
    $cfg = afxNotifyConfig(true);
    $g = (array)($input['global'] ?? []);
    foreach (['enabled', 'ch_telegram', 'ch_push', 'ch_email', 'ch_sms'] as $k) {
        if (array_key_exists($k, $g)) { $cfg['global'][$k] = afxNotifyBool($g[$k]); }
    }
    foreach (['quiet_from', 'quiet_to'] as $k) {
        if (isset($g[$k]) && afxNotifyTimeToMin((string)$g[$k]) !== null) { $cfg['global'][$k] = afxNotifyNormTime((string)$g[$k]); }
    }
    if (isset($g['admin_email'])) {
        $em = trim((string)$g['admin_email']);
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) { return [false, 'Neplatný e-mail administrátora.']; }
        $cfg['global']['admin_email'] = $em;
    }
    if (isset($g['admin_phone'])) { $cfg['global']['admin_phone'] = mb_substr(trim((string)$g['admin_phone']), 0, 30); }
    if (isset($g['base_url'])) {
        $u = rtrim(trim((string)$g['base_url']), '/');
        if ($u !== '' && !preg_match('#^https?://[^\s/]+#i', $u)) { return [false, 'Adresa CRM musí začínat https://']; }
        $cfg['global']['base_url'] = $u;
    }

    $rules = afxNotifyRules();
    foreach ((array)($input['rules'] ?? []) as $id => $vals) {
        if (!isset($rules[$id]) || !is_array($vals)) { continue; }
        if (array_key_exists('enabled', $vals)) { $cfg['rules'][$id]['enabled'] = afxNotifyBool($vals['enabled']); }
        foreach ($rules[$id]['params'] as $p => $def) {
            if (!array_key_exists($p, $vals)) { continue; }
            $v = $vals[$p];
            switch ($def['type']) {
                case 'int':
                    $n = (int)$v;
                    $cfg['rules'][$id][$p] = max((int)$def['min'], min((int)$def['max'], $n));
                    break;
                case 'bool':
                    $cfg['rules'][$id][$p] = afxNotifyBool($v);
                    break;
                case 'time':
                    if (afxNotifyTimeToMin((string)$v) === null) { return [false, $rules[$id]['title'] . ': čas zadej jako 14:00.']; }
                    $cfg['rules'][$id][$p] = afxNotifyNormTime((string)$v);
                    break;
                case 'times':
                    $list = afxNotifyParseTimes((string)$v);
                    if (!$list) { return [false, $rules[$id]['title'] . ': zadej aspoň jeden čas, např. 14:00, 20:00.']; }
                    $cfg['rules'][$id][$p] = implode(', ', $list);
                    break;
                case 'day':
                    if (isset(AFX_NOTIFY_DAYS[$v])) { $cfg['rules'][$id][$p] = $v; }
                    break;
            }
        }
    }
    afxNotifyStoreConfig($cfg);
    return [true, 'Uloženo.'];
}

function afxNotifyStoreConfig(array $cfg): void
{
    set_setting(AFX_NOTIFY_CONFIG_KEY, json_encode($cfg, JSON_UNESCAPED_UNICODE));
    afxNotifyConfig(true);
}

function afxNotifyRuleOn(string $id): bool
{
    $cfg = afxNotifyConfig();
    return !empty($cfg['global']['enabled']) && !empty($cfg['rules'][$id]['enabled']);
}

function afxNotifyParam(string $id, string $p)
{
    $cfg = afxNotifyConfig();
    return $cfg['rules'][$id][$p] ?? (afxNotifyRules()[$id]['params'][$p]['default'] ?? null);
}

/** Tajný token pro externí cron (tick.php?key=…) — vznikne při první potřebě. */
function afxNotifyTickToken(bool $regenerate = false): string
{
    $cfg = afxNotifyConfig(true);
    if ($regenerate || strlen((string)$cfg['global']['tick_token']) < 24) {
        $cfg['global']['tick_token'] = bin2hex(random_bytes(20));
        afxNotifyStoreConfig($cfg);
    }
    return (string)$cfg['global']['tick_token'];
}

/** Veřejná adresa CRM pro odkazy ve zprávách (cron nemá HTTP_HOST). */
function afxNotifyBaseUrl(): string
{
    $u = trim((string)(afxNotifyConfig()['global']['base_url'] ?? ''));
    if ($u !== '') { return rtrim($u, '/'); }
    $known = trim((string)get_setting('smart_notify_seen_base', ''));
    if ($known !== '') { return rtrim($known, '/'); }
    $hook = trim((string)get_setting('fixer_webhook_url', ''));
    return $hook !== '' ? rtrim($hook, '/') : 'https://admin.applefix.cloud';
}

/** Zapamatuje si adresu, na které CRM běží (volá se z webových požadavků). */
function afxNotifyRememberBaseUrl(): void
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-:]{3,120}$/', $host) || str_starts_with($host, 'localhost')) { return; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    foreach (['/api', '/upozorneni', '/rozpis'] as $sub) {
        if (str_ends_with($dir, $sub)) { $dir = substr($dir, 0, -strlen($sub)); }
    }
    $url = ($https ? 'https://' : 'http://') . $host . ($dir === '.' ? '' : $dir);
    if ($url !== get_setting('smart_notify_seen_base', '')) { set_setting('smart_notify_seen_base', $url); }
}

/* ═══════════════════════════════════════════════════════════════════════════
   POMOCNÉ FUNKCE — čas, intervaly, formát
   ═══════════════════════════════════════════════════════════════════════════ */

function afxNotifyBool($v): bool
{
    return $v === true || $v === 1 || in_array(strtolower((string)$v), ['1', 'true', 'on', 'yes', 'ano'], true);
}

/** „9:05" → 545; neplatné → null. */
function afxNotifyTimeToMin(string $t): ?int
{
    if (!preg_match('/^\s*([01]?\d|2[0-3])[:.]([0-5]\d)(?::\d\d)?\s*$/', $t, $m)) { return null; }
    return (int)$m[1] * 60 + (int)$m[2];
}

function afxNotifyMinToTime(int $m): string
{
    $m = max(0, min(24 * 60, $m));
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

function afxNotifyNormTime(string $t): string
{
    $m = afxNotifyTimeToMin($t);
    return $m === null ? '' : afxNotifyMinToTime($m);
}

/** „14:00, 20:00" → ['14:00','20:00'] (seřazené, bez duplicit). */
function afxNotifyParseTimes(string $s): array
{
    $out = [];
    foreach (preg_split('/[,;\s]+/', $s) ?: [] as $p) {
        $m = afxNotifyTimeToMin($p);
        if ($m !== null) { $out[$m] = afxNotifyMinToTime($m); }
    }
    ksort($out);
    return array_values($out);
}

/**
 * Je čas $hhmm dne $now „splatný"? Ano od $hhmm do $hhmm + $window minut.
 * Okno pokrývá nepravidelné tiky; deduplikace zajistí, že se pošle jednou.
 */
function afxNotifyDue(DateTimeImmutable $now, string $hhmm, int $window = 180): bool
{
    $m = afxNotifyTimeToMin($hhmm);
    if ($m === null) { return false; }
    $nowMin = (int)$now->format('G') * 60 + (int)$now->format('i');
    return $nowMin >= $m && $nowMin < $m + $window;
}

/** Datum + „10:00:00" → DateTimeImmutable. */
function afxNotifyAt(string $date, string $time): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . substr($time, 0, 5));
    return $d ?: null;
}

/** „čt 9. 10." */
function afxNotifyDayLabel(string $date, bool $long = false): string
{
    $short = ['po', 'út', 'st', 'čt', 'pá', 'so', 'ne'];
    $full  = ['pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];
    $ts = strtotime($date);
    $i = ((int)date('N', $ts)) - 1;
    return ($long ? $full[$i] : $short[$i]) . ' ' . date('j. n.', $ts);
}

/** „dnes" / „zítra" / „čt 9. 10." podle vztahu k $now. */
function afxNotifyRelDay(string $date, DateTimeImmutable $now): string
{
    $today = $now->format('Y-m-d');
    if ($date === $today) { return 'dnes'; }
    if ($date === $now->modify('+1 day')->format('Y-m-d')) { return 'zítra'; }
    return afxNotifyDayLabel($date);
}

/** „10:00:00" → „10:00", celé hodiny „10" nechává pro stručnost i tak. */
function afxNotifyHm(string $t): string
{
    return substr($t, 0, 5);
}

/** Sloučí intervaly [[a,b],…] (minuty) do nepřekrývajících se. */
function afxNotifyMergeIntervals(array $iv): array
{
    usort($iv, static fn($x, $y) => $x[0] <=> $y[0]);
    $out = [];
    foreach ($iv as [$a, $b]) {
        if ($b <= $a) { continue; }
        if ($out && $a <= $out[count($out) - 1][1]) {
            $out[count($out) - 1][1] = max($out[count($out) - 1][1], $b);
        } else {
            $out[] = [$a, $b];
        }
    }
    return $out;
}

/** Díry v pokrytí [open, close] intervaly $covered (už sloučenými). */
function afxNotifyGaps(int $open, int $close, array $covered): array
{
    $gaps = [];
    $cur = $open;
    foreach ($covered as [$a, $b]) {
        if ($b <= $cur) { continue; }
        if ($a >= $close) { break; }
        if ($a > $cur) { $gaps[] = [$cur, min($a, $close)]; }
        $cur = max($cur, $b);
        if ($cur >= $close) { break; }
    }
    if ($cur < $close) { $gaps[] = [$cur, $close]; }
    return $gaps;
}

/**
 * Otvírací doba z textu („10:00 – 20:00", „zavřeno", „9–18").
 * Vrací [open, close] v minutách, false = zavřeno, null = nejde přečíst.
 */
function afxNotifyParseHoursText(?string $txt)
{
    $txt = trim((string)$txt);
    if ($txt === '') { return null; }
    if (preg_match('/zav[řr]en|closed|nepracuj|volno/iu', $txt)) { return false; }
    if (preg_match('/(\d{1,2})(?:[:.](\d{2}))?\s*[–—-]\s*(\d{1,2})(?:[:.](\d{2}))?/u', $txt, $m)) {
        $o = (int)$m[1] * 60 + (int)($m[2] ?? 0);
        $c = (int)$m[3] * 60 + (int)($m[4] ?? 0);
        if ($c > $o && $c <= 24 * 60) { return [$o, $c]; }
    }
    return null;
}

/**
 * Pokrytí dne směnami.
 *  open:   true/false — má pobočka ten den otevřeno?
 *  hours:  [open, close] v minutách nebo null (neznámá otvírací doba)
 *  gaps:   nepokryté úseky otvírací doby [[a,b],…]
 *  count:  počet zapsaných lidí
 *
 * Neznámá otvírací doba: když pobočka má vyplněné hodiny pro jiné dny a pro
 * tenhle ne, bere se jako zavřeno (typicky chybějící řádek „Ne"). Když nemá
 * vyplněné nic, platí po–so otevřeno, neděle zavřeno — rozumný výchozí stav
 * prodejny, aby systém nehlásil prázdnou neděli.
 */
function afxShiftCoverage(int $branchId, string $date, array $entries): array
{
    $hoursAll = afxShiftOpeningHours($branchId);
    $dow = ((int)date('N', strtotime($date))) - 1;
    $parsed = array_key_exists($dow, $hoursAll) ? afxNotifyParseHoursText($hoursAll[$dow]) : null;

    if ($parsed === false) {
        $open = false; $hours = null;
    } elseif (is_array($parsed)) {
        $open = true; $hours = $parsed;
    } elseif (array_key_exists($dow, $hoursAll)) {
        $open = true; $hours = null;              // řádek je, jen nejde přečíst čas
    } elseif ($hoursAll) {
        $open = false; $hours = null;             // jiné dny vyplněné, tenhle chybí
    } else {
        $open = ($dow !== 6); $hours = null;
    }

    $iv = [];
    foreach ($entries as $e) {
        $a = afxNotifyTimeToMin((string)$e['time_from']);
        $b = afxNotifyTimeToMin((string)$e['time_to']);
        if ($a !== null && $b !== null) { $iv[] = [$a, $b]; }
    }
    $covered = afxNotifyMergeIntervals($iv);
    $gaps = ($open && $hours && $covered) ? afxNotifyGaps($hours[0], $hours[1], $covered) : [];

    return [
        'open'    => $open,
        'hours'   => $hours,
        'covered' => $covered,
        'gaps'    => $gaps,
        'count'   => count($entries),
        'text'    => $hoursAll[$dow] ?? '',
    ];
}

/** Lidsky: [[600,720]] → „10:00–12:00". */
function afxNotifyGapsText(array $gaps): string
{
    return implode(', ', array_map(static fn($g) => afxNotifyMinToTime($g[0]) . '–' . afxNotifyMinToTime($g[1]), $gaps));
}

/** Pobočky, které se hlídají (aktivní, bez Demo servisu). */
function afxNotifyBranches(): array
{
    return array_values(array_filter(getBranches(true),
        static fn($b) => strtoupper((string)($b['code'] ?? '')) !== 'DEMO'));
}

function afxNotifyBranchName(int $branchId): string
{
    $s = function_exists('crmBranchShortLabel') ? (string)crmBranchShortLabel($branchId) : '';
    return $s !== '' ? $s : (getBranchLabel($branchId) ?: ('pobočka #' . $branchId));
}

/* ═══════════════════════════════════════════════════════════════════════════
   PŘÍJEMCI
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Adresář všech, komu jde něco poslat, podle staff_key („tech:5", „user:1").
 * Technik = zaměstnanec (tabulka technicians, role engineer/brigadnik/manager/
 * boss/accountant…). Administrátor bez technického profilu = účet z users;
 * jeho Telegram je v nastavení admin_telegram_id (stejně jako u zakázek).
 */
function afxNotifyDirectory(bool $fresh = false): array
{
    global $pdo;
    static $cache = null;
    if ($cache !== null && !$fresh) { return $cache; }
    $dir = [];
    $linked = [];   // tech_id → [user:ID…] (týž člověk přihlášený přes users)
    try {
        foreach ($pdo->query('SELECT * FROM users')->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $tid = (int)($u['technician_id'] ?? 0);
            if ($tid > 0) { $linked[$tid][] = 'user:' . (int)$u['id']; }
        }
    } catch (Throwable $e) { /* users bez technician_id */ }

    try {
        $rows = $pdo->query('SELECT * FROM technicians WHERE IFNULL(is_active,1) = 1')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    foreach ($rows as $t) {
        $id = (int)$t['id'];
        $dir['tech:' . $id] = [
            'key'       => 'tech:' . $id,
            'tech_id'   => $id,
            'name'      => (string)($t['name'] ?? ('#' . $id)),
            'role'      => (string)($t['role'] ?? 'engineer') ?: 'engineer',
            'branch_id' => (int)($t['branch_id'] ?? 0),
            'telegram'  => crmNormalizeTelegramChatId($t['telegram_id'] ?? null),
            'email'     => trim((string)($t['email'] ?? '')),
            'phone'     => trim((string)($t['phone'] ?? '')),
            'alt_keys'  => $linked[$id] ?? [],
        ];
    }

    // administrátoři z users, kteří nemají technický profil
    $g = afxNotifyConfig()['global'];
    $adminTg = crmNormalizeTelegramChatId(get_setting('admin_telegram_id', ''));
    try {
        foreach ($pdo->query("SELECT * FROM users WHERE role = 'admin' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            if ((int)($u['technician_id'] ?? 0) > 0 && isset($dir['tech:' . (int)$u['technician_id']])) { continue; }
            $dir['user:' . (int)$u['id']] = [
                'key'       => 'user:' . (int)$u['id'],
                'tech_id'   => 0,
                'name'      => (string)(($u['full_name'] ?? '') ?: ($u['username'] ?? 'Administrátor')),
                'role'      => 'admin',
                'branch_id' => 0,
                'telegram'  => $adminTg,          // jeden společný chat → jen prvnímu (níže)
                'email'     => (string)$g['admin_email'],
                'phone'     => (string)$g['admin_phone'],
                'alt_keys'  => [],
            ];
            $adminTg = null; $g['admin_email'] = ''; $g['admin_phone'] = '';
        }
    } catch (Throwable $e) { /* bez users */ }

    return $cache = $dir;
}

function afxNotifyRecipient(string $key): ?array
{
    return afxNotifyDirectory()[$key] ?? null;
}

/** Vedení = Boss a administrátoři (celofiremní přehled, obě pobočky). */
function afxNotifyManagement(): array
{
    return array_values(array_filter(afxNotifyDirectory(),
        static fn($r) => in_array($r['role'], ['boss', 'admin'], true)));
}

/** Manažeři konkrétní pobočky. */
function afxNotifyBranchManagers(int $branchId): array
{
    return array_values(array_filter(afxNotifyDirectory(),
        static fn($r) => $r['role'] === 'manager' && $r['branch_id'] === $branchId));
}

/** Vedení + manažeři pobočky (bez duplicit). */
function afxNotifyBranchLeads(int $branchId): array
{
    $out = [];
    foreach (array_merge(afxNotifyManagement(), afxNotifyBranchManagers($branchId)) as $r) { $out[$r['key']] = $r; }
    return array_values($out);
}

/**
 * Provozní zaměstnanci pobočky (ti, kdo se zapisují do rozpisu).
 * $withManagers = false u výzev „zapiš se" — manažer v tu chvíli dostává
 * variantu pro vedení a dvě zprávy o tomtéž by byly jen šum.
 */
function afxNotifyBranchStaff(int $branchId, bool $withManagers = true): array
{
    $skip = $withManagers ? ['accountant', 'boss', 'admin'] : ['accountant', 'boss', 'admin', 'manager'];
    return array_values(array_filter(afxNotifyDirectory(),
        static fn($r) => $r['tech_id'] > 0 && $r['branch_id'] === $branchId && !in_array($r['role'], $skip, true)));
}

function afxNotifyAccountants(): array
{
    return array_values(array_filter(afxNotifyDirectory(), static fn($r) => $r['role'] === 'accountant'));
}

/** Osobní nastavení příjemce (výchozí, když si nic nenastavil). */
function afxNotifyPrefs(string $key, bool $fresh = false): array
{
    global $pdo;
    static $cache = [];
    if (isset($cache[$key]) && !$fresh) { return $cache[$key]; }
    $p = ['reminder_minutes' => null, 'evening_before' => 1, 'ch_telegram' => 1, 'ch_push' => 1,
          'ch_email' => 0, 'ch_sms' => 1, 'muted' => []];
    try {
        $st = $pdo->prepare('SELECT * FROM smart_notify_prefs WHERE staff_key = ?');
        $st->execute([$key]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $p = array_merge($p, $r);
            $p['muted'] = array_values(array_filter(explode(',', (string)$r['muted'])));
        }
    } catch (Throwable $e) { /* tabulka ještě není */ }
    return $cache[$key] = $p;
}

function afxNotifySavePrefs(string $key, array $in): array
{
    global $pdo;
    afxNotifyEnsureSchema();
    $rules = afxNotifyRules();
    $muted = [];
    foreach ((array)($in['muted'] ?? []) as $m) { if (isset($rules[$m])) { $muted[] = $m; } }
    $min = ($in['reminder_minutes'] ?? '') === '' ? null : max(5, min(720, (int)$in['reminder_minutes']));
    try {
        $pdo->prepare('INSERT INTO smart_notify_prefs (staff_key, reminder_minutes, evening_before, ch_telegram, ch_push, ch_email, ch_sms, muted)
                       VALUES (?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE reminder_minutes=VALUES(reminder_minutes), evening_before=VALUES(evening_before),
                           ch_telegram=VALUES(ch_telegram), ch_push=VALUES(ch_push), ch_email=VALUES(ch_email),
                           ch_sms=VALUES(ch_sms), muted=VALUES(muted)')
            ->execute([$key, $min, (int)afxNotifyBool($in['evening_before'] ?? 0),
                       (int)afxNotifyBool($in['ch_telegram'] ?? 0), (int)afxNotifyBool($in['ch_push'] ?? 0),
                       (int)afxNotifyBool($in['ch_email'] ?? 0), (int)afxNotifyBool($in['ch_sms'] ?? 0),
                       implode(',', $muted)]);
    } catch (Throwable $e) {
        error_log('afxNotifySavePrefs: ' . $e->getMessage());
        return [false, 'Uložení selhalo.'];
    }
    afxNotifyPrefs($key, true);
    return [true, 'Uloženo.'];
}

/** Hlavní klíč přihlášeného (technický profil má přednost, viz dual-login). */
function afxNotifyMyKey(): string
{
    $tid = afxShiftCurrentTechId();
    if ($tid > 0) { return 'tech:' . $tid; }
    return function_exists('crmStaffKey') ? crmStaffKey() : '';
}

/** Všechny klíče, pod kterými může mít přihlášený upozornění (users ↔ technicians). */
function afxNotifyMyKeys(): array
{
    $keys = array_filter([afxNotifyMyKey(), function_exists('crmStaffKey') ? crmStaffKey() : '']);
    return array_values(array_unique($keys));
}

/* ═══════════════════════════════════════════════════════════════════════════
   DORUČENÍ
   ═══════════════════════════════════════════════════════════════════════════ */

/** Režim „nanečisto": místo odeslání se zprávy jen sbírají (náhled v UI). */
function afxNotifyDryRun(?bool $set = null): bool
{
    static $dry = false;
    if ($set !== null) { $dry = $set; afxNotifyCollected([]); }
    return $dry;
}

function afxNotifyCollected(?array $reset = null, ?array $add = null): array
{
    static $items = [];
    if ($reset !== null) { $items = $reset; }
    if ($add !== null) { $items[] = $add; }
    return $items;
}

/** Text s **tučným** → HTML pro Telegram/e-mail, nebo prostý text. */
function afxNotifyFormat(string $text, string $target): string
{
    if ($target === 'plain') { return str_replace('**', '', $text); }
    $h = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $h = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $h) ?? $h;
    return $target === 'email' ? nl2br($h) : $h;
}

function afxNotifyAbsUrl(string $url): string
{
    if ($url === '') { return ''; }
    if (preg_match('#^https?://#i', $url)) { return $url; }
    return afxNotifyBaseUrl() . '/' . ltrim($url, '/');
}

function afxNotifyIsQuiet(DateTimeImmutable $now): bool
{
    $g = afxNotifyConfig()['global'];
    $f = afxNotifyTimeToMin((string)$g['quiet_from']);
    $t = afxNotifyTimeToMin((string)$g['quiet_to']);
    if ($f === null || $t === null || $f === $t) { return false; }
    $n = (int)$now->format('G') * 60 + (int)$now->format('i');
    return $f < $t ? ($n >= $f && $n < $t) : ($n >= $f || $n < $t);
}

/** Telegram s tlačítkem „Otevřít v CRM" a tichým režimem v noci. */
function afxNotifyTelegram(string $chatId, string $html, string $url, bool $silent): array
{
    if (!defined('TG_BOT_TOKEN') || TG_BOT_TOKEN === '') { return [false, 'Telegram bot není nastavený']; }
    $data = [
        'chat_id' => $chatId,
        'text' => $html,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
        'disable_notification' => $silent,
    ];
    // URL tlačítka musí být veřejná https adresa, jinak Telegram celou zprávu odmítne
    if ($url !== '' && preg_match('#^https://[a-z0-9.\-]+\.[a-z]{2,}(/|$)#i', $url)) {
        $data['reply_markup'] = json_encode(['inline_keyboard' => [[['text' => 'Otevřít v CRM', 'url' => $url]]]]);
    }
    $ch = curl_init('https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $j = is_string($resp) ? json_decode($resp, true) : null;
    if (is_array($j) && !empty($j['ok'])) { return [true, '']; }
    return [false, 'Telegram: ' . ($j['description'] ?? $err ?: 'neznámá chyba')];
}

/**
 * Doručí jedno upozornění jednomu příjemci. Vrací true, když se opravdu
 * odeslalo (false = už bylo poslané dřív, ztlumené, nebo vypnuté).
 *
 * $o: url, level (info|warn|urgent), sms (bool — pravidlo dovoluje SMS),
 *     now (DateTimeImmutable), force_channels (test)
 */
function afxNotifyDeliver(array $r, string $rule, string $dedupe, string $title, string $body, array $o = []): bool
{
    global $pdo;
    afxNotifyEnsureSchema();
    $now   = $o['now'] ?? new DateTimeImmutable();
    $level = (string)($o['level'] ?? 'info');
    $url   = (string)($o['url'] ?? '');
    $key   = (string)$r['key'];
    $dkey  = mb_substr($rule . '|' . $dedupe . '|' . $key, 0, 190);

    $prefs = afxNotifyPrefs($key);
    if (in_array($rule, $prefs['muted'], true) && empty($o['force_channels'])) { return false; }

    if (afxNotifyDryRun()) {
        // nanečisto: ukázat jen to, co by opravdu odešlo (ne už poslané)
        try {
            $st = $pdo->prepare('SELECT 1 FROM smart_notify_log WHERE dedupe_key = ?');
            $st->execute([$dkey]);
            if ($st->fetchColumn()) { return false; }
        } catch (Throwable $e) { /* bez logu */ }
        afxNotifyCollected(null, ['rule' => $rule, 'to' => $r['name'], 'role' => $r['role'],
            'level' => $level, 'title' => $title, 'body' => afxNotifyFormat($body, 'plain')]);
        return true;
    }

    // CLAIM: UNIQUE index rozhodne, kdo pošle (souběžné tiky se nepotkají)
    try {
        $ins = $pdo->prepare('INSERT IGNORE INTO smart_notify_log (dedupe_key, rule, recipient_key, recipient_name, level, title, body, url)
                              VALUES (?,?,?,?,?,?,?,?)');
        $ins->execute([$dkey, $rule, $key, mb_substr((string)$r['name'], 0, 120), $level,
                       mb_substr($title, 0, 190), afxNotifyFormat($body, 'plain'), mb_substr($url, 0, 255)]);
        if ($ins->rowCount() === 0) { return false; }
        $logId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('afxNotifyDeliver claim: ' . $e->getMessage());
        return false;
    }

    $g = afxNotifyConfig()['global'];
    $sent = ['crm'];
    $errors = [];
    $abs = afxNotifyAbsUrl($url);
    $icon = ['urgent' => '🚨', 'warn' => '⚠️'][$level] ?? '';

    // 1) upozornění v CRM — vždy (toast + přehled na stránce Upozornění)
    try {
        $pdo->prepare('INSERT INTO smart_notify_inbox (staff_key, rule, level, title, body, url) VALUES (?,?,?,?,?,?)')
            ->execute([$key, $rule, $level, mb_substr($title, 0, 190), afxNotifyFormat($body, 'plain'), mb_substr($url, 0, 255)]);
    } catch (Throwable $e) { $errors[] = 'CRM: ' . $e->getMessage(); }

    // 2) Telegram
    if (!empty($g['ch_telegram']) && !empty($prefs['ch_telegram']) && !empty($r['telegram'])
        && defined('TG_BOT_TOKEN') && TG_BOT_TOKEN !== '') {
        $html = ($icon !== '' ? $icon . ' ' : '') . '<b>' . afxNotifyFormat($title, 'html') . "</b>\n" . afxNotifyFormat($body, 'html');
        [$ok, $err] = afxNotifyTelegram((string)$r['telegram'], $html, $abs, $level !== 'urgent' && afxNotifyIsQuiet($now));
        if ($ok) { $sent[] = 'telegram'; } else { $errors[] = $err; }
    }

    // 3) iOS push (appka) — pod hlavním klíčem i pod propojeným účtem z users
    if (!empty($g['ch_push']) && !empty($prefs['ch_push']) && function_exists('apnsConfigured') && apnsConfigured()) {
        try {
            $pushBody = mb_substr(preg_replace('/\s*\n\s*/u', ' · ', afxNotifyFormat($body, 'plain')) ?? '', 0, 220);
            foreach (array_merge([$key], (array)($r['alt_keys'] ?? [])) as $k) {
                pushToStaff($pdo, $k, ($icon !== '' ? $icon . ' ' : '') . $title, $pushBody,
                    ['data' => ['url' => $url], 'collapse' => mb_substr($rule . '-' . md5($dedupe), 0, 60)]);
            }
            $sent[] = 'push';
        } catch (Throwable $e) { $errors[] = 'push: ' . $e->getMessage(); }
    }

    // 4) e-mail — jen kdo si ho zapnul (nebo vedení s vyplněným e-mailem)
    if (!empty($g['ch_email']) && !empty($prefs['ch_email']) && filter_var((string)$r['email'], FILTER_VALIDATE_EMAIL)
        && function_exists('smtpSendMail')) {
        $html = '<div style="font-family:-apple-system,Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.5;color:#111">'
              . '<h2 style="font-size:18px;margin:0 0 12px">' . afxNotifyFormat($title, 'html') . '</h2>'
              . '<p style="margin:0 0 16px">' . afxNotifyFormat($body, 'email') . '</p>'
              . ($abs !== '' ? '<p><a href="' . htmlspecialchars($abs, ENT_QUOTES) . '" style="display:inline-block;padding:10px 18px;background:#0a84ff;color:#fff;border-radius:10px;text-decoration:none">Otevřít v CRM</a></p>' : '')
              . '<p style="color:#888;font-size:12px;margin-top:24px">Chytrá upozornění Fix-CRM · nastavení najdeš v CRM → Upozornění</p></div>';
        try {
            [$ok, $err] = smtpSendMail((string)$r['email'], $title, $html);
            if ($ok) { $sent[] = 'email'; } else { $errors[] = 'e-mail: ' . $err; }
        } catch (Throwable $e) { $errors[] = 'e-mail: ' . $e->getMessage(); }
    }

    // 5) SMS — jen pravidla, která ji dovolují, a jen když ji vedení zapnulo
    if (!empty($o['sms']) && !empty($g['ch_sms']) && !empty($prefs['ch_sms']) && trim((string)$r['phone']) !== ''
        && function_exists('crmSendSms')) {
        try {
            [$ok, $err] = crmSendSms((string)$r['phone'], mb_substr($title . ': ' . preg_replace('/\s*\n\s*/u', ' ', afxNotifyFormat($body, 'plain')), 0, 300));
            if ($ok) { $sent[] = 'sms'; } else { $errors[] = 'SMS: ' . $err; }
        } catch (Throwable $e) { $errors[] = 'SMS: ' . $e->getMessage(); }
    }

    try {
        $pdo->prepare('UPDATE smart_notify_log SET channels = ?, error = ? WHERE id = ?')
            ->execute([implode(',', $sent), mb_substr(implode(' | ', $errors), 0, 255), $logId]);
    } catch (Throwable $e) { /* jen log */ }
    return true;
}

/** Stejná zpráva více příjemcům; vrací počet skutečně odeslaných. */
function afxNotifyDeliverMany(array $recipients, string $rule, string $dedupe, string $title, string $body, array $o = [], array $excludeKeys = []): int
{
    $n = 0;
    $seen = [];
    foreach ($recipients as $r) {
        if (!$r || isset($seen[$r['key']]) || in_array($r['key'], $excludeKeys, true)) { continue; }
        $seen[$r['key']] = true;
        if (afxNotifyDeliver($r, $rule, $dedupe, $title, $body, $o)) { $n++; }
    }
    return $n;
}

/** Bylo už pro tenhle klíč (bez příjemce) něco posláno? */
function afxNotifyWasSent(string $rule, string $dedupePrefix): bool
{
    global $pdo;
    try {
        $st = $pdo->prepare('SELECT 1 FROM smart_notify_log WHERE dedupe_key LIKE ? LIMIT 1');
        $st->execute([str_replace(['%', '_'], ['\%', '\_'], $rule . '|' . $dedupePrefix) . '%']);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   DATA PRO PRAVIDLA
   ═══════════════════════════════════════════════════════════════════════════ */

/** Všechny zápisy rozpisu v rozsahu dní napříč pobočkami. */
function afxNotifyShiftsBetween(string $from, string $to): array
{
    global $pdo;
    afxShiftEnsureSchema();
    try {
        $st = $pdo->prepare("SELECT s.*, t.name AS tech_name, IFNULL(t.role,'engineer') AS tech_role
                             FROM shift_plan s LEFT JOIN technicians t ON t.id = s.tech_id
                             WHERE s.work_date BETWEEN ? AND ? AND IFNULL(t.is_active,1) = 1
                             ORDER BY s.work_date, s.branch_id, s.time_from");
        $st->execute([$from, $to]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Zápisy seskupené [branch_id][date][]. */
function afxNotifyGroupShifts(array $rows): array
{
    $out = [];
    foreach ($rows as $r) { $out[(int)$r['branch_id']][(string)$r['work_date']][] = $r; }
    return $out;
}

/** Kdo v daný den v týdnu obvykle pracuje (posledních 8 týdnů) — návrh pro vedení. */
function afxNotifyUsualStaff(int $branchId, string $date, array $excludeTechIds = []): array
{
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT s.tech_id, t.name, COUNT(*) AS n
                             FROM shift_plan s JOIN technicians t ON t.id = s.tech_id
                             WHERE s.branch_id = ? AND s.work_date >= DATE_SUB(?, INTERVAL 56 DAY) AND s.work_date < ?
                               AND DAYOFWEEK(s.work_date) = DAYOFWEEK(?) AND IFNULL(t.is_active,1) = 1
                             GROUP BY s.tech_id, t.name ORDER BY n DESC, t.name LIMIT 6");
        $st->execute([$branchId, $date, $date, $date]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    return array_values(array_filter($rows, static fn($r) => !in_array((int)$r['tech_id'], $excludeTechIds, true)));
}

/** Technici, kteří mají v daný den směnu na jakékoli pobočce. */
function afxNotifyBusyTechIds(string $date): array
{
    return array_map(static fn($r) => (int)$r['tech_id'], afxNotifyShiftsBetween($date, $date));
}

function afxNotifyTableExists(string $table): bool
{
    global $pdo;
    static $cache = [];
    if (isset($cache[$table])) { return $cache[$table]; }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$table]);
        return $cache[$table] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) { return $cache[$table] = false; }
}

function afxNotifyColumnExists(string $table, string $col): bool
{
    global $pdo;
    static $cache = [];
    $k = $table . '.' . $col;
    if (isset($cache[$k])) { return $cache[$k]; }
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $st->execute([$table, $col]);
        return $cache[$k] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) { return $cache[$k] = false; }
}

/** Převzatá pokladna pobočky (otevřená směna), nebo null. */
function afxNotifyOpenPosShift(int $branchId): ?array
{
    global $pdo;
    if (!afxNotifyTableExists('pos_shifts')) { return null; }
    try {
        $st = $pdo->prepare("SELECT * FROM pos_shifts WHERE status = 'open' AND branch_id = ?
                             ORDER BY opened_at DESC LIMIT 1");
        $st->execute([$branchId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
}

/** Převzal dnes někdo pokladnu pobočky (i když ji už uzavřel)? */
function afxNotifyPosTakenToday(int $branchId, string $date): bool
{
    global $pdo;
    if (!afxNotifyTableExists('pos_shifts')) { return true; }    // bez pokladny nic nehlásit
    try {
        $st = $pdo->prepare("SELECT 1 FROM pos_shifts WHERE (branch_id = ? OR branch_id IS NULL)
                             AND (status = 'open' OR DATE(opened_at) = ?) LIMIT 1");
        $st->execute([$branchId, $date]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return true; }
}

/**
 * Je zaměstnanec od $since „vidět"? Přihlášení do CRM (technicians.last_seen,
 * evidence přítomnosti i přes propojený účet z users) nebo převzatá pokladna.
 */
function afxNotifySeenSince(int $techId, DateTimeImmutable $since): bool
{
    global $pdo;
    $s = $since->format('Y-m-d H:i:s');
    try {
        $st = $pdo->prepare('SELECT 1 FROM technicians WHERE id = ? AND last_seen >= ?');
        $st->execute([$techId, $s]);
        if ($st->fetchColumn()) { return true; }
    } catch (Throwable $e) { /* bez last_seen */ }
    if (afxNotifyTableExists('staff_presence_daily')) {
        try {
            $st = $pdo->prepare("SELECT 1 FROM staff_presence_daily
                                 WHERE work_date = ? AND last_seen >= ?
                                   AND ((staff_type = 'tech' AND user_id = ?)
                                     OR (staff_type = 'user' AND user_id IN (SELECT id FROM users WHERE technician_id = ?)))
                                 LIMIT 1");
            $st->execute([$since->format('Y-m-d'), $s, $techId, $techId]);
            if ($st->fetchColumn()) { return true; }
        } catch (Throwable $e) { /* users bez technician_id */ }
    }
    if (afxNotifyTableExists('pos_shifts')) {
        try {
            $st = $pdo->prepare('SELECT 1 FROM pos_shifts WHERE opened_by_tech = ? AND opened_at >= ? LIMIT 1');
            $st->execute([$techId, $s]);
            if ($st->fetchColumn()) { return true; }
        } catch (Throwable $e) { /* starší pokladna */ }
    }
    return false;
}

/** Krátký briefing pobočky do připomínky směny. */
function afxNotifyBranchBrief(int $branchId, string $date): array
{
    global $pdo;
    $lines = [];
    $legacy = afxNotifyColumnExists('orders', 'source') ? " AND IFNULL(source,'') <> 'legacy'" : '';
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE branch_id = ? AND status IN (' . orderStatusSqlIn($pdo, 'completed') . ')' . $legacy);
        $st->execute([$branchId]);
        if (($n = (int)$st->fetchColumn()) > 0) { $lines[] = '📦 k vydání ' . $n; }
        $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE branch_id = ? AND status IN (' . orderStatusSqlIn($pdo, 'active') . ')' . $legacy);
        $st->execute([$branchId]);
        if (($n = (int)$st->fetchColumn()) > 0) { $lines[] = '🛠 rozpracováno ' . $n; }
        $st = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE branch_id = ? AND status IN (' . orderStatusSqlIn($pdo, 'waiting_parts') . ')' . $legacy);
        $st->execute([$branchId]);
        if (($n = (int)$st->fetchColumn()) > 0) { $lines[] = '⏳ čeká na díl ' . $n; }
    } catch (Throwable $e) { /* briefing je jen bonus */ }
    return $lines;
}

/* ═══════════════════════════════════════════════════════════════════════════
   PRAVIDLA — ROZPIS SLUŽEB
   ═══════════════════════════════════════════════════════════════════════════ */

/** Kolegové na stejné směně (jména + časy), bez dotyčného. */
function afxNotifyColleaguesText(array $dayEntries, int $techId): string
{
    $parts = [];
    foreach ($dayEntries as $e) {
        if ((int)$e['tech_id'] === $techId) { continue; }
        $parts[] = (string)$e['tech_name'] . ' (' . afxNotifyHm((string)$e['time_from']) . '–' . afxNotifyHm((string)$e['time_to']) . ')';
    }
    return $parts ? implode(', ', $parts) : 'nikdo další — jsi tam sám/sama';
}

function afxNotifyRuleShiftReminder(DateTimeImmutable $now): int
{
    if (!afxNotifyRuleOn('shift_reminder')) { return 0; }
    $default = (int)afxNotifyParam('shift_reminder', 'minutes');
    $brief = (bool)afxNotifyParam('shift_reminder', 'brief');
    $today = $now->format('Y-m-d');
    $rows = afxNotifyShiftsBetween($today, $now->modify('+1 day')->format('Y-m-d'));
    $byDay = afxNotifyGroupShifts($rows);
    $n = 0;
    foreach ($rows as $e) {
        $r = afxNotifyRecipient('tech:' . (int)$e['tech_id']);
        if (!$r) { continue; }
        $start = afxNotifyAt((string)$e['work_date'], (string)$e['time_from']);
        if (!$start || $start <= $now) { continue; }
        $prefs = afxNotifyPrefs($r['key']);
        $min = $prefs['reminder_minutes'] !== null ? (int)$prefs['reminder_minutes'] : $default;
        if ($now < $start->modify('-' . $min . ' minutes')) { continue; }

        $bid = (int)$e['branch_id'];
        $date = (string)$e['work_date'];
        $left = (int)round(($start->getTimestamp() - $now->getTimestamp()) / 60);
        $when = $left >= 90 ? ('v ' . afxNotifyHm((string)$e['time_from'])) : ('za ' . $left . ' min');
        $cov = afxShiftCoverage($bid, $date, $byDay[$bid][$date] ?? []);

        $body = '**' . afxNotifyBranchName($bid) . '** · ' . afxNotifyRelDay($date, $now) . ' '
              . afxNotifyHm((string)$e['time_from']) . '–' . afxNotifyHm((string)$e['time_to']) . "\n"
              . 'S tebou: ' . afxNotifyColleaguesText($byDay[$bid][$date] ?? [], (int)$e['tech_id']);
        if ($cov['text'] !== '') { $body .= "\nOtevřeno " . $cov['text']; }
        if (trim((string)$e['note']) !== '') { $body .= "\nPoznámka: " . trim((string)$e['note']); }
        if ($brief) {
            $b = afxNotifyBranchBrief($bid, $date);
            if ($b) { $body .= "\n\nNa pobočce: " . implode(' · ', $b); }
            // je první na směně a pokladnu ještě nikdo nepřevzal?
            $first = true;
            foreach ($byDay[$bid][$date] ?? [] as $o) { if ((string)$o['time_from'] < (string)$e['time_from']) { $first = false; break; } }
            if ($first && $date === $today && !afxNotifyPosTakenToday($bid, $date)) {
                $body .= "\n💡 Jdeš první — při příchodu převezmi pokladnu.";
            }
        }
        $dd = (int)$e['id'] . '|' . $date . '|' . afxNotifyHm((string)$e['time_from']);
        if (afxNotifyDeliver($r, 'shift_reminder', $dd, 'Směna ti začíná ' . $when, $body,
            ['now' => $now, 'url' => 'rozpis.php?b=' . $bid . '&t=' . $date])) { $n++; }
    }
    return $n;
}

function afxNotifyRuleShiftEvening(DateTimeImmutable $now): int
{
    if (!afxNotifyRuleOn('shift_evening')) { return 0; }
    if (!afxNotifyDue($now, (string)afxNotifyParam('shift_evening', 'time'), 150)) { return 0; }
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $rows = afxNotifyShiftsBetween($tomorrow, $tomorrow);
    $byDay = afxNotifyGroupShifts($rows);
    $n = 0;
    foreach ($rows as $e) {
        $r = afxNotifyRecipient('tech:' . (int)$e['tech_id']);
        if (!$r || empty(afxNotifyPrefs($r['key'])['evening_before'])) { continue; }
        $bid = (int)$e['branch_id'];
        $body = '**' . afxNotifyDayLabel($tomorrow, true) . '** · ' . afxNotifyHm((string)$e['time_from']) . '–'
              . afxNotifyHm((string)$e['time_to']) . ' · **' . afxNotifyBranchName($bid) . "**\n"
              . 'S tebou: ' . afxNotifyColleaguesText($byDay[$bid][$tomorrow] ?? [], (int)$e['tech_id']);
        if (trim((string)$e['note']) !== '') { $body .= "\nPoznámka: " . trim((string)$e['note']); }
        $body .= "\n\nKdyby to nešlo, dej co nejdřív vědět vedení.";
        if (afxNotifyDeliver($r, 'shift_evening', (int)$e['tech_id'] . '|' . $tomorrow, 'Zítra jdeš do práce', $body,
            ['now' => $now, 'url' => 'rozpis.php?b=' . $bid . '&t=' . $tomorrow])) { $n++; }
    }
    return $n;
}

function afxNotifyRuleShiftUncovered(DateTimeImmutable $now): int
{
    if (!afxNotifyRuleOn('shift_uncovered')) { return 0; }
    $times = afxNotifyParseTimes((string)afxNotifyParam('shift_uncovered', 'times'));
    $appeal = (bool)afxNotifyParam('shift_uncovered', 'appeal_staff');
    $smsLast = (bool)afxNotifyParam('shift_uncovered', 'sms_last');
    $n = 0;

    // ── den předem: zítřek bez lidí ──
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $stage = null;
    foreach ($times as $i => $t) {
        // okno do další kontroly (nebo do půlnoci) — pozdní tik nespustí starší stupeň
        $next = $times[$i + 1] ?? '23:59';
        $win = max(1, (int)afxNotifyTimeToMin($next) - (int)afxNotifyTimeToMin($t));
        if (afxNotifyDue($now, $t, $win)) { $stage = $i; }
    }
    if ($stage !== null) {
        $byDay = afxNotifyGroupShifts(afxNotifyShiftsBetween($tomorrow, $tomorrow));
        $busy = afxNotifyBusyTechIds($tomorrow);
        $isLast = ($stage === count($times) - 1);
        foreach (afxNotifyBranches() as $b) {
            $bid = (int)$b['id'];
            $cov = afxShiftCoverage($bid, $tomorrow, $byDay[$bid][$tomorrow] ?? []);
            if (!$cov['open'] || $cov['count'] > 0) { continue; }
            $name = afxNotifyBranchName($bid);
            $usual = afxNotifyUsualStaff($bid, $tomorrow, $busy);
            $body = 'Na **' . afxNotifyDayLabel($tomorrow, true) . '** se na pobočce **' . $name . '** zatím nikdo nezapsal.';
            if ($cov['text'] !== '') { $body .= "\nOtevírací doba: " . $cov['text']; }
            $body .= "\nZaměstnanci se můžou zapsat do půlnoci, pak už jen vedení.";
            if ($usual) {
                $body .= "\n\nV tenhle den obvykle chodí: " . implode(', ', array_map(static fn($u) => $u['name'] . ' (' . $u['n'] . '×)', $usual));
            }
            $url = 'rozpis.php?b=' . $bid . '&t=' . $tomorrow;
            $title = $isLast && $stage > 0 ? 'Pořád nikdo na zítřek — ' . $name : 'Zítra nikdo na směně — ' . $name;
            $n += afxNotifyDeliverMany(afxNotifyBranchLeads($bid), 'shift_uncovered', $bid . '|' . $tomorrow . '|s' . $stage,
                $title, $body, ['now' => $now, 'url' => $url, 'level' => $isLast ? 'urgent' : 'warn', 'sms' => $isLast && $smsLast]);

            if ($appeal && $stage === 0) {
                $usualIds = array_map(static fn($u) => (int)$u['tech_id'], $usual);
                foreach (afxNotifyBranchStaff($bid, false) as $s) {
                    if (in_array($s['tech_id'], $busy, true)) { continue; }   // zítra už pracuje jinde
                    $hint = in_array($s['tech_id'], $usualIds, true) ? "\nV tenhle den obvykle chodíš ty — šlo by to?" : '';
                    if (afxNotifyDeliver($s, 'shift_uncovered', $bid . '|' . $tomorrow . '|appeal',
                        'Zítra nikdo na pobočce ' . $name, 'Na **' . afxNotifyDayLabel($tomorrow, true) . '** se zatím nikdo nezapsal.'
                        . ($cov['text'] !== '' ? "\nOtevřeno " . $cov['text'] : '') . $hint . "\nZápis jde do půlnoci — stačí den a čas.",
                        ['now' => $now, 'url' => $url, 'level' => 'warn'])) { $n++; }
                }
            }
        }
    }

    // ── ráno: dnešek bez lidí ──
    $morning = (string)afxNotifyParam('shift_uncovered', 'morning');
    if ($morning !== '' && afxNotifyDue($now, $morning, 240)) {
        $today = $now->format('Y-m-d');
        $byDay = afxNotifyGroupShifts(afxNotifyShiftsBetween($today, $today));
        foreach (afxNotifyBranches() as $b) {
            $bid = (int)$b['id'];
            $cov = afxShiftCoverage($bid, $today, $byDay[$bid][$today] ?? []);
            if (!$cov['open'] || $cov['count'] > 0) { continue; }
            $name = afxNotifyBranchName($bid);
            $body = 'Na **' . $name . '** dnes není nikdo zapsaný v rozpisu'
                  . ($cov['text'] !== '' ? ' (otevřeno ' . $cov['text'] . ')' : '') . ".\nPokud prodejna otevírá, je potřeba to hned vyřešit.";
            $n += afxNotifyDeliverMany(afxNotifyBranchLeads($bid), 'shift_uncovered', $bid . '|' . $today . '|today',
                'DNES nikdo na směně — ' . $name, $body,
                ['now' => $now, 'url' => 'rozpis.php?b=' . $bid . '&t=' . $today, 'level' => 'urgent', 'sms' => $smsLast]);
        }
    }
    return $n;
}

function afxNotifyRuleShiftGap(DateTimeImmutable $now): int
{
    if (!afxNotifyRuleOn('shift_gap')) { return 0; }
    if (!afxNotifyDue($now, (string)afxNotifyParam('shift_gap', 'time'), 240)) { return 0; }
    $minGap = (int)afxNotifyParam('shift_gap', 'min_gap');
    $appeal = (bool)afxNotifyParam('shift_gap', 'appeal_staff');
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $byDay = afxNotifyGroupShifts(afxNotifyShiftsBetween($tomorrow, $tomorrow));
    $n = 0;
    foreach (afxNotifyBranches() as $b) {
        $bid = (int)$b['id'];
        $cov = afxShiftCoverage($bid, $tomorrow, $byDay[$bid][$tomorrow] ?? []);
        if (!$cov['open'] || $cov['count'] === 0 || !$cov['hours']) { continue; }
        $gaps = array_values(array_filter($cov['gaps'], static fn($g) => $g[1] - $g[0] >= $minGap));
        if (!$gaps) { continue; }
        $name = afxNotifyBranchName($bid);
        $who = implode(', ', array_map(static fn($e) => $e['tech_name'] . ' ' . afxNotifyHm((string)$e['time_from']) . '–' . afxNotifyHm((string)$e['time_to']),
            $byDay[$bid][$tomorrow]));
        $body = '**' . afxNotifyDayLabel($tomorrow, true) . '**, ' . $name . ' (otevřeno ' . afxNotifyMinToTime($cov['hours'][0]) . '–' . afxNotifyMinToTime($cov['hours'][1]) . ")\n"
              . 'Bez obsluhy: **' . afxNotifyGapsText($gaps) . "**\nZapsáno: " . $who;
        $url = 'rozpis.php?b=' . $bid . '&t=' . $tomorrow;
        $sig = md5(json_encode($gaps));
        $n += afxNotifyDeliverMany(afxNotifyBranchLeads($bid), 'shift_gap', $bid . '|' . $tomorrow . '|' . $sig,
            'Zítra díra v rozpisu — ' . $name, $body, ['now' => $now, 'url' => $url, 'level' => 'warn']);
        if ($appeal) {
            $busy = afxNotifyBusyTechIds($tomorrow);
            foreach (afxNotifyBranchStaff($bid, false) as $s) {
                if (in_array($s['tech_id'], $busy, true)) { continue; }
                if (afxNotifyDeliver($s, 'shift_gap', $bid . '|' . $tomorrow . '|' . $sig . '|appeal', 'Zítra chybí člověk — ' . $name,
                    'Zítra (' . afxNotifyDayLabel($tomorrow) . ') je bez obsluhy **' . afxNotifyGapsText($gaps) . "**.\nMůžeš se zapsat do půlnoci.",
                    ['now' => $now, 'url' => $url])) { $n++; }
            }
        }
    }
    return $n;
}

function afxNotifyRuleShiftWeek(DateTimeImmutable $now): int
{
    if (!afxNotifyRuleOn('shift_week')) { return 0; }
    $day = (string)afxNotifyParam('shift_week', 'day');
    if (strtolower($now->format('D')) !== $day) { return 0; }
    if (!afxNotifyDue($now, (string)afxNotifyParam('shift_week', 'time'), 360)) { return 0; }
    $maxH = (int)afxNotifyParam('shift_week', 'max_hours');
    $maxD = (int)afxNotifyParam('shift_week', 'max_days');

    $mon = $now->modify('monday next week');
    $days = afxShiftWeekDays($mon->format('Y-m-d'));
    $rows = afxNotifyShiftsBetween($days[0], $days[6]);
    $byDay = afxNotifyGroupShifts($rows);
    $weekLabel = date('j. n.', strtotime($days[0])) . ' – ' . date('j. n.', strtotime($days[6]));
    $n = 0;
    $mgmtParts = [];

    // přetížení lidí napříč pobočkami (hodiny, dny v kuse včetně konce tohoto týdne)
    $hours = []; $names = []; $workDays = [];
    foreach (afxNotifyShiftsBetween($mon->modify('-13 day')->format('Y-m-d'), $days[6]) as $e) {
        $tid = (int)$e['tech_id'];
        $names[$tid] = (string)$e['tech_name'];
        $workDays[$tid][(string)$e['work_date']] = true;
        if ((string)$e['work_date'] >= $days[0]) {
            $hours[$tid] = ($hours[$tid] ?? 0)
                + max(0, (int)afxNotifyTimeToMin((string)$e['time_to']) - (int)afxNotifyTimeToMin((string)$e['time_from'])) / 60;
        }
    }
    $warn = [];
    foreach ($hours as $tid => $h) {
        if ($h > $maxH) { $warn[] = $names[$tid] . ' ' . round($h, 1) . ' h'; }
    }
    foreach ($workDays as $tid => $set) {
        $run = 0; $best = 0;
        for ($d = $mon->modify('-13 day'); $d->format('Y-m-d') <= $days[6]; $d = $d->modify('+1 day')) {
            $run = isset($set[$d->format('Y-m-d')]) ? $run + 1 : 0;
            // počítá se jen série, která zasahuje do příštího týdne
            if ($d->format('Y-m-d') >= $days[0]) { $best = max($best, $run); }
        }
        if ($best > $maxD) { $warn[] = $names[$tid] . ' ' . $best . ' dní v kuse'; }
    }

    foreach (afxNotifyBranches() as $b) {
        $bid = (int)$b['id'];
        $empty = []; $holes = []; $openDays = 0;
        foreach ($days as $d) {
            $cov = afxShiftCoverage($bid, $d, $byDay[$bid][$d] ?? []);
            if (!$cov['open']) { continue; }
            $openDays++;
            if ($cov['count'] === 0) { $empty[] = afxNotifyDayLabel($d); }
            elseif ($cov['gaps']) { $holes[] = afxNotifyDayLabel($d) . ' (' . afxNotifyGapsText($cov['gaps']) . ')'; }
        }
        $name = afxNotifyBranchName($bid);
        $line = '**' . $name . '**: ';
        if (!$empty && !$holes) { $line .= 'pokryto ✅'; }
        else {
            if ($empty && !$holes && count($empty) === $openDays) { $line .= 'zatím úplně prázdný (' . $openDays . ' otevřených dní)'; }
            elseif ($empty) { $line .= 'nikdo — ' . implode(', ', $empty); }
            if ($holes) { $line .= ($empty ? '; ' : '') . 'díry — ' . implode(', ', $holes); }
        }
        $mgmtParts[$bid] = $line;

        if ($empty || $holes) {
            $url = 'rozpis.php?b=' . $bid . '&t=' . $days[0];
            $msg = 'Příští týden (' . $weekLabel . ') na pobočce **' . $name . '** chybí lidi'
                 . ($empty ? ":\nnikdo zapsaný — " . implode(', ', $empty) : '')
                 . ($holes ? "\nčástečně — " . implode(', ', $holes) : '')
                 . "\n\nZapiš se, kdy můžeš — na každý den nejpozději do půlnoci předem.";
            $n += afxNotifyDeliverMany(afxNotifyBranchStaff($bid, false), 'shift_week', $days[0] . '|' . $bid . '|appeal',
                'Rozpis na příští týden — ' . $name, $msg, ['now' => $now, 'url' => $url]);
            $n += afxNotifyDeliverMany(afxNotifyBranchManagers($bid), 'shift_week', $days[0] . '|' . $bid . '|mgr',
                'Rozpis na příští týden — ' . $name, $line . "\n\nZaměstnancům pobočky odešla výzva k zápisu.", ['now' => $now, 'url' => $url]);
        }
    }

    $hoursLine = $hours ? implode(', ', array_map(static fn($tid) => $names[$tid] . ' ' . round($hours[$tid], 1) . ' h',
        array_keys(array_filter($hours)))) : 'zatím nikdo';
    $body = 'Týden ' . $weekLabel . "\n" . implode("\n", $mgmtParts) . "\n\nZapsané hodiny: " . $hoursLine;
    if ($warn) { $body .= "\n⚠️ Pozor na přetížení: " . implode(', ', $warn); }
    $n += afxNotifyDeliverMany(afxNotifyManagement(), 'shift_week', $days[0] . '|mgmt', 'Výhled rozpisu na příští týden', $body,
        ['now' => $now, 'url' => 'rozpis.php?t=' . $days[0], 'level' => (count(array_filter($mgmtParts, static fn($l) => !str_contains($l, '✅'))) ? 'warn' : 'info')]);
    return $n;
}

function afxNotifyRuleShiftNoShow(DateTimeImmutable $now): int
{
    if (!afxNotifyRuleOn('shift_noshow')) { return 0; }
    $m1 = (int)afxNotifyParam('shift_noshow', 'minutes');
    $m2 = (int)afxNotifyParam('shift_noshow', 'escalate');
    $today = $now->format('Y-m-d');
    $rows = afxNotifyShiftsBetween($today, $today);
    $byDay = afxNotifyGroupShifts($rows);
    $n = 0;
    foreach ($rows as $e) {
        $start = afxNotifyAt($today, (string)$e['time_from']);
        $end = afxNotifyAt($today, (string)$e['time_to']);
        if (!$start || !$end || $now < $start->modify('+' . $m1 . ' minutes') || $now >= $end) { continue; }
        if ($now > $start->modify('+4 hours')) { continue; }          // po 4 h už to nemá smysl hlásit
        // zápis doplněný až po začátku směny (vedení dopisuje skutečnost) se nehlídá
        if (!empty($e['created_at']) && strtotime((string)$e['created_at']) > $start->getTimestamp()) { continue; }
        $tid = (int)$e['tech_id'];
        // „vidět" od hodiny a půl před směnou — kdo přišel dřív, je v pořádku
        if (afxNotifySeenSince($tid, $start->modify('-90 minutes'))) { continue; }
        $r = afxNotifyRecipient('tech:' . $tid);
        if (!$r) { continue; }
        $bid = (int)$e['branch_id'];
        $name = afxNotifyBranchName($bid);
        $from = afxNotifyHm((string)$e['time_from']);

        if (afxNotifyDeliver($r, 'shift_noshow', $e['id'] . '|' . $today . '|me', 'Začala ti směna — jsi na místě?',
            'Směna na pobočce **' . $name . '** začala v ' . $from . ", ale v CRM tě zatím nevidíme.\nPřihlas se, prosím (a převezmi pokladnu). Když jdeš pozdě, dej vědět vedení.",
            ['now' => $now, 'url' => 'pokladna.php', 'level' => 'warn'])) { $n++; }

        if ($m2 > 0 && $now >= $start->modify('+' . $m2 . ' minutes')) {
            $others = [];
            foreach ($byDay[$bid][$today] ?? [] as $o) {
                if ((int)$o['tech_id'] === $tid) { continue; }
                $of = afxNotifyAt($today, (string)$o['time_from']);
                $ot = afxNotifyAt($today, (string)$o['time_to']);
                if ($of && $ot && $of <= $now && $now < $ot) { $others[] = (string)$o['tech_name']; }
            }
            $body = '**' . $r['name'] . '** měl(a) nastoupit v ' . $from . ' na pobočku ' . $name . ', do ' . $now->format('H:i')
                  . " se nepřihlásil(a) do CRM ani nepřevzal(a) pokladnu.\n"
                  . ($others ? 'Na pobočce teď je: ' . implode(', ', $others) : '⚠️ Na pobočce teď podle rozpisu nikdo jiný není!');
            $n += afxNotifyDeliverMany(afxNotifyBranchLeads($bid), 'shift_noshow', $e['id'] . '|' . $today . '|lead',
                'Nástup nezaznamenán — ' . $r['name'], $body,
                ['now' => $now, 'url' => 'rozpis.php?b=' . $bid . '&t=' . $today, 'level' => $others ? 'warn' : 'urgent'], [$r['key']]);
        }
    }
    return $n;
}

/* ═══════════════════════════════════════════════════════════════════════════
   PRAVIDLA — POKLADNA
   ═══════════════════════════════════════════════════════════════════════════ */

function afxNotifyRulePos(DateTimeImmutable $now): int
{
    $n = 0;
    $today = $now->format('Y-m-d');
    $byDay = afxNotifyGroupShifts(afxNotifyShiftsBetween($today, $today));

    foreach (afxNotifyBranches() as $b) {
        $bid = (int)$b['id'];
        $list = $byDay[$bid][$today] ?? [];
        $name = afxNotifyBranchName($bid);

        // ── nepřevzatá pokladna ──
        if ($list && afxNotifyRuleOn('pos_open')) {
            $first = null;
            foreach ($list as $e) { $s = afxNotifyAt($today, (string)$e['time_from']); if ($s && (!$first || $s < $first)) { $first = $s; } }
            $due = $first ? $first->modify('+' . (int)afxNotifyParam('pos_open', 'minutes') . ' minutes') : null;
            if ($due && $now >= $due && $now < $due->modify('+3 hours') && !afxNotifyPosTakenToday($bid, $today)) {
                $onShift = [];
                foreach ($list as $e) {
                    $s = afxNotifyAt($today, (string)$e['time_from']); $t = afxNotifyAt($today, (string)$e['time_to']);
                    if ($s && $t && $s <= $now && $now < $t && ($r = afxNotifyRecipient('tech:' . (int)$e['tech_id']))) { $onShift[] = $r; }
                }
                $n += afxNotifyDeliverMany($onShift, 'pos_open', $bid . '|' . $today, 'Pokladna není převzatá — ' . $name,
                    "Směna běží od " . $first->format('H:i') . ", ale pokladnu zatím nikdo nepřevzal.\nBez převzetí nejde markovat — otevři Pokladnu a potvrď stav hotovosti.",
                    ['now' => $now, 'url' => 'pokladna.php', 'level' => 'warn']);
            }
        }

        // ── pokladna otevřená po konci směn ──
        if (afxNotifyRuleOn('pos_close')) {
            $last = null;
            foreach ($list as $e) { $t = afxNotifyAt($today, (string)$e['time_to']); if ($t && (!$last || $t > $last)) { $last = $t; } }
            if (!$last) {
                $cov = afxShiftCoverage($bid, $today, []);
                if ($cov['hours']) { $last = afxNotifyAt($today, afxNotifyMinToTime($cov['hours'][1])); }
            }
            $due = $last ? $last->modify('+' . (int)afxNotifyParam('pos_close', 'minutes') . ' minutes') : null;
            if ($due && $now >= $due && $now->format('Y-m-d') === $today) {
                $shift = afxNotifyOpenPosShift($bid);
                if ($shift) {
                    $who = trim((string)($shift['opened_by'] ?? ''));
                    $opener = ((int)($shift['opened_by_tech'] ?? 0) > 0) ? afxNotifyRecipient('tech:' . (int)$shift['opened_by_tech']) : null;
                    $body = 'Pokladna na pobočce **' . $name . '** je po konci směny (' . $last->format('H:i') . ') pořád převzatá'
                          . ($who !== '' ? ' — převzal(a) ' . $who . ' ' . date('j. n. H:i', strtotime((string)$shift['opened_at'])) : '')
                          . ".\nUdělej uzávěrku: napočítej hotovost a směnu uzavři.";
                    $rcpt = afxNotifyBranchLeads($bid);
                    if ($opener) { array_unshift($rcpt, $opener); }
                    $n += afxNotifyDeliverMany($rcpt, 'pos_close', $bid . '|' . $today . '|' . (int)$shift['id'],
                        'Chybí uzávěrka pokladny — ' . $name, $body, ['now' => $now, 'url' => 'pokladna.php', 'level' => 'warn']);
                }
            }
        }
    }
    return $n;
}

/* ═══════════════════════════════════════════════════════════════════════════
   PRAVIDLA — ZAKÁZKY, REKLAMACE, SKLAD, FAKTURY, ZÁLOHY
   ═══════════════════════════════════════════════════════════════════════════ */

/** Řádek zakázky pro výpis. */
function afxNotifyOrderLine(array $o, bool $withPhone = false): string
{
    $code = (string)(($o['order_code'] ?? '') ?: ('#' . $o['id']));
    $dev = trim((string)($o['device_brand'] ?? '') . ' ' . (string)($o['device_model'] ?? ''));
    $cust = trim((string)($o['first_name'] ?? '') . ' ' . (string)($o['last_name'] ?? ''));
    $line = '• ' . $code . ($dev !== '' ? ' ' . $dev : '') . ' — ' . (int)$o['age_days'] . ' dní';
    if (!empty($o['status'])) { $line .= ', ' . $o['status']; }
    if ($withPhone && $cust !== '') { $line .= ' · ' . $cust . (trim((string)($o['phone'] ?? '')) !== '' ? ' ' . trim((string)$o['phone']) : ''); }
    return $line;
}

function afxNotifyRuleOrdersStale(DateTimeImmutable $now): int
{
    global $pdo;
    if (!afxNotifyRuleOn('orders_stale')) { return 0; }
    if (!afxNotifyDue($now, (string)afxNotifyParam('orders_stale', 'time'), 240)) { return 0; }
    if (afxNotifyParam('orders_stale', 'weekdays') && (int)$now->format('N') >= 6) { return 0; }
    $days = (int)afxNotifyParam('orders_stale', 'days');
    $today = $now->format('Y-m-d');
    $legacy = afxNotifyColumnExists('orders', 'source') ? " AND IFNULL(o.source,'') <> 'legacy'" : '';
    $logJoin = afxNotifyTableExists('order_status_log')
        ? 'LEFT JOIN (SELECT order_id, MAX(changed_at) AS last_change FROM order_status_log GROUP BY order_id) l ON l.order_id = o.id'
        : 'LEFT JOIN (SELECT NULL AS order_id, NULL AS last_change) l ON 1 = 0';
    $waiting = getOrderStatusList('waiting_parts');
    try {
        $st = $pdo->query("SELECT o.id, o.order_code, o.device_brand, o.device_model, o.status, o.technician_id, o.branch_id,
                                  DATEDIFF(CURDATE(), DATE(GREATEST(o.created_at, IFNULL(l.last_change, o.created_at)))) AS age_days
                           FROM orders o $logJoin
                           WHERE o.status IN (" . orderStatusSqlIn($pdo, 'active') . ")$legacy
                           HAVING age_days > $days
                           ORDER BY age_days DESC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('orders_stale: ' . $e->getMessage()); return 0; }
    // „čeká na díl" má toleranci dvojnásobnou (díl jde z Číny, to není zaseknutí)
    $rows = array_values(array_filter($rows, static fn($o) => !in_array((string)$o['status'], $waiting, true) || (int)$o['age_days'] > 2 * $days));
    if (!$rows) { return 0; }

    $n = 0;
    $byTech = []; $byBranch = [];
    foreach ($rows as $o) {
        if ((int)$o['technician_id'] > 0) { $byTech[(int)$o['technician_id']][] = $o; }
        $byBranch[(int)$o['branch_id']][] = $o;
    }
    foreach ($byTech as $tid => $list) {
        $r = afxNotifyRecipient('tech:' . $tid);
        if (!$r) { continue; }
        $body = 'Máš **' . count($list) . '** ' . afxNotifyPlural(count($list), 'zakázku', 'zakázky', 'zakázek') . ' bez pohybu déle než ' . $days . " dní:\n"
              . implode("\n", array_map('afxNotifyOrderLine', array_slice($list, 0, 8)))
              . (count($list) > 8 ? "\n… a dalších " . (count($list) - 8) : '')
              . "\n\nPosuň stav, nebo napiš klientovi, na co se čeká.";
        if (afxNotifyDeliver($r, 'orders_stale', $tid . '|' . $today, 'Zakázky bez pohybu', $body,
            ['now' => $now, 'url' => 'orders.php'])) { $n++; }
    }
    foreach ($byBranch as $bid => $list) {
        foreach (afxNotifyBranchManagers($bid) as $m) {
            $body = '**' . afxNotifyBranchName($bid) . '**: ' . count($list) . ' zakázek bez pohybu přes ' . $days . " dní. Nejstarší:\n"
                  . implode("\n", array_map('afxNotifyOrderLine', array_slice($list, 0, 6)));
            if (afxNotifyDeliver($m, 'orders_stale', 'branch' . $bid . '|' . $today, 'Zakázky bez pohybu — ' . afxNotifyBranchName($bid), $body,
                ['now' => $now, 'url' => 'orders.php'])) { $n++; }
        }
    }
    $parts = [];
    foreach ($byBranch as $bid => $list) { $parts[] = afxNotifyBranchName($bid) . ' ' . count($list); }
    $unassigned = count(array_filter($rows, static fn($o) => (int)$o['technician_id'] <= 0));
    $body = 'Celkem **' . count($rows) . '** zakázek bez pohybu přes ' . $days . ' dní (' . implode(', ', $parts) . ').'
          . ($unassigned ? "\n⚠️ Z toho bez přiděleného technika: " . $unassigned : '')
          . "\nNejstarší:\n" . implode("\n", array_map('afxNotifyOrderLine', array_slice($rows, 0, 6)));
    $n += afxNotifyDeliverMany(afxNotifyManagement(), 'orders_stale', 'mgmt|' . $today, 'Zakázky bez pohybu', $body,
        ['now' => $now, 'url' => 'orders.php']);
    return $n;
}

function afxNotifyPlural(int $n, string $one, string $few, string $many): string
{
    return $n === 1 ? $one : (($n >= 2 && $n <= 4) ? $few : $many);
}

function afxNotifyRuleOrdersPickup(DateTimeImmutable $now): int
{
    global $pdo;
    if (!afxNotifyRuleOn('orders_pickup')) { return 0; }
    if (strtolower($now->format('D')) !== (string)afxNotifyParam('orders_pickup', 'day')) { return 0; }
    if (!afxNotifyDue($now, (string)afxNotifyParam('orders_pickup', 'time'), 360)) { return 0; }
    $days = (int)afxNotifyParam('orders_pickup', 'days');
    $legacy = afxNotifyColumnExists('orders', 'source') ? " AND IFNULL(o.source,'') <> 'legacy'" : '';
    $logJoin = afxNotifyTableExists('order_status_log')
        ? 'LEFT JOIN (SELECT order_id, MAX(changed_at) AS last_change FROM order_status_log GROUP BY order_id) l ON l.order_id = o.id'
        : 'LEFT JOIN (SELECT NULL AS order_id, NULL AS last_change) l ON 1 = 0';
    try {
        $rows = $pdo->query("SELECT o.id, o.order_code, o.device_brand, o.device_model, o.branch_id, c.first_name, c.last_name, c.phone,
                                    DATEDIFF(CURDATE(), DATE(GREATEST(o.created_at, IFNULL(l.last_change, o.created_at)))) AS age_days
                             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id $logJoin
                             WHERE o.status IN (" . orderStatusSqlIn($pdo, 'completed') . ")$legacy
                             HAVING age_days > $days ORDER BY age_days DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('orders_pickup: ' . $e->getMessage()); return 0; }
    if (!$rows) { return 0; }
    $week = $now->format('o-W');
    $byBranch = [];
    foreach ($rows as $o) { $byBranch[(int)$o['branch_id']][] = $o; }
    $n = 0;
    foreach ($byBranch as $bid => $list) {
        $body = 'Na **' . afxNotifyBranchName($bid) . '** čeká ' . count($list) . ' hotových zakázek déle než ' . $days . " dní. Stojí za to zavolat:\n"
              . implode("\n", array_map(static fn($o) => afxNotifyOrderLine($o, true), array_slice($list, 0, 12)))
              . (count($list) > 12 ? "\n… a dalších " . (count($list) - 12) : '');
        $n += afxNotifyDeliverMany(afxNotifyBranchLeads($bid), 'orders_pickup', $bid . '|' . $week,
            'Nevyzvednuté zakázky — ' . afxNotifyBranchName($bid), $body, ['now' => $now, 'url' => 'orders.php']);
    }
    return $n;
}

function afxNotifyRuleComplaints(DateTimeImmutable $now): int
{
    global $pdo;
    if (!afxNotifyRuleOn('complaints_deadline') || !afxNotifyTableExists('complaints')) { return 0; }
    if (!afxNotifyDue($now, (string)afxNotifyParam('complaints_deadline', 'time'), 300)) { return 0; }
    $warn = (int)afxNotifyParam('complaints_deadline', 'warn_days');
    $techCol = afxNotifyColumnExists('complaints', 'technician_id') ? 'technician_id' : 'NULL AS technician_id';
    try {
        $rows = $pdo->query("SELECT id, complaint_code, device, complaint_status, $techCol,
                                    DATEDIFF(CURDATE(), DATE(created_at)) AS age_days
                             FROM complaints
                             WHERE IFNULL(complaint_status,'') NOT IN ('Vyřízeno','Zamítnuto')
                               AND DATEDIFF(CURDATE(), DATE(created_at)) >= $warn
                             ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('complaints_deadline: ' . $e->getMessage()); return 0; }
    if (!$rows) { return 0; }
    $today = $now->format('Y-m-d');
    $line = static function (array $c): string {
        $left = 30 - (int)$c['age_days'];
        $mark = $left < 0 ? '⛔ po lhůtě o ' . (-$left) . ' d' : ($left <= 3 ? '🔴 zbývá ' . $left . ' d' : '🟠 zbývá ' . $left . ' d');
        return '• ' . ($c['complaint_code'] ?: ('#' . $c['id'])) . (trim((string)$c['device']) !== '' ? ' ' . trim((string)$c['device']) : '')
             . ' — ' . $mark . ' (' . $c['complaint_status'] . ')';
    };
    $urgent = count(array_filter($rows, static fn($c) => 30 - (int)$c['age_days'] <= 3));
    $n = 0;
    $byTech = [];
    foreach ($rows as $c) { if ((int)$c['technician_id'] > 0) { $byTech[(int)$c['technician_id']][] = $c; } }
    foreach ($byTech as $tid => $list) {
        if (($r = afxNotifyRecipient('tech:' . $tid)) && afxNotifyDeliver($r, 'complaints_deadline', $tid . '|' . $today,
            'Reklamace — dochází lhůta', "Zákonná lhůta na vyřízení je 30 dní:\n" . implode("\n", array_map($line, $list)),
            ['now' => $now, 'url' => 'reklamace.php', 'level' => 'warn'])) { $n++; }
    }
    $n += afxNotifyDeliverMany(afxNotifyManagement(), 'complaints_deadline', 'mgmt|' . $today,
        'Reklamace blízko 30denní lhůty (' . count($rows) . ')',
        "Otevřené reklamace, kterým dochází zákonná lhůta 30 dní:\n" . implode("\n", array_map($line, array_slice($rows, 0, 12))),
        ['now' => $now, 'url' => 'reklamace.php', 'level' => $urgent ? 'urgent' : 'warn']);
    return $n;
}

function afxNotifyRuleInvoices(DateTimeImmutable $now): int
{
    global $pdo;
    if (!afxNotifyRuleOn('invoices_overdue') || !afxNotifyTableExists('invoices')) { return 0; }
    if (strtolower($now->format('D')) !== (string)afxNotifyParam('invoices_overdue', 'day')) { return 0; }
    if (!afxNotifyDue($now, (string)afxNotifyParam('invoices_overdue', 'time'), 360)) { return 0; }
    $paid = afxNotifyColumnExists('invoices', 'paid_amount') ? 'IFNULL(i.paid_amount,0)' : '0';
    $type = afxNotifyColumnExists('invoices', 'invoice_type') ? " AND IFNULL(i.invoice_type,'invoice') = 'invoice'" : '';
    try {
        $rows = $pdo->query("SELECT i.invoice_number, i.date_due, i.total_amount - $paid AS owed,
                                    COALESCE(NULLIF(i.cust_name_override,''), TRIM(CONCAT(IFNULL(c.first_name,''),' ',IFNULL(c.last_name,'')))) AS cust,
                                    DATEDIFF(CURDATE(), i.date_due) AS late
                             FROM invoices i LEFT JOIN customers c ON c.id = i.customer_id
                             WHERE i.status NOT IN ('paid','cancelled','draft') AND i.date_due < CURDATE()$type
                               AND i.total_amount - $paid > 0.5
                             ORDER BY i.date_due ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('invoices_overdue: ' . $e->getMessage()); return 0; }
    if (!$rows) { return 0; }
    $sum = array_sum(array_map(static fn($r) => (float)$r['owed'], $rows));
    $money = static fn(float $v) => number_format($v, 0, ',', ' ') . ' Kč';
    $body = '**' . count($rows) . '** ' . afxNotifyPlural(count($rows), 'faktura', 'faktury', 'faktur') . ' po splatnosti, celkem **' . $money($sum) . "**:\n"
          . implode("\n", array_map(static fn($r) => '• ' . $r['invoice_number'] . ' ' . trim((string)$r['cust']) . ' — ' . $money((float)$r['owed'])
              . ', ' . (int)$r['late'] . ' dní po splatnosti', array_slice($rows, 0, 10)))
          . (count($rows) > 10 ? "\n… a dalších " . (count($rows) - 10) : '');
    return afxNotifyDeliverMany(array_merge(afxNotifyManagement(), afxNotifyAccountants()), 'invoices_overdue', $now->format('o-W'),
        'Faktury po splatnosti', $body, ['now' => $now, 'url' => 'accounting.php']);
}

function afxNotifyRuleStockLow(DateTimeImmutable $now): int
{
    global $pdo;
    if (!afxNotifyRuleOn('stock_low') || !afxNotifyTableExists('inventory')) { return 0; }
    if (!afxNotifyDue($now, (string)afxNotifyParam('stock_low', 'time'), 300)) { return 0; }
    $stocked = afxNotifyColumnExists('inventory', 'is_stocked') ? ' AND (is_stocked = 1 OR quantity > 0)' : '';
    $branch = afxNotifyColumnExists('inventory', 'branch_id') ? 'branch_id' : '0 AS branch_id';
    try {
        $rows = $pdo->query("SELECT id, part_name, quantity, min_stock, $branch FROM inventory
                             WHERE IFNULL(min_stock,0) > 0 AND IFNULL(quantity,0) <= min_stock$stocked
                             ORDER BY (IFNULL(quantity,0) - min_stock) ASC, part_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('stock_low: ' . $e->getMessage()); return 0; }
    // posílat jen, když přibylo něco nového — ne každý den totéž
    $prev = array_filter(array_map('intval', explode(',', (string)get_setting('smart_notify_stock_seen', ''))));
    $ids = array_map(static fn($r) => (int)$r['id'], $rows);
    if (!afxNotifyDryRun()) { set_setting('smart_notify_stock_seen', implode(',', $ids)); }
    $new = array_values(array_filter($rows, static fn($r) => !in_array((int)$r['id'], $prev, true)));
    if (!$new) { return 0; }
    $line = static fn($r) => '• ' . $r['part_name'] . ' — ' . (int)$r['quantity'] . ' ks (min. ' . (int)$r['min_stock'] . ')';
    $today = $now->format('Y-m-d');
    $sig = md5(implode(',', array_map(static fn($r) => $r['id'], $new)));
    $n = afxNotifyDeliverMany(afxNotifyManagement(), 'stock_low', $today . '|' . $sig, 'Docházející díly (' . count($new) . ' nových)',
        "Pod minimální zásobu nově spadly:\n" . implode("\n", array_map($line, array_slice($new, 0, 15)))
        . (count($new) > 15 ? "\n… a dalších " . (count($new) - 15) : '')
        . "\n\nCelkem pod minimem: " . count($rows), ['now' => $now, 'url' => 'inventory.php']);
    $byBranch = [];
    foreach ($new as $r) { if ((int)$r['branch_id'] > 0) { $byBranch[(int)$r['branch_id']][] = $r; } }
    foreach ($byBranch as $bid => $list) {
        $n += afxNotifyDeliverMany(afxNotifyBranchManagers($bid), 'stock_low', $today . '|' . $sig . '|' . $bid,
            'Docházející díly — ' . afxNotifyBranchName($bid), implode("\n", array_map($line, array_slice($list, 0, 15))),
            ['now' => $now, 'url' => 'inventory.php']);
    }
    return $n;
}

function afxNotifyRuleBackup(DateTimeImmutable $now): int
{
    if (!afxNotifyRuleOn('backup_failed')) { return 0; }
    $status = trim((string)get_setting('backup_last_status', ''));
    $lastOk = (int)get_setting('backup_last_run', '0');
    $problem = '';
    if (stripos($status, 'CHYBA') === 0) { $problem = $status; }
    elseif ($lastOk > 0 && $lastOk < $now->getTimestamp() - 26 * 3600) {
        $problem = 'Poslední úspěšná záloha je z ' . date('j. n. Y H:i', $lastOk) . ' — přes den žádná nová.';
    }
    if ($problem === '') { return 0; }
    $admins = array_values(array_filter(afxNotifyManagement(), static fn($r) => in_array($r['role'], ['admin', 'boss'], true)));
    return afxNotifyDeliverMany($admins, 'backup_failed', $now->format('Y-m-d') . '|' . md5($problem), 'Záloha CRM selhala',
        $problem . "\nZkontroluj Nastavení → Zálohy.", ['now' => $now, 'url' => 'settings.php', 'level' => 'urgent']);
}

/* ═══════════════════════════════════════════════════════════════════════════
   UDÁLOSTI Z ROZPISU (volá rozpis/lib.php po uložení / smazání)
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Změna směny. $old/$new = řádek shift_plan (nebo null), $actorTechId = kdo to udělal.
 * Odeslání se odkládá až za odpověď prohlížeči (fastcgi_finish_request), ať
 * zápis do rozpisu nečeká na Telegram.
 */
function afxNotifyShiftChanged(?array $old, ?array $new, int $actorTechId, string $actorName): void
{
    if (!afxNotifyRuleOn('shift_change')) { return; }
    $run = static function () use ($old, $new, $actorTechId, $actorName): void {
        try { afxNotifyShiftChangedNow($old, $new, $actorTechId, $actorName, new DateTimeImmutable()); }
        catch (Throwable $e) { error_log('afxNotifyShiftChanged: ' . $e->getMessage()); }
    };
    if (PHP_SAPI === 'cli') { $run(); return; }
    register_shutdown_function(static function () use ($run): void {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        $run();
    });
}

function afxNotifyShiftChangedNow(?array $old, ?array $new, int $actorTechId, string $actorName, DateTimeImmutable $now): int
{
    $row = $new ?? $old;
    if (!$row) { return 0; }
    $tid = (int)$row['tech_id'];
    $bid = (int)$row['branch_id'];
    $date = (string)$row['work_date'];
    $today = $now->format('Y-m-d');
    if ($date < $today) { return 0; }
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $name = afxNotifyBranchName($bid);
    $span = static fn(?array $r) => $r ? afxNotifyHm((string)$r['time_from']) . '–' . afxNotifyHm((string)$r['time_to']) : '';
    $actor = $actorName !== '' ? $actorName : 'Vedení';
    $url = 'rozpis.php?b=' . $bid . '&t=' . $date;
    $sig = md5(json_encode([$span($old), $span($new), $now->format('Y-m-d H:i:s')]));
    $n = 0;

    if ($old && $new && $span($old) === $span($new)) { return 0; }   // jen poznámka — nic hlásit

    // 1) dotčený zaměstnanec — když to neudělal sám
    if ($tid !== $actorTechId && ($emp = afxNotifyRecipient('tech:' . $tid))) {
        if ($old && $new)  { $t = 'Změna tvé směny'; $b = $actor . ' upravil(a) tvou směnu na **' . afxNotifyDayLabel($date, true) . '** (' . $name . '): ' . $span($old) . ' → **' . $span($new) . '**'; }
        elseif ($new)      { $t = 'Máš novou směnu'; $b = $actor . ' tě zapsal(a) na **' . afxNotifyDayLabel($date, true) . '** · **' . $span($new) . '** · ' . $name; }
        else               { $t = 'Tvá směna byla zrušena'; $b = $actor . ' smazal(a) tvou směnu na **' . afxNotifyDayLabel($date, true) . '** (' . $span($old) . ', ' . $name . ')'; }
        if ($new && trim((string)$new['note']) !== '') { $b .= "\nPoznámka: " . trim((string)$new['note']); }
        if (afxNotifyDeliver($emp, 'shift_change', $tid . '|' . $date . '|' . $sig, $t, $b,
            ['now' => $now, 'url' => $url, 'level' => $date <= $tomorrow ? 'warn' : 'info'])) { $n++; }
    }

    // 2) vedení — změna na poslední chvíli (dnes/zítra), která ubírá obsazení
    if ($date <= $tomorrow) {
        $entries = afxShiftEntries($bid, $date, $date)[$date] ?? [];
        $cov = afxShiftCoverage($bid, $date, $entries);
        $reduces = !$new || ($old && (afxNotifyTimeToMin((string)$new['time_from']) > afxNotifyTimeToMin((string)$old['time_from'])
                                   || afxNotifyTimeToMin((string)$new['time_to']) < afxNotifyTimeToMin((string)$old['time_to'])));
        $who = (string)(afxNotifyRecipient('tech:' . $tid)['name'] ?? ($row['tech_name'] ?? 'Zaměstnanec'));
        $state = $cov['count'] === 0
            ? ($cov['open'] ? '⚠️ ' . ucfirst(afxNotifyRelDay($date, $now)) . ' teď na pobočce ' . $name . ' **nikdo není**!' : '')
            : ($cov['gaps'] ? 'Bez obsluhy teď: **' . afxNotifyGapsText($cov['gaps']) . '**' : 'Den zůstává pokrytý ✅');
        if ($reduces) {
            $b = ($tid === $actorTechId ? $who : $actor) . ($new ? ' zkrátil(a) směnu' : ' zrušil(a) směnu') . ' — ' . $who . ', '
               . afxNotifyRelDay($date, $now) . ' ' . $span($old) . ($new ? ' → ' . $span($new) : '') . ' (' . $name . ")\n" . $state;
            $n += afxNotifyDeliverMany(afxNotifyBranchLeads($bid), 'shift_change', 'late|' . $bid . '|' . $date . '|' . $tid . '|' . $sig,
                'Změna rozpisu na poslední chvíli', $b,
                ['now' => $now, 'url' => $url, 'level' => ($cov['count'] === 0 && $cov['open']) ? 'urgent' : 'warn'],
                ['tech:' . $actorTechId]);
        } elseif ($new && !$old && afxNotifyWasSent('shift_uncovered', $bid . '|' . $date . '|')) {
            // dřív hlášený „nikdo není zapsaný" je vyřešený — uzavřít smyčku
            $n += afxNotifyDeliverMany(afxNotifyBranchLeads($bid), 'shift_change', 'solved|' . $bid . '|' . $date . '|' . $tid,
                'Vyřešeno: ' . afxNotifyRelDay($date, $now) . ' už někdo je — ' . $name,
                $who . ' se zapsal(a) na ' . afxNotifyDayLabel($date) . ' ' . $span($new) . ".\n" . $state,
                ['now' => $now, 'url' => $url], ['tech:' . $actorTechId]);
        }
    }
    return $n;
}

/* ═══════════════════════════════════════════════════════════════════════════
   PLÁNOVAČ
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Jeden průchod všemi pravidly. Zámek v DB zajistí, že naráz běží jen jeden.
 * $dry = nanečisto (nic se neodešle, vrátí se seznam toho, co by odešlo).
 */
function afxNotifyRun(?DateTimeImmutable $now = null, bool $dry = false): array
{
    global $pdo;
    $now = $now ?? new DateTimeImmutable();
    afxNotifyEnsureSchema();
    afxShiftEnsureSchema();
    $cfg = afxNotifyConfig(true);
    if (empty($cfg['global']['enabled']) && !$dry) { return ['skipped' => 'Upozornění jsou vypnutá.']; }

    $locked = false;
    if (!$dry) {
        try { $locked = (int)$pdo->query("SELECT GET_LOCK('afx_smart_notify', 0)")->fetchColumn() === 1; }
        catch (Throwable $e) { $locked = true; }
        if (!$locked) { return ['skipped' => 'Kontrola už běží.']; }
    }
    afxNotifyDryRun($dry);
    $counts = [];
    $t0 = microtime(true);
    $steps = [
        'shift_reminder'      => 'afxNotifyRuleShiftReminder',
        'shift_evening'       => 'afxNotifyRuleShiftEvening',
        'shift_uncovered'     => 'afxNotifyRuleShiftUncovered',
        'shift_gap'           => 'afxNotifyRuleShiftGap',
        'shift_week'          => 'afxNotifyRuleShiftWeek',
        'shift_noshow'        => 'afxNotifyRuleShiftNoShow',
        'pos'                 => 'afxNotifyRulePos',
        'orders_stale'        => 'afxNotifyRuleOrdersStale',
        'orders_pickup'       => 'afxNotifyRuleOrdersPickup',
        'complaints_deadline' => 'afxNotifyRuleComplaints',
        'invoices_overdue'    => 'afxNotifyRuleInvoices',
        'stock_low'           => 'afxNotifyRuleStockLow',
        'backup_failed'       => 'afxNotifyRuleBackup',
    ];
    foreach ($steps as $id => $fn) {
        try { $counts[$id] = $fn($now); }
        catch (Throwable $e) { $counts[$id] = 0; error_log('afxNotifyRun ' . $id . ': ' . $e->getMessage()); }
    }
    $items = afxNotifyCollected();
    afxNotifyDryRun(false);
    if ($locked) {
        try { $pdo->query("SELECT RELEASE_LOCK('afx_smart_notify')"); } catch (Throwable $e) { /* nic */ }
        set_setting('smart_notify_last_run', $now->format('Y-m-d H:i:s'));
        // úklid: log 120 dní, přečtené upozornění v CRM 60 dní (jednou za den stačí)
        if (get_setting('smart_notify_last_cleanup', '') !== $now->format('Y-m-d')) {
            set_setting('smart_notify_last_cleanup', $now->format('Y-m-d'));
            try {
                $pdo->exec('DELETE FROM smart_notify_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 120 DAY)');
                $pdo->exec('DELETE FROM smart_notify_inbox WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)');
            } catch (Throwable $e) { /* úklid počká */ }
        }
    }
    return ['sent' => array_sum($counts), 'counts' => $counts, 'items' => $items, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
}

/**
 * Poor-man's cron: zavolat z častých požadavků. Levná kontrola (1 dotaz);
 * jednou za minutu spustí průchod na pozadí přes php-cli, a když exec na
 * hostingu nejde, doběhne po odeslání odpovědi v tomtéž požadavku.
 */
function afxNotifyMaybeSchedule(): void
{
    try {
        if (PHP_SAPI !== 'cli') { afxNotifyRememberBaseUrl(); }
        global $pdo;
        $st = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $st->execute(['smart_notify_last_attempt']);
        $last = (int)$st->fetchColumn();
        if (time() - $last < 60) { return; }
        // claim přes podmíněný UPDATE — dva souběžné požadavky nespustí dva běhy
        $up = $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ? AND setting_value = ?');
        $up->execute([(string)time(), 'smart_notify_last_attempt', (string)($last ?: '')]);
        if ($up->rowCount() === 0) {
            if ($last !== 0) { return; }
            $ins = $pdo->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
            $ins->execute(['smart_notify_last_attempt', (string)time()]);
            if ($ins->rowCount() === 0) { return; }
        }
        $script = __DIR__ . '/cron.php';
        $php = (function_exists('exec') && function_exists('crmBackupFindBin')) ? crmBackupFindBin(['php', 'php8.3', 'php8.2', 'php8.1']) : null;
        if ($php !== null && is_file($script)) {
            exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
            return;
        }
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            try { set_setting('smart_notify_last_trigger', 'web'); afxNotifyRun(); } catch (Throwable $e) { /* nic */ }
        });
    } catch (Throwable $e) { /* upozornění nesmí shodit požadavek */ }
}

/* ═══════════════════════════════════════════════════════════════════════════
   UPOZORNĚNÍ V CRM (inbox)
   ═══════════════════════════════════════════════════════════════════════════ */

function afxNotifyInbox(array $keys, int $limit = 40): array
{
    global $pdo;
    if (!$keys) { return []; }
    afxNotifyEnsureSchema();
    try {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("SELECT * FROM smart_notify_inbox WHERE staff_key IN ($ph) ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
        $st->execute($keys);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** [počet nepřečtených, nejnovější nepřečtené]. */
function afxNotifyUnread(array $keys): array
{
    global $pdo;
    if (!$keys) { return [0, null]; }     // chybějící tabulka = výjimka níže → [0, null]
    try {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM smart_notify_inbox WHERE staff_key IN ($ph) AND read_at IS NULL");
        $st->execute($keys);
        $cnt = (int)$st->fetchColumn();
        if ($cnt === 0) { return [0, null]; }
        $st = $pdo->prepare("SELECT id, level, title, body, url FROM smart_notify_inbox WHERE staff_key IN ($ph) AND read_at IS NULL ORDER BY id DESC LIMIT 1");
        $st->execute($keys);
        return [$cnt, $st->fetch(PDO::FETCH_ASSOC) ?: null];
    } catch (Throwable $e) { return [0, null]; }
}

function afxNotifyMarkRead(array $keys, int $id = 0): void
{
    global $pdo;
    if (!$keys) { return; }
    try {
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $sql = "UPDATE smart_notify_inbox SET read_at = NOW() WHERE staff_key IN ($ph) AND read_at IS NULL" . ($id > 0 ? ' AND id <= ?' : '');
        $pdo->prepare($sql)->execute($id > 0 ? array_merge($keys, [$id]) : $keys);
    } catch (Throwable $e) { /* nic */ }
}

/**
 * Nejbližší plánovaná upozornění o směnách (náhled „co přijde") pro UI.
 * Vrací [[at, to, title], …] na příštích ~36 hodin.
 */
function afxNotifyUpcoming(DateTimeImmutable $now, ?string $onlyKey = null): array
{
    $out = [];
    $today = $now->format('Y-m-d');
    $rows = afxNotifyShiftsBetween($today, $now->modify('+2 day')->format('Y-m-d'));
    $default = (int)afxNotifyParam('shift_reminder', 'minutes');
    $evening = (string)afxNotifyParam('shift_evening', 'time');
    $limit = $now->modify('+36 hours');
    foreach ($rows as $e) {
        $key = 'tech:' . (int)$e['tech_id'];
        if ($onlyKey !== null && $key !== $onlyKey) { continue; }
        $start = afxNotifyAt((string)$e['work_date'], (string)$e['time_from']);
        if (!$start || $start <= $now) { continue; }
        $p = afxNotifyPrefs($key);
        $span = afxNotifyHm((string)$e['time_from']) . '–' . afxNotifyHm((string)$e['time_to']);
        if (afxNotifyRuleOn('shift_reminder')) {
            $min = $p['reminder_minutes'] !== null ? (int)$p['reminder_minutes'] : $default;
            $at = $start->modify('-' . $min . ' minutes');
            if ($at < $limit) {
                $out[] = ['at' => max($at, $now), 'to' => (string)$e['tech_name'], 'title' => 'Připomínka směny ' . afxNotifyDayLabel((string)$e['work_date']) . ' ' . $span . ' (' . afxNotifyBranchName((int)$e['branch_id']) . ')'];
            }
        }
        if (afxNotifyRuleOn('shift_evening') && !empty($p['evening_before'])) {
            $at = afxNotifyAt((new DateTimeImmutable((string)$e['work_date']))->modify('-1 day')->format('Y-m-d'), $evening);
            if ($at && $at > $now && $at < $limit) {
                $out[] = ['at' => $at, 'to' => (string)$e['tech_name'], 'title' => 'Večerní „zítra jdeš do práce" (' . $span . ')'];
            }
        }
    }
    usort($out, static fn($a, $b) => $a['at'] <=> $b['at']);
    return array_slice($out, 0, 30);
}
