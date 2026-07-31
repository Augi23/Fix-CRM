<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$sync_token = getenv('SYNC_TOKEN') ?: 'DEFAULT_SECURE_TOKEN_REPLACE_ME';
if (php_sapi_name() !== 'cli' && (!isset($_GET['token']) || $_GET['token'] !== $sync_token)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);


if (!isset($pdo)) {
    die("PDO not initialized. Check config.php\n");
}

echo "Working directory: " . getcwd() . "\n";

function sync_orders($data) {
    global $pdo;
    $updated = 0;
    foreach ($data as $item) {
        $id = intval($item['id']);
        if (!$id) continue;
        
        $zap = $item['zap']; // d.m.Y or null
        $amt = floatval($item['amt']);
        
        $shipping_date = null;
        if ($zap && $zap !== '-') {
            $d = DateTime::createFromFormat('j.m.Y', $zap);
            if ($d) $shipping_date = $d->format('Y-m-d H:i:s');
        }

        // Check local
        $stmt = $pdo->prepare("SELECT final_cost, status, shipping_date FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        $local = $stmt->fetch();
        
        if ($local) {
            $needs_update = false;
            $upd_fields = [];
            $params = [];
            
            // 1. Amount
            if (abs(floatval($local['final_cost']) - $amt) > 0.01) {
                $upd_fields[] = "final_cost = ?";
                $params[] = $amt;
                $needs_update = true;
            }
            
            // 2. Status & Shipping Date
            if ($shipping_date) {
                if (!isOrderStatusIn((string)$local['status'], 'collected')) {
                    $upd_fields[] = "status = 'Vydáno'";
                    $needs_update = true;
                }
                // Only update shipping_date if it differs significantly
                if (!$local['shipping_date'] || abs(strtotime($local['shipping_date']) - strtotime($shipping_date)) > 86400) {
                    $upd_fields[] = "shipping_date = ?";
                    $params[] = $shipping_date;
                    $needs_update = true;
                }
            }

            if ($needs_update) {
                $params[] = $id;
                $sql = "UPDATE orders SET " . implode(', ', $upd_fields) . " WHERE id = ?";
                $pdo->prepare($sql)->execute($params);
                
                // Také srovnat navázanou fakturu — ale JEN dokud na ní nevisí peníze.
                // Dřív se tady natvrdo nastavovalo status='issued', takže jediné spuštění
                // synchronizace shodilo i zaplacené faktury zpět mezi nezaplacené a smazalo
                // datum platby. Faktury s evidovanou platbou (nebo označené jako zaplacené)
                // se proto nechávají být a jen se zaloguje, že se rozcházejí částkou.
                // …a JEN mimo uzamčené měsíce (uzávěrka platí i pro legacy synchronizaci)
                $__lockedSql = '';
                if (function_exists('afxAccountingLockedPeriods')) {
                    $__locked = array_keys(afxAccountingLockedPeriods());
                    if ($__locked) {
                        $__lockedSql = " AND DATE_FORMAT(date_issue, '%Y-%m') NOT IN ("
                            . implode(',', array_fill(0, count($__locked), '?')) . ")";
                    }
                }
                $stmt_inv = $pdo->prepare("UPDATE invoices SET total_amount = ?
                    WHERE order_id = ? AND status IN ('draft','issued','overdue')
                      AND COALESCE(paid_amount, 0) = 0
                      AND NOT EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = invoices.id)"
                    . $__lockedSql);
                $stmt_inv->execute(array_merge([$amt, $id], $__lockedSql !== '' ? $__locked : []));
                if ($stmt_inv->rowCount() === 0) {
                    $chk = $pdo->prepare("SELECT invoice_number, total_amount, status FROM invoices WHERE order_id = ?");
                    $chk->execute([$id]);
                    foreach ($chk->fetchAll(PDO::FETCH_ASSOC) as $__f) {
                        if (abs((float)$__f['total_amount'] - (float)$amt) > 0.01) {
                            echo "POZOR: faktura {$__f['invoice_number']} ({$__f['status']}) má jinou částku než zakázka "
                               . "({$__f['total_amount']} vs $amt) — nechávám ji být, oprav ji ručně v Účetnictví.\n";
                        }
                    }
                }
                
                $updated++;
                echo "Updated Order #$id\n";
            }
        }
    }
    return $updated;
}

// Data will be passed via temporary file or similar
if (file_exists('temp_sync_data.json')) {
    $data = json_decode(file_get_contents('temp_sync_data.json'), true);
    if ($data) {
        $count = sync_orders($data);
        echo "Sync finished. Total updated: $count\n";
    }
}
