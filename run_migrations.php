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

/** Rozdělí SQL soubor na příkazy. Naivní explode(';') nestačí — středník se běžně
 *  vyskytuje v komentářích (české popisy) i v řetězcích a rozsekal by příkaz vejpůl.
 *  Proto se prochází znak po znaku a hlídá se, jestli zrovna nejsme v komentáři
 *  nebo v uvozovkách. (29.7.2026) */
function migration_split(string $sql): array {
    $out = []; $buf = ''; $n = strlen($sql);
    $inS = false; $inD = false; $inB = false;      // ' " `
    $inLine = false; $inBlock = false;             // -- #   /* */
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i]; $next = $i + 1 < $n ? $sql[$i + 1] : '';
        if ($inLine)  { if ($c === "\n") { $inLine = false; $buf .= $c; } continue; }
        if ($inBlock) { if ($c === '*' && $next === '/') { $inBlock = false; $i++; } continue; }
        if (!$inS && !$inD && !$inB) {
            if ($c === '-' && $next === '-') { $inLine = true; continue; }
            if ($c === '#')                  { $inLine = true; continue; }
            if ($c === '/' && $next === '*') { $inBlock = true; $i++; continue; }
            if ($c === ';') { $t = trim($buf); if ($t !== '') { $out[] = $t; } $buf = ''; continue; }
        }
        if ($c === "'" && !$inD && !$inB) { $inS = !$inS; }
        elseif ($c === '"' && !$inS && !$inB) { $inD = !$inD; }
        elseif ($c === '`' && !$inS && !$inD) { $inB = !$inB; }
        $buf .= $c;
    }
    $t = trim($buf); if ($t !== '') { $out[] = $t; }
    return $out;
}

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
        foreach (migration_split($sql) as $stmt) {
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
