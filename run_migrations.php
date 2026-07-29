<?php
/**
 * CRM Migration Runner
 * -------------------
 * Scans ./migrations/ for *.sql files (sorted alphabetically) and
 * executes any that have not yet been recorded in the `migrations` table.
 *
 * Usage (CLI):
 *   php run_migrations.php
 *
 * Usage (web – admin only):
 *   Open https://your-domain/run_migrations.php while logged in as admin.
 */

require_once __DIR__ . '/includes/config.php';

// ── Auth guard (web) ──────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/includes/functions.php';
    if (empty($_SESSION['user_id']) || !hasPermission('admin_access')) {
        http_response_code(403);
        die('<h1>403 Forbidden</h1>');
    }
    echo '<pre>';
}

/** Chyby, které znamenají „už hotovo" — objekt v DB existuje, migraci lze označit za provedenou.
 *  1050 tabulka existuje · 1060 duplicitní sloupec · 1061 duplicitní index · 1091 nelze zahodit
 *  (neexistuje) · 1022/1826 duplicitní klíč · 1913 duplicitní constraint */
const MIGRATION_BENIGN_CODES = [1050, 1060, 1061, 1091, 1022, 1826, 1913];

// ── Bootstrap: ensure migrations table exists ─────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `migration_name` VARCHAR(255)   NOT NULL UNIQUE,
        `executed_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── Load already-executed migrations ─────────────────────────────────────────
$executed = $pdo->query("SELECT migration_name FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

// ── Scan migration files ──────────────────────────────────────────────────────
$files = glob(__DIR__ . '/migrations/*.sql');
if (!$files) {
    echo "No migration files found.\n";
    exit(0);
}
sort($files);

$ok = 0; $skip = 0; $fail = 0;

foreach ($files as $file) {
    $name = basename($file);

    if (in_array($name, $executed, true)) {
        echo "SKIP : $name\n";
        $skip++;
        continue;
    }

    $sql = file_get_contents($file);
    $already = 0;
    try {
        // Split on semicolons so multi-statement files work
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            // samotný komentář není příkaz
            if ($stmt === '' || preg_match('/^(--|#)/', $stmt)) { continue; }
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                // Sloupec/tabulka/index už existují (typicky je založila ensure* funkce
                // za běhu aplikace dřív, než migrace doběhla). To NENÍ chyba — cíl je
                // splněn, jen jinou cestou. Dřív to shodilo celý běh a všechny další
                // migrace kvůli `break` nikdy neproběhly. (29.7.2026)
                $code = (int)($e->errorInfo[1] ?? 0);
                if (in_array($code, MIGRATION_BENIGN_CODES, true)) { $already++; continue; }
                throw $e;
            }
        }
        $pdo->prepare("INSERT INTO migrations (migration_name) VALUES (?)")->execute([$name]);
        echo $already > 0 ? "OK*  : $name (už bylo v DB: $already příkazů)\n" : "OK   : $name\n";
        $ok++;
    } catch (Throwable $e) {
        echo "ERROR: $name — " . $e->getMessage() . "\n";
        echo "       (běh zastaven, další migrace neproběhly — oprav soubor a spusť znovu)\n";
        $fail++;
        break; // skutečná chyba → stop, ať se DB nerozjede do nekonzistence
    }
}

echo "\nDone. OK=$ok  SKIP=$skip  FAIL=$fail\n";

if (php_sapi_name() !== 'cli') {
    echo '</pre>';
}
