/* ═══════════════════════════════════════════════════════════════════════════
   ŘAZENÍ TABULEK KLIKEM NA ZÁHLAVÍ (v4.6.0, požadavek majitele 27. 11. 2026)
   Platí pro KAŽDOU tabulku v CRM s <thead> a <tbody>: klik na záhlaví seřadí
   vzestupně (▲), druhý klik sestupně (▼), třetí vrátí původní pořadí.

   Dva režimy:
   · SERVER (tabulka má data-sort-url="1", záhlaví data-sort-key): klik jen
     přejde na stejnou adresu s ?sort=klíč&dir=asc|desc (stránkované seznamy —
     řadí se celá databáze, ne jen zobrazená stránka). Aktivní sloupec a směr
     říká tabulka přes data-sort-key / data-sort-dir.
   · KLIENT (vše ostatní): řadí vykreslené řádky v prohlížeči. Rozpozná číslo
     („1 500 Kč", „12,5 %"), české datum (28.10.2026 [10:30]) i text; buňka může
     nabídnout přesnou hodnotu v data-sort. Řádky-oddělovače (jedna buňka přes
     víc sloupců, např. název pobočky) zůstávají na místě, řadí se jen úseky
     mezi nimi. Volba se pamatuje v localStorage pro danou stránku a tabulku.

   Vypnutí: <table data-nosort> nebo <th data-nosort> (prázdná záhlaví, akce,
   zaškrtávátka se vynechávají samy).
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    var STORE_PREFIX = 'afxTableSort:';

    function cellText(td) {
        if (!td) return '';
        if (td.dataset && td.dataset.sort !== undefined) return td.dataset.sort;
        var inp = td.querySelector('select, input:not([type=checkbox]):not([type=hidden])');
        if (inp) {
            if (inp.tagName === 'SELECT') return (inp.options[inp.selectedIndex] || {}).text || '';
            return inp.value || '';
        }
        return (td.textContent || '').replace(/\s+/g, ' ').trim();
    }
    // "1 500 Kč" / "−1 500" / "12,5 %" / "+100" → číslo; jinak NaN
    function asNumber(s) {
        if (s === '') return NaN;
        var t = s.replace(/[\s ]/g, '').replace(/Kč|CZK|%|ks|×/gi, '').replace('−', '-').replace(',', '.');
        if (!/^[+-]?\d+(\.\d+)?$/.test(t)) return NaN;
        return parseFloat(t);
    }
    // 28.10.2026, 28. 10. 2026, 28.10.2026 10:30, 2026-10-28, 2026-10-28 10:30 → čas
    function asDate(s) {
        var m = s.match(/^(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/);
        if (m) return new Date(+m[3], +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0)).getTime();
        m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (m) return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)).getTime();
        // „28.10. 10:30" bez roku (dnešní pohyby, feed) → letošní rok
        m = s.match(/^(\d{1,2})\.\s?(\d{1,2})\.(?:\s+(\d{1,2}):(\d{2}))?$/);
        if (m) return new Date(new Date().getFullYear(), +m[2] - 1, +m[1], +(m[3] || 0), +(m[4] || 0)).getTime();
        return NaN;
    }
    var collator = new Intl.Collator('cs', { numeric: true, sensitivity: 'base' });
    function compare(a, b) {
        if (a === '' && b !== '') return 1;    // prázdné vždy na konec
        if (b === '' && a !== '') return -1;
        var na = asNumber(a), nb = asNumber(b);
        if (!isNaN(na) && !isNaN(nb)) return na - nb;
        var da = asDate(a), db = asDate(b);
        if (!isNaN(da) && !isNaN(db)) return da - db;
        return collator.compare(a, b);
    }
    function isGroupRow(tr, cols) {
        var cells = tr.children;
        if (cells.length === 0) return true;
        if (cells.length === 1 && (parseInt(cells[0].getAttribute('colspan') || '1', 10) > 1)) return true;
        return cells.length < Math.max(2, Math.floor(cols / 2));
    }
    function headerSortable(th) {
        if (th.hasAttribute('data-nosort')) return false;
        if (th.querySelector('input, button')) return false;
        var txt = (th.textContent || '').trim();
        return txt.length > 0;
    }

    function setupTable(table, idx) {
        var thead = table.tHead;
        var tbody = table.tBodies[0];
        if (!thead || !tbody) return;
        var headRow = thead.rows[thead.rows.length - 1];
        if (!headRow) return;
        var ths = Array.prototype.slice.call(headRow.cells);
        var serverMode = table.getAttribute('data-sort-url') === '1';
        var storeKey = STORE_PREFIX + location.pathname + '#' + idx;

        // původní pořadí (pro třetí klik = zrušit řazení)
        var originalRows = Array.prototype.slice.call(tbody.rows);

        function render(activeIdx, dir) {
            ths.forEach(function (th, i) {
                var arrow = th.querySelector('.ts-arrow');
                if (!arrow) return;
                th.classList.toggle('ts-active', i === activeIdx && !!dir);
                arrow.textContent = (i === activeIdx && dir) ? (dir === 'asc' ? '▲' : '▼') : '⇅';
                th.setAttribute('aria-sort', (i === activeIdx && dir) ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
            });
        }

        function applyClient(colIdx, dir) {
            if (!dir) {
                originalRows.forEach(function (r) { tbody.appendChild(r); });
                render(-1, null);
                return;
            }
            var cols = ths.length;
            var rows = Array.prototype.slice.call(tbody.rows);
            var out = [], segment = [];
            function flush() {
                if (!segment.length) return;
                var keyed = segment.map(function (tr, i) { return { tr: tr, i: i, v: cellText(tr.cells[colIdx]) }; });
                keyed.sort(function (a, b) {
                    var c = compare(a.v, b.v);
                    if (c === 0) return a.i - b.i;
                    return dir === 'asc' ? c : -c;
                });
                keyed.forEach(function (k) { out.push(k.tr); });
                segment = [];
            }
            rows.forEach(function (tr) {
                if (isGroupRow(tr, cols)) { flush(); out.push(tr); } else { segment.push(tr); }
            });
            flush();
            out.forEach(function (r) { tbody.appendChild(r); });
            render(colIdx, dir);
        }

        ths.forEach(function (th, i) {
            if (!headerSortable(th)) return;
            if (serverMode && !th.hasAttribute('data-sort-key')) return;
            th.classList.add('ts-th');
            th.setAttribute('tabindex', '0');
            th.setAttribute('role', 'button');
            th.title = th.title || 'Seřadit podle tohoto sloupce (znovu = opačně)';
            var arrow = document.createElement('span');
            arrow.className = 'ts-arrow';
            arrow.textContent = '⇅';
            th.appendChild(arrow);

            function activate() {
                if (serverMode) {
                    var key = th.getAttribute('data-sort-key');
                    var curKey = table.getAttribute('data-sort-key') || '';
                    var curDir = table.getAttribute('data-sort-dir') || '';
                    var nextDir = (curKey === key && curDir === 'asc') ? 'desc' : 'asc';
                    var u = new URL(location.href);
                    u.searchParams.set('sort', key);
                    u.searchParams.set('dir', nextDir);
                    ['p', 'page', 'strana'].forEach(function (p) { u.searchParams.delete(p); });   // zpět na 1. stránku
                    location.href = u.toString();
                    return;
                }
                var state = getState();
                var nextDir2 = (state.col === i) ? (state.dir === 'asc' ? 'desc' : (state.dir === 'desc' ? null : 'asc')) : 'asc';
                saveState(nextDir2 ? { col: i, dir: nextDir2 } : null);
                applyClient(i, nextDir2);
            }
            th.addEventListener('click', function (ev) {
                if (ev.target.closest('a, button, input, select, label')) return;   // odkazy v záhlaví nechat být
                activate();
            });
            th.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); activate(); } });
        });

        function getState() {
            try { return JSON.parse(localStorage.getItem(storeKey) || 'null') || {}; } catch (e) { return {}; }
        }
        function saveState(st) {
            try { if (st) localStorage.setItem(storeKey, JSON.stringify(st)); else localStorage.removeItem(storeKey); } catch (e) {}
        }

        if (serverMode) {
            var k = table.getAttribute('data-sort-key'), d = table.getAttribute('data-sort-dir');
            var ai = -1;
            ths.forEach(function (th, i) { if (th.getAttribute('data-sort-key') === k) ai = i; });
            render(ai, d === 'asc' || d === 'desc' ? d : null);
        } else {
            var st = getState();
            if (st && typeof st.col === 'number' && ths[st.col] && ths[st.col].classList.contains('ts-th')) {
                applyClient(st.col, st.dir);
            } else {
                render(-1, null);
            }
        }
    }

    function init() {
        var tables = document.querySelectorAll('table');
        var n = 0;
        Array.prototype.forEach.call(tables, function (t) {
            if (t.hasAttribute('data-nosort') || t.closest('[data-nosort], .modal, .afx-print, .no-sort')) return;
            if (!t.tHead || !t.tBodies[0]) return;
            var rows = t.tBodies[0].rows.length;
            if (t.getAttribute('data-sort-url') !== '1' && rows < 2) return;
            if (t.getAttribute('data-ts-ready') === '1') return;
            t.setAttribute('data-ts-ready', '1');
            setupTable(t, n++);
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
    window.afxTableSortInit = init;   // pro tabulky vykreslené dodatečně (AJAX)
})();
