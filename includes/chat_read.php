<?php
/**
 * CHAT — kdo jsem a kam mám přečteno (v3.64.0).
 *
 * Vlastní soubor, ne functions.php: tohle řeší jednu věc a je potřeba
 * i v pollerů upozornění, kde se nechce tahat nic dalšího.
 */

/**
 * VŠECHNY identity přihlášeného člověka v chatu (v3.64.0).
 *
 * CRM má dvojí přihlášení (users / technicians) a `users.technician_id` je
 * spojuje. Historicky ale vznikly zprávy pod obojím — Jan Augustin má část
 * zpráv jako uživatel #1 a část jako technik #18. Filtr „moje zprávy"
 * porovnával jen tu jednu aktuální, takže si člověk počítal VLASTNÍ starší
 * zprávy jako cizí nepřečtené a bublina u chatu nešla zhasnout.
 * Vrací pole dvojic [typ, id] — vždy aspoň tu aktuální.
 */
function crmChatIdentities(): array {
    global $pdo;
    $actor = crmChatActor();
    if ($actor === null) { return []; }
    $out = [[$actor[0], $actor[1]]];
    try {
        if ($actor[0] === 'technician') {
            // ke které uživatelské roli je technik připojený
            $st = $pdo->prepare("SELECT id FROM users WHERE technician_id = ?");
            $st->execute([$actor[1]]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) { $out[] = ['user', (int)$uid]; }
        } else {
            $st = $pdo->prepare("SELECT technician_id FROM users WHERE id = ? AND technician_id IS NOT NULL");
            $st->execute([$actor[1]]);
            $tid = (int)($st->fetchColumn() ?: 0);
            if ($tid > 0) { $out[] = ['technician', $tid]; }
        }
    } catch (Throwable $e) { error_log('crmChatIdentities: ' . $e->getMessage()); }
    return $out;
}

/** SQL podmínka „tohle NEJSOU moje zprávy" + parametry. */
function crmChatNotMineSql(string $alias = ''): array {
    $a = $alias !== '' ? $alias . '.' : '';
    $ids = crmChatIdentities();
    if (!$ids) { return ['1=1', []]; }
    $parts = []; $par = [];
    foreach ($ids as $i) {
        $parts[] = "({$a}actor_type = ? AND {$a}actor_id = ?)";
        $par[] = $i[0]; $par[] = (int)$i[1];
    }
    return ['NOT (' . implode(' OR ', $parts) . ')', $par];
}

/** Kam až má kdo přečteno — SERVEROVĚ, ať bublina zhasne i na jiném zařízení. */
function afxEnsureChatReadsTable(): void {
    global $pdo;
    static $done = false;
    if ($done || !isset($pdo)) return;
    if ($pdo->inTransaction()) { return; }   // DDL v transakci = implicitní commit
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_chat_reads (
            actor_type VARCHAR(20) NOT NULL,
            actor_id INT NOT NULL,
            last_seen_id INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (actor_type, actor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    } catch (Throwable $e) { error_log('afxEnsureChatReadsTable: ' . $e->getMessage()); }
}

/** Dokud kam má přihlášený přečteno (napříč oběma jeho identitami). */
function crmChatLastSeenId(): int {
    global $pdo;
    $ids = crmChatIdentities();
    if (!$ids) { return 0; }
    afxEnsureChatReadsTable();
    $max = 0;
    try {
        $parts = []; $par = [];
        foreach ($ids as $i) { $parts[] = '(actor_type = ? AND actor_id = ?)'; $par[] = $i[0]; $par[] = (int)$i[1]; }
        $st = $pdo->prepare("SELECT COALESCE(MAX(last_seen_id), 0) FROM staff_chat_reads WHERE " . implode(' OR ', $parts));
        $st->execute($par);
        $max = (int)$st->fetchColumn();
    } catch (Throwable $e) { error_log('crmChatLastSeenId: ' . $e->getMessage()); }
    return $max;
}

/** Zapsat „přečteno až sem". Nikdy nejde zpátky (jiná záložka může být pozadu). */
function crmChatMarkRead(int $upToId): void {
    global $pdo;
    if ($upToId <= 0) { return; }
    $actor = crmChatActor();
    if ($actor === null) { return; }
    afxEnsureChatReadsTable();
    try {
        $st = $pdo->prepare("INSERT INTO staff_chat_reads (actor_type, actor_id, last_seen_id) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE last_seen_id = GREATEST(last_seen_id, VALUES(last_seen_id))");
        $st->execute([$actor[0], (int)$actor[1], $upToId]);
    } catch (Throwable $e) { error_log('crmChatMarkRead: ' . $e->getMessage()); }
}
