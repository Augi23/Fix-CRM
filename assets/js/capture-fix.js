/* ═══════════════════════════════════════════════════════════════════════════
   Fotky na Macu — odstranění atributu capture

   Na telefonu je `capture="environment"` žádoucí: klepnutí na „+" otevře rovnou
   foťák. Na Macu ale žádný takový foťák není a v aplikaci „Designed for iPad"
   (AppleFix CRM na macOS) se pokus o otevření fotoaparátu z file inputu chová
   fatálně — appka spadne (známá vada iOS aplikací běžících na macOS, viz Apple
   Developer Forums 743011 / 748448). Bez `capture` se korektně otevře výběr
   souborů.

   Rozpoznání Macu: iPad i Mac hlásí v user agentu „Macintosh", liší se ale
   počtem dotykových bodů (iPad 5, Mac 0). Na desktopu je `capture` stejně bez
   efektu, takže odstranění nic nerozbije.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    var ua = navigator.userAgent || '';
    var isMac = /Macintosh|Mac OS X/.test(ua) && (navigator.maxTouchPoints || 0) <= 1;
    if (!isMac) return;

    // Konkrétní přípony místo image/* — WebKit pak otevře rovnou výběr souborů
    // (a ne nabídku Fotky / Vyfotit, kde volba foťáku appku na Macu shodí).
    var EXTS = '.jpg,.jpeg,.png,.heic,.heif,.webp';

    function strip(root) {
        var nodes = (root && root.querySelectorAll)
            ? root.querySelectorAll('input[type="file"][capture], input[type="file"][accept="image/*"]')
            : [];
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].removeAttribute('capture');
            if ((nodes[i].getAttribute('accept') || '') === 'image/*') {
                nodes[i].setAttribute('accept', EXTS);
            }
        }
    }

    function run() {
        strip(document);
        // Formuláře a modaly se dokreslují až za běhu (nová reklamace, doklady…)
        if (window.MutationObserver) {
            new MutationObserver(function () { strip(document); })
                .observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
}());
