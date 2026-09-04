<?php
function clientCheckLoginAttempts($pdo): bool {
    if (!isset($pdo)) return true;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn() < 5;
    } catch (Exception $e) {
        return true;
    }
}

function clientRecordLoginAttempt($pdo, bool $success): void {
    if (!isset($pdo)) return;
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if ($success) {
            $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?")->execute([$ip]);
        } else {
            $pdo->prepare("INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW())")->execute([$ip]);
        }
    } catch (Exception $e) {
        // ignore
    }
}

function clientIsLoggedIn(): bool {
    return !empty($_SESSION['client_authenticated']) && !empty($_SESSION['client_customer_id']);
}

function clientRequireAuth(): void {
    if (!clientIsLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

function clientLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

function clientNormalizePhone(string $value): string {
    return preg_replace('/\D+/', '', $value) ?: '';
}

function clientNormalizeEmail(string $value): string {
    return strtolower(trim($value));
}

/* ── JEDNA OSOBA, VÍC ZÁZNAMŮ ZÁKAZNÍKA ─────────────────────────────────────
   CRM při zakládání zákazníka neslučuje duplicity: nová reklamace „s novým
   klientem", import reklamací nebo rezervace z webu klidně založí DALŠÍ řádek
   customers se stejným telefonem (jinak zapsaným). Klient se ale přihlásí vždy
   k JEDNOMU z těch řádků, takže reklamace zapsané pod jeho druhým záznamem pro
   něj v portálu neexistovaly.

   BEZPEČNOSTNÍ HRANICE (revize 4. 9. 2026): sourozenecké záznamy se používají
   JEN pro reklamace (výpis, protokol, přílohy). Zakázky, faktury a doklady se
   berou výhradně podle záznamu, který klient prokázal PINem — jinak by sdílené
   číslo (firemní linka) nebo rezervace z webu s cizím telefonem otevřely cizí
   doklady. Za tutéž osobu se považují jen záznamy se STEJNÝM CELÝM telefonním
   číslem (po číslicích, s předvolbou: „+420 777 123 456" = „777123456" =
   „00420777123456"; slovenské +421 se stejnými číslicemi je jiné číslo).
   E-mail se pro slučování nepoužívá (rodina/firma sdílí schránku). */

/** Kanonický tvar telefonu: jen číslice, bez „00", české devítimístné číslo
 *  dostane předvolbu 420. Prázdno = nepoužitelné pro párování (krátké číslo,
 *  placeholder typu 000000000 / 123456789). */
function clientPhoneCanonical(string $phone): string {
    $d = clientNormalizePhone($phone);
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (strlen($d) === 9) $d = '420' . $d;
    if (strlen($d) < 11) return '';
    $tail = substr($d, -9);
    if (count(array_unique(str_split($tail))) < 3) return '';                       // 000000000, 111111111…
    if (in_array($tail, ['123456789', '987654321', '123456780'], true)) return '';   // vymyšlená čísla
    return $d;
}

/** Klíč jména pro párování záznamů: příjmení bez diakritiky a velikosti písmen
 *  („Novák" = „novak"); když příjmení chybí (import/„—"), vezme se jméno. Prázdno = nepárovat. */
function clientNameKey(string $firstName, string $lastName): string {
    $norm = static function (string $v): string {
        $v = mb_strtolower(trim($v));
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
        if ($t !== false && $t !== '') $v = $t;
        return preg_replace('/[^a-z0-9]+/', '', $v) ?: '';
    };
    $k = $norm($lastName);
    if ($k === '') $k = $norm($firstName);
    return $k;
}

/** Id všech záznamů zákazníka, které jsou tatáž osoba jako přihlášený: stejný
 *  (celý) telefon A stejné příjmení. Přihlášený je vždy první. Bez telefonu,
 *  s nepoužitelným číslem nebo bez jména = jen on. (Sdílená firemní linka s jinými
 *  příjmeními se nesloučí — kolegové by si viděli reklamace.) */
function clientSiblingCustomerIds($pdo, int $customerId): array {
    static $cache = [];
    if ($customerId <= 0) return [];
    if (isset($cache[$customerId])) return $cache[$customerId];
    $ids = [$customerId];
    if (!isset($pdo)) return $cache[$customerId] = $ids;
    try {
        $st = $pdo->prepare("SELECT phone, first_name, last_name FROM customers WHERE id = ? LIMIT 1");
        $st->execute([$customerId]);
        $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $mine = clientPhoneCanonical((string)($me['phone'] ?? ''));
        $myName = clientNameKey((string)($me['first_name'] ?? ''), (string)($me['last_name'] ?? ''));
        if ($mine === '' || $myName === '') return $cache[$customerId] = $ids;
        $rows = [];
        try {   // levný předvýběr podle konce čísla (MySQL 8 / MariaDB 10.0.5+), přesná shoda až v PHP
            $st = $pdo->prepare("SELECT id, phone, first_name, last_name FROM customers WHERE id <> ? AND REGEXP_REPLACE(COALESCE(phone,''),'[^0-9]','') LIKE ?");
            $st->execute([$customerId, '%' . substr($mine, -9)]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $q = $pdo->prepare("SELECT id, phone, first_name, last_name FROM customers WHERE id <> ? AND phone IS NOT NULL AND phone <> ''");
            $q->execute([$customerId]);
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $rows[] = $r; }
        }
        foreach ($rows as $r) {
            $rid = (int)($r['id'] ?? 0);
            if ($rid > 0 && !in_array($rid, $ids, true)
                && clientPhoneCanonical((string)($r['phone'] ?? '')) === $mine
                && clientNameKey((string)($r['first_name'] ?? ''), (string)($r['last_name'] ?? '')) === $myName) $ids[] = $rid;
        }
        // Záznam založený rezervací z webu, se kterým servis ještě nepracoval (má jen
        // zakázky s neověřeným PINem), za sourozence neplatí — kdokoli si ho na webu
        // založí s cizím telefonem. Záznamy bez zakázek (reklamace „s novým klientem") zůstávají.
        if (count($ids) > 1) {
            try {
                if (function_exists('ensureOrderPinUnverifiedColumn')) ensureOrderPinUnverifiedColumn();
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $q = $pdo->prepare("SELECT customer_id, COUNT(*) AS n, SUM(CASE WHEN COALESCE(pin_unverified,0) = 0 THEN 1 ELSE 0 END) AS ok
                                    FROM orders WHERE customer_id IN ($ph) GROUP BY customer_id");
                $q->execute($ids);
                $untrusted = [];
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ((int)$r['n'] > 0 && (int)$r['ok'] === 0) $untrusted[] = (int)$r['customer_id'];
                }
                $ids = array_values(array_filter($ids, static fn($i) => $i === $customerId || !in_array($i, $untrusted, true)));
            } catch (Throwable $e) { /* bez sloupce = bez filtru (starší DB) */ }
        }
    } catch (Throwable $e) {
        error_log('clientSiblingCustomerIds: ' . $e->getMessage());
    }
    return $cache[$customerId] = $ids;
}

/** SQL výraz: kanonický telefon sloupce (stejná pravidla jako clientPhoneCanonical,
 *  bez detekce placeholderů — ty odfiltruje prázdný klíč na straně PHP). */
function clientPhoneCanonicalSql(string $column): string {
    $d = "REGEXP_REPLACE(COALESCE($column,''),'[^0-9]','')";
    $s = "(CASE WHEN $d LIKE '00%' THEN SUBSTRING($d, 3) ELSE $d END)";   // nejdřív pryč „00"…
    return "(CASE WHEN LENGTH($s) = 9 THEN CONCAT('420', $s) ELSE $s END)";  // …pak české číslo bez předvolby
}

/** Podmínka „tahle reklamace patří přihlášenému klientovi" (pro WHERE):
 *    · customer_id je jeho záznam nebo záznam se stejným telefonem, nebo
 *    · je navázaná na JEHO zakázku (order_id) a nemá zákazníka / má některý z jeho záznamů
 *      (reklamace omylem přiřazená cizímu klientovi se neukáže — nesla by cizí údaje), nebo
 *    · nemá zákazníka vůbec (starý import) a telefon na ní je přesně jeho.
 *  Vrací ['sql' => '(…)', 'params' => […]]; $alias = alias tabulky complaints. */
function clientComplaintOwnerSql($pdo, int $customerId, string $alias = 'c'): array {
    $ids = clientSiblingCustomerIds($pdo, $customerId);
    if (!$ids) return ['sql' => '0', 'params' => []];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "($alias.customer_id IN ($ph)"
         . " OR ($alias.order_id IN (SELECT o.id FROM orders o WHERE o.customer_id = ?) AND ($alias.customer_id IS NULL OR $alias.customer_id IN ($ph)))";
    $params = array_merge($ids, [$customerId], $ids);
    static $regexOk = null;
    if ($regexOk === null) {
        try { $pdo->query("SELECT REGEXP_REPLACE('a1','[^0-9]','')"); $regexOk = true; }
        catch (Throwable $e) { $regexOk = false; }
    }
    $mine = '';
    try {
        $st = $pdo->prepare("SELECT phone FROM customers WHERE id = ? LIMIT 1");
        $st->execute([$customerId]);
        $mine = clientPhoneCanonical((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) { $mine = ''; }
    if ($regexOk && $mine !== '') {
        $sql .= " OR ($alias.customer_id IS NULL AND " . clientPhoneCanonicalSql("$alias.phone") . " = ?)";
        $params[] = $mine;
    }
    return ['sql' => $sql . ')', 'params' => $params];
}

function clientLookupCustomerAndOrders($pdo, string $identifier): array {
    $identifier = trim($identifier);
    $result = [
        'customer' => null,
        'customers' => [],      // id => řádek — všechny záznamy, na které identifikátor sedí
        'orders' => [],         // zakázky všech kandidátů (každá nese customer_id svého záznamu)
        'matched_order' => null,
    ];

    if (!isset($pdo) || $identifier === '') {
        return $result;
    }

    $cols = "id, first_name, last_name, phone, email, company, customer_type, preferred_language";
    $candidates = [];
    $onlyOrder = null;
    // Zakázka s neověřeným PINem (rezervace z webu, se kterou servis ještě nepracoval)
    // do portálu nepouští — v ŽÁDNÉ větvi (číslo zakázky, e-mail, telefon).
    if (function_exists('ensureOrderPinUnverifiedColumn')) ensureOrderPinUnverifiedColumn();
    $pinOk = "COALESCE(o.pin_unverified,0) = 0";

    try {
        // 1) Číslo zakázky: kód (APFAZ2600485), starý kód z importu, nebo interní id.
        //    Dřív se bralo JEN interní číslo (orders.id) — kód zakázky, který klient
        //    má na zakázkovém listu, přihlášení neuměl. PIN pak musí sedět na TU zakázku.
        $ordStmt = null;
        if (preg_match('/[A-Za-z]/', $identifier) && !filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $ordStmt = $pdo->prepare("SELECT o.id AS order_id, o.*, c.first_name, c.last_name, c.phone, c.email, c.company, c.customer_type, c.preferred_language
                 FROM orders o INNER JOIN customers c ON c.id = o.customer_id
                 WHERE (UPPER(o.order_code) = UPPER(?) OR UPPER(COALESCE(o.legacy_code,'')) = UPPER(?)) AND $pinOk LIMIT 1");
            $ordStmt->execute([$identifier, $identifier]);
        } elseif (ctype_digit($identifier) && strlen($identifier) <= 9) {
            $ordStmt = $pdo->prepare("SELECT o.id AS order_id, o.*, c.first_name, c.last_name, c.phone, c.email, c.company, c.customer_type, c.preferred_language
                 FROM orders o INNER JOIN customers c ON c.id = o.customer_id
                 WHERE o.id = ? AND $pinOk LIMIT 1");
            $ordStmt->execute([(int)$identifier]);
        }
        if ($ordStmt && ($row = $ordStmt->fetch())) {
            $candidates[(int)$row['customer_id']] = [
                'id' => (int)$row['customer_id'],
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'phone' => $row['phone'] ?? '',
                'email' => $row['email'] ?? '',
                'company' => $row['company'] ?? '',
                'customer_type' => $row['customer_type'] ?? 'private',
                'preferred_language' => $row['preferred_language'] ?? 'cs',
            ];
            $result['matched_order'] = $row;
            $onlyOrder = $row;
        }

        // 2) E-mail — všechny záznamy s tímhle e-mailem (duplicity).
        if (!$candidates && filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT $cols FROM customers WHERE LOWER(email) = LOWER(?) ORDER BY id ASC");
            $stmt->execute([$identifier]);
            foreach ($stmt->fetchAll() as $row) { $candidates[(int)$row['id']] = $row; }
        }

        // 3) Telefon — celé číslo po číslicích (s předvolbou), takže „+420 777 123 456"
        //    najde i záznam uložený jako „777123456" a naopak.
        //    Kanonický tvar některá pravá čísla odmítá (slovenské 0905…, čísla s málo
        //    různými číslicemi) — pro PŘIHLÁŠENÍ proto platí i prostá shoda číslic
        //    (chování před touto verzí); přísná kanonizace hlídá jen slučování záznamů.
        if (!$candidates) {
            $needle = clientPhoneCanonical($identifier);
            $needleRaw = clientNormalizePhone($identifier);
            if ($needle !== '' || strlen($needleRaw) >= 6) {
                $stmt = $pdo->query("SELECT $cols FROM customers WHERE phone IS NOT NULL AND phone <> '' ORDER BY id ASC");
                while ($row = $stmt->fetch()) {
                    $rowPhone = (string)($row['phone'] ?? '');
                    if (($needle !== '' && clientPhoneCanonical($rowPhone) === $needle)
                        || (strlen($needleRaw) >= 6 && clientNormalizePhone($rowPhone) === $needleRaw)) {
                        $candidates[(int)$row['id']] = $row;
                    }
                }
            }
        }

        if ($candidates) {
            $result['customers'] = $candidates;
            $result['customer'] = reset($candidates);
            if ($onlyOrder) {
                // zadané číslo zakázky → PIN musí sedět právě na ni
                $result['orders'] = [$onlyOrder];
            } else {
                // Zakázky každého kandidáta zvlášť (každá nese customer_id svého záznamu).
                $ids = array_keys($candidates);
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("SELECT o.* FROM orders o WHERE o.customer_id IN ($ph) AND $pinOk
                    ORDER BY o.created_at DESC, o.id DESC");
                $stmt->execute($ids);
                $result['orders'] = $stmt->fetchAll();
            }
            if (!$result['matched_order'] && !empty($result['orders'])) {
                $result['matched_order'] = $result['orders'][0];
            }
        }

        return $result;
    } catch (Exception $e) {
        return $result;
    }
}
