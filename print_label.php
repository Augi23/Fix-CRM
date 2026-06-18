<?php
/**
 * Label print for Brother QL-810W — 62×38 mm.
 * Big bold order number, problem description, optional PIN. Auto-fits the code to width
 * and triggers the browser print dialog (pick the Brother QL-810W there).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) {
    die('Unauthorized');
}

$id = $_GET['id'] ?? $_GET['order_id'] ?? null;
if (!$id) {
    die('Order ID is not specified');
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    die(__('print_not_found'));
}
if (!canAccessOrderBranch($order)) {
    die(__('access_denied_msg'));
}

$code    = orderDisplayCode($order);
$problem = trim((string)($order['problem_description'] ?? ''));
$pin     = trim((string)($order['pin_code'] ?? ''));
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Štítek <?php echo htmlspecialchars($code); ?></title>
<style>
    @page { size: 62mm 38mm; margin: 0; }
    html, body { margin: 0; padding: 0; }
    body { width: 62mm; height: 38mm; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
    .label {
        box-sizing: border-box;
        width: 62mm; height: 38mm;
        padding: 2mm 2.5mm;
        display: flex; flex-direction: column;
        overflow: hidden;
    }
    .code {
        font-weight: 900;
        line-height: 1;
        letter-spacing: -0.3px;
        white-space: nowrap;
        font-size: 11mm; /* JS shrinks to fit width */
    }
    .problem {
        font-size: 3mm;
        line-height: 1.15;
        margin-top: 1.4mm;
        flex: 1 1 auto;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
    }
    .pin {
        font-size: 3.6mm;
        margin-top: 1mm;
        font-weight: bold;
        border-top: 0.3mm solid #000;
        padding-top: 0.8mm;
        white-space: nowrap;
    }
    .pin b { font-weight: 900; }

    /* On-screen helper (hidden when printing) */
    @media screen {
        body { background: #f0f0f0; }
        .label { margin: 16px auto; background: #fff; box-shadow: 0 0 0 1px #bbb, 0 4px 14px rgba(0,0,0,.15); }
        .toolbar { text-align: center; }
        .toolbar button { padding: 8px 18px; font-size: 14px; cursor: pointer; }
    }
    @media print { .toolbar { display: none !important; } }
</style>
</head>
<body>
<div class="label">
    <div class="code" id="labelCode"><?php echo htmlspecialchars($code); ?></div>
    <?php if ($problem !== ''): ?>
        <div class="problem"><?php echo nl2br(htmlspecialchars($problem)); ?></div>
    <?php endif; ?>
    <?php if ($pin !== ''): ?>
        <div class="pin">PIN: <b><?php echo htmlspecialchars($pin); ?></b></div>
    <?php endif; ?>
</div>
<div class="toolbar"><button onclick="window.print()">Vytisknout štítek</button></div>
<script>
    // Shrink the order code until it fits the label width, then open the print dialog.
    (function() {
        function fit() {
            var el = document.getElementById('labelCode');
            if (!el) return;
            var avail = el.parentElement.clientWidth - 2; // small safety margin
            var size = 64; // px, generous start
            el.style.fontSize = size + 'px';
            while (el.scrollWidth > avail && size > 12) {
                size -= 1;
                el.style.fontSize = size + 'px';
            }
        }
        window.addEventListener('load', function() {
            fit();
            // tiny delay so layout settles before the print dialog
            setTimeout(function() { window.print(); }, 150);
        });
    })();
</script>
</body>
</html>
