<?php
/**
 * ZÁKAZNICKÝ DISPLEJ — celoobrazovková stránka pro druhý monitor na recepci.
 *
 * Otevírá se s tokenem pobočky: display.php?token=... (URL dá tlačítko 🖥️
 * v horní liště CRM). Displej NEMÁ zaměstnaneckou session — jen token, přes
 * který umí číst svůj stav, číst produkty pro reklamu a odeslat formulář.
 *
 * Stavy (řídí obsluha přes api/customer_display.php):
 *   idle        — logo + rotující produkty z e-shopu (reklama)
 *   client_form — klient sám vyplní své údaje (propíší se obsluze do zakázky)
 *   cart        — položky a součet z pokladny (živě)
 *   receipt     — zaplaceno: částka + vráceno
 *
 * ?preview=idle|client_form|cart|receipt — statická ukázka s DEMO daty (bez DB),
 * slouží jen k vývoji/náhledu vzhledu; nikdy nezobrazuje ostrá data.
 */
$preview = isset($_GET['preview']) ? (string)$_GET['preview'] : '';
$bootToken = '';
if ($preview === '') {
    require_once 'includes/config.php';
    require_once 'includes/functions.php';
    ensureCustomerDisplayTable();
    $bootToken = trim((string)($_GET['token'] ?? ''));
    $ok = false;
    if (preg_match('/^[a-f0-9]{32,64}$/', $bootToken)) {
        $sel = $pdo->prepare("SELECT branch_id FROM customer_display WHERE token = ?");
        $sel->execute([$bootToken]);
        $ok = (bool)$sel->fetch();
    }
    if (!$ok) { http_response_code(403); echo 'Neplatný odkaz displeje.'; exit; }
}
?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AppleFix</title>
<style>
  :root {
    --bg: #08090c; --panel: #101319; --line: rgba(255,255,255,.08);
    --txt: #f2f4f8; --dim: #8b93a1; --cyan: #2ed0eb; --blue: #0a84ff;
    --green: #35d07f;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { height: 100%; }
  body {
    background: var(--bg); color: var(--txt); overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", Roboto, sans-serif;
    cursor: none;
  }
  .view { position: absolute; inset: 0; display: none; }
  .view.on { display: flex; }

  /* ── značka ── */
  .brand { font-weight: 900; font-style: italic; letter-spacing: -.02em; }
  .brand .fx { color: var(--cyan); }

  /* ── IDLE: oficiální logo v rohu + velká reklama na středu ── */
  /* POZOR: žádné position:relative — přepsalo by .view { position:absolute; inset:0 }
     a sekce by se smrskla na nulovou výšku (karta kotvená dolů pak ulétla nad obrazovku) */
  #vIdle { flex-direction: column; align-items: center; justify-content: center; gap: 4vh;
           background: radial-gradient(60% 50% at 50% 42%, rgba(46,208,235,.07), transparent 70%), var(--bg); }
  #idleLogo { position: absolute; top: 4vh; right: 3vw; height: 5.5vh; opacity: .96; }
  #vIdle .brand { font-size: 7vw; }
  #idleClaim { color: var(--dim); font-size: 1.6vw; letter-spacing: .12em; text-transform: uppercase; }
  /* běží-li reklama, velký textový nápis uprostřed zmizí — scéna patří produktu:
     karta sedí NATVRDO v levém spodním rohu (absolutně — nezávisle na flexu) */
  #vIdle.adOn .brand, #vIdle.adOn #idleClaim { display: none; }
  #vIdle.adOn { gap: 0; }
  #vIdle.adOn #adCard { position: absolute; left: 3vw; bottom: 5vh; margin: 0; max-width: 46vw; }
  #adCard { display: none; align-items: center; gap: 2.5vw;
            background: var(--panel); border: 1px solid var(--line); border-radius: 28px;
            padding: 4vh 2.5vw; max-width: 56vw;
            opacity: 0; transition: opacity .6s ease; }
  #adCard.show { opacity: 1; }
  #adImg { height: 30vh; max-width: 24vw; object-fit: contain; border-radius: 18px; }
  #adTitle { font-size: 2.2vw; font-weight: 800; line-height: 1.2; }
  #adMan { color: var(--dim); font-size: 1.3vw; margin-top: .7vh; }
  #adPrice { color: var(--cyan); font-size: 3.2vw; font-weight: 900; margin-top: 1.6vh; }

  /* ── FORMULÁŘ ── */
  #vForm { align-items: center; justify-content: center; }
  .formCard { width: min(880px, 92vw); background: var(--panel); border: 1px solid var(--line);
              border-radius: 28px; padding: 44px 48px; }
  .formCard h1 { font-size: 34px; margin-bottom: 6px; }
  .formCard .sub { color: var(--dim); font-size: 17px; margin-bottom: 26px; }
  .seg { display: flex; gap: 10px; margin-bottom: 22px; }
  .seg button { flex: 1; font-size: 18px; padding: 14px; border-radius: 14px; border: 1px solid var(--line);
                background: transparent; color: var(--dim); cursor: pointer; }
  .seg button.on { background: rgba(46,208,235,.12); border-color: var(--cyan); color: var(--txt); }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .grid .full { grid-column: 1 / -1; }
  label { display: block; font-size: 14px; color: var(--dim); margin: 0 0 6px 2px; }
  input, textarea { width: 100%; font-size: 20px; padding: 14px 16px; color: var(--txt);
    background: #0a0d12; border: 1px solid var(--line); border-radius: 14px; outline: none; }
  input:focus, textarea:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(10,132,255,.25); }
  .companyOnly { display: none; }
  .isCompany .companyOnly { display: block; }
  #fSubmit { width: 100%; margin-top: 26px; font-size: 22px; font-weight: 700; color: #fff;
    padding: 18px; border: 0; border-radius: 16px; cursor: pointer;
    background: linear-gradient(180deg, #52a9ff, #0a84ff); box-shadow: 0 8px 24px rgba(10,132,255,.35); }
  #fSubmit:disabled { opacity: .5; }
  #fErr { color: #ff6b6b; font-size: 16px; margin-top: 14px; min-height: 20px; }

  /* ── KOŠÍK ── */
  #vCart { flex-direction: column; padding: 6vh 8vw; }
  #vCart header { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 4vh; }
  #vCart header .brand { font-size: 3vw; }
  #cartRows { flex: 1; overflow: hidden; display: flex; flex-direction: column; gap: 1.4vh; }
  .cRow { display: flex; justify-content: space-between; align-items: baseline; gap: 2vw;
          font-size: 2.2vw; border-bottom: 1px solid var(--line); padding-bottom: 1.2vh; }
  .cRow .nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cRow .qt { color: var(--dim); font-size: 1.6vw; flex: 0 0 auto; }
  .cRow .pr { flex: 0 0 auto; font-weight: 700; font-variant-numeric: tabular-nums; }
  #cartTotalRow { display: flex; justify-content: space-between; align-items: baseline;
                  margin-top: 3vh; padding-top: 2.5vh; border-top: 2px solid rgba(46,208,235,.4); }
  #cartTotalRow .lbl { font-size: 2.4vw; color: var(--dim); text-transform: uppercase; letter-spacing: .08em; }
  #cartTotal { font-size: 5.4vw; font-weight: 900; color: var(--cyan); font-variant-numeric: tabular-nums; }

  /* ── ÚČTENKA / DĚKUJEME ── */
  #vDone { flex-direction: column; align-items: center; justify-content: center; gap: 2.5vh; text-align: center; }
  #vDone .big { font-size: 4.4vw; font-weight: 900; }
  #vDone .amount { font-size: 6.5vw; font-weight: 900; color: var(--green); font-variant-numeric: tabular-nums; }
  #vDone .sub { color: var(--dim); font-size: 2vw; }
  .check { width: 9vw; height: 9vw; border-radius: 50%; background: rgba(53,208,127,.12);
           border: 2px solid var(--green); display: flex; align-items: center; justify-content: center; }
  .check svg { width: 4.5vw; height: 4.5vw; stroke: var(--green); stroke-width: 3; fill: none; }
</style>
</head>
<body>

<section id="vIdle" class="view on">
  <img id="idleLogo" src="assets/img/applefix-logo.png" alt="AppleFix">
  <div class="brand">APPLE<span class="fx">⌁</span>FIX</div>
  <div id="idleClaim">servis se vším všudy</div>
  <div id="adCard">
    <img id="adImg" alt="">
    <div>
      <div id="adTitle"></div>
      <div id="adMan"></div>
      <div id="adPrice"></div>
    </div>
  </div>
</section>

<section id="vForm" class="view">
  <form class="formCard" id="clientForm" autocomplete="off">
    <h1>Vyplňte prosím své údaje</h1>
    <div class="sub">Údaje se rovnou propíší k vaší zakázce — děkujeme, šetříte nám čas ✦</div>
    <div class="seg">
      <button type="button" id="segPrivate" class="on">Soukromá osoba</button>
      <button type="button" id="segCompany">Firma</button>
    </div>
    <div class="grid" id="formGrid">
      <div class="companyOnly full"><label>Název firmy</label><input name="company_name" maxlength="160"></div>
      <div class="companyOnly"><label>IČO</label><input name="ico" maxlength="20" inputmode="numeric"></div>
      <div class="companyOnly"><label>DIČ</label><input name="dic" maxlength="20"></div>
      <div><label>Jméno *</label><input name="first_name" maxlength="80" required></div>
      <div><label>Příjmení *</label><input name="last_name" maxlength="80" required></div>
      <div><label>Telefon *</label><input name="phone" type="tel" maxlength="40" required inputmode="tel"></div>
      <div><label>E-mail</label><input name="email" type="email" maxlength="120" inputmode="email"></div>
      <div class="full"><label>Adresa</label><textarea name="address" rows="2" maxlength="300"></textarea></div>
    </div>
    <button id="fSubmit" type="submit">Odeslat</button>
    <div id="fErr"></div>
  </form>
</section>

<section id="vCart" class="view">
  <header>
    <div class="brand">APPLE<span class="fx">⌁</span>FIX</div>
    <div style="color:var(--dim);font-size:1.6vw">Pokladna</div>
  </header>
  <div id="cartRows"></div>
  <div id="cartTotalRow">
    <span class="lbl">Celkem</span>
    <span id="cartTotal">0 Kč</span>
  </div>
</section>

<section id="vDone" class="view">
  <div class="check"><svg viewBox="0 0 24 24"><path d="M4 12.5 10 18 20 6"/></svg></div>
  <div class="big" id="doneTitle">Zaplaceno — děkujeme!</div>
  <div class="amount" id="doneAmount"></div>
  <div class="sub" id="doneSub"></div>
</section>

<script>
(function () {
  var TOKEN = <?php echo json_encode($bootToken); ?>;
  var PREVIEW = <?php echo json_encode($preview); ?>;
  var API = 'api/customer_display.php';
  var stateId = 0, doneTimer = null, formLastKey = 0;

  var views = { idle: vIdle, client_form: vForm, cart: vCart, receipt: vDone };
  function show(name) {
    Object.keys(views).forEach(function (k) { views[k].classList.toggle('on', k === name); });
    if (name !== 'client_form') document.body.style.cursor = 'none';
    else document.body.style.cursor = 'auto';
  }
  function fmt(n) { return (Math.round(n * 100) / 100).toLocaleString('cs-CZ') + ' Kč'; }

  /* ── typ klienta ── */
  function setType(company) {
    clientForm.classList.toggle('isCompany', company);
    segCompany.classList.toggle('on', company);
    segPrivate.classList.toggle('on', !company);
  }
  segPrivate.onclick = function () { setType(false); };
  segCompany.onclick = function () { setType(true); };

  // „klient právě píše" — poslední úhoz v kterémkoli poli (input i textarea)
  formGrid.addEventListener('input', function () { formLastKey = Date.now(); });

  /* ── stavy z payloadu ── */
  function apply(mode, payload) {
    payload = payload || {};
    if (mode === 'client_form') {
      // Opakované poslání formuláře NEmaže, co už klient napsal — resetuje se
      // jen při čerstvém otevření (z reklamy/košíku/účtenky).
      if (!vForm.classList.contains('on')) {
        clientForm.reset(); setType(false); fErr.textContent = ''; fSubmit.disabled = false;
        formLastKey = 0;
      }
    } else if (mode === 'cart') {
      var rows = payload.items || [];
      cartRows.innerHTML = '';
      rows.slice(-12).forEach(function (it) {
        var d = document.createElement('div'); d.className = 'cRow';
        var nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = it.name || '';
        var qt = document.createElement('span'); qt.className = 'qt';
        qt.textContent = (it.qty > 1 ? it.qty + ' ×' : '');
        var pr = document.createElement('span'); pr.className = 'pr'; pr.textContent = fmt(it.line_total || 0);
        d.appendChild(nm); d.appendChild(qt); d.appendChild(pr);
        cartRows.appendChild(d);
      });
      cartTotal.textContent = fmt(payload.total || 0);
    } else if (mode === 'receipt') {
      doneTitle.textContent = 'Zaplaceno — děkujeme!';
      doneAmount.textContent = fmt(payload.total || 0);
      doneSub.textContent = payload.change > 0 ? ('Vráceno: ' + fmt(payload.change)) : '';
      clearTimeout(doneTimer);
      doneTimer = setTimeout(function () { show('idle'); }, 10000);
    }
    show(mode);
  }

  /* ── odeslání formuláře klientem ── */
  clientForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var data = {
      customer_type: clientForm.classList.contains('isCompany') ? 'company' : 'private',
      company_name: clientForm.company_name.value, ico: clientForm.ico.value, dic: clientForm.dic.value,
      first_name: clientForm.first_name.value, last_name: clientForm.last_name.value,
      phone: clientForm.phone.value, email: clientForm.email.value, address: clientForm.address.value
    };
    if (!data.first_name.trim() || !data.last_name.trim() || !data.phone.trim()) {
      fErr.textContent = 'Vyplňte prosím jméno, příjmení a telefon.'; return;
    }
    if (PREVIEW) { doneTitle.textContent = 'Děkujeme!'; doneAmount.textContent = ''; doneSub.textContent = 'Údaje jsme předali obsluze.'; show('receipt'); return; }
    fSubmit.disabled = true;
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                 body: JSON.stringify({ token: TOKEN, action: 'reply', data: data }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { fErr.textContent = 'Odeslání se nepovedlo, zkuste to prosím znovu.'; fSubmit.disabled = false; return; }
        doneTitle.textContent = 'Děkujeme!'; doneAmount.textContent = '';
        doneSub.textContent = 'Údaje jsme předali obsluze.';
        clearTimeout(doneTimer); doneTimer = setTimeout(function () { show('idle'); }, 6000);
        show('receipt');
      })
      .catch(function () { fErr.textContent = 'Chyba spojení — zkuste to prosím znovu.'; fSubmit.disabled = false; });
  });

  /* ── reklamní kolotoč ── */
  var products = [], adIx = 0, adTimer = null;
  function nextAd() {
    if (!products.length) {
      adCard.style.display = 'none';
      document.getElementById('vIdle').classList.remove('adOn');
      return;
    }
    var p = products[adIx % products.length]; adIx++;
    document.getElementById('vIdle').classList.add('adOn');
    adCard.classList.remove('show');
    setTimeout(function () {
      adImg.src = p.image; adTitle.textContent = p.title;
      adMan.textContent = p.manufacturer || '';
      adPrice.textContent = fmt(p.price);
      adCard.style.display = 'flex';
      void adCard.offsetWidth;   // reflow místo requestAnimationFrame — ten v okně
      adCard.classList.add('show');   // na pozadí nejede a karta by zůstala neviditelná
    }, 600);
  }
  function loadProducts() {
    if (PREVIEW) {
      products = [
        { title: 'iPhone 15 Pro 128 GB', manufacturer: 'Apple', price: 21990,
          image: 'https://placehold.co/480x480/101319/2ed0eb?text=iPhone' },
        { title: 'MacBook Air M3 13″', manufacturer: 'Apple', price: 27990,
          image: 'https://placehold.co/480x480/101319/2ed0eb?text=MacBook' }
      ];
      nextAd(); return;
    }
    fetch(API + '?token=' + encodeURIComponent(TOKEN) + '&action=products')
      .then(function (r) { return r.json(); })
      .then(function (d) { products = (d && d.products) || []; nextAd(); })
      .catch(function () {});
  }
  adTimer = setInterval(nextAd, 8000);
  setInterval(loadProducts, 10 * 60 * 1000);
  loadProducts();

  /* ── polling stavu ── */
  function poll() {
    if (PREVIEW) return;
    fetch(API + '?token=' + encodeURIComponent(TOKEN) + '&action=state&after=' + stateId)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) return;
        if (d.state_id > stateId && d.mode) {
          // Neshazovat rozepsaný formulář: pokud klient v posledních 20 s
          // něco napsal, jiný režim se NEpotvrdí (stateId se neposune) —
          // další poll to zkusí znovu a stav se ukáže, jakmile klient dopíše.
          var typing = vForm.classList.contains('on') && (Date.now() - formLastKey < 20000);
          if (!(typing && d.mode !== 'client_form')) {
            stateId = d.state_id;
            apply(d.mode, d.payload);
          }
        }
      })
      .catch(function () {});
  }
  setInterval(poll, 1500);
  poll();

  if (PREVIEW && views[PREVIEW]) {
    if (PREVIEW === 'cart') apply('cart', { items: [
      { name: 'Výměna displeje iPhone 13', qty: 1, line_total: 2990 },
      { name: 'Tvrzené sklo 3D', qty: 2, line_total: 598 },
      { name: 'Kryt MagSafe průhledný', qty: 1, line_total: 449 } ], total: 4037 });
    else if (PREVIEW === 'receipt') apply('receipt', { total: 4037, change: 963 });
    else apply(PREVIEW, {});
  }
})();
</script>
</body>
</html>
