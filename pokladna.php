<?php
/**
 * POKLADNA (kasa prodejna) — přímý pultový prodej dílů a produktů bez zakázky.
 * Vlevo: živé vyhledávání skladem dostupných položek + dnešní prodeje (dotisk).
 * Vpravo: košík (množství i cena upravitelné — sleva na místě), volba platby
 * hotově / kartou / na fakturu (u faktury povinný zákazník), dokončení prodeje.
 * Prodej okamžitě odepisuje sklad (api/pos_checkout.php, atomicky).
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensurePosTables();
ensureProductsTable();
ensureProductsPosColumn();

$canCancel = crmCanCancelPosSale();

// dnešní prodeje (pro rychlý dotisk účtenky přímo z kasy)
$today = [];
$todaySums = ['cash' => 0.0, 'card' => 0.0, 'invoice' => 0.0];
try {
    $st = $pdo->query("SELECT id, sale_number, created_at, total, payment_method, status, seller_name
        FROM pos_sales WHERE DATE(created_at) = CURDATE() ORDER BY id DESC LIMIT 20");
    $today = $st->fetchAll();
    foreach ($pdo->query("SELECT payment_method, SUM(total) s FROM pos_sales
        WHERE DATE(created_at) = CURDATE() AND status = 'completed' GROUP BY payment_method") as $r) {
        $todaySums[(string)$r['payment_method']] = (float)$r['s'];
    }
} catch (Throwable $e) {}
?>

<style>
/* Kasa — velké dotykové ovládání, liquid glass akcenty ladí s dokem (.afx-cell.act-*) */
.pos-search { font-size: 17px; padding: 13px 16px; border-radius: 14px; }
.pos-results { max-height: 380px; overflow-y: auto; }
.pos-hit { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 12px; cursor: pointer; transition: background .13s; }
.pos-hit:hover { background: rgba(0,163,255,.12); }
.pos-hit .nm { font-weight: 600; }
.pos-hit .cd { font-size: 11.5px; color: rgba(255,255,255,.5); }
.pos-hit .pr { margin-left: auto; font-weight: 700; white-space: nowrap; }
.pos-type { font-size: 10px; font-weight: 700; letter-spacing: .05em; border-radius: 7px; padding: 2px 7px; white-space: nowrap; }
.pos-type.part { background: rgba(0,163,255,.18); color: #6fd0ff; }
.pos-type.product { background: rgba(48,209,88,.16); color: #6fe08d; }
.pos-cart td { vertical-align: middle; }
.pos-cart input { text-align: right; }
.pos-total { font-size: 30px; font-weight: 700; letter-spacing: -.02em; }
.pos-pay { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px;
  flex: 1; padding: 14px 8px; border-radius: 17px; border: 0; cursor: pointer; font-weight: 600; font-size: 13.5px;
  background: rgba(255,255,255,.05); color: #a7b1c2; box-shadow: inset 0 0 0 1px rgba(255,255,255,.09);
  transition: background .16s, color .16s, box-shadow .16s; }
.pos-pay i { font-size: 19px; }
.pos-pay.sel-cash { color: #6fe08d; background: rgba(48,209,88,.16); box-shadow: inset 0 0 0 1px rgba(48,209,88,.4), 0 0 16px rgba(48,209,88,.22); text-shadow: 0 0 12px rgba(48,209,88,.5); }
.pos-pay.sel-card { color: #5fd2ff; background: rgba(0,163,255,.18); box-shadow: inset 0 0 0 1px rgba(0,163,255,.45), 0 0 16px rgba(0,163,255,.25); text-shadow: 0 0 12px rgba(95,210,255,.5); }
.pos-pay.sel-invoice { color: #ffc46b; background: rgba(255,159,10,.15); box-shadow: inset 0 0 0 1px rgba(255,159,10,.42), 0 0 16px rgba(255,159,10,.22); text-shadow: 0 0 12px rgba(255,159,10,.5); }
.pos-finish { width: 100%; padding: 15px; border: 0; border-radius: 17px; font-size: 16.5px; font-weight: 700;
  color: #eaf6ff; background: linear-gradient(135deg, rgba(0,163,255,.34), rgba(90,200,250,.22));
  box-shadow: inset 0 0 0 1px rgba(0,163,255,.5), 0 8px 26px rgba(0,120,210,.28); cursor: pointer; transition: filter .15s, transform .12s; }
.pos-finish:hover { filter: brightness(1.15); }
.pos-finish:active { transform: scale(.985); }
.pos-finish:disabled { opacity: .45; cursor: not-allowed; }
.pos-empty { color: rgba(255,255,255,.4); text-align: center; padding: 26px 0; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="fas fa-cash-register me-2 text-info"></i>Pokladna</h2>
    <span class="text-white-50 small">Dnes: hotově <strong><?php echo formatMoney($todaySums['cash']); ?></strong>
        · kartou <strong><?php echo formatMoney($todaySums['card']); ?></strong>
        · fakturou <strong><?php echo formatMoney($todaySums['invoice']); ?></strong></span>
</div>

<div class="row g-4">
    <!-- ── vyhledávání + dnešní prodeje ── -->
    <div class="col-lg-7">
        <div class="glass-panel p-3 border-secondary mb-4">
            <label class="form-label small text-white-50 mb-2"><i class="fas fa-search me-1"></i> Najdi produkt nebo díl (jen skladem)</label>
            <input type="text" id="posSearch" class="form-control pos-search" placeholder="iPhone 13, displej, sériové číslo…" autocomplete="off">
            <div id="posResults" class="pos-results mt-2"></div>
        </div>

        <div class="glass-panel p-3 border-secondary">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong><i class="fas fa-clock me-2 text-white-50"></i>Dnešní prodeje</strong>
                <?php if (crmCanViewHistory()): ?>
                <a href="history.php?tab=kasa" class="small text-info text-decoration-none">Celá historie kasy →</a>
                <?php endif; ?>
            </div>
            <?php if (empty($today)): ?>
                <div class="pos-empty">Dnes zatím žádný prodej.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-dark table-sm align-middle mb-0">
                    <tbody>
                    <?php foreach ($today as $t): $cn = (string)$t['status'] === 'cancelled'; ?>
                        <tr<?php echo $cn ? ' class="text-decoration-line-through opacity-50"' : ''; ?>>
                            <td><code><?php echo e($t['sale_number']); ?></code></td>
                            <td class="small"><?php echo date('H:i', strtotime((string)$t['created_at'])); ?></td>
                            <td class="small"><?php echo e($t['seller_name']); ?></td>
                            <td><span class="badge <?php echo ['cash' => 'bg-success', 'card' => 'bg-info text-dark', 'invoice' => 'bg-warning text-dark'][(string)$t['payment_method']] ?? 'bg-secondary'; ?>"><?php echo ['cash' => 'Hotově', 'card' => 'Kartou', 'invoice' => 'Faktura'][(string)$t['payment_method']] ?? ''; ?></span></td>
                            <td class="text-end fw-bold"><?php echo formatMoney((float)$t['total']); ?></td>
                            <td class="text-end"><a href="print_receipt.php?id=<?php echo (int)$t['id']; ?>" target="_blank" class="btn btn-sm btn-white border text-info" title="Účtenka"><i class="fas fa-print"></i></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── košík + platba ── -->
    <div class="col-lg-5">
        <div class="glass-panel p-3 border-secondary">
            <strong class="d-block mb-2"><i class="fas fa-shopping-basket me-2 text-white-50"></i>Košík</strong>
            <div class="table-responsive">
                <table class="table table-dark table-sm align-middle mb-2 pos-cart">
                    <thead>
                        <tr class="small text-white-50"><th>Položka</th><th style="width:74px;">Ks</th><th style="width:104px;">Cena/ks</th><th class="text-end">Celkem</th><th style="width:34px;"></th></tr>
                    </thead>
                    <tbody id="posCartBody"></tbody>
                </table>
            </div>
            <div id="posCartEmpty" class="pos-empty">Košík je prázdný — vyhledej zboží vlevo.</div>

            <div class="d-flex justify-content-between align-items-center my-3">
                <span class="text-white-50">Celkem</span>
                <span class="pos-total" id="posTotal">0 Kč</span>
            </div>

            <div class="d-flex gap-2 mb-3">
                <button type="button" class="pos-pay" data-pay="cash"><i class="fas fa-money-bill-wave"></i>Hotově</button>
                <button type="button" class="pos-pay" data-pay="card"><i class="fas fa-credit-card"></i>Kartou<span class="small" style="font-size:10px;opacity:.7;">terminál zvlášť</span></button>
                <button type="button" class="pos-pay" data-pay="invoice"><i class="fas fa-file-invoice"></i>Na fakturu</button>
            </div>

            <div id="posCustomerWrap" class="mb-3" style="display:none;">
                <label class="form-label small text-white-50 mb-1">Zákazník (povinné u faktury)</label>
                <select id="posCustomer" class="form-select" style="width:100%;"></select>
            </div>

            <button type="button" class="pos-finish" id="posFinish" disabled><i class="fas fa-check me-2"></i>Dokončit prodej</button>
        </div>

        <div class="glass-panel p-3 border-secondary mt-3" id="posDone" style="display:none;">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-check-circle text-success fs-4"></i>
                <strong>Prodáno — doklad <code id="posDoneNumber"></code></strong>
            </div>
            <div id="posDoneProductNote" class="alert alert-info border-0 py-2 small" style="display:none;">
                <i class="fas fa-check me-1"></i> Sklad CRM odečten automaticky — produkt zůstane vyprodaný i po dalším nahrání souboru z naskladňovací appky.
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-info" id="posDoneReceipt"><i class="fas fa-print me-1"></i> Účtenka</button>
                <button type="button" class="btn btn-warning" id="posDoneInvoice" style="display:none;"><i class="fas fa-file-invoice me-1"></i> Faktura</button>
                <button type="button" class="btn btn-outline-light ms-auto" id="posDoneNew"><i class="fas fa-plus me-1"></i> Nový prodej</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var cart = [];          // {type, id, name, code, price, qty, stock}
    var payment = '';
    var lastSale = null;

    var $body = document.getElementById('posCartBody');
    var $empty = document.getElementById('posCartEmpty');
    var $total = document.getElementById('posTotal');
    var $finish = document.getElementById('posFinish');
    var $custWrap = document.getElementById('posCustomerWrap');

    function fmt(n) { return new Intl.NumberFormat('cs-CZ', { maximumFractionDigits: 0 }).format(n) + ' Kč'; }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    // ── vyhledávání ──
    var searchTimer = null;
    var $search = document.getElementById('posSearch');
    var $results = document.getElementById('posResults');
    function doSearch() {
        var q = $search.value.trim();
        fetch('api/pos_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) return;
                $results.innerHTML = '';
                if (!d.results.length) {
                    $results.innerHTML = '<div class="pos-empty">Nic skladem nenalezeno.</div>';
                    return;
                }
                d.results.forEach(function (r) {
                    var el = document.createElement('div');
                    el.className = 'pos-hit';
                    el.innerHTML = '<span class="pos-type ' + r.type + '">' + (r.type === 'part' ? 'DÍL' : 'PRODUKT') + '</span>'
                        + '<div><div class="nm">' + esc(r.name) + '</div><div class="cd">' + esc(r.code || '') + ' · skladem ' + r.stock + ' ks</div></div>'
                        + '<span class="pr">' + fmt(r.price) + '</span>';
                    el.addEventListener('click', function () { addToCart(r); });
                    $results.appendChild(el);
                });
            })
            .catch(function () {});
    }
    $search.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(doSearch, 280); });
    $search.addEventListener('focus', function () { if (!$results.children.length) doSearch(); });

    // ── košík ──
    function addToCart(r) {
        var found = cart.find(function (c) { return c.type === r.type && c.id === r.id; });
        if (found) {
            if (found.qty + 1 > r.stock) { alert('Skladem je jen ' + r.stock + ' ks.'); return; }
            found.qty++;
        } else {
            cart.push({ type: r.type, id: r.id, name: r.name, code: r.code, price: r.price, qty: 1, stock: r.stock });
        }
        render();
    }
    window.posRemove = function (i) { cart.splice(i, 1); render(); };
    window.posQty = function (i, v) {
        v = parseInt(v, 10) || 1;
        if (v < 1) v = 1;
        if (v > cart[i].stock) { alert('Skladem je jen ' + cart[i].stock + ' ks.'); v = cart[i].stock; }
        cart[i].qty = v; render();
    };
    window.posPrice = function (i, v) {
        v = parseFloat(String(v).replace(',', '.'));
        if (isNaN(v) || v < 0) v = 0;
        cart[i].price = v; render();
    };

    function total() { return cart.reduce(function (s, c) { return s + c.price * c.qty; }, 0); }

    function render() {
        $body.innerHTML = '';
        cart.forEach(function (c, i) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><div class="fw-semibold small">' + esc(c.name) + '</div><div class="cd small text-white-50">' + esc(c.code || '') + '</div></td>'
                + '<td><input type="number" class="form-control form-control-sm" min="1" max="' + c.stock + '" value="' + c.qty + '" onchange="posQty(' + i + ', this.value)"></td>'
                + '<td><input type="text" class="form-control form-control-sm" value="' + c.price + '" onchange="posPrice(' + i + ', this.value)"></td>'
                + '<td class="text-end fw-bold">' + fmt(c.price * c.qty) + '</td>'
                + '<td><button type="button" class="btn btn-sm btn-white border text-danger" onclick="posRemove(' + i + ')"><i class="fas fa-times"></i></button></td>';
            $body.appendChild(tr);
        });
        $empty.style.display = cart.length ? 'none' : '';
        $total.textContent = fmt(total());
        updateFinish();
    }

    // ── platba ──
    document.querySelectorAll('.pos-pay').forEach(function (btn) {
        btn.addEventListener('click', function () {
            payment = btn.dataset.pay;
            document.querySelectorAll('.pos-pay').forEach(function (b) { b.className = 'pos-pay'; });
            btn.classList.add('sel-' + payment);
            $custWrap.style.display = payment === 'invoice' ? '' : 'none';
            updateFinish();
        });
    });

    // zákazník (select2 — stejný endpoint jako wizard Nová zakázka)
    $(document).ready(function () {
        $('#posCustomer').select2({
            width: '100%', placeholder: 'Hledat zákazníka…', allowClear: true, minimumInputLength: 0,
            ajax: {
                url: 'api/search_customers.php', dataType: 'json', delay: 250,
                data: function (params) { return { q: params.term || '', page: params.page || 1 }; },
                processResults: function (data, params) {
                    params.page = params.page || 1;
                    return { results: data.results, pagination: { more: data.pagination && data.pagination.more } };
                }
            }
        }).on('change', updateFinish);
    });

    function updateFinish() {
        var ok = cart.length > 0 && payment !== '';
        if (payment === 'invoice' && !$('#posCustomer').val()) ok = false;
        $finish.disabled = !ok;
    }

    // ── dokončení ──
    $finish.addEventListener('click', function () {
        $finish.disabled = true;
        $finish.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Zpracovávám…';
        fetch('api/pos_checkout.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: '<?php echo $_SESSION['csrf_token'] ?? ''; ?>',
                payment: payment,
                customer_id: parseInt($('#posCustomer').val() || 0, 10),
                items: cart.map(function (c) { return { type: c.type, id: c.id, qty: c.qty, price: c.price }; })
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            $finish.innerHTML = '<i class="fas fa-check me-2"></i>Dokončit prodej';
            if (!d.success) { alert(d.message || 'Prodej se nepodařil.'); updateFinish(); return; }
            lastSale = d;
            document.getElementById('posDoneNumber').textContent = d.sale_number;
            document.getElementById('posDoneProductNote').style.display = d.has_product ? '' : 'none';
            document.getElementById('posDoneInvoice').style.display = d.invoice_id ? '' : 'none';
            document.getElementById('posDone').style.display = '';
            cart = []; payment = '';
            document.querySelectorAll('.pos-pay').forEach(function (b) { b.className = 'pos-pay'; });
            $custWrap.style.display = 'none';
            $('#posCustomer').val(null).trigger('change');
            render();
            window.open('print_receipt.php?id=' + d.sale_id + '&auto=1', '_blank');
        })
        .catch(function () {
            $finish.innerHTML = '<i class="fas fa-check me-2"></i>Dokončit prodej';
            alert('Síťová chyba — prodej se možná neuložil, zkontroluj dnešní prodeje.');
            updateFinish();
        });
    });

    document.getElementById('posDoneReceipt').addEventListener('click', function () {
        if (lastSale) window.open('print_receipt.php?id=' + lastSale.sale_id + '&auto=1', '_blank');
    });
    document.getElementById('posDoneInvoice').addEventListener('click', function () {
        if (lastSale && lastSale.invoice_id) window.open('print_invoice.php?id=' + lastSale.invoice_id, '_blank');
    });
    document.getElementById('posDoneNew').addEventListener('click', function () {
        document.getElementById('posDone').style.display = 'none';
        location.reload();   // obnoví i seznam dnešních prodejů
    });

    render();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
