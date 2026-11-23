<?php
/**
 * ÚKOLY — předávání práce mezi lidmi (v4.4.0, zadání majitele 22. 11. 2026).
 *
 * Místo, kde si zaměstnanci a vedení nechávají úkoly, které si nestihli říct
 * po telefonu nebo při předání směny. Kdokoli zadá úkol komukoli, ten ho
 * odškrtne a zadavatel vidí, v jakém je stavu.
 *
 * KOMU jde úkol zadat:
 *   - konkrétnímu člověku (`tech:15`, výjimečně `user:3` u účtu bez zaměstnance),
 *   - celé prodejně (`branch:1`) — „kdokoli, kdo tam zrovna je"; odškrtne ho
 *     první, kdo ho udělá. Typický vzkaz pro ranní směnu.
 * Úkol pro víc lidí se zakládá každému zvlášť, aby měl každý vlastní stav.
 *
 * KDO CO VIDÍ:
 *   - každý: úkoly, které zadal, které jsou pro něj a které jsou pro jeho prodejnu,
 *   - manažer navíc úkoly lidí své prodejny,
 *   - admin a Boss všechno,
 *   - úkol zadaný sám sobě je soukromá poznámka: vidí ho jen jeho autor.
 * Kontroly jsou TADY u dat, ne v UI — na endpoint se dá poslat cokoli.
 *
 * Klíč člověka je crmStaffKey() — stejný, pod kterým se ukládají push tokeny,
 * takže upozornění do appky míří rovnou na správného člověka.
 */

/** Jak dlouho zůstávají hotové úkoly ve výpisech. */
const AFX_TASK_DONE_KEEP_DAYS = 30;

function afxTaskEnsureSchema(): void
{
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) { return; }
    if ($pdo->inTransaction()) { return; }   // DDL v transakci = implicitní commit
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_tasks (
            id INT NOT NULL AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            detail TEXT NULL,
            creator_key VARCHAR(32) NOT NULL,
            creator_name VARCHAR(120) NOT NULL DEFAULT '',
            assignee_key VARCHAR(32) NOT NULL,
            due_date DATE NULL,
            due_time TIME NULL,
            urgent TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            seen_at DATETIME NULL,
            seen_by_name VARCHAR(120) NOT NULL DEFAULT '',
            done_at DATETIME NULL,
            done_by_key VARCHAR(32) NOT NULL DEFAULT '',
            done_by_name VARCHAR(120) NOT NULL DEFAULT '',
            deleted_at DATETIME NULL,
            deleted_by_name VARCHAR(120) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_assignee (assignee_key, status),
            KEY idx_creator (creator_key, status),
            KEY idx_done (status, done_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        /* Průběh úkolu: poznámky lidí i to, co se s úkolem stalo (hotovo, vráceno, změna). */
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_task_events (
            id INT NOT NULL AUTO_INCREMENT,
            task_id INT NOT NULL,
            kind VARCHAR(12) NOT NULL DEFAULT 'comment',
            author_key VARCHAR(32) NOT NULL DEFAULT '',
            author_name VARCHAR(120) NOT NULL DEFAULT '',
            body VARCHAR(1000) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_task (task_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('afxTaskEnsureSchema: ' . $e->getMessage());
    }
}

/** Klíč přihlášeného („tech:15" / „user:3"); prázdný = nikdo přihlášený. */
function afxTaskMe(): string
{
    return function_exists('crmStaffKey') ? (string)crmStaffKey() : '';
}

function afxTaskMeName(): string
{
    $n = trim((string)($_SESSION['full_name'] ?? ''));
    if ($n !== '') { return $n; }
    $p = afxTaskPeople()[afxTaskMe()] ?? null;
    return $p ? (string)$p['name'] : 'Neznámý';
}

/** Admin a Boss: vidí a smí všechno. */
function afxTaskIsLead(): bool
{
    return function_exists('isBranchGlobalViewer') && isBranchGlobalViewer();
}

/** Má záložku „Tým" (admin, Boss, manažer)? */
function afxTaskHasTeamView(): bool
{
    if (afxTaskIsLead()) { return true; }
    return function_exists('getCurrentStaffRole') && getCurrentStaffRole() === 'manager';
}

/** Barva člověka — stejný výpočet jako v Rozpisu, ať má každý všude tu samou. */
function afxTaskColor(string $key): string
{
    [$type, $id] = array_pad(explode(':', $key, 2), 2, '0');
    $hue = ((int)$id * 47 + ($type === 'user' ? 180 : 0)) % 360;
    return 'hsl(' . $hue . ' 62% 52%)';
}

function afxTaskInitials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
    if (!$parts) { return '?'; }
    $a = mb_substr($parts[0], 0, 1, 'UTF-8');
    $b = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : mb_substr($parts[0], 1, 1, 'UTF-8');
    return mb_strtoupper($a . $b, 'UTF-8');
}

/** Prodejny, kterým jde zadat úkol (bez „Demo servisu", ten není prodejna). */
function afxTaskBranches(): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    foreach (getBranches(true) as $b) {
        if (strtoupper((string)($b['code'] ?? '')) === 'DEMO') { continue; }
        $id = (int)$b['id'];
        $cache['branch:' . $id] = [
            'key'  => 'branch:' . $id,
            'id'   => $id,
            'name' => function_exists('crmBranchShortLabel') ? crmBranchShortLabel($id) : (string)$b['name'],
        ];
    }
    return $cache;
}

/**
 * Lidé, kterým jde zadat úkol: aktivní zaměstnanci obou prodejen a účty
 * administrátorů, které nejsou spojené s žádným zaměstnancem.
 * Vrací pole klíč → [key, name, branch_id, role, color, initials].
 */
function afxTaskPeople(): array
{
    global $pdo;
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    $me = afxTaskMe();
    $demo = [];
    foreach (getBranches(false) as $b) {
        if (strtoupper((string)($b['code'] ?? '')) === 'DEMO') { $demo[(int)$b['id']] = true; }
    }
    try {
        $rows = $pdo->query("SELECT id, name, IFNULL(role,'engineer') AS role, branch_id
                             FROM technicians WHERE IFNULL(is_active,1) = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $key = 'tech:' . (int)$r['id'];
            $bid = (int)$r['branch_id'];
            // účty z „Demo servisu" (kontrola appky Applem) se nenabízejí
            if (isset($demo[$bid]) && $key !== $me) { continue; }
            $cache[$key] = ['key' => $key, 'name' => (string)$r['name'], 'branch_id' => $bid, 'role' => (string)$r['role']];
        }
    } catch (Throwable $e) { error_log('afxTaskPeople technicians: ' . $e->getMessage()); }
    try {
        if (function_exists('ensureUsersBranchColumn')) { ensureUsersBranchColumn(); }
        $rows = $pdo->query("SELECT id, full_name, username, branch_id FROM users
                             WHERE technician_id IS NULL OR technician_id = 0 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $key = 'user:' . (int)$r['id'];
            $name = trim((string)($r['full_name'] ?? '')) ?: (string)$r['username'];
            $cache[$key] = ['key' => $key, 'name' => $name, 'branch_id' => (int)($r['branch_id'] ?? 0), 'role' => 'admin'];
        }
    } catch (Throwable $e) { error_log('afxTaskPeople users: ' . $e->getMessage()); }
    foreach ($cache as $k => $p) {
        $cache[$k]['color'] = afxTaskColor($k);
        $cache[$k]['initials'] = afxTaskInitials($p['name']);
    }
    return $cache;
}

/** Jméno příjemce pro texty („Petr Novák" / „kdokoli z Karlína"). */
function afxTaskAssigneeLabel(string $key): string
{
    if (str_starts_with($key, 'branch:')) {
        $b = afxTaskBranches()[$key] ?? null;
        return 'kdokoli – ' . ($b ? $b['name'] : 'prodejna');
    }
    $p = afxTaskPeople()[$key] ?? null;
    if ($p) { return (string)$p['name']; }
    return afxTaskFormerName($key);
}

/** Jméno člověka, který už mezi aktivními není (odešel, účet vypnutý). */
function afxTaskFormerName(string $key): string
{
    global $pdo;
    static $cache = [];
    if (isset($cache[$key])) { return $cache[$key]; }
    [$type, $id] = array_pad(explode(':', $key, 2), 2, '0');
    $name = '';
    try {
        if ($type === 'tech') {
            $st = $pdo->prepare('SELECT name FROM technicians WHERE id = ?');
            $st->execute([(int)$id]);
            $name = (string)$st->fetchColumn();
        } elseif ($type === 'user') {
            $st = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
            $st->execute([(int)$id]);
            $name = (string)$st->fetchColumn();
        }
    } catch (Throwable $e) { $name = ''; }
    return $cache[$key] = ($name !== '' ? $name : 'bývalý kolega');
}

/** Je úkol „pro mě"? Úkol pro prodejnu, který jsem sám zadal, za můj se nepočítá. */
function afxTaskIsForMe(array $t): bool
{
    $me = afxTaskMe();
    if ($me === '') { return false; }
    if ((string)$t['assignee_key'] === $me) { return true; }
    $mine = 'branch:' . (int)getCurrentStaffBranchId();
    return (string)$t['assignee_key'] === $mine && (string)$t['creator_key'] !== $me;
}

/** Smí přihlášený úkol vůbec vidět? */
function afxTaskCanSee(array $t): bool
{
    $me = afxTaskMe();
    if ($me === '') { return false; }
    if ((string)$t['creator_key'] === $me || (string)$t['assignee_key'] === $me) { return true; }
    // úkol, který si někdo zadal sám sobě, je jeho poznámka — nevidí ho ani vedení
    if ((string)$t['creator_key'] === (string)$t['assignee_key']) { return false; }
    if (afxTaskIsLead()) { return true; }
    $myBranch = (int)getCurrentStaffBranchId();
    if ($myBranch > 0 && (string)$t['assignee_key'] === 'branch:' . $myBranch) { return true; }
    if (afxTaskHasTeamView() && $myBranch > 0) {
        $p = afxTaskPeople()[(string)$t['assignee_key']] ?? null;
        if ($p && (int)$p['branch_id'] === $myBranch) { return true; }
    }
    return false;
}

/** Co s úkolem přihlášený smí. */
function afxTaskCan(array $t): array
{
    $me   = afxTaskMe();
    $lead = afxTaskIsLead();
    $byMe = (string)$t['creator_key'] === $me;
    $forMe = afxTaskIsForMe($t) || ((string)$t['assignee_key'] === 'branch:' . (int)getCurrentStaffBranchId());
    $open = (string)$t['status'] === 'open';
    return [
        'done'   => $open && ($forMe || $byMe || $lead),
        'reopen' => !$open && ($forMe || $byMe || $lead || (string)$t['done_by_key'] === $me),
        'edit'   => $byMe || $lead,
        'delete' => $byMe || $lead,
    ];
}

function afxTaskGet(int $id): ?array
{
    global $pdo;
    afxTaskEnsureSchema();
    try {
        $st = $pdo->prepare('SELECT * FROM staff_tasks WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

/**
 * Úkoly, které přihlášený vidí: všechny otevřené a hotové za posledních
 * AFX_TASK_DONE_KEEP_DAYS dní. $markSeen = stránka Úkoly je právě otevřená,
 * takže se nové úkoly „pro mě" označí jako viděné (zadavatel to uvidí).
 */
function afxTaskList(bool $markSeen = false): array
{
    global $pdo;
    afxTaskEnsureSchema();
    $me = afxTaskMe();
    if ($me === '') { return []; }
    $myBranch = (int)getCurrentStaffBranchId();

    // cizí úkoly „pro sebe" jsou soukromé poznámky — nevidí je nikdo jiný, ani vedení
    $where = ['t.deleted_at IS NULL', "(t.status = 'open' OR t.done_at >= ?)", '(t.creator_key <> t.assignee_key OR t.creator_key = ?)'];
    $par = [date('Y-m-d H:i:s', time() - AFX_TASK_DONE_KEEP_DAYS * 86400), $me];
    if (!afxTaskIsLead()) {
        $own = ['t.creator_key = ?', 't.assignee_key = ?'];
        $ownPar = [$me, $me];
        if ($myBranch > 0) {
            $own[] = 't.assignee_key = ?';
            $ownPar[] = 'branch:' . $myBranch;
            if (afxTaskHasTeamView()) {
                $keys = [];
                foreach (afxTaskPeople() as $p) { if ((int)$p['branch_id'] === $myBranch) { $keys[] = $p['key']; } }
                if ($keys) {
                    $own[] = 't.assignee_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
                    $ownPar = array_merge($ownPar, $keys);
                }
            }
        }
        $where[] = '(' . implode(' OR ', $own) . ')';
        $par = array_merge($par, $ownPar);
    }

    try {
        $st = $pdo->prepare('SELECT t.* FROM staff_tasks t WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC LIMIT 600');
        $st->execute($par);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('afxTaskList: ' . $e->getMessage());
        return [];
    }

    // Nové úkoly pro mě → viděno. Jen když se na stránku opravdu někdo dívá.
    $fresh = [];
    if ($markSeen) {
        foreach ($rows as $i => $r) {
            if ($r['status'] === 'open' && $r['seen_at'] === null && $r['creator_key'] !== $me && afxTaskIsForMe($r)) {
                $fresh[] = (int)$r['id'];
                $rows[$i]['seen_at'] = date('Y-m-d H:i:s');
                $rows[$i]['seen_by_name'] = afxTaskMeName();
            }
        }
        if ($fresh) {
            try {
                $pdo->prepare('UPDATE staff_tasks SET seen_at = ?, seen_by_name = ?, updated_at = updated_at
                               WHERE seen_at IS NULL AND id IN (' . implode(',', $fresh) . ')')
                    ->execute([date('Y-m-d H:i:s'), afxTaskMeName()]);
            } catch (Throwable $e) { error_log('afxTaskList seen: ' . $e->getMessage()); }
        }
    }

    $events = [];
    if ($rows) {
        try {
            $ids = implode(',', array_map(static fn($r) => (int)$r['id'], $rows));
            foreach ($pdo->query("SELECT id, task_id, kind, author_key, author_name, body, created_at
                                  FROM staff_task_events WHERE task_id IN ($ids) ORDER BY id ASC") as $e) {
                $events[(int)$e['task_id']][] = [
                    'id' => (int)$e['id'], 'kind' => (string)$e['kind'], 'author_key' => (string)$e['author_key'],
                    'author' => (string)$e['author_name'], 'body' => (string)$e['body'], 'at' => (string)$e['created_at'],
                ];
            }
        } catch (Throwable $e) { /* bez průběhu */ }
    }

    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $out[] = [
            'id'            => $id,
            'title'         => (string)$r['title'],
            'detail'        => (string)($r['detail'] ?? ''),
            'creator_key'   => (string)$r['creator_key'],
            'creator_name'  => (string)$r['creator_name'],
            'assignee_key'  => (string)$r['assignee_key'],
            'assignee_name' => afxTaskAssigneeLabel((string)$r['assignee_key']),
            'due_date'      => $r['due_date'] !== null ? (string)$r['due_date'] : '',
            'due_time'      => $r['due_time'] !== null ? substr((string)$r['due_time'], 0, 5) : '',
            'urgent'        => (int)$r['urgent'] === 1,
            'status'        => (string)$r['status'],
            'seen_at'       => (string)($r['seen_at'] ?? ''),
            'seen_by'       => (string)$r['seen_by_name'],
            'done_at'       => (string)($r['done_at'] ?? ''),
            'done_by'       => (string)$r['done_by_name'],
            'created_at'    => (string)$r['created_at'],
            'for_me'        => afxTaskIsForMe($r),
            'by_me'         => (string)$r['creator_key'] === $me,
            'fresh'         => in_array($id, $fresh, true),
            'can'           => afxTaskCan($r),
            'events'        => $events[$id] ?? [],
        ];
    }
    return $out;
}

/** Všechno, co stránka Úkoly potřebuje k vykreslení (první načtení i každá odpověď API). */
function afxTaskSnapshot(bool $markSeen): array
{
    return [
        'me'        => afxTaskMe(),
        'me_name'   => afxTaskMeName(),
        'my_branch' => 'branch:' . (int)getCurrentStaffBranchId(),
        'team_view' => afxTaskHasTeamView(),
        'lead'      => afxTaskIsLead(),
        'now'       => date('Y-m-d H:i:s'),
        'people'    => array_values(afxTaskPeople()),
        'branches'  => array_values(afxTaskBranches()),
        'tasks'     => afxTaskList($markSeen),
        'badge'     => afxTaskBadge(),
    ];
}

/** Čísla pro odznak v menu: kolik otevřených úkolů mám, kolik jsem ještě neviděl. */
function afxTaskBadge(): array
{
    global $pdo;
    afxTaskEnsureSchema();
    $me = afxTaskMe();
    $zero = ['count' => 0, 'unseen' => 0, 'overdue' => 0, 'last_id' => 0];
    if ($me === '') { return $zero; }
    $branchKey = 'branch:' . (int)getCurrentStaffBranchId();
    try {
        $st = $pdo->prepare("SELECT COUNT(*) AS c,
                COALESCE(SUM(seen_at IS NULL AND creator_key <> ?), 0) AS unseen,
                COALESCE(SUM(due_date IS NOT NULL AND (due_date < ? OR (due_date = ? AND due_time IS NOT NULL AND due_time < ?))), 0) AS overdue,
                COALESCE(MAX(CASE WHEN creator_key <> ? THEN id ELSE 0 END), 0) AS last_id
            FROM staff_tasks
            WHERE deleted_at IS NULL AND status = 'open'
              AND (assignee_key = ? OR (assignee_key = ? AND creator_key <> ?))");
        $st->execute([$me, date('Y-m-d'), date('Y-m-d'), date('H:i:s'), $me, $me, $branchKey, $me]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['count' => (int)($r['c'] ?? 0), 'unseen' => (int)($r['unseen'] ?? 0),
                'overdue' => (int)($r['overdue'] ?? 0), 'last_id' => (int)($r['last_id'] ?? 0)];
    } catch (Throwable $e) {
        return $zero;
    }
}

/** Ověří a srovná termín. Vrací [datum|null, čas|null, chyba]. */
function afxTaskParseDue(string $date, string $time): array
{
    $date = trim($date); $time = trim($time);
    if ($date === '') { return [null, null, '']; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) { return [null, null, 'Termín nemá platné datum.']; }
    if ($d > new DateTimeImmutable('+2 years')) { return [null, null, 'Termín je moc daleko.']; }
    if ($time === '') { return [$date, null, '']; }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) { return [null, null, 'Zadej čas ve tvaru 14:00.']; }
    return [$date, $time . ':00', ''];
}

/** Platný příjemce? */
function afxTaskAssigneeValid(string $key): bool
{
    return isset(afxTaskPeople()[$key]) || isset(afxTaskBranches()[$key]);
}

/** Klíče lidí, kterým má přijít upozornění na úkol s daným příjemcem. */
function afxTaskRecipients(string $assigneeKey): array
{
    if (str_starts_with($assigneeKey, 'branch:')) {
        $bid = (int)substr($assigneeKey, 7);
        $out = [];
        foreach (afxTaskPeople() as $p) { if ((int)$p['branch_id'] === $bid) { $out[] = $p['key']; } }
        return $out;
    }
    return [$assigneeKey];
}

/** Upozornění do appky. Nikdy neshodí uložení úkolu. */
function afxTaskPush(array $keys, string $title, string $body, int $taskId): void
{
    global $pdo;
    $me = afxTaskMe();
    try {
        require_once __DIR__ . '/../includes/push.php';
        if (!function_exists('pushToStaff') || !function_exists('apnsConfigured') || !apnsConfigured()) { return; }
        foreach (array_unique($keys) as $k) {
            if ($k === '' || $k === $me) { continue; }
            pushToStaff($pdo, $k, $title, mb_substr($body, 0, 140, 'UTF-8'),
                ['thread' => 'ukoly', 'collapse' => 'ukol-' . $taskId, 'data' => ['url' => 'ukoly.php?u=' . $taskId]]);
        }
    } catch (Throwable $e) { error_log('afxTaskPush: ' . $e->getMessage()); }
}

function afxTaskAddEvent(int $taskId, string $kind, string $body = ''): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO staff_task_events (task_id, kind, author_key, author_name, body, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$taskId, $kind, afxTaskMe(), afxTaskMeName(), mb_substr(trim($body), 0, 1000, 'UTF-8'), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { error_log('afxTaskAddEvent: ' . $e->getMessage()); }
}

/** Jak se termín řekne ve větě („do zítřka", „do 24. 11. 14:00"). */
function afxTaskDueWords(?string $date, ?string $time): string
{
    if (!$date) { return 'bez termínu'; }
    $t = $time ? ' ' . substr($time, 0, 5) : '';
    if ($date === date('Y-m-d')) { return 'dnes' . $t; }
    if ($date === date('Y-m-d', strtotime('+1 day'))) { return 'zítra' . $t; }
    return date('j. n.', strtotime($date)) . $t;
}

/**
 * Založí úkol každému z příjemců zvlášť. Vrací [ok, zpráva, id nových úkolů].
 */
function afxTaskCreate(string $title, string $detail, array $assignees, string $dueDate, string $dueTime, bool $urgent): array
{
    global $pdo;
    afxTaskEnsureSchema();
    $me = afxTaskMe();
    if ($me === '') { return [false, 'Nejsi přihlášený.', []]; }

    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    if ($title === '') { return [false, 'Napiš, co je potřeba udělat.', []]; }
    if (mb_strlen($title, 'UTF-8') > 200) { return [false, 'Úkol je moc dlouhý — zkrať ho a zbytek napiš do podrobností.', []]; }
    $detail = trim($detail);
    if (mb_strlen($detail, 'UTF-8') > 4000) { return [false, 'Podrobnosti jsou moc dlouhé.', []]; }

    $assignees = array_values(array_unique(array_filter(array_map('strval', $assignees), static fn($k) => $k !== '')));
    if (!$assignees) { return [false, 'Vyber, pro koho úkol je.', []]; }
    if (count($assignees) > 30) { return [false, 'Moc příjemců najednou.', []]; }
    foreach ($assignees as $k) {
        if (!afxTaskAssigneeValid($k)) { return [false, 'Některý z vybraných lidí už není k dispozici — načti stránku znovu.', []]; }
    }

    [$d, $t, $err] = afxTaskParseDue($dueDate, $dueTime);
    if ($err !== '') { return [false, $err, []]; }

    $ids = [];
    try {
        $st = $pdo->prepare('INSERT INTO staff_tasks (title, detail, creator_key, creator_name, assignee_key, due_date, due_time, urgent, seen_at, seen_by_name, created_at)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($assignees as $k) {
            $self = ($k === $me);   // úkol sám sobě je rovnou „viděný"
            $st->execute([$title, $detail !== '' ? $detail : null, $me, afxTaskMeName(), $k, $d, $t, $urgent ? 1 : 0,
                          $self ? date('Y-m-d H:i:s') : null, $self ? afxTaskMeName() : '', date('Y-m-d H:i:s')]);
            $ids[] = (int)$pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        error_log('afxTaskCreate: ' . $e->getMessage());
        return [false, 'Uložení selhalo.', $ids];
    }

    foreach ($assignees as $i => $k) {
        afxTaskPush(afxTaskRecipients($k),
            ($urgent ? 'Spěchá: ' : 'Nový úkol: ') . $title,
            'Od ' . afxTaskMeName() . ' · ' . afxTaskDueWords($d, $t),
            $ids[$i] ?? 0);
    }
    return [true, count($ids) > 1 ? 'Zadáno ' . count($ids) . ' lidem.' : 'Zadáno.', $ids];
}

/** Úprava úkolu (zadavatel, admin, Boss). Vrací [ok, zpráva]. */
function afxTaskUpdate(int $id, string $title, string $detail, string $assignee, string $dueDate, string $dueTime, bool $urgent): array
{
    global $pdo;
    $task = afxTaskGet($id);
    if (!$task || !afxTaskCanSee($task)) { return [false, 'Úkol nenalezen.']; }
    if (!afxTaskCan($task)['edit']) { return [false, 'Upravit úkol smí jen ten, kdo ho zadal, nebo vedení.']; }

    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    if ($title === '') { return [false, 'Napiš, co je potřeba udělat.']; }
    if (mb_strlen($title, 'UTF-8') > 200) { return [false, 'Úkol je moc dlouhý — zkrať ho a zbytek napiš do podrobností.']; }
    $detail = trim($detail);
    if (mb_strlen($detail, 'UTF-8') > 4000) { return [false, 'Podrobnosti jsou moc dlouhé.']; }
    $assignee = trim($assignee) !== '' ? trim($assignee) : (string)$task['assignee_key'];
    // původní příjemce smí zůstat, i kdyby mezitím odešel
    if ($assignee !== (string)$task['assignee_key'] && !afxTaskAssigneeValid($assignee)) {
        return [false, 'Vybraný člověk už není k dispozici — načti stránku znovu.'];
    }
    [$d, $t, $err] = afxTaskParseDue($dueDate, $dueTime);
    if ($err !== '') { return [false, $err]; }

    $changes = [];
    $moved = $assignee !== (string)$task['assignee_key'];
    if ($moved) { $changes[] = 'předáno: ' . afxTaskAssigneeLabel($assignee); }
    $oldDue = (string)($task['due_date'] ?? '') . ' ' . (string)($task['due_time'] ?? '');
    if (trim($oldDue) !== trim((string)$d . ' ' . (string)$t)) { $changes[] = 'termín: ' . afxTaskDueWords($d, $t); }
    if ($title !== (string)$task['title'] || $detail !== (string)($task['detail'] ?? '')) { $changes[] = 'upravené zadání'; }
    if ((int)$task['urgent'] !== ($urgent ? 1 : 0)) { $changes[] = $urgent ? 'označeno jako spěchá' : 'už nespěchá'; }
    if (!$changes) { return [true, 'Beze změny.']; }

    try {
        $self = ($assignee === afxTaskMe());
        $sql = 'UPDATE staff_tasks SET title = ?, detail = ?, assignee_key = ?, due_date = ?, due_time = ?, urgent = ?';
        $par = [$title, $detail !== '' ? $detail : null, $assignee, $d, $t, $urgent ? 1 : 0];
        if ($moved) {   // nový příjemce úkol ještě neviděl
            $sql .= ', seen_at = ?, seen_by_name = ?';
            $par[] = $self ? date('Y-m-d H:i:s') : null;
            $par[] = $self ? afxTaskMeName() : '';
        }
        $par[] = $id;
        $pdo->prepare($sql . ' WHERE id = ?')->execute($par);
    } catch (Throwable $e) {
        error_log('afxTaskUpdate: ' . $e->getMessage());
        return [false, 'Uložení selhalo.'];
    }
    afxTaskAddEvent($id, 'edit', implode(', ', $changes));
    if ((string)$task['status'] === 'open') {
        afxTaskPush(afxTaskRecipients($assignee), ($moved ? 'Nový úkol: ' : 'Změna úkolu: ') . $title,
            afxTaskMeName() . ' · ' . implode(', ', $changes), $id);
    }
    return [true, 'Uloženo.'];
}

/** Hotovo / vrátit mezi nedokončené. Vrací [ok, zpráva]. */
function afxTaskSetDone(int $id, bool $done, string $note = ''): array
{
    global $pdo;
    $task = afxTaskGet($id);
    if (!$task || !afxTaskCanSee($task)) { return [false, 'Úkol nenalezen.']; }
    $can = afxTaskCan($task);
    $isOpen = (string)$task['status'] === 'open';
    if ($done === !$isOpen) { return [true, $done ? 'Už je hotovo.' : 'Už je mezi nedokončenými.']; }
    if ($done && !$can['done']) { return [false, 'Tenhle úkol není tvůj.']; }
    if (!$done && !$can['reopen']) { return [false, 'Vrátit úkol smí ten, kdo ho zadal nebo plnil.']; }

    $me = afxTaskMe();
    try {
        if ($done) {
            $now = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE staff_tasks SET status = 'done', done_at = ?, done_by_key = ?, done_by_name = ?,
                                  seen_at = COALESCE(seen_at, ?), seen_by_name = IF(seen_by_name = '', ?, seen_by_name)
                           WHERE id = ? AND status = 'open'")
                ->execute([$now, $me, afxTaskMeName(), $now, afxTaskMeName(), $id]);
        } else {
            // vrátil-li to někdo jiný než ten, kdo úkol plní, je to pro příjemce zase novinka
            $byAssignee = afxTaskIsForMe($task);
            $pdo->prepare("UPDATE staff_tasks SET status = 'open', done_at = NULL, done_by_key = '', done_by_name = ''"
                        . ($byAssignee ? '' : ", seen_at = NULL, seen_by_name = ''")
                        . " WHERE id = ? AND status = 'done'")
                ->execute([$id]);
        }
    } catch (Throwable $e) {
        error_log('afxTaskSetDone: ' . $e->getMessage());
        return [false, 'Uložení selhalo.'];
    }
    afxTaskAddEvent($id, $done ? 'done' : 'reopen', $note);

    $title = (string)$task['title'];
    if ($done) {
        afxTaskPush([(string)$task['creator_key']], 'Hotovo: ' . $title,
            afxTaskMeName() . (trim($note) !== '' ? ' — ' . trim($note) : ' to má hotové.'), $id);
    } else {
        afxTaskPush(array_merge(afxTaskRecipients((string)$task['assignee_key']), [(string)$task['creator_key']]),
            'Vráceno k dořešení: ' . $title,
            afxTaskMeName() . (trim($note) !== '' ? ' — ' . trim($note) : ''), $id);
    }
    return [true, $done ? 'Hotovo.' : 'Vráceno mezi nedokončené.'];
}

/** Poznámka k úkolu. Vrací [ok, zpráva]. */
function afxTaskComment(int $id, string $body): array
{
    $task = afxTaskGet($id);
    if (!$task || !afxTaskCanSee($task)) { return [false, 'Úkol nenalezen.']; }
    $body = trim($body);
    if ($body === '') { return [false, 'Napiš poznámku.']; }
    if (mb_strlen($body, 'UTF-8') > 1000) { return [false, 'Poznámka je moc dlouhá.']; }
    afxTaskAddEvent($id, 'comment', $body);
    afxTaskPush(array_merge(afxTaskRecipients((string)$task['assignee_key']), [(string)$task['creator_key']]),
        afxTaskMeName() . ' k úkolu: ' . (string)$task['title'], $body, $id);
    return [true, 'Přidáno.'];
}

/** Smazání (zadavatel, admin, Boss). Řádek zůstává v databázi jako stopa. */
function afxTaskDelete(int $id): array
{
    global $pdo;
    $task = afxTaskGet($id);
    if (!$task || !afxTaskCanSee($task)) { return [false, 'Úkol nenalezen.']; }
    if (!afxTaskCan($task)['delete']) { return [false, 'Smazat úkol smí jen ten, kdo ho zadal, nebo vedení.']; }
    try {
        $pdo->prepare('UPDATE staff_tasks SET deleted_at = ?, deleted_by_name = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), afxTaskMeName(), $id]);
    } catch (Throwable $e) { return [false, 'Smazání selhalo.']; }
    return [true, 'Úkol smazán.'];
}
