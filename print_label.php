<?php
/**
 * Label print for Brother QL-810W — 62×38 mm.
 * Big bold order number + a single 1D Code128 barcode (the order id) — readable by the
 * X-9100 desk laser scanner AND by the in-app phone scanner (one code for everything),
 * plus problem description and optional PIN. Auto-fits the number, then prints.
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
$orderId = (int)$order['id'];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title>Štítek <?php echo htmlspecialchars($code); ?></title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
    @page { size: 62mm 38mm; margin: 0; }
    html, body { margin: 0; padding: 0; }
    body { width: 62mm; height: 38mm; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; }
    .label {
        box-sizing: border-box;
        width: 62mm; height: 38mm;
        padding: 1.6mm 2.5mm;
        display: flex; flex-direction: column;
        overflow: hidden;
    }
    .code {
        display: inline-block;
        font-weight: 900;
        line-height: 1;
        letter-spacing: -0.3px;
        white-space: nowrap;
        font-size: 8mm; /* JS shrinks to fit width */
    }
    .barcode { text-align: center; line-height: 0; margin-top: 0.8mm; }
    .barcode svg { display: inline-block; }
    .problem {
        font-size: 2.9mm;
        line-height: 1.12;
        margin-top: 1mm;
        flex: 1 1 auto;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    .pin {
        font-size: 3.4mm;
        margin-top: 0.6mm;
        font-weight: bold;
        border-top: 0.3mm solid #000;
        padding-top: 0.6mm;
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
    <div><span class="code" id="labelCode"><?php echo htmlspecialchars($code); ?></span></div>
    <div class="barcode" id="barcode"></div>
    <?php if ($problem !== ''): ?>
        <div class="problem"><?php echo nl2br(htmlspecialchars($problem)); ?></div>
    <?php endif; ?>
    <?php if ($pin !== ''): ?>
        <div class="pin">PIN: <b><?php echo htmlspecialchars($pin); ?></b></div>
    <?php endif; ?>
</div>
<div class="toolbar"><button onclick="window.print()">Vytisknout štítek</button></div>
<script>
    var ORDER_ID = <?php echo json_encode((string)$orderId); ?>;

    function fitCode() {
        var el = document.getElementById('labelCode');
        if (!el) return;
        var avail = (el.parentElement.clientWidth) - 1;
        var size = 50;
        el.style.fontSize = size + 'px';
        while (el.offsetWidth > avail && size > 8) { size -= 1; el.style.fontSize = size + 'px'; }
    }

    function renderBarcode() {
        var box = document.getElementById('barcode');
        if (!box || typeof window.JsBarcode !== 'function') return;
        try {
            var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            box.appendChild(svg);
            JsBarcode(svg, ORDER_ID, { format: 'CODE128', displayValue: false, margin: 0, height: 60, width: 2 });
            // Uniform-scale to fit the label width and ~10mm height (no aspect distortion → stays scannable).
            var w = parseFloat(svg.getAttribute('width')) || 1;
            var h = parseFloat(svg.getAttribute('height')) || 1;
            var maxW = box.clientWidth;
            var maxH = Math.round(10 * (96 / 25.4)); // ~10mm in px
            var scale = Math.min(maxW / w, maxH / h);
            if (scale > 0 && isFinite(scale)) {
                svg.setAttribute('width', Math.floor(w * scale) + 'px');
                svg.setAttribute('height', Math.floor(h * scale) + 'px');
            }
        } catch (e) {}
    }

    window.addEventListener('load', function() {
        fitCode();
        renderBarcode();
        setTimeout(function() { window.print(); }, 200);
    });
</script>
</body>
</html>
