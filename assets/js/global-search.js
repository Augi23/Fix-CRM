/* ═══════════════════════════════════════════════════════════════════════════
   GLOBÁLNÍ VYHLEDÁVÁNÍ — „Spotlight" v horní liště (v3.90.0)
   Výsledky už při psaní (api/global_search.php), seskupené podle typu,
   ovládání šipkami / Enter / Esc, ⌘K (Ctrl+K) kdykoli otevře pole.
   Enter bez vybrané položky → stránka všech výsledků (search.php).
   V Účetnictví (data-scope="accounting") hledá pole jen v účetnictví.
   Oprava skenů z čtečky (main.js, view_order.php?scan=) zůstává nedotčená.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    /* zvýraznění shody bez ohledu na diakritiku a velikost písmen */
    function fold(s) {
        return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }
    function highlight(text, tokens) {
        text = String(text == null ? '' : text);
        if (!tokens.length) { return esc(text); }
        var f = fold(text), marks = [];
        tokens.forEach(function (t) {
            if (t.length < 2) { return; }
            var i = f.indexOf(t);
            while (i !== -1) { marks.push([i, i + t.length]); i = f.indexOf(t, i + t.length); }
        });
        if (!marks.length) { return esc(text); }
        marks.sort(function (a, b) { return a[0] - b[0]; });
        var out = '', pos = 0;
        marks.forEach(function (m) {
            if (m[0] < pos) { return; }
            out += esc(text.slice(pos, m[0])) + '<mark>' + esc(text.slice(m[0], m[1])) + '</mark>';
            pos = m[1];
        });
        return out + esc(text.slice(pos));
    }

    /* naposledy hledané (jen v tomto prohlížeči) — ukážou se po kliknutí do prázdného pole */
    var RECENT_KEY = 'afx_recent_search';
    function recentGet() {
        try { var a = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(a) ? a.slice(0, 8) : []; }
        catch (e) { return []; }
    }
    function recentAdd(q) {
        q = String(q || '').trim();
        if (q.length < 2 || q.length > 80) { return; }
        try {
            var a = recentGet().filter(function (x) { return x.toLowerCase() !== q.toLowerCase(); });
            a.unshift(q);
            localStorage.setItem(RECENT_KEY, JSON.stringify(a.slice(0, 8)));
        } catch (e) {}
    }

    function init() {
        // horní lišta (PC) i výsuvný panel (mobil) — stejný našeptávač
        document.querySelectorAll('form.crm-navbar-search, form.afx-sheet-search').forEach(initForm);
        /* ⌘K / Ctrl+K kdykoli zaostří pole */
        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
                var inp = document.querySelector('form.crm-navbar-search input[name="search"]');
                if (!inp || !inp.offsetParent) { return; }
                e.preventDefault();
                inp.focus();
                inp.select();
            }
        });
    }

    function initForm(form) {
        var input = form.querySelector('input[name="search"]');
        if (!input || input.__afxSpot) { return; }
        input.__afxSpot = true;
        if (form.classList.contains('afx-sheet-search')) { form.style.position = 'relative'; }
        var scope = form.getAttribute('data-scope')
            || (form.querySelector('input[name="scope"][value="accounting"]') ? 'accounting' : 'all');
        var panel = document.createElement('div');
        panel.className = 'afx-spot';
        panel.hidden = true;
        panel.setAttribute('role', 'listbox');
        form.appendChild(panel);

        var ctl = null, timer = null, items = [], sel = -1, lastQ = null, lastData = null;

        function close() { panel.hidden = true; sel = -1; }
        function open() { if (panel.innerHTML) { panel.hidden = false; } }

        function render(data, q) {
            lastData = data;
            var tokens = (data.tokens || []).map(fold);
            var h = '';
            if (data.suggestion) {
                h += '<div class="afx-spot-dym">Měli jste na mysli <a href="#" data-dym="' + esc(data.suggestion) + '">' + esc(data.suggestion) + '</a>?'
                   + (data.mode === 'suggestion' ? ' <span>Zobrazuji výsledky pro opravený dotaz.</span>' : '') + '</div>';
            }
            if (data.mode === 'similar') {
                h += '<div class="afx-spot-dym">Přesná shoda nenalezena — <span>zobrazuji podobné výsledky.</span></div>';
            }
            items = [];
            (data.groups || []).forEach(function (g) {
                h += '<div class="afx-spot-group"><div class="afx-spot-gh"><i class="fas ' + esc(g.icon) + '"></i>' + esc(g.label)
                   + '<span class="afx-spot-count">' + (g.total > g.items.length ? g.items.length + ' z ' + g.total : g.total) + '</span>'
                   + (g.more_url && g.total > g.items.length ? '<a class="afx-spot-more" href="' + esc(g.more_url) + '">Zobrazit vše</a>' : '') + '</div>';
                g.items.forEach(function (it) {
                    var idx = items.length;
                    items.push(it);
                    h += '<a class="afx-spot-item" role="option" data-idx="' + idx + '" href="' + esc(it.url) + '">'
                       + '<span class="afx-spot-ic" style="--c:' + esc(g.color || '#8e8e93') + '"><i class="fas ' + esc(it.icon || g.icon) + '"></i></span>'
                       + '<span class="afx-spot-txt"><span class="afx-spot-t">' + highlight(it.title, tokens) + '</span>'
                       + (it.subtitle ? '<span class="afx-spot-s">' + highlight(it.subtitle, tokens) + '</span>' : '') + '</span>'
                       + (it.meta ? '<span class="afx-spot-m">' + esc(it.meta) + '</span>' : '')
                       + '</a>';
                });
                h += '</div>';
            });
            if (!items.length) {
                h += '<div class="afx-spot-empty"><i class="fas fa-magnifying-glass"></i>Nic nenalezeno pro „' + esc(q) + '"</div>';
            }
            if (q.length >= 2) {
                h += '<a class="afx-spot-foot" href="search.php?search=' + encodeURIComponent(q) + (scope === 'accounting' ? '&scope=accounting' : '') + '"><span>Všechny výsledky' + (data.total ? ' (' + data.total + ')' : '') + '</span>'
                   + '<span class="afx-spot-keys"><kbd>↑</kbd><kbd>↓</kbd> vybrat · <kbd>↵</kbd> otevřít · <kbd>esc</kbd> zavřít</span></a>';
            }
            panel.innerHTML = h;
            sel = -1;
            open();
        }

        function query(q) {
            if (ctl) { ctl.abort(); }
            ctl = new AbortController();
            fetch('api/global_search.php?q=' + encodeURIComponent(q) + '&scope=' + encodeURIComponent(scope),
                  { credentials: 'same-origin', signal: ctl.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.ok && input.value.trim() === q) { render(d, q); } })
                .catch(function () {});
        }

        function showRecent() {
            var list = recentGet();
            if (!list.length) { panel.innerHTML = ''; close(); return; }
            items = [];
            var h = '<div class="afx-spot-group"><div class="afx-spot-gh"><i class="fas fa-clock-rotate-left"></i>Naposledy hledané'
                  + '<a class="afx-spot-more" href="#" data-recent-clear="1">Vymazat</a></div>';
            list.forEach(function (q) {
                var idx = items.length;
                items.push({ url: 'search.php?search=' + encodeURIComponent(q) + (scope === 'accounting' ? '&scope=accounting' : ''), recent: q });
                h += '<a class="afx-spot-item" role="option" data-idx="' + idx + '" data-recent="' + esc(q) + '" href="#">'
                   + '<span class="afx-spot-ic" style="--c:#8e8e93"><i class="fas fa-magnifying-glass"></i></span>'
                   + '<span class="afx-spot-txt"><span class="afx-spot-t">' + esc(q) + '</span></span></a>';
            });
            panel.innerHTML = h + '</div>';
            sel = -1;
            open();
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 2) { lastQ = null; if (q === '') { showRecent(); } else { panel.innerHTML = ''; close(); } return; }
            if (q === lastQ) { return; }
            timer = setTimeout(function () { lastQ = q; query(q); }, 140);
        });
        input.addEventListener('focus', function () {
            if (input.value.trim() === '') { showRecent(); }
            else if (input.value.trim().length >= 2 && panel.innerHTML) { open(); }
        });

        function move(d) {
            if (!items.length || panel.hidden) { return; }
            sel = (sel + d + items.length) % items.length;
            panel.querySelectorAll('.afx-spot-item').forEach(function (a) {
                var on = +a.getAttribute('data-idx') === sel;
                a.classList.toggle('is-sel', on);
                if (on) { a.scrollIntoView({ block: 'nearest' }); }
            });
        }
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            else if (e.key === 'Escape') { close(); input.blur(); }
            else if (e.key === 'Enter' && sel >= 0 && items[sel] && !panel.hidden) {
                e.preventDefault();
                if (items[sel].recent) { input.value = items[sel].recent; input.dispatchEvent(new Event('input')); return; }
                recentAdd(input.value);
                window.location.href = items[sel].url;
            }
        });

        panel.addEventListener('click', function (e) {
            if (e.target.closest('[data-recent-clear]')) {
                e.preventDefault();
                try { localStorage.removeItem(RECENT_KEY); } catch (er) {}
                panel.innerHTML = ''; close(); return;
            }
            var r = e.target.closest('[data-recent]');
            if (r) {
                e.preventDefault();
                input.value = r.getAttribute('data-recent');
                input.dispatchEvent(new Event('input'));
                input.focus();
                return;
            }
            // klik na výsledek / „Zobrazit vše" → zapamatovat dotaz
            if (e.target.closest('a.afx-spot-item, a.afx-spot-more, a.afx-spot-foot')) { recentAdd(input.value); }
            var a = e.target.closest('[data-dym]');
            if (a) {
                e.preventDefault();
                input.value = a.getAttribute('data-dym');
                input.dispatchEvent(new Event('input'));
                input.focus();
            }
        });
        panel.addEventListener('mousedown', function (e) { e.preventDefault(); });   // klik nesebere fokus poli
        document.addEventListener('click', function (e) { if (!form.contains(e.target)) { close(); } });

        /* Enter bez výběru → všechny výsledky (search.php; v Účetnictví se skrytým scope=accounting).
           name="search" zůstává — váže se na něj oprava skenů z čtečky (main.js). */
        form.setAttribute('action', 'search.php');
        form.addEventListener('submit', function () { recentAdd(input.value); });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
