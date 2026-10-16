/* ═══════════════════════════════════════════════════════════════════════════
   PLATBA PŘEDEM hned po založení zakázky (v3.94.1)
   Okno „Zakázkový list" (openOrderDocChoice v main.js) dostane tlačítko
   „Zaplatit předem v Pokladně" → pokladna.php?order_id=… zakázku rovnou vyhledá.
   U reklamací (stejné okno) se tlačítko schová.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    function btn() { return document.getElementById('orderDocPayBtn'); }
    function wrap(name, show) {
        var orig = window[name];
        if (typeof orig !== 'function' || orig.__afxPrepay) { return; }
        var w = function (id) {
            var r = orig.apply(this, arguments);
            var b = btn();
            if (b) {
                if (show && id) { b.href = 'pokladna.php?order_id=' + encodeURIComponent(id); b.style.display = ''; }
                else { b.style.display = 'none'; }
            }
            return r;
        };
        w.__afxPrepay = true;
        window[name] = w;
    }
    function init() {
        wrap('openOrderDocChoice', true);
        wrap('openComplaintDocChoice', false);
    }
    // main.js definuje funkce v <head>; po založení je volá se zpožděním 400 ms
    init();
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
})();
