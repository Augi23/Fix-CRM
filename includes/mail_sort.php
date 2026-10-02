<?php
/**
 * TŘÍDĚNÍ POŠTY — automatické roztřídění firemní schránky (Forpsi i jiné IMAP).
 *
 * Každá nová zpráva v Doručené poště dostane jednu ze tří kategorií:
 *   customer = skutečný člověk / zákazník s dotazem (zůstává v Doručené poště)
 *   offer    = firemní nabídky produktů a služeb, newslettery, reklama
 *   robot    = automatické souhrny, notifikace a reporty z webů a služeb
 *
 * Rozhoduje se v tomto pořadí (první jistá odpověď vyhrává):
 *   1) ruční pravidla (odesílatel / doména — vznikají i „naučením" z přehledu),
 *   2) odesílatel je klient v CRM → zákazník,
 *   3) heuristika z hlaviček a textu (List-Unsubscribe, noreply@, Auto-Submitted…),
 *   4) u nejasných zpráv volitelně AI (stejný klíč jako Nastavení → Integrace → AI).
 * Zásada: když si třídič není jistý, nechá zprávu u zákazníků — přehlédnutý
 * zákazník stojí víc než newsletter navíc v Doručené poště.
 *
 * IMAP klient je vlastní (socket + TLS), nepotřebuje rozšíření php-imap,
 * které od PHP 8.4 v jádře není. Zprávy se čtou přes BODY.PEEK, takže
 * třídění NEMĚNÍ stav přečteno/nepřečteno.
 *
 * Spouštění: samo z notify_poll (poor-man's cron, každé ~3 min na pozadí),
 * nebo systémovým cronem:  * /5 * * * * php /cesta/k/crm/scripts/mail_sort.php
 */

const CRM_MAIL_CATEGORIES = ['customer', 'offer', 'robot'];

/** Popisky kategorií pro UI. */
function crmMailCategoryMeta(): array {
    return [
        'customer' => ['label' => 'Zákazníci', 'one' => 'Zákazník', 'icon' => 'fa-user', 'color' => '#30d158'],
        'offer'    => ['label' => 'Nabídky',   'one' => 'Nabídka',  'icon' => 'fa-tags', 'color' => '#ff9f0a'],
        'robot'    => ['label' => 'Roboti',    'one' => 'Robot',    'icon' => 'fa-robot', 'color' => '#64d2ff'],
    ];
}

/* ═════════════════════════════  IMAP KLIENT  ═════════════════════════════ */

final class CrmImapException extends RuntimeException {}

final class CrmImap
{
    /** @var resource|null */
    private $fp = null;
    private int $tagNo = 0;
    public array $caps = [];
    public string $delim = '.';
    public string $prefix = '';
    private array $knownFolders = [];

    public function __construct(
        private string $host,
        private int $port = 993,
        private string $secure = 'ssl',     // ssl | tls (STARTTLS) | none
        private int $timeout = 25
    ) {}

    public function connect(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $this->host,
        ]]);
        $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $fp = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new CrmImapException('Nepodařilo se spojit s ' . $this->host . ':' . $this->port . ' (' . trim($errstr ?: 'neznámá chyba') . ').');
        }
        stream_set_timeout($fp, $this->timeout);
        $this->fp = $fp;
        $greet = $this->readLine();
        if (!preg_match('/^\* (OK|PREAUTH)/i', $greet)) {
            throw new CrmImapException('Server nepozdravil jako IMAP: ' . trim($greet));
        }
        if ($this->secure === 'tls') {
            $this->cmd('STARTTLS');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new CrmImapException('Nepodařilo se zapnout šifrování (STARTTLS).');
            }
        }
        $this->loadCaps();
    }

    public function login(string $user, string $pass): void
    {
        $printable = static fn(string $s): bool => (bool)preg_match('/^[\x20-\x7e]*$/', $s);
        try {
            if ($printable($user) && $printable($pass)) {
                $this->cmd('LOGIN ' . self::quote($user) . ' ' . self::quote($pass), true);
            } else {
                // diakritika v hesle → AUTHENTICATE PLAIN (base64, žádné uvozovky)
                $this->authPlain($user, $pass);
            }
        } catch (CrmImapException $e) {
            throw new CrmImapException('Přihlášení odmítnuto — zkontroluj e-mail a heslo. (' . $e->getMessage() . ')');
        }
        $this->loadCaps();
        $this->loadNamespace();
    }

    public function logout(): void
    {
        if ($this->fp) {
            try { $this->cmd('LOGOUT'); } catch (Throwable $e) {}
            @fclose($this->fp);
            $this->fp = null;
        }
    }

    public function hasCap(string $cap): bool { return in_array(strtoupper($cap), $this->caps, true); }

    /** SELECT/EXAMINE složky → ['exists'=>int, 'uidvalidity'=>int, 'uidnext'=>int]. */
    public function select(string $folder, bool $readOnly = false): array
    {
        $r = $this->cmd(($readOnly ? 'EXAMINE ' : 'SELECT ') . self::quote($this->encodeName($folder)));
        $out = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($r['untagged'] as $u) {
            $t = $u['text'];
            if (preg_match('/^\* (\d+) EXISTS/i', $t, $m)) { $out['exists'] = (int)$m[1]; }
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $t, $m)) { $out['uidvalidity'] = (int)$m[1]; }
            if (preg_match('/\[UIDNEXT (\d+)\]/i', $t, $m)) { $out['uidnext'] = (int)$m[1]; }
        }
        if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $r['tagline'], $m)) { $out['uidvalidity'] = (int)$m[1]; }
        return $out;
    }

    /** UID SEARCH → seřazené UID. $criteria je hotový IMAP výraz (ASCII). */
    public function uidSearch(string $criteria): array
    {
        $r = $this->cmd('UID SEARCH ' . $criteria);
        $uids = [];
        foreach ($r['untagged'] as $u) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $u['text'], $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $n) {
                    if ($n !== '' && ctype_digit($n)) { $uids[] = (int)$n; }
                }
            }
        }
        $uids = array_values(array_unique($uids));
        sort($uids);
        return $uids;
    }

    /**
     * Hlavičky + začátek těla (bez označení jako přečtené).
     * @return array<int, array{uid:int, flags:string, internaldate:string, header:string, body:string}>
     */
    public function fetchMessages(array $uids, int $bodyBytes = 16000): array
    {
        $out = [];
        foreach (array_chunk($uids, 20) as $chunk) {
            $set = implode(',', array_map('intval', $chunk));
            $r = $this->cmd('UID FETCH ' . $set . ' (UID FLAGS INTERNALDATE BODY.PEEK[HEADER] BODY.PEEK[TEXT]<0.' . $bodyBytes . '>)');
            foreach ($r['untagged'] as $u) {
                $t = $u['text'];
                if (!preg_match('/^\* \d+ FETCH \(/i', $t)) { continue; }
                if (!preg_match('/\bUID (\d+)/i', $t, $m)) { continue; }
                $uid = (int)$m[1];
                $msg = $out[$uid] ?? ['uid' => $uid, 'flags' => '', 'internaldate' => '', 'header' => '', 'body' => ''];
                if (preg_match('/\bFLAGS \(([^)]*)\)/i', $t, $m)) { $msg['flags'] = $m[1]; }
                if (preg_match('/\bINTERNALDATE "([^"]+)"/i', $t, $m)) { $msg['internaldate'] = $m[1]; }
                $h = self::fetchItem($t, $u['lits'], 'BODY\[HEADER\]');
                if ($h !== null) { $msg['header'] = $h; }
                $b = self::fetchItem($t, $u['lits'], 'BODY\[TEXT\](?:<\d+>)?');
                if ($b !== null) { $msg['body'] = $b; }
                $out[$uid] = $msg;
            }
        }
        return $out;
    }

    /** Přesun zpráv (UID) do složky. MOVE, jinak COPY + \Deleted + UID EXPUNGE. */
    public function moveUids(array $uids, string $folder): void
    {
        if (!$uids) { return; }
        $set = implode(',', array_map('intval', $uids));
        $box = self::quote($this->encodeName($folder));
        if ($this->hasCap('MOVE')) {
            $this->cmd('UID MOVE ' . $set . ' ' . $box);
            return;
        }
        $this->cmd('UID COPY ' . $set . ' ' . $box);
        $this->cmd('UID STORE ' . $set . ' +FLAGS.SILENT (\\Deleted)');
        if ($this->hasCap('UIDPLUS')) {
            $this->cmd('UID EXPUNGE ' . $set);
        }
        // bez UIDPLUS radši NEvoláme EXPUNGE — smazal by i zprávy, které má
        // uživatel sám označené ke smazání. Zprávy zůstanou skryté jako \Deleted.
    }

    /** Plná cesta ke složce podle jmenného prostoru serveru (Forpsi: „INBOX.Nabídky"). */
    public function folderPath(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strcasecmp($name, 'INBOX') === 0) { return 'INBOX'; }
        if ($this->prefix !== '' && stripos($name, $this->prefix) === 0) { return $name; }
        // uživatel mohl psát „/" jako oddělovač podsložek
        if ($this->delim !== '/' && $this->delim !== '') { $name = str_replace('/', $this->delim, $name); }
        return $this->prefix . $name;
    }

    /** Založí složku, pokud chybí (a přihlásí k odběru, ať ji ukáže i mobil/Outlook). */
    public function ensureFolder(string $path): void
    {
        if ($path === 'INBOX' || isset($this->knownFolders[$path])) { return; }
        $enc = $this->encodeName($path);
        $r = $this->cmd('LIST "" ' . self::quote($enc));
        $exists = false;
        foreach ($r['untagged'] as $u) {
            if (preg_match('/^\* LIST /i', $u['text'])) { $exists = true; break; }
        }
        if (!$exists) {
            $this->cmd('CREATE ' . self::quote($enc));
            try { $this->cmd('SUBSCRIBE ' . self::quote($enc)); } catch (Throwable $e) {}
        }
        $this->knownFolders[$path] = true;
    }

    /** Najde UID zprávy podle Message-ID v aktuálně vybrané složce. */
    public function findByMessageId(string $messageId): array
    {
        $messageId = trim($messageId);
        if ($messageId === '' || !preg_match('/^[\x21-\x7e]+$/', $messageId)) { return []; }
        return $this->uidSearch('HEADER Message-ID ' . self::quote($messageId));
    }

    /* ── nízká úroveň ─────────────────────────────────────────────────────── */

    public function encodeName(string $name): string
    {
        if (preg_match('/^[\x20-\x7e]*$/', $name) && strpos($name, '&') === false) { return $name; }
        return (string)mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    }

    public static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    private function loadCaps(): void
    {
        try {
            $r = $this->cmd('CAPABILITY');
            foreach ($r['untagged'] as $u) {
                if (preg_match('/^\* CAPABILITY (.+)$/i', $u['text'], $m)) {
                    $this->caps = array_map('strtoupper', preg_split('/\s+/', trim($m[1])));
                }
            }
        } catch (Throwable $e) {}
    }

    private function loadNamespace(): void
    {
        // oddělovač z LIST "" "" — „." (Forpsi, Courier) nebo „/" (Dovecot)
        try {
            $r = $this->cmd('LIST "" ""');
            foreach ($r['untagged'] as $u) {
                if (preg_match('/^\* LIST \([^)]*\) (?:"((?:[^"\\\\]|\\\\.)*)"|NIL)/i', $u['text'], $m)) {
                    $this->delim = isset($m[1]) ? stripslashes($m[1]) : '';
                }
            }
        } catch (Throwable $e) {}
        // osobní jmenný prostor, např. (("INBOX." ".")) → složky musí být pod INBOX.
        if ($this->hasCap('NAMESPACE')) {
            try {
                $r = $this->cmd('NAMESPACE');
                foreach ($r['untagged'] as $u) {
                    if (preg_match('/^\* NAMESPACE \(\("((?:[^"\\\\]|\\\\.)*)" (?:"((?:[^"\\\\]|\\\\.)*)"|NIL)/i', $u['text'], $m)) {
                        $this->prefix = stripslashes($m[1]);
                        if (isset($m[2]) && $m[2] !== '') { $this->delim = stripslashes($m[2]); }
                    }
                }
            } catch (Throwable $e) {}
        }
    }

    private function authPlain(string $user, string $pass): void
    {
        $tag = $this->nextTag();
        $this->write($tag . ' AUTHENTICATE PLAIN');
        $line = $this->readLine();
        if (!str_starts_with($line, '+')) {
            throw new CrmImapException('AUTHENTICATE PLAIN nepodporováno: ' . trim($line));
        }
        $this->write(base64_encode("\0" . $user . "\0" . $pass));
        $r = $this->readResponse($tag);
        if ($r['status'] !== 'OK') { throw new CrmImapException(trim($r['tagline'])); }
    }

    /** Odešle příkaz a vrátí ['status','tagline','untagged'=>[['text','lits']]]. */
    private function cmd(string $command, bool $sensitive = false): array
    {
        $tag = $this->nextTag();
        $this->write($tag . ' ' . $command);
        $r = $this->readResponse($tag);
        if ($r['status'] !== 'OK') {
            $what = $sensitive ? strtok($command, ' ') : (strlen($command) > 80 ? substr($command, 0, 80) . '…' : $command);
            throw new CrmImapException($what . ' → ' . trim(substr($r['tagline'], strlen($tag) + 1)));
        }
        return $r;
    }

    private function nextTag(): string { return 'A' . str_pad((string)(++$this->tagNo), 4, '0', STR_PAD_LEFT); }

    private function write(string $line): void
    {
        if (!$this->fp) { throw new CrmImapException('Spojení není otevřené.'); }
        if (@fwrite($this->fp, $line . "\r\n") === false) { throw new CrmImapException('Zápis na server selhal.'); }
    }

    private function readLine(): string
    {
        $line = @fgets($this->fp);
        if ($line === false) {
            $meta = stream_get_meta_data($this->fp);
            throw new CrmImapException(!empty($meta['timed_out']) ? 'Server neodpovídá (timeout).' : 'Server ukončil spojení.');
        }
        return $line;
    }

    private function readBytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = @fread($this->fp, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->fp);
                if (!empty($meta['timed_out']) || feof($this->fp)) { throw new CrmImapException('Spojení přerušeno při čtení zprávy.'); }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /** Čte odpovědi až po tagovaný řádek. Literály {n} nahradí značkou \0LITn\0. */
    private function readResponse(string $tag): array
    {
        $untagged = [];
        while (true) {
            $text = $this->readLine();
            $lits = [];
            while (preg_match('/\{(\d+)\+?\}\r?\n$/', $text, $m)) {
                $lits[] = $this->readBytes((int)$m[1]);
                $text = substr($text, 0, -strlen($m[0])) . "\0LIT" . (count($lits) - 1) . "\0" . $this->readLine();
            }
            if (strncmp($text, $tag . ' ', strlen($tag) + 1) === 0) {
                $status = strtoupper((string)strtok(substr($text, strlen($tag) + 1), ' '));
                return ['status' => trim($status), 'tagline' => rtrim($text), 'untagged' => $untagged];
            }
            if (isset($text[0]) && $text[0] === '*') {
                $untagged[] = ['text' => rtrim($text, "\r\n"), 'lits' => $lits];
            }
            // „+ " pokračování nepoužíváme (kromě AUTHENTICATE, ta čte sama)
        }
    }

    /** Hodnota položky FETCH — literál, řetězec v uvozovkách, nebo NIL. */
    private static function fetchItem(string $text, array $lits, string $itemRe): ?string
    {
        if (!preg_match('/' . $itemRe . '\s+(?:\x00LIT(\d+)\x00|"((?:[^"\\\\]|\\\\.)*)"|NIL)/i', $text, $m)) { return null; }
        if (isset($m[1]) && $m[1] !== '') { return $lits[(int)$m[1]] ?? ''; }
        if (isset($m[2])) { return (string)preg_replace('/\\\\(.)/s', '$1', $m[2]); }
        return '';
    }
}

/* ═════════════════════════════  PARSOVÁNÍ ZPRÁV  ═════════════════════════ */

/** Rozbalí hlavičky do ['nazev-malymi' => [hodnota, …]] (hodnoty surové). */
function crmMailParseHeaders(string $raw): array
{
    $raw = preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $raw)) ?? $raw;   // unfolding
    $out = [];
    foreach (explode("\n", $raw) as $line) {
        $p = strpos($line, ':');
        if ($p === false || $p === 0) { continue; }
        $k = strtolower(trim(substr($line, 0, $p)));
        if ($k === '' || preg_match('/\s/', $k)) { continue; }
        $out[$k][] = trim(substr($line, $p + 1));
    }
    return $out;
}

function crmMailHeader(array $h, string $name): string
{
    return (string)($h[strtolower($name)][0] ?? '');
}

/** Dekóduje =?utf-8?B?…?= apod. na UTF-8. */
function crmMailDecodeMime(string $s): string
{
    if ($s === '' || strpos($s, '=?') === false) { return crmMailToUtf8($s, ''); }
    $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($d === false || $d === '') { $d = mb_decode_mimeheader($s); }
    return crmMailToUtf8($d, 'UTF-8');
}

function crmMailToUtf8(string $s, string $charset): string
{
    $charset = strtolower(trim($charset, " \t\"'"));
    if ($charset === '' || $charset === 'us-ascii') {
        if (mb_check_encoding($s, 'UTF-8')) { return $s; }
        $charset = 'windows-1250';                     // české maily bez charsetu
    }
    if (in_array($charset, ['utf-8', 'utf8'], true)) {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_scrub($s, 'UTF-8');
    }
    $c = @iconv($charset, 'UTF-8//IGNORE', $s);
    if ($c === false) { $c = @mb_convert_encoding($s, 'UTF-8', $charset) ?: $s; }
    return mb_scrub($c, 'UTF-8');
}

/** Rozloží „Jméno <a@b.cz>" → [email, jméno]. */
function crmMailParseAddress(string $raw): array
{
    $raw = crmMailDecodeMime($raw);
    $email = '';
    if (preg_match('/<\s*([^<>\s]+@[^<>\s]+)\s*>/', $raw, $m)) { $email = $m[1]; }
    elseif (preg_match('/([A-Za-z0-9._%+\-=\'!#$&*\/?^`{|}~]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $raw, $m)) { $email = $m[1]; }
    $name = trim((string)preg_replace('/<[^>]*>/', '', $raw), " \t\"'");
    if ($name === $email) { $name = ''; }
    return [strtolower(trim($email)), $name];
}

/** Z (částečného) těla vytáhne čitelný text — text/plain přednostně, jinak HTML bez značek. */
function crmMailExtractText(array $headers, string $body, int $depth = 0): string
{
    $ctype = crmMailHeader($headers, 'content-type') ?: 'text/plain';
    $type = strtolower(trim(strtok($ctype, ';')));
    if ($depth < 4 && str_starts_with($type, 'multipart/')) {
        if (!preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $ctype, $m)) { return ''; }
        $boundary = $m[1] !== '' ? $m[1] : $m[2];
        $parts = explode('--' . $boundary, str_replace("\r\n", "\n", $body));
        array_shift($parts);                                    // preambule
        $plain = ''; $html = '';
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) { break; }         // konec multipartu
            $part = ltrim($part, "\n");
            $sep = strpos($part, "\n\n");
            $ph = $sep === false ? $part : substr($part, 0, $sep);
            $pb = $sep === false ? '' : substr($part, $sep + 2);
            $phArr = crmMailParseHeaders($ph);
            $pType = strtolower(trim((string)strtok(crmMailHeader($phArr, 'content-type') ?: 'text/plain', ';')));
            if (stripos(crmMailHeader($phArr, 'content-disposition'), 'attachment') !== false) { continue; }
            if (str_starts_with($pType, 'multipart/')) {
                $t = crmMailExtractText($phArr, $pb, $depth + 1);
                if ($t !== '' && $plain === '') { $plain = $t; }
            } elseif ($pType === 'text/plain' && $plain === '') {
                $plain = crmMailDecodePart($phArr, $pb);
            } elseif ($pType === 'text/html' && $html === '') {
                $html = crmMailDecodePart($phArr, $pb);
            }
        }
        $text = trim($plain) !== '' ? $plain : crmMailHtmlToText($html);
    } else {
        $text = crmMailDecodePart($headers, $body);
        if ($type === 'text/html') { $text = crmMailHtmlToText($text); }
    }
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
    $text = preg_replace("/\n\s*\n+/u", "\n\n", $text) ?? $text;
    return trim($text);
}

function crmMailDecodePart(array $h, string $body): string
{
    $cte = strtolower(trim(crmMailHeader($h, 'content-transfer-encoding')));
    if ($cte === 'base64') {
        $b = preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '';
        $b = substr($b, 0, strlen($b) - strlen($b) % 4);       // useknutý konec (BODY<0.N>)
        $body = (string)base64_decode($b);
    } elseif ($cte === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }
    $charset = '';
    if (preg_match('/charset\s*=\s*"?([A-Za-z0-9_\-:.]+)"?/i', crmMailHeader($h, 'content-type'), $m)) { $charset = $m[1]; }
    return crmMailToUtf8($body, $charset);
}

function crmMailHtmlToText(string $html): string
{
    if ($html === '') { return ''; }
    $t = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $t = preg_replace('#<(br|/p|/div|/tr|/h[1-6]|/li)\s*/?>#i', "\n", $t) ?? $t;
    $t = strip_tags($t);
    return html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Lidsky čitelná „obálka" zprávy pro klasifikátor i log. */
function crmMailEnvelope(string $rawHeader, string $rawBody): array
{
    $h = crmMailParseHeaders($rawHeader);
    [$fromEmail, $fromName] = crmMailParseAddress(crmMailHeader($h, 'from'));
    [$replyTo] = crmMailParseAddress(crmMailHeader($h, 'reply-to'));
    $subject = crmMailDecodeMime(crmMailHeader($h, 'subject'));
    $text = crmMailExtractText($h, $rawBody);
    $date = strtotime(crmMailHeader($h, 'date')) ?: null;
    return [
        'headers'    => $h,
        'from_email' => $fromEmail,
        'from_name'  => $fromName,
        'reply_to'   => $replyTo,
        'subject'    => $subject,
        'message_id' => trim(crmMailHeader($h, 'message-id')),
        'date'       => $date,
        'text'       => $text,
    ];
}

/* ═════════════════════════════  KLASIFIKÁTOR  ════════════════════════════ */

/** Domény osobních schránek — tam píšou lidé, ne firmy. Pravidlo na celou doménu je zakázané. */
function crmMailFreemailDomains(): array {
    return ['gmail.com', 'googlemail.com', 'seznam.cz', 'email.cz', 'post.cz', 'centrum.cz', 'volny.cz',
        'atlas.cz', 'tiscali.cz', 'quick.cz', 'iol.cz', 'o2active.cz', 'upcmail.cz', 'chello.cz', 'cbox.cz',
        'icloud.com', 'me.com', 'mac.com', 'outlook.com', 'outlook.cz', 'hotmail.com', 'hotmail.cz', 'live.com',
        'live.cz', 'msn.com', 'yahoo.com', 'ymail.com', 'aol.com', 'gmx.com', 'gmx.de', 'gmx.net', 'proton.me',
        'protonmail.com', 'pm.me', 'azet.sk', 'zoznam.sk', 'centrum.sk', 'post.sk', 'mail.ru', 'yandex.ru',
        'yandex.com', 'ukr.net', 'i.ua', 'inbox.ru', 'bk.ru', 'list.ru', 'wp.pl', 'o2.pl', 'onet.pl', 'interia.pl'];
}

/** Domény služeb, které posílají hlavně automatické zprávy. */
function crmMailServiceDomains(): array {
    return ['google.com', 'accounts.google.com', 'youtube.com', 'facebookmail.com', 'facebook.com', 'meta.com',
        'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'tiktok.com', 'apple.com', 'id.apple.com',
        'insideapple.apple.com', 'itunes.com', 'github.com', 'gitlab.com', 'microsoft.com', 'microsoftonline.com',
        'office.com', 'paypal.com', 'paypal.cz', 'stripe.com', 'forpsi.com', 'forpsi.cz', 'wedos.cz', 'wedos.com',
        'active24.cz', 'websupport.cz', 'zasilkovna.cz', 'packeta.com', 'ppl.cz', 'gls-czech.com', 'gls-group.eu',
        'dpd.cz', 'cpost.cz', 'ceskaposta.cz', 'balikovna.cz', 'heureka.cz', 'zbozi.cz', 'srovname.cz', 'kb.cz',
        'csas.cz', 'fio.cz', 'airbank.cz', 'rb.cz', 'moneta.cz', 'csob.cz', 'comgate.cz', 'gopay.cz', 'gopay.com',
        'shoptet.cz', 'wordpress.com', 'cloudflare.com', 'godaddy.com', 'uptimerobot.com', 'gosms.cz',
        'telegram.org', 'openrouter.ai', 'openai.com', 'anthropic.com', 'notion.so', 'slack.com', 'zoom.us',
        'dropbox.com', 'wetransfer.com', 'booking.com', 'airbnb.com', 'uber.com', 'bolt.eu', 'wolt.com',
        'alza.cz', 'datart.cz', 'mall.cz', 'aliexpress.com', 'amazon.com', 'amazon.de', 'ebay.com', 'temu.com',
        'idoklad.cz', 'fakturoid.cz', 'pohoda.cz', 'stormware.cz', 'mojedatovaschranka.cz', 'datovky.cz',
        'mfcr.cz', 'financnisprava.cz', 'cssz.cz', 'vzp.cz', 'portal.gov.cz', 'gov.cz'];
}

function crmMailDomainOf(string $email): string
{
    $p = strrpos($email, '@');
    return $p === false ? '' : strtolower(substr($email, $p + 1));
}

/** Platí doména pro seznam domén (včetně subdomén: mail.google.com ∈ google.com). */
function crmMailDomainIn(string $domain, array $list): bool
{
    foreach ($list as $d) {
        if ($domain === $d || str_ends_with($domain, '.' . $d)) { return true; }
    }
    return false;
}

/** Spočítá zásahy klíčových slov (každé slovo jednou). */
function crmMailKeywordHits(string $haystack, array $words, array &$found = []): int
{
    $n = 0;
    foreach ($words as $w) {
        if (mb_stripos($haystack, $w) !== false) { $n++; $found[] = $w; }
    }
    return $n;
}

/**
 * Heuristická klasifikace (bez DB i sítě — testovatelné).
 * $ctx: 'rules' => [pattern => category], 'is_crm_customer' => bool, 'own_domains' => [..]
 * Vrací ['category','method','reason','scores'=>[...],'confident'=>bool].
 */
function crmMailClassifyHeuristic(array $env, array $ctx = []): array
{
    $h = $env['headers'];
    $from = (string)$env['from_email'];
    $domain = crmMailDomainOf($from);
    $local = strtolower((string)strstr($from, '@', true));
    $subject = (string)$env['subject'];
    $text = mb_substr((string)$env['text'], 0, 6000);
    $subjL = mb_strtolower($subject);
    $all = mb_strtolower($subject . "\n" . $text);
    $rules = $ctx['rules'] ?? [];

    $res = static fn(string $cat, string $method, string $reason, array $scores = [], bool $confident = true): array =>
        ['category' => $cat, 'method' => $method, 'reason' => $reason, 'scores' => $scores, 'confident' => $confident];

    // 1) ruční pravidla — přesná adresa, pak doména (i nadřazená)
    if ($from !== '' && isset($rules[$from])) {
        return $res($rules[$from], 'rule', 'Pravidlo pro odesílatele ' . $from);
    }
    $d = $domain;
    while ($d !== '' && strpos($d, '.') !== false) {
        if (isset($rules['@' . $d])) { return $res($rules['@' . $d], 'rule', 'Pravidlo pro doménu @' . $d); }
        $d = (string)substr($d, strpos($d, '.') + 1);
    }

    // 2) známý klient z CRM
    if (!empty($ctx['is_crm_customer'])) {
        return $res('customer', 'crm', 'Odesílatel je klient v CRM');
    }

    $ctype = strtolower(crmMailHeader($h, 'content-type'));
    $autoSub = strtolower(crmMailHeader($h, 'auto-submitted'));
    $precedence = strtolower(crmMailHeader($h, 'precedence'));
    $listUnsub = crmMailHeader($h, 'list-unsubscribe') !== '';
    $listId = crmMailHeader($h, 'list-id') !== '';
    $xMailer = strtolower(crmMailHeader($h, 'x-mailer') . ' ' . crmMailHeader($h, 'user-agent'));
    $isReply = crmMailHeader($h, 'in-reply-to') !== '' || crmMailHeader($h, 'references') !== '';
    $reSubject = (bool)preg_match('/^\s*(re|odp|aw|sv|fw|fwd|tr)\s*:/i', $subject);

    // 3) doručenky a nedoručitelné zprávy
    if (str_starts_with($ctype, 'multipart/report') || preg_match('/^(mailer-daemon|postmaster)$/', $local)) {
        return $res('robot', 'heuristic', 'Doručenka / nedoručitelná zpráva');
    }

    // 4) kontaktní formulář z webu = zákazník, i když ho odeslal robot webu
    if (!$listUnsub && preg_match('/(kontaktn\w* formul|formulář|dotaz z webu|zpráva z webu|zpráva z kontakt|nová zpráva od|poptávk|rezervac\w* (termínu|opravy|servisu)|objednávka opravy|výkup|contact form|new message from|new submission|nový dotaz)/iu', $subject)) {
        return $res('customer', 'heuristic', 'Zpráva z kontaktního formuláře / poptávka' . ($env['reply_to'] !== '' && $env['reply_to'] !== $from ? ' (odpovídat na ' . $env['reply_to'] . ')' : ''));
    }

    $robot = 0; $offer = 0; $cust = 0; $why = ['robot' => [], 'offer' => [], 'customer' => []];

    // ── ROBOT ──
    if ($autoSub !== '' && $autoSub !== 'no') { $robot += 5; $why['robot'][] = 'hlavička Auto-Submitted'; }
    if (preg_match('/^(no-?reply|do-?not-?reply|donotreply|noreply[\w.\-]*|[\w.\-]*-noreply|notifications?|notify|notifikace|alerts?|automat\w*|robot|daemon|system|bounces?[\w.\-+=]*|reports?|digest|security|accounts?|billing|invoices?|faktur\w*|orders?|objednavk\w*|status|monitoring|cron|wordpress|admin)$/', $local)) {
        $isNoreply = (bool)preg_match('/no-?reply|do-?not-?reply|donotreply/', $local);
        $robot += $isNoreply ? 5 : 3;
        $why['robot'][] = 'adresa ' . $local . '@';
    }
    if (crmMailHeader($h, 'x-auto-response-suppress') !== '' || crmMailHeader($h, 'x-autoreply') !== '' || crmMailHeader($h, 'x-autorespond') !== '') {
        $robot += 2; $why['robot'][] = 'automatická odpověď';
    }
    if (in_array($precedence, ['bulk', 'list', 'junk', 'auto_reply'], true)) {
        $robot += 2; $offer += 2; $why['robot'][] = 'Precedence: ' . $precedence;
    }
    if ($domain !== '' && crmMailDomainIn($domain, crmMailServiceDomains())) {
        $robot += 4; $why['robot'][] = 'služba ' . $domain;
    }
    if (!empty($ctx['own_domains']) && $domain !== '' && crmMailDomainIn($domain, $ctx['own_domains']) && ($autoSub !== '' && $autoSub !== 'no')) {
        $robot += 3; $why['robot'][] = 'automat z vlastní domény';
    }
    $kw = [];
    $robotWords = ['souhrn', 'přehled', 'report', 'statistik', 'výpis', 'upozornění', 'oznámení', 'notifikac',
        'notification', 'alert', 'digest', 'summary', 'weekly', 'daily', 'monthly', 'týdenní', 'denní', 'měsíční',
        'potvrzení', 'ověřovací kód', 'verification', 'verify', 'security', 'přihlášení', 'sign-in', 'login',
        'password', 'heslo', 'faktura', 'invoice', 'receipt', 'účtenka', 'payment', 'platba', 'zásilk', 'balík',
        'doručen', 'tracking', 'your order', 'vaše objednávka', 'objednávka č', 'expir', 'vyprš', 'prodloužení',
        'renewal', 'backup', 'záloha', 'failed', 'error', 'dmarc', 'delivery status', 'nová recenze', 'new review',
        'statement', 'předplatné', 'subscription', 'account', 'účet byl', 'webhook', 'monitor', 'uptime', 'downtime'];
    $robot += min(6, 2 * crmMailKeywordHits($subjL, $robotWords, $kw));
    if ($kw) { $why['robot'][] = 'předmět: ' . implode(', ', array_slice(array_unique($kw), 0, 3)); }

    // ── NABÍDKA ──
    if ($listUnsub || $listId) { $offer += 3; $robot += 1; $why['offer'][] = 'hromadná pošta (List-Unsubscribe)'; }
    $espHeaders = ['x-mailchimp-campaign', 'x-mc-user', 'x-campaign', 'x-campaignid', 'x-campaign-id', 'x-mailgun-tag',
        'x-ecomail', 'x-smartemailing', 'x-mailerlite', 'x-sib-id', 'x-mj-campaign', 'x-hs-campaign', 'x-klaviyo',
        'x-newsletter', 'x-mailkit', 'x-emarsys', 'x-sfmc-stack'];
    foreach ($espHeaders as $eh) {
        if (isset($h[$eh])) { $offer += 3; $why['offer'][] = 'kampaň (' . $eh . ')'; break; }
    }
    if (preg_match('/(mailchimp|mailkit|ecomail|smartemailing|sendinblue|brevo|mailerlite|getresponse|hubspot|klaviyo|campaign|newsletter|emailing|mailjet|mailpoet|activecampaign)/', $xMailer)) {
        $offer += 3; $why['offer'][] = 'rozesílací systém';
    }
    if (preg_match('/^(newsletter|news|novinky|marketing|promo|akce|nabidk\w*|sales|obchod|hello|team|tym)$/', $local)) {
        $offer += 2; $why['offer'][] = 'adresa ' . $local . '@';
    }
    $ko = [];
    $offerWords = ['sleva', 'slevu', 'slevy', 'akce', 'akční', 'výprodej', 'nabídk', 'nabízíme', 'nabídnout',
        'zdarma', 'novink', 'newsletter', 'black friday', 'cyber monday', 'kupón', 'kupon', 'voucher', 'promo',
        'webinář', 'webinar', 'spolupráce', 'spolupráci', 'partnerství', 'ceník', 'velkoobchod', 'b2b', 'seo', 'ppc',
        'marketing', 'reklam', 'propagac', 'zviditeln', 'tvorba web', 'web na míru', 'leasing', 'financování',
        'pojištění', 'dodávky energi', 'offer', 'discount', 'sale', 'deal', 'limited', 'free trial', 'promotion',
        'partnership', 'grow your', 'exkluzivn', 'jen dnes', 'pouze dnes', 'do vyprodání', 'nejlepší cen', 'akční cen',
        'předobjednáv', 'vyzkoušejte', 'objednejte', 'nakupte', 'ušetřete', 'ušetříte', 'kč bez dph', 'rádi bychom vám'];
    $sHits = crmMailKeywordHits($subjL, $offerWords, $ko);
    $bHits = crmMailKeywordHits(mb_strtolower($text), $offerWords, $ko);
    $offer += min(8, 2 * $sHits + $bHits);
    if ($ko) { $why['offer'][] = 'slova: ' . implode(', ', array_slice(array_unique($ko), 0, 3)); }
    if (preg_match('/(odhlásit|odhlášení|odhlaste|nechcete dostávat|unsubscribe|opt[\s-]?out|zrušit odběr)/iu', $text)) {
        $offer += 2; $robot += 1; $why['offer'][] = 'odkaz na odhlášení';
    }

    // ── ZÁKAZNÍK ──
    if ($domain !== '' && crmMailDomainIn($domain, crmMailFreemailDomains())) {
        $cust += 3; $why['customer'][] = 'osobní schránka (' . $domain . ')';
    }
    if (!$listUnsub && !$listId && !in_array($precedence, ['bulk', 'list', 'junk'], true) && ($autoSub === '' || $autoSub === 'no')) {
        $cust += 2; $why['customer'][] = 'osobní e-mail (ne hromadný)';
    }
    if ($isReply && $reSubject) { $cust += 3; $why['customer'][] = 'odpověď v konverzaci'; }
    $kc = [];
    $custWords = ['oprav', 'servis', 'displej', 'display', 'baterie', 'baterk', 'nabíj', 'iphone', 'ipad', 'macbook',
        'imac', 'apple watch', 'airpods', 'telefon', 'mobil', 'rozbit', 'prasklý', 'praskl', 'nefunguje', 'nejde',
        'nezapne', 'nenabíj', 'polit', 'voda', 'záruk', 'reklamac', 'zakázk', 'vyzvednu', 'kolik by', 'kolik stojí',
        'cena opravy', 'cenu opravy', 'termín', 'otevírací', 'dobrý den', 'prosím', 'děkuji', 'dekuji', 'výkup',
        'prodat', 'koupit', 'mám dotaz', 'chtěl bych', 'chtěla bych', 'potřeboval', 'potřebovala', 'zdravím',
        'здравствуйте', 'ремонт', 'hello, i', 'repair', 'broken', 'screen'];
    $cust += min(5, crmMailKeywordHits(mb_strtolower($subject . "\n" . mb_substr($text, 0, 1500)), $custWords, $kc));
    if ($kc) { $why['customer'][] = 'téma: ' . implode(', ', array_slice(array_unique($kc), 0, 3)); }
    if (mb_strpos(mb_substr($text, 0, 1000), '?') !== false) { $cust += 1; $why['customer'][] = 'otázka'; }
    if (mb_strlen($text) > 0 && mb_strlen($text) < 1500) { $cust += 1; }

    $scores = ['customer' => $cust, 'offer' => $offer, 'robot' => $robot];

    // ── rozhodnutí ──
    $top = $robot >= $offer ? 'robot' : 'offer';
    $topScore = max($robot, $offer);
    // robot s výrazně promo obsahem = nabídka (newsletter služby)
    if ($top === 'robot' && $offer >= $robot - 1 && ($listUnsub || $listId) && $sHits + $bHits >= 2) { $top = 'offer'; $topScore = $offer; }

    if ($topScore < 4 || $cust >= $topScore) {
        $confident = $cust - $topScore >= 3 || $topScore < 3;
        return $res('customer', 'heuristic', $why['customer'] ? ucfirst(implode(' · ', array_slice($why['customer'], 0, 3))) : 'Nic nenasvědčuje automatu ani reklamě', $scores, $confident);
    }
    $margin = $topScore - $cust;
    $second = $top === 'robot' ? $offer : $robot;
    $confident = $margin >= 3 && ($topScore - $second >= 2 || $topScore >= 8);
    // zákazník těsně za → radši zákazník (bezpečná strana), ať AI/člověk rozhodne
    if ($margin <= 1) {
        return $res('customer', 'heuristic', 'Nejisté — ponecháno u zákazníků (' . implode(' · ', array_slice($why[$top], 0, 2)) . ')', $scores, false);
    }
    return $res($top, 'heuristic', ucfirst(implode(' · ', array_slice($why[$top], 0, 3))), $scores, $confident);
}

/** AI rozhodnutí u nejasných zpráv. Vrací [category, reason] nebo null. */
function crmMailClassifyAi(array $env, ?string &$error = null): ?array
{
    $key = trim((string)get_setting('ai_api_key', ''));
    if ($key === '') { $key = trim((string)getenv('AI_API_KEY')); }
    if ($key === '') { $error = 'AI klíč není nastavený'; return null; }
    $provider = (string)get_setting('ai_provider', 'openrouter') ?: 'openrouter';
    $model = trim((string)get_setting('ai_model', '')) ?: 'google/gemini-2.0-flash-001';
    $url = 'https://openrouter.ai/api/v1/chat/completions';
    if ($provider === 'openai') {
        $url = 'https://api.openai.com/v1/chat/completions';
        if (strpos($model, '/') !== false) { $model = 'gpt-4o-mini'; }
    }
    $company = (string)get_setting('company_name', 'AppleFix');
    $h = $env['headers'];
    $system = "Třídíš firemní e-mailovou schránku firmy {$company} (servis a prodej elektroniky, hlavně Apple: opravy iPhone, iPad, Mac, výkup, e-shop). "
        . "Zařaď e-mail do JEDNÉ kategorie:\n"
        . "customer = skutečný člověk nebo zákazník: dotaz, poptávka opravy nebo zboží, reklamace, objednávka termínu, odpověď v konverzaci, zpráva z kontaktního formuláře webu, dodavatel/partner, který píše osobně k probíhající věci;\n"
        . "offer = obchodní nabídka produktů nebo služeb, newsletter, reklama, akce a slevy, nevyžádaný (cold) e-mail od firmy, nabídka spolupráce, SEO/marketing;\n"
        . "robot = automatická zpráva webu nebo služby: souhrn, report, statistika, notifikace, potvrzení objednávky/platby, faktura nebo výpis ze systému, sledování zásilky, bezpečnostní upozornění, ověřovací kód, doručenka.\n"
        . "Když váháš mezi customer a jinou kategorií, zvol customer. Odpověz POUZE JSON: {\"category\":\"customer|offer|robot\",\"reason\":\"krátce česky, max 10 slov\"}";
    $user = 'Od: ' . ($env['from_name'] !== '' ? $env['from_name'] . ' ' : '') . '<' . $env['from_email'] . ">\n"
        . ($env['reply_to'] !== '' && $env['reply_to'] !== $env['from_email'] ? 'Odpověď na: ' . $env['reply_to'] . "\n" : '')
        . 'Předmět: ' . $env['subject'] . "\n"
        . 'Hromadná pošta (List-Unsubscribe): ' . (crmMailHeader($h, 'list-unsubscribe') !== '' ? 'ano' : 'ne') . "\n"
        . 'Auto-Submitted: ' . (crmMailHeader($h, 'auto-submitted') ?: 'ne') . "\n\n"
        . "Text:\n" . mb_substr((string)$env['text'], 0, 1800);
    $payload = [
        'model' => $model,
        'temperature' => 0,
        'max_tokens' => 120,
        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'X-Title: Fix-CRM mail sort'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $code >= 400) { $error = 'AI nedostupná (' . ($cerr ?: 'HTTP ' . $code) . ')'; return null; }
    $j = json_decode((string)$resp, true);
    $content = (string)($j['choices'][0]['message']['content'] ?? '');
    if (!preg_match('/\{.*\}/s', $content, $m)) { $error = 'AI nevrátila JSON'; return null; }
    $d = json_decode($m[0], true);
    $cat = strtolower((string)($d['category'] ?? ''));
    if (!in_array($cat, CRM_MAIL_CATEGORIES, true)) { $error = 'AI vrátila neznámou kategorii'; return null; }
    return [$cat, mb_substr(trim((string)($d['reason'] ?? '')), 0, 120)];
}

/* ═════════════════════════════  DATABÁZE  ════════════════════════════════ */

function crmMailEnsureSchema(): void
{
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) { return; }
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_sort_accounts (
        id INT NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        imap_host VARCHAR(190) NOT NULL DEFAULT 'imap.forpsi.com',
        imap_port INT NOT NULL DEFAULT 993,
        imap_secure VARCHAR(8) NOT NULL DEFAULT 'ssl',
        username VARCHAR(190) NOT NULL DEFAULT '',
        password TEXT NULL,
        folder_customer VARCHAR(190) NOT NULL DEFAULT 'INBOX',
        folder_offer VARCHAR(190) NOT NULL DEFAULT 'Nabídky',
        folder_robot VARCHAR(190) NOT NULL DEFAULT 'Roboti',
        use_ai TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        uidvalidity BIGINT NOT NULL DEFAULT 0,
        last_uid BIGINT NOT NULL DEFAULT 0,
        backfill_days INT NOT NULL DEFAULT 0,
        last_run_at DATETIME NULL,
        last_ok TINYINT(1) NULL,
        last_status VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_sort_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        account_id INT NOT NULL,
        msg_key CHAR(40) NOT NULL,
        uid BIGINT NOT NULL DEFAULT 0,
        message_id VARCHAR(255) NOT NULL DEFAULT '',
        from_email VARCHAR(190) NOT NULL DEFAULT '',
        from_name VARCHAR(190) NOT NULL DEFAULT '',
        reply_to VARCHAR(190) NOT NULL DEFAULT '',
        subject VARCHAR(255) NOT NULL DEFAULT '',
        snippet VARCHAR(300) NOT NULL DEFAULT '',
        received_at DATETIME NULL,
        category VARCHAR(12) NOT NULL,
        method VARCHAR(12) NOT NULL DEFAULT 'heuristic',
        reason VARCHAR(255) NOT NULL DEFAULT '',
        folder VARCHAR(190) NOT NULL DEFAULT 'INBOX',
        moved TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_msg (account_id, msg_key),
        KEY idx_created (created_at),
        KEY idx_cat (category, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_sort_rules (
        id INT NOT NULL AUTO_INCREMENT,
        pattern VARCHAR(190) NOT NULL,
        category VARCHAR(12) NOT NULL,
        created_by VARCHAR(120) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pattern (pattern)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

function crmMailAccounts(bool $onlyEnabled = false): array
{
    global $pdo;
    crmMailEnsureSchema();
    return $pdo->query('SELECT * FROM mail_sort_accounts' . ($onlyEnabled ? ' WHERE enabled = 1' : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

function crmMailAccount(int $id): ?array
{
    global $pdo;
    crmMailEnsureSchema();
    $s = $pdo->prepare('SELECT * FROM mail_sort_accounts WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function crmMailRules(): array
{
    global $pdo;
    crmMailEnsureSchema();
    $out = [];
    foreach ($pdo->query('SELECT pattern, category FROM mail_sort_rules') as $r) {
        $out[strtolower((string)$r['pattern'])] = (string)$r['category'];
    }
    return $out;
}

/** Je adresa klientem v CRM (customers / e-shop zákazníci)? Cache na běh. */
function crmMailIsCrmCustomer(string $email): bool
{
    global $pdo;
    static $cache = [];
    if ($email === '') { return false; }
    if (array_key_exists($email, $cache)) { return $cache[$email]; }
    $hit = false;
    foreach (['customers', 'eshop_customers'] as $tbl) {
        try {
            $s = $pdo->prepare("SELECT 1 FROM `$tbl` WHERE LOWER(TRIM(email)) = ? LIMIT 1");
            $s->execute([$email]);
            if ($s->fetchColumn()) { $hit = true; break; }
        } catch (Throwable $e) { /* tabulka nemusí existovat */ }
    }
    return $cache[$email] = $hit;
}

/** Vlastní domény firmy (ze schránek v třídění + SMTP odesílatele). */
function crmMailOwnDomains(): array
{
    $d = [];
    foreach (crmMailAccounts() as $a) { $d[] = crmMailDomainOf((string)$a['email']); }
    $d[] = crmMailDomainOf((string)get_setting('smtp_from_email', ''));
    return array_values(array_filter(array_unique($d)));
}

/** Úplné rozhodnutí: pravidla → CRM → heuristika → (AI u nejasných). */
function crmMailClassify(array $env, array $acc, array $rules, int &$aiBudget): array
{
    $c = crmMailClassifyHeuristic($env, [
        'rules' => $rules,
        'is_crm_customer' => crmMailIsCrmCustomer((string)$env['from_email']) || ($env['reply_to'] !== '' && crmMailIsCrmCustomer((string)$env['reply_to'])),
        'own_domains' => crmMailOwnDomains(),
    ]);
    if ($c['method'] === 'heuristic' && !$c['confident'] && !empty($acc['use_ai']) && $aiBudget > 0) {
        $aiBudget--;
        $err = null;
        $ai = crmMailClassifyAi($env, $err);
        if ($ai !== null) {
            $c['category'] = $ai[0];
            $c['method'] = 'ai';
            $c['reason'] = 'AI: ' . ($ai[1] !== '' ? $ai[1] : crmMailCategoryMeta()[$ai[0]]['one']);
        } else {
            $c['reason'] .= ' · ' . $err;
        }
    }
    return $c;
}

/** Připojí se ke schránce účtu. Volající musí zavolat ->logout(). */
function crmMailConnect(array $acc): CrmImap
{
    $imap = new CrmImap((string)$acc['imap_host'], (int)$acc['imap_port'], (string)$acc['imap_secure']);
    $imap->connect();
    $imap->login((string)($acc['username'] ?: $acc['email']), (string)$acc['password']);
    return $imap;
}

function crmMailFolderFor(array $acc, string $category): string
{
    $f = trim((string)($acc['folder_' . $category] ?? ''));
    return $f === '' ? 'INBOX' : $f;
}

/** Klíč zprávy pro deduplikaci logu. */
function crmMailMsgKey(array $env, int $uidvalidity, int $uid): string
{
    return sha1($env['message_id'] !== '' ? strtolower($env['message_id']) : ('uid:' . $uidvalidity . ':' . $uid));
}

/**
 * Náhled: roztřídí posledních $limit zpráv v Doručené poště, NIC nepřesouvá ani neukládá.
 */
function crmMailPreview(array $acc, int $limit = 30): array
{
    $imap = crmMailConnect($acc);
    try {
        $imap->select('INBOX', true);
        $uids = $imap->uidSearch('ALL');
        $uids = array_slice($uids, -$limit);
        $msgs = $imap->fetchMessages($uids, 12000);
        $rules = crmMailRules();
        $budget = 15;
        $out = [];
        foreach (array_reverse($uids) as $uid) {
            if (!isset($msgs[$uid])) { continue; }
            $env = crmMailEnvelope($msgs[$uid]['header'], $msgs[$uid]['body']);
            $c = crmMailClassify($env, $acc, $rules, $budget);
            $out[] = [
                'uid' => $uid,
                'from_email' => $env['from_email'],
                'from_name' => $env['from_name'],
                'subject' => $env['subject'],
                'date' => $env['date'] ? date('d.m. H:i', $env['date']) : '',
                'category' => $c['category'],
                'method' => $c['method'],
                'reason' => $c['reason'],
            ];
        }
        return $out;
    } finally {
        $imap->logout();
    }
}

/**
 * Roztřídí nové zprávy jednoho účtu. Vrací souhrn ['processed','moved','counts'=>[cat=>n],'error'=>?string].
 * $opts['limit'] = max. zpráv za běh (zbytek příště).
 */
function crmMailSortAccount(array $acc, array $opts = []): array
{
    global $pdo;
    crmMailEnsureSchema();
    $limit = (int)($opts['limit'] ?? 150);
    $sum = ['processed' => 0, 'moved' => 0, 'counts' => ['customer' => 0, 'offer' => 0, 'robot' => 0], 'error' => null];
    $imap = null;
    try {
        $imap = crmMailConnect($acc);
        $box = $imap->select('INBOX');
        $lastUid = (int)$acc['last_uid'];
        if ((int)$acc['uidvalidity'] !== 0 && (int)$acc['uidvalidity'] !== $box['uidvalidity']) {
            $lastUid = 0;                       // server přečísloval schránku → začít znovu od data
        }
        if ($lastUid > 0) {
            $uids = array_values(array_filter($imap->uidSearch('UID ' . ($lastUid + 1) . ':*'), static fn($u) => $u > $lastUid));
        } else {
            $days = max(0, (int)$acc['backfill_days']);
            if ($days === 0) {
                // první spuštění bez dotřídění staré pošty: jen si zapamatovat, kde začínáme
                $start = $box['uidnext'] > 0 ? $box['uidnext'] - 1 : 0;
                if ($start === 0) { $all = $imap->uidSearch('ALL'); $start = $all ? max($all) : 0; }
                $pdo->prepare('UPDATE mail_sort_accounts SET last_uid = ?, uidvalidity = ? WHERE id = ?')
                    ->execute([$start, $box['uidvalidity'], $acc['id']]);
                crmMailSaveStatus((int)$acc['id'], true, 'Připraveno — třídím nově příchozí poštu');
                return $sum;
            }
            $uids = $imap->uidSearch('SINCE ' . date('d-M-Y', strtotime('-' . $days . ' days')));
        }
        $uids = array_slice($uids, 0, $limit);
        if (!$uids) {
            crmMailSaveStatus((int)$acc['id'], true, 'OK — žádná nová pošta', $lastUid, $box['uidvalidity']);
            return $sum;
        }

        $msgs = $imap->fetchMessages($uids);
        $rules = crmMailRules();
        $aiBudget = 25;
        $toMove = [];
        $logRows = [];
        $maxUid = $lastUid;
        $envs = [];
        foreach ($uids as $uid) {
            if (isset($msgs[$uid])) { $envs[$uid] = crmMailEnvelope($msgs[$uid]['header'], $msgs[$uid]['body']); }
        }
        // Už jednou roztříděné zprávy znovu nesahat: když ji někdo vrátí do Doručené
        // (přeřazením v CRM, nebo přetažením v Outlooku/telefonu), dostane nové UID
        // a bez téhle kontroly by ji další běh zase odklidil.
        $seen = [];
        if ($envs) {
            $keys = array_map(static fn($uid) => crmMailMsgKey($envs[$uid], $box['uidvalidity'], $uid), array_keys($envs));
            $q = $pdo->prepare('SELECT msg_key FROM mail_sort_log WHERE account_id = ? AND msg_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
            $q->execute(array_merge([(int)$acc['id']], $keys));
            $seen = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
        }
        foreach ($uids as $uid) {
            $maxUid = max($maxUid, $uid);
            if (!isset($envs[$uid])) { continue; }
            $env = $envs[$uid];
            if (isset($seen[crmMailMsgKey($env, $box['uidvalidity'], $uid)])) { continue; }
            $c = crmMailClassify($env, $acc, $rules, $aiBudget);
            $folder = crmMailFolderFor($acc, $c['category']);
            $path = $imap->folderPath($folder);
            $move = $path !== 'INBOX';
            if ($move) { $toMove[$path][] = $uid; }
            $sum['processed']++;
            $sum['counts'][$c['category']]++;
            $logRows[] = [$env, $c, $uid, $path, $move];
        }

        foreach ($toMove as $path => $list) {
            $imap->ensureFolder($path);
            $imap->moveUids($list, $path);
            $sum['moved'] += count($list);
        }

        $ins = $pdo->prepare('INSERT INTO mail_sort_log
            (account_id, msg_key, uid, message_id, from_email, from_name, reply_to, subject, snippet, received_at, category, method, reason, folder, moved)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE uid = VALUES(uid), category = VALUES(category), method = VALUES(method),
                reason = VALUES(reason), folder = VALUES(folder), moved = VALUES(moved), created_at = NOW()');
        foreach ($logRows as [$env, $c, $uid, $path, $move]) {
            $snippet = trim((string)preg_replace('/\s+/u', ' ', mb_substr((string)$env['text'], 0, 400)));
            $ins->execute([
                $acc['id'], crmMailMsgKey($env, $box['uidvalidity'], $uid), $uid,
                mb_substr($env['message_id'], 0, 255), mb_substr($env['from_email'], 0, 190), mb_substr($env['from_name'], 0, 190),
                mb_substr($env['reply_to'], 0, 190), mb_substr($env['subject'], 0, 255), mb_substr($snippet, 0, 300),
                $env['date'] ? date('Y-m-d H:i:s', $env['date']) : null,
                $c['category'], $c['method'], mb_substr($c['reason'], 0, 255), $path, $move ? 1 : 0,
            ]);
        }

        if ($sum['processed'] === 0) {
            crmMailSaveStatus((int)$acc['id'], true, 'OK — žádná nová pošta', $maxUid, $box['uidvalidity']);
            return $sum;
        }
        $meta = crmMailCategoryMeta();
        $parts = [];
        foreach ($sum['counts'] as $cat => $n) { if ($n) { $parts[] = $n . '× ' . mb_strtolower($meta[$cat]['label']); } }
        crmMailSaveStatus((int)$acc['id'], true, 'OK — roztříděno ' . $sum['processed'] . ' (' . implode(', ', $parts) . ')', $maxUid, $box['uidvalidity']);
    } catch (Throwable $e) {
        $sum['error'] = $e->getMessage();
        crmMailSaveStatus((int)$acc['id'], false, 'CHYBA: ' . $e->getMessage());
    } finally {
        if ($imap) { $imap->logout(); }
    }
    return $sum;
}

function crmMailSaveStatus(int $id, bool $ok, string $status, ?int $lastUid = null, ?int $uidvalidity = null): void
{
    global $pdo;
    $sql = 'UPDATE mail_sort_accounts SET last_run_at = NOW(), last_ok = ?, last_status = ?';
    $par = [$ok ? 1 : 0, mb_substr($status, 0, 500)];
    if ($lastUid !== null) { $sql .= ', last_uid = ?'; $par[] = $lastUid; }
    if ($uidvalidity !== null) { $sql .= ', uidvalidity = ?'; $par[] = $uidvalidity; }
    $sql .= ' WHERE id = ?';
    $par[] = $id;
    $pdo->prepare($sql)->execute($par);
}

/** Roztřídí všechny zapnuté schránky (cron / pozadí). Zámek proti souběhu. */
function crmMailSortRunAll(): array
{
    global $pdo;
    $lockFile = rtrim(sys_get_temp_dir(), '/') . '/fixcrm_mail_sort.lock';
    $lock = @fopen($lockFile, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return ['skipped' => 'běží jiný běh třídění']; }
    $out = [];
    try {
        crmMailEnsureSchema();
        foreach (crmMailAccounts(true) as $acc) {
            $out[$acc['email']] = crmMailSortAccount($acc);
        }
        // retence přehledu: 90 dní
        $pdo->exec('DELETE FROM mail_sort_log WHERE created_at < NOW() - INTERVAL 90 DAY');
        set_setting('mail_sort_last_run', (string)time());
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $out;
}

/** Poor-man's cron: z notify_poll spustí třídění na pozadí každé ~3 minuty. */
function crmMailSortMaybeSchedule(): void
{
    try {
        $last = (int)get_setting('mail_sort_last_attempt', '0');
        if (time() - $last < 180) { return; }
        global $pdo;
        $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mail_sort_accounts'")->fetchColumn();
        if ($n === 0) { set_setting('mail_sort_last_attempt', (string)time()); return; }
        if ((int)$pdo->query('SELECT COUNT(*) FROM mail_sort_accounts WHERE enabled = 1')->fetchColumn() === 0) {
            set_setting('mail_sort_last_attempt', (string)time());
            return;
        }
        set_setting('mail_sort_last_attempt', (string)time());       // claim (proti souběhu)
        if (!function_exists('exec') || !function_exists('crmBackupFindBin')) { return; }
        $php = crmBackupFindBin(['php', 'php8.3', 'php8.2', 'php8.1']);
        $script = dirname(__DIR__) . '/scripts/mail_sort.php';
        if ($php !== null && is_file($script)) {
            exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
        }
    } catch (Throwable $e) { /* třídění pošty nesmí nikdy shodit request */ }
}

/**
 * Ruční přeřazení zprávy z přehledu: přesune ji na serveru do složky nové
 * kategorie a (volitelně) založí pravidlo pro odesílatele / doménu.
 */
function crmMailReclassify(int $logId, string $category, string $learn = 'sender'): array
{
    global $pdo;
    crmMailEnsureSchema();
    if (!in_array($category, CRM_MAIL_CATEGORIES, true)) { return [false, 'Neznámá kategorie.']; }
    $s = $pdo->prepare('SELECT * FROM mail_sort_log WHERE id = ?');
    $s->execute([$logId]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return [false, 'Záznam nenalezen.']; }
    $acc = crmMailAccount((int)$row['account_id']);
    if (!$acc) { return [false, 'Schránka už není v CRM.']; }

    $note = '';
    $newPath = (string)$row['folder'];
    $moved = (int)$row['moved'];
    try {
        $imap = crmMailConnect($acc);
        try {
            $target = $imap->folderPath(crmMailFolderFor($acc, $category));
            $candidates = array_unique([(string)$row['folder'], 'INBOX',
                $imap->folderPath(crmMailFolderFor($acc, 'customer')),
                $imap->folderPath(crmMailFolderFor($acc, 'offer')),
                $imap->folderPath(crmMailFolderFor($acc, 'robot'))]);
            $found = false;
            foreach ($candidates as $folder) {
                try { $imap->select($folder); } catch (Throwable $e) { continue; }
                $uids = $imap->findByMessageId((string)$row['message_id']);
                if (!$uids) { continue; }
                $found = true;
                if ($folder !== $target) {
                    $imap->ensureFolder($target);
                    $imap->moveUids($uids, $target);
                }
                $newPath = $target;
                $moved = $target !== 'INBOX' ? 1 : 0;
                break;
            }
            if (!$found) { $note = ' Zprávu se na serveru nepodařilo najít (smazaná / přesunutá ručně) — změnila se jen kategorie.'; }
        } finally {
            $imap->logout();
        }
    } catch (Throwable $e) {
        return [false, 'Server: ' . $e->getMessage()];
    }

    $pdo->prepare("UPDATE mail_sort_log SET category = ?, method = 'manual', reason = 'Ručně přeřazeno', folder = ?, moved = ? WHERE id = ?")
        ->execute([$category, $newPath, $moved, $logId]);

    $pattern = '';
    $email = strtolower((string)$row['from_email']);
    if ($learn === 'sender' && $email !== '') { $pattern = $email; }
    if ($learn === 'domain' && $email !== '') {
        $dom = crmMailDomainOf($email);
        if ($dom !== '' && !crmMailDomainIn($dom, crmMailFreemailDomains())) { $pattern = '@' . $dom; }
        else { $pattern = $email; $note .= ' Doména ' . $dom . ' je osobní (freemail) — pravidlo platí jen pro tuto adresu.'; }
    }
    if ($pattern !== '') {
        crmMailSaveRule($pattern, $category);
    }
    return [true, 'Přeřazeno.' . ($pattern !== '' ? ' Další pošta od ' . $pattern . ' půjde rovnou sem.' : '') . $note];
}

function crmMailSaveRule(string $pattern, string $category): void
{
    global $pdo;
    $who = (string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'systém');
    $pdo->prepare('INSERT INTO mail_sort_rules (pattern, category, created_by) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE category = VALUES(category), created_by = VALUES(created_by), created_at = NOW()')
        ->execute([strtolower($pattern), $category, mb_substr($who, 0, 120)]);
}
