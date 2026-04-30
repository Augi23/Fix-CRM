<?php
ob_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !hasPermission('admin_access')) {
    die(json_encode(['success' => false, 'message' => __('access_denied_msg')]));
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => __('csrf_token_invalid')]));
}

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    echo json_encode(['success' => false, 'message' => 'Invalid project root']);
    exit;
}

$backupDir = $projectRoot . '/backup_db';
$persistToDisk = true;

if (!is_dir($backupDir)) {
    if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        $persistToDisk = false;
    }
}

if (!is_writable($backupDir)) {
    $persistToDisk = false;
}

// Generate filename
$filename = 'backup_' . DB_NAME . '_' . date('Y-m-d_H-i-s') . '.sql';
$filePath = rtrim($backupDir, '/') . '/' . $filename;

try {
    $tables = [];
    $result = $pdo->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $sqlScript = "-- CRM Database Backup\n";
    $sqlScript .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
    $sqlScript .= "-- Database: " . DB_NAME . "\n\n";
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $table_safe = str_replace('`', '``', $table);
        // Table structure
        $stmt = $pdo->query("SHOW CREATE TABLE `$table_safe`");
        $row = $stmt->fetch(PDO::FETCH_NUM);
        $sqlScript .= "\n\n" . $row[1] . ";\n\n";

        // Table data
        $stmt = $pdo->query("SELECT * FROM `$table_safe`");
        $columnCount = $stmt->columnCount();

        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $sqlScript .= "INSERT INTO `$table_safe` VALUES(";
            for ($j = 0; $j < $columnCount; $j++) {
                if (isset($row[$j])) {
                    $val = str_replace("\n", "\\n", addslashes((string)$row[$j]));
                    $sqlScript .= '"' . $val . '"';
                } else {
                    $sqlScript .= 'NULL';
                }
                if ($j < ($columnCount - 1)) {
                    $sqlScript .= ',';
                }
            }
            $sqlScript .= ");\n";
        }
        $sqlScript .= "\n";
    }

    $sqlScript .= "\nSET FOREIGN_KEY_CHECKS=1;";

    if ($persistToDisk && file_put_contents($filePath, $sqlScript)) {
        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'path' => 'backup_db/' . $filename,
            'inline' => false,
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'filename' => $filename,
            'inline' => true,
            'content_base64' => base64_encode($sqlScript),
            'message' => 'Backup directory is not writable, using direct download fallback.',
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
