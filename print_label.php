<?php
/**
 * Label print for Brother QL-810W — 62×38 mm.
 * Big bold order number + QR code (opens the order when scanned), problem description, optional PIN.
 * Auto-fits the code to width and triggers the browser print dialog (pick the Brother QL-810W there).
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

// Absolute URL of the order — encoded into the QR so a scan opens the order.
$scheme   = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host     = (string)($_SERVER['HTTP_HOST'] ?? '');
$orderUrl = ($host !== '' ? ($scheme . '://' . $host) : '') . '/view_order.php?id=' . (int)$order['id'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Štítek <?php echo htmlspecialchars($code); ?></title>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
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
    .top { display: flex; align-items: flex-start; gap: 1.5mm; }
    .top .code-wrap { flex: 1 1 auto; min-width: 0; }
    .code {
        display: inline-block;
        font-weight: 900;
        line-height: 1;
        letter-spacing: -0.3px;
        white-space: nowrap;
        font-size: 9mm; /* JS shrinks to fit width */
    }
    .qr { flex: 0 0 auto; width: 14mm; height: 14mm; }
    .qr svg, .qr img { width: 14mm; height: 14mm; display: block; }
    .problem {
        font-size: 3mm;
        line-height: 1.15;
        margin-top: 1.2mm;
        flex: 1 1 auto;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
    }
    .pin {
        font-size: 3.6mm;
        margin-top: 0.8mm;
        font-weight: bold;
        border-top: 0.3mm solid #000;
        padding-top: 0.7mm;
        white-space: nowrap;
    }
    .pin b { font-weight: 900; }

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
    <div class="top">
        <div class="code-wrap"><div class="code" id="labelCode"><?php echo htmlspecialchars($code); ?></div></div>
        <div class="qr" id="qr"></div>
    </div>
    <?php if ($problem !== ''): ?>
        <div class="problem"><?php echo nl2br(htmlspecialchars($problem)); ?></div>
    <?php endif; ?>
    <?php if ($pin !== ''): ?>
        <div class="pin">PIN: <b><?php echo htmlspecialchars($pin); ?></b></div>
    <?php endif; ?>
</div>
<div class="toolbar"><button onclick="window.print()">Vytisknout štítek</button></div>
<script>
    var ORDER_URL = <?php echo json_encode($orderUrl, JSON_UNESCAPED_SLASHES); ?>;

    function fitCode() {
        var el = document.getElementById('labelCode');
        if (!el) return;
        var avail = el.parentElement.clientWidth - 1;
        var size = 56;
        el.style.fontSize = size + 'px';
        // .code is inline-block → offsetWidth is the actual text width
        while (el.offsetWidth > avail && size > 8) { size -= 1; el.style.fontSize = size + 'px'; }
    }

    function doPrint() {
        fitCode();
        setTimeout(function() { window.print(); }, 150);
    }

    window.addEventListener('load', function() {
        var qrBox = document.getElementById('qr');
        try {
            if (window.qrcode && qrBox) {
                var qr = qrcode(0, 'M'); // auto type, medium error correction
                qr.addData(ORDER_URL);
                qr.make();
                qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
            }
        } catch (e) {}
        doPrint();
    });
</script>
</body>
</html>
