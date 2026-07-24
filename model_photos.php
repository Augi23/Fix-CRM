<?php
/**
 * SKLAD → PRODUKTY → FOTKY MODELŮ.
 * Hromadné přiřazení studiové fotky na úrovni MODEL + BARVA. Jedna fotka „MacBook Pro 16
 * Space Gray" pokryje všechny takové kusy (i budoucí naskladněné) — produkt bez vlastní
 * studiovky ji ve feedu zdědí (productModelKey / crmModelPhotoMap). Šetří klikání u 400+ kusů.
 * Per-produkt studiovka v Galerii (products.php) má vždy přednost.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';
ensureProductsTable();
ensureProductsCrmColumns();
ensureModelPhotosTable();

if (!crmCanManageProducts()) {
    echo '<div class="container my-5"><div class="alert alert-danger">Nedostatečná oprávnění.</div></div>';
    require_once 'includes/footer.php';
    exit;
}
$CSRF = $_SESSION['csrf_token'] ?? '';
?>
<div class="container-fluid py-4">
    <?php $procurementBadgeCount = 0; require 'includes/inventory_tabs.php'; ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h4 class="mb-1"><i class="fas fa-images me-2"></i>Studiové fotky modelů</h4>
            <div class="text-white-50 small" style="max-width:640px">
                Nahraj <strong>jednu</strong> studiovou fotku pro model + barvu — přiřadí se
                <strong>všem</strong> kusům toho modelu (i těm, co teprve naskladníš). Fotka se
                pak zobrazí jako hlavní obrázek na e-shopu a půjde do Meta/Google katalogu.
            </div>
        </div>
        <a href="products.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Zpět na Produkty</a>
    </div>

    <!-- Coverage přehled -->
    <div id="mpStats" class="row g-2 mb-3"></div>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-light active" data-filter="all">Vše</button>
            <button type="button" class="btn btn-outline-light" data-filter="missing">Jen bez fotky</button>
            <button type="button" class="btn btn-outline-light" data-filter="covered">Jen s fotkou</button>
        </div>
        <input type="text" id="mpSearch" class="form-control form-control-sm bg-dark text-white border-secondary"
               placeholder="Hledat model…" style="max-width:260px">
        <span id="mpToast" class="small text-info ms-auto"></span>
    </div>

    <div id="mpGrid" class="row g-3">
        <div class="col-12 text-center text-white-50 py-5"><span class="spinner-border spinner-border-sm me-2"></span>Načítám modely…</div>
    </div>
</div>

<style>
.mp-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.10);border-radius:14px;padding:12px;height:100%;display:flex;flex-direction:column;gap:10px}
.mp-thumb{width:100%;aspect-ratio:1/1;border-radius:10px;background:#fff center/contain no-repeat;display:flex;align-items:center;justify-content:center;overflow:hidden}
.mp-thumb.empty{background:repeating-linear-gradient(45deg,rgba(255,255,255,.03),rgba(255,255,255,.03) 10px,rgba(255,255,255,.06) 10px,rgba(255,255,255,.06) 20px);color:rgba(255,255,255,.35);font-size:.8rem}
.mp-card.covered{border-color:rgba(52,199,89,.45)}
.mp-badge-count{font-variant-numeric:tabular-nums}
</style>

<script>
(function(){
  var CSRF = '<?php echo $CSRF; ?>';
  var grid = document.getElementById('mpGrid');
  var statsEl = document.getElementById('mpStats');
  var toast = document.getElementById('mpToast');
  var DATA = [], filter = 'all', q = '';

  // Escapování do HTML — g.label i samples pochází z products.color/title (import/uživatel),
  // takže se nesmí vkládat do innerHTML syrově (jinak stored XSS v adminské session).
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  function say(m, ok){ toast.textContent = m || ''; toast.className = 'small ms-auto ' + (ok===false?'text-warning':'text-info'); }

  function stat(label, val, cls){
    return '<div class="col-6 col-md-3 col-lg-2"><div class="glass-panel p-2 text-center h-100">'+
      '<div class="h4 mb-0 '+(cls||'')+'">'+val+'</div><div class="small text-white-50">'+label+'</div></div></div>';
  }
  function renderStats(s){
    if(!s){ statsEl.innerHTML=''; return; }
    statsEl.innerHTML =
      stat('Produktů', s.products_total) +
      stat('Pokryto fotkou', s.products_covered, 'text-success') +
      stat('Skupin modelů', s.groups_total) +
      stat('Skupin s fotkou', s.groups_covered, 'text-success') +
      stat('Bez modelu', s.products_no_key, s.products_no_key? 'text-warning':'') +
      stat('Vlastní studiovka', s.products_own);
  }

  function card(g){
    var codeName = g.model_key;
    var covered = !!g.current_url;
    var samples = (g.samples||[]).map(function(t){return t;}).join('\n');
    var thumb = covered
      ? '<div class="mp-thumb" style="background-image:url(\''+g.current_url.replace(/'/g,"%27")+'\')"></div>'
      : '<div class="mp-thumb empty">bez fotky</div>';
    return ''+
    '<div class="col-6 col-md-4 col-lg-3 mp-col" data-key="'+encodeURIComponent(g.model_key)+'">'+
      '<div class="mp-card '+(covered?'covered':'')+'">'+
        thumb+
        '<div>'+
          '<div class="fw-semibold text-white" style="line-height:1.2">'+esc(g.label)+'</div>'+
          '<div class="small text-white-50" title="'+esc(samples)+'">'+
            '<span class="mp-badge-count">'+g.count+'</span> ks'+(g.in_stock?(' · '+g.in_stock+' skladem'):'')+
            (g.has_color?'':' · <span class="text-warning">bez barvy</span>')+
          '</div>'+
        '</div>'+
        '<div class="mt-auto d-flex gap-2">'+
          '<label class="btn btn-sm btn-primary flex-grow-1 mb-0">'+
            '<i class="fas fa-upload me-1"></i>'+(covered?'Změnit':'Nahrát')+
            '<input type="file" accept="image/*" hidden class="mp-file">'+
          '</label>'+
          (covered?'<button class="btn btn-sm btn-outline-danger mp-clear" title="Odebrat fotku"><i class="fas fa-trash"></i></button>':'')+
        '</div>'+
      '</div>'+
    '</div>';
  }

  function apply(){
    var html = DATA.filter(function(g){
      if(filter==='missing' && g.current_url) return false;
      if(filter==='covered' && !g.current_url) return false;
      if(q && (g.label+' '+g.model_key).toLowerCase().indexOf(q)<0) return false;
      return true;
    }).map(card).join('');
    grid.innerHTML = html || '<div class="col-12 text-center text-white-50 py-5">Nic k zobrazení.</div>';
  }

  function load(){
    fetch('api/model_photos.php?action=groups', {credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        if(!d.success){ grid.innerHTML='<div class="col-12 text-danger">'+(d.message||'Chyba')+'</div>'; return; }
        DATA = d.groups||[]; renderStats(d.stats); apply();
      })
      .catch(function(e){ grid.innerHTML='<div class="col-12 text-danger">Chyba načtení: '+e+'</div>'; });
  }

  // upload fotky → set do knihovny
  function upload(col, file){
    var key = decodeURIComponent(col.getAttribute('data-key'));
    say('Nahrávám '+key+'…');
    var fd = new FormData();
    fd.append('image', file);
    fd.append('code', key);              // upload endpoint si název sanitizuje sám
    fd.append('variant', 'model');
    fd.append('keep_alpha', '1');        // studiová fotka bez pozadí = PNG s průhledností
    fd.append('csrf_token', CSRF);
    fetch('api/upload_product_image.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(u){
        if(!u.success) throw new Error(u.message||'upload selhal');
        var f2 = new FormData();
        f2.append('action','set'); f2.append('model_key', key);
        f2.append('studio_url', u.url); f2.append('csrf_token', CSRF);
        return fetch('api/model_photos.php', {method:'POST', body:f2, credentials:'same-origin'}).then(function(r){return r.json();});
      })
      .then(function(s){
        if(!s.success) throw new Error(s.message||'uložení selhalo');
        var g = DATA.find(function(x){return x.model_key===key;});
        if(g){ g.current_url = s.studio_url + '?t=' + Date.now(); }
        apply(); say('Uloženo: '+key, true);
      })
      .catch(function(e){ say('Chyba: '+e.message, false); });
  }
  function clear(col){
    var key = decodeURIComponent(col.getAttribute('data-key'));
    if(!confirm('Odebrat studiovou fotku modelu „'+key+'"?')) return;
    var fd = new FormData();
    fd.append('action','clear'); fd.append('model_key', key); fd.append('csrf_token', CSRF);
    fetch('api/model_photos.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(s){
        if(!s.success) throw new Error(s.message||'chyba');
        var g = DATA.find(function(x){return x.model_key===key;});
        if(g){ g.current_url=''; }
        apply(); say('Odebráno: '+key, true);
      })
      .catch(function(e){ say('Chyba: '+e.message, false); });
  }

  grid.addEventListener('change', function(e){
    if(e.target.classList.contains('mp-file') && e.target.files[0]){
      upload(e.target.closest('.mp-col'), e.target.files[0]);
      e.target.value = '';   // umožní re-výběr téhož souboru po chybě (jinak 'change' znovu nevyskočí)
    }
  });
  grid.addEventListener('click', function(e){
    var b = e.target.closest('.mp-clear');
    if(b) clear(b.closest('.mp-col'));
  });
  document.querySelectorAll('[data-filter]').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('[data-filter]').forEach(function(x){x.classList.remove('active');});
      btn.classList.add('active'); filter = btn.getAttribute('data-filter'); apply();
    });
  });
  document.getElementById('mpSearch').addEventListener('input', function(){ q=this.value.trim().toLowerCase(); apply(); });

  load();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
