<?php
/* Ceny dodavatelů (v4.0.0) — nastavení přepočtu cen z webů dodavatelů a denní synchronizace
   katalogů. Vloženo do Nastavení → Dodavatelé (dřív karta v Nákupech, tam překážela).
   Předpoklad: crmCanManageCatalogs() a načtené includes/catalog_sync.php. */
ensureSupplierSyncSchema();
?>
<?php
    /* Ceny dodavatelů (v4.0.0): katalogy se každý den ráno samy projdou (ceny, dostupnost,
       zmizelé díly). Tady se nastavuje, jak se cena z webu přepočte na „co zaplatíme". */
    $__sync = $pdo->query("SELECT skey, name, price_vat_add, discount_pct, sync_enabled, last_sync_at, last_sync_info
        FROM supplier_catalogs WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $__running = (string)get_setting('supplier_sync_running', '');
    if ($__running !== '' && strtotime($__running) < time() - 3 * 3600) { $__running = ''; }
?>
<div class="sup-sync">
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <div>
                <h5 class="mb-1"><i class="fas fa-rotate me-2 text-info"></i>Ceny dodavatelů</h5>
                <div class="sup-sync-note">Katalogy se každý den ráno samy aktualizují: cena podle dnešního kurzu ČNB, s DPH a po naší slevě, dostupnost a díly, které dodavatel už nenabízí.</div>
            </div>
            <button type="button" class="btn btn-outline-info" id="supSyncRun"<?php echo $__running !== '' ? ' disabled' : ''; ?>>
                <i class="fas fa-rotate me-2<?php echo $__running !== '' ? ' fa-spin' : ''; ?>"></i><?php echo $__running !== '' ? 'Synchronizuje se…' : 'Synchronizovat teď'; ?>
            </button>
        </div>
        <form id="supSyncForm" class="table-responsive">
            <table class="table align-middle mb-0 sup-sync-table">
                <thead><tr><th>Dodavatel</th><th>Ceny na webu jsou</th><th>Naše sleva</th><th>Denně</th><th>Poslední kontrola</th></tr></thead>
                <tbody>
                <?php foreach ($__sync as $__s): $__k = (string)$__s['skey']; ?>
                    <tr>
                        <td class="fw-semibold"><?php echo e($__s['name']); ?></td>
                        <td>
                            <?php $__vat = (float)$__s['price_vat_add']; ?>
                            <select class="form-select form-select-sm" name="price_vat_add[<?php echo e($__k); ?>]">
                                <option value="0"<?php echo $__vat == 0 ? ' selected' : ''; ?>>s DPH (beze změny)</option>
                                <option value="21"<?php echo $__vat == 21 ? ' selected' : ''; ?>>bez DPH → přičíst 21 %</option>
                                <?php if ($__vat != 0 && $__vat != 21): ?><option value="<?php echo e((string)$__vat); ?>" selected>přičíst <?php echo e(rtrim(rtrim(number_format($__vat, 2, ',', ''), '0'), ',')); ?> %</option><?php endif; ?>
                            </select>
                        </td>
                        <td><div class="input-group input-group-sm sup-disc"><input type="text" inputmode="decimal" pattern="[0-9]+([.,][0-9]+)?" class="form-control" name="discount_pct[<?php echo e($__k); ?>]" value="<?php echo e(rtrim(rtrim(number_format((float)$__s['discount_pct'], 2, '.', ''), '0'), '.')); ?>"><b class="input-group-text">%</b></div></td>
                        <td><input type="checkbox" class="form-check-input" name="sync_enabled[<?php echo e($__k); ?>]" value="1"<?php echo (int)$__s['sync_enabled'] === 1 ? ' checked' : ''; ?>></td>
                        <td class="sup-last"><?php if (!empty($__s['last_sync_at'])): ?><b><?php echo e(date('j. n. H:i', strtotime((string)$__s['last_sync_at']))); ?></b> <em><?php echo e((string)$__s['last_sync_info']); ?></em><?php else: ?><em>zatím neproběhla</em><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="d-flex justify-content-between align-items-center gap-2 mt-2 flex-wrap">
                <div class="sup-sync-note">Sleva = B2B / zákaznická sleva u dodavatele, odečte se z ceny na webu. Ručně upravená prodejní cena dílu se synchronizací nepřepíše.</div>
                <button type="submit" class="btn btn-sm btn-primary">Uložit nastavení</button>
            </div>
        </form>
    </div>
</div>
<style>
.sup-sync-note{font-size:13px !important;color:rgba(233,238,247,.62)}
html[data-lg-theme="light"] .sup-sync-note{color:rgba(16,23,42,.62)}
.sup-sync-table th{font-size:12.5px !important;font-weight:600;color:rgba(233,238,247,.6);border-bottom-width:1px}
.sup-sync-table td{font-size:14px}
.sup-sync-table .sup-disc{max-width:120px;min-width:96px}
.sup-sync-table select{min-width:205px}
.sup-sync-table .sup-last b{font-weight:600;font-size:13.5px}
.sup-sync-table .sup-last em{font-style:normal;font-size:12.5px;color:rgba(233,238,247,.6)}
.sup-price-age{font-size:12px !important;color:rgba(233,238,247,.55)}
html[data-lg-theme="light"] .sup-sync-table th, html[data-lg-theme="light"] .sup-sync-table .sup-last em, html[data-lg-theme="light"] .sup-price-age{color:rgba(16,23,42,.66)}
.sup-gone{display:inline-block;margin-top:2px;padding:1px 7px;border-radius:6px;font-size:11.5px !important;font-weight:600;background:rgba(255,69,58,.16);color:#ff8a80}
</style>
<script>
(function () {
    var form = document.getElementById('supSyncForm');
    var btn = document.getElementById('supSyncRun');
    function post(data) {
        data.append('csrf_token', window.afxCsrf ? window.afxCsrf() : '');
        return fetch('api/supplier_sync.php', { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var bad = Array.prototype.some.call(form.querySelectorAll('input[name^="discount_pct"]'), function (i) {
            var v = parseFloat(String(i.value).replace(',', '.')); return i.value !== '' && (isNaN(v) || v < 0 || v > 90);
        });
        if (bad) { alert('Sleva musí být číslo od 0 do 90 %.'); return; }
        var fd = new FormData(form); fd.append('action', 'save');
        var b = form.querySelector('button[type=submit]'); b.disabled = true;
        post(fd).then(function (r) { b.disabled = false; if (!r.ok) { alert(r.error || 'Nepodařilo se uložit.'); return; }
            b.textContent = 'Uloženo ✓'; setTimeout(function () { b.textContent = 'Uložit nastavení'; }, 1800); })
            .catch(function () { b.disabled = false; alert('Síťová chyba.'); });
    });
    btn.addEventListener('click', function () {
        var fd = new FormData(); fd.append('action', 'run');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-rotate fa-spin me-2"></i>Synchronizuje se…';
        post(fd).then(function (r) { if (!r.ok) { alert(r.error || 'Nepodařilo se spustit.'); btn.disabled = false; return; } poll(); })
            .catch(function () { btn.disabled = false; });
    });
    function poll() {
        fetch('api/supplier_sync.php?action=status', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.running) { setTimeout(poll, 8000); } else { location.reload(); } })
            .catch(function () { setTimeout(poll, 15000); });
    }
    if (btn.disabled) { setTimeout(poll, 8000); }
})();
</script>
