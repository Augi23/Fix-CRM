<?php
/* Interní týmový chat — jedna společná místnost pro všechny zaměstnance.
   Zprávy se ukládají do staff_chat; nové hlásí poller zvukem po celém CRM. */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

ensureStaffChatTable();
$__me = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''));
?>

<div class="container-fluid" style="max-width: 900px;">
    <h4 class="mb-2 text-white"><i class="fas fa-comments me-2 text-info"></i>Týmový chat</h4>

    <?php /* dvh: na iOS Safari je 100vh větší než viditelný viewport (adresní řádek)
             → vstup chatu by byl schovaný; dvh sleduje skutečnou výšku.
             Žádný panel „okno v okně" — bubliny leží přímo na stránce a dole je
             jen plovoucí pole pro psaní (messenger styl, přání Jana 9. 8.). */ ?>
    <?php /* Výšku dopočítává JS z reálného viewportu (afxSizeChat níž) — pevné
             odečty typu „100dvh − 290px" na mobilu neseděly (jiná výška lišty,
             nativní dock v appce, vysunutá klávesnice) a chat pak přetékal
             mimo obrazovku: rolovala se hned dvě věci najednou. */ ?>
    <div class="d-flex flex-column afx-chat-box">
        <div id="chatMessages" class="flex-grow-1 overflow-auto d-flex flex-column gap-2"></div>
        <div id="chatPending" class="afx-chat-pending" hidden></div>
        <form id="chatForm" class="afx-chat-inputbar" autocomplete="off">
            <div id="chatMentionMenu" class="afx-mention-menu" hidden></div>
            <label class="afx-attach-btn" for="chatFiles" title="Přiložit fotku, video nebo dokument" aria-label="Přiložit soubor"><i class="fas fa-paperclip"></i></label>
            <input type="file" id="chatFiles" multiple hidden accept="image/*,video/mp4,video/quicktime,audio/*,.pdf,.txt,.csv,.zip,.doc,.docx,.xls,.xlsx,.pptx">
            <input type="text" id="chatInput" placeholder="Napiš zprávu týmu… (@ označí kolegu)" maxlength="2000" autofocus>
            <button type="submit" id="chatSendBtn" aria-label="Odeslat zprávu"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>
</div>

<style>
/* Chat bez panelu: průhledné pozadí, žádný rámeček — jen bubliny na stránce. */
.afx-chat-box { background: transparent !important; border: 0 !important; box-shadow: none !important; border-radius: 0 !important; }
/* Fallback, než se JS dopočte (a když by selhal): rozumná výška z viewportu. */
.afx-chat-box { height: calc(100vh - 240px); height: calc(100dvh - 240px); min-height: 260px; }
#chatMessages {
  padding: 6px 2px;
  /* Bez tohohle se dojezd scrollu „přelije" na stránku pod chatem a působí to,
     jako by se posouvala jen část obsahu (hlášeno na iPhonu i Androidu). */
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}
/* Android WebView jinak zvětšuje text podle systémové velikosti písma a bubliny
   pak přetékají; velikost si řídí appka (textZoom) i tahle pojistka. */
.afx-chat-box, .afx-chat-box * { -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
/* Stránka chatu NIKDY neroluje — na žádném zařízení. Roluje se výhradně
   historie zpráv uvnitř. Dřív platilo jen pro mobil (≤1080 px) a na desktopu
   šla stránka pořád „popotáhnout" o výšku horní lišty; teď globálně:
   1) žádná spodní rezerva, 2) obsah si nevynucuje min-height přes viewport,
   3) body má scroll zamčený úplně (výšku chatu dopočítává afxSizeChat). */
.crm-main-content { padding-bottom: 0 !important; }
#content, #content.crm-v2-content { min-height: 0 !important; }
html, body { height: 100%; overflow: hidden !important; }

/* Vybrané soubory před odesláním — chipy nad psací lištou */
.afx-chat-pending { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 2px 0; }
.afx-chat-chip {
  display: inline-flex; align-items: center; gap: 7px; max-width: 260px;
  font-size: .78rem; color: #fff; background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.14); border-radius: 14px; padding: 4px 8px 4px 10px;
}
.afx-chat-chip .nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.afx-chat-chip .sz { color: rgba(255,255,255,.45); flex: 0 0 auto; }
.afx-chat-chip button { border: 0; background: transparent; color: rgba(255,255,255,.55); padding: 0 2px; line-height: 1; }
.afx-chat-chip button:hover { color: #ff6b6b; }

/* Sponka vedle vstupu */
.afx-attach-btn {
  flex: 0 0 auto; width: 42px; height: 42px; border-radius: 50%; cursor: pointer; margin: 0;
  display: flex; align-items: center; justify-content: center; font-size: 15px;
  color: rgba(255,255,255,.75); background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.14);
}
.afx-attach-btn:hover { color: #fff; border-color: rgba(10,132,255,.65); }

/* Přílohy ve zprávě */
.chat-att { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.chat-msg.mine .chat-att { justify-content: flex-end; }
.chat-att img { max-width: 240px; max-height: 240px; border-radius: 12px; display: block; cursor: zoom-in; }
.chat-att video { max-width: 280px; max-height: 240px; border-radius: 12px; display: block; background: #000; }
.chat-att audio { max-width: 260px; display: block; }
.chat-att-doc {
  display: inline-flex; align-items: center; gap: 8px; max-width: 280px; text-decoration: none;
  font-size: .82rem; color: #fff; background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.14); border-radius: 12px; padding: 8px 12px;
}
.chat-att-doc:hover { color: #fff; border-color: rgba(10,132,255,.65); background: rgba(10,132,255,.15); }
.chat-att-doc .nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-att-doc .sz { color: rgba(255,255,255,.5); flex: 0 0 auto; font-size: .72rem; }
.chat-att-doc i { color: rgba(140,200,255,.9); flex: 0 0 auto; }

/* Číslo zakázky / reklamace v textu = klikací odkaz na detail */
.chat-code-link {
  color: #8CC8FF; font-weight: 700; text-decoration: none;
  border-bottom: 1px dashed rgba(140,200,255,.7); padding-bottom: 1px;
  font-family: ui-monospace, Menlo, Consolas, monospace; letter-spacing: .01em;
}
.chat-code-link:hover, .chat-code-link:focus { color: #fff; border-bottom-style: solid; background: rgba(10,132,255,.18); border-radius: 4px; }
.chat-msg.mine .bubble .chat-code-link { color: #fff; border-bottom-color: rgba(255,255,255,.7); }
.chat-msg.mine .bubble .chat-code-link:hover { background: rgba(255,255,255,.22); }

/* @zmínky: zvýraznění ve zprávě + „mluví se o mně" celá bublina */
.mention { color: #8CC8FF; font-weight: 700; background: rgba(10,132,255,.16); border-radius: 6px; padding: 0 4px; }
.chat-msg.mine .bubble .mention { color: #fff; background: rgba(255,255,255,.22); }
.mention.me { color: #FFD262; background: rgba(255,171,0,.18); }
.chat-msg.mentions-me .bubble { border: 1px solid rgba(255,171,0,.55); box-shadow: 0 0 0 2px rgba(255,171,0,.14); }

/* Našeptávač @zmínek nad psací lištou */
.afx-chat-inputbar { position: relative; }
.afx-mention-menu {
  position: absolute; bottom: calc(100% + 6px); left: 52px; right: 52px; max-width: 340px; z-index: 50;
  background: rgba(24,26,32,.97); border: 1px solid rgba(255,255,255,.14); border-radius: 12px;
  box-shadow: 0 12px 32px rgba(0,0,0,.5); overflow: hidden;
  backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
}
.afx-mention-menu .it { display: flex; align-items: center; gap: 8px; padding: 9px 13px; color: #fff; font-size: .88rem; cursor: pointer; }
.afx-mention-menu .it i { color: rgba(140,200,255,.85); font-size: .8rem; }
.afx-mention-menu .it.active, .afx-mention-menu .it:hover { background: rgba(10,132,255,.25); }
/* Plovoucí psací lišta — pilulka + kulaté odesílací tlačítko (messenger styl). */
.afx-chat-inputbar { display: flex; gap: 8px; align-items: center; padding: 10px 2px 4px; }
.afx-chat-inputbar input {
  flex: 1; min-width: 0; color: #fff; font-size: .95rem;
  background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.14);
  border-radius: 22px; padding: 10px 16px; outline: none;
  backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
}
.afx-chat-inputbar input::placeholder { color: rgba(255,255,255,.45); }
.afx-chat-inputbar input:focus { border-color: rgba(10,132,255,.65); box-shadow: 0 0 0 3px rgba(10,132,255,.18); }
.afx-chat-inputbar button {
  flex: 0 0 auto; width: 42px; height: 42px; border: 0; border-radius: 50%; color: #fff;
  background: linear-gradient(180deg, #52A0FF, #0A6BFF); box-shadow: 0 4px 14px rgba(10,107,255,.4);
  display: flex; align-items: center; justify-content: center; font-size: 15px;
}
.afx-chat-inputbar button:active { transform: scale(.94); }
.chat-msg { max-width: 72%; }
/* Něco globálního nastavuje kontejneru flex-wrap:wrap — sloupec se pak na mobilu
   zalamoval do dalších sloupců DOPRAVA (vodorovný chat). Nowrap = klasická svislá
   historie; přetečení řeší overflow-auto na výšku. */
#chatMessages { flex-wrap: nowrap !important; }
.chat-msg .bubble { padding: 8px 14px; border-radius: 16px; font-size: .95rem; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
.chat-msg.other { align-self: flex-start; }
.chat-msg.other .bubble { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.10); color: #fff; border-bottom-left-radius: 5px; }
.chat-msg.mine { align-self: flex-end; text-align: right; }
.chat-msg.mine .bubble { background: rgba(10,132,255,.85); color: #fff; border-bottom-right-radius: 5px; text-align: left; }
.chat-msg .meta { display: flex; align-items: center; gap: 5px; font-size: .74rem; color: rgba(255,255,255,.5); margin: 3px 6px 0; }
.chat-msg.mine .meta { justify-content: flex-end; }
.chat-delete-btn { border: 0; padding: 1px 4px; background: transparent; color: rgba(255,255,255,.42); line-height: 1; border-radius: 5px; }
.chat-delete-btn:hover, .chat-delete-btn:focus { color: #ff6b6b; background: rgba(255,59,48,.12); outline: none; }
.chat-msg .who { font-weight: 700; color: rgba(140,200,255,.95); }
.chat-msg.mine .who { color: rgba(255,255,255,.75); }
.chat-day { align-self: center; font-size: .72rem; color: rgba(255,255,255,.45); background: rgba(255,255,255,.06); border-radius: 10px; padding: 2px 12px; margin: 6px 0; }
</style>

<script>
(function () {
    var box = document.getElementById('chatMessages');
    var form = document.getElementById('chatForm');
    var input = document.getElementById('chatInput');
    var lastId = 0, lastDay = '', firstLoad = true;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var chatBox = document.querySelector('.afx-chat-box');
    var sendBtn = document.getElementById('chatSendBtn');
    var filesInput = document.getElementById('chatFiles');
    var pendingBox = document.getElementById('chatPending');
    var mentionMenu = document.getElementById('chatMentionMenu');
    var ME = <?php echo json_encode($__me, JSON_UNESCAPED_UNICODE); ?>;
    var FILE_CAP = 25 * 1024 * 1024;   // musí sedět s AFX_CHAT_FILE_CAP na serveru
    var pending = [];                  // vybrané soubory před odesláním
    var members = [];                  // jména zaměstnanců pro @zmínky
    var membersByLen = [];             // nejdelší dřív — greedy match víceslovných jmen
    var mIndex = -1, mItems = [], mQueryStart = -1;

    function fold(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
    function fmtSize(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
        if (b >= 1024) return Math.round(b / 1024) + ' kB';
        return b + ' B';
    }

    /* ── Výška chatu podle SKUTEČNÉHO viewportu ───────────────────────────────
       Pevné odečty nefungovaly: na iPhonu ubírá adresní řádek, v appce překrývá
       spodek nativní dock, na Androidu se při psaní vysune klávesnice. Když je
       box vyšší než obrazovka, rolují se dvě věci naráz a působí to, že „jede
       jen část textu". Měříme proto pokaždé znovu, včetně visualViewportu
       (ten jediný ví o klávesnici).                                            */
    function chatBottomGap() {
        var tab = document.querySelector('nav.afx-tabbar');
        if (tab) {
            var r = tab.getBoundingClientRect();
            if (r.height > 0) return r.height + 8;   // web/Android: spodní lišta CRM
        }
        // appka skrývá webovou lištu a spodek překrývá nativním dockem
        if (window.innerWidth <= 1080) return 96;
        return 8;
    }

    function afxSizeChat() {
        if (!chatBox) return;
        var vv = window.visualViewport;
        var vh = vv ? vv.height : window.innerHeight;
        var off = vv ? vv.offsetTop : 0;
        var top = chatBox.getBoundingClientRect().top - off;
        var h = Math.max(240, Math.round(vh - top - chatBottomGap()));
        if (chatBox.style.height !== h + 'px') { chatBox.style.height = h + 'px'; }
    }

    afxSizeChat();
    window.addEventListener('resize', afxSizeChat);
    window.addEventListener('orientationchange', function () { setTimeout(afxSizeChat, 250); });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', afxSizeChat);
        window.visualViewport.addEventListener('scroll', afxSizeChat);
    }
    // po vysunutí klávesnice dorovnat, až doběhne animace
    if (input) {
        input.addEventListener('focus', function () { setTimeout(afxSizeChat, 300); });
        input.addEventListener('blur', function () { setTimeout(afxSizeChat, 300); });
    }

    /* Čísla zakázek (APFAZ + 7 číslic) a reklamací (RK-NNN) v textu → klikací odkaz
       rovnou na detail. Zakázka jde přes ?scan= (resolver order_code/legacy), reklamace
       přes ?code=. Zase jen DOM uzly, žádné innerHTML. */
    var CODE_RE = /\b(APFAZ\d{7}|RK-\d{3,})\b/gi;
    function appendLinkified(el, seg) {
        var last = 0, m;
        CODE_RE.lastIndex = 0;
        while ((m = CODE_RE.exec(seg)) !== null) {
            if (m.index > last) el.appendChild(document.createTextNode(seg.slice(last, m.index)));
            var code = m[0].toUpperCase();
            var a = document.createElement('a');
            a.className = 'chat-code-link';
            if (code.indexOf('RK-') === 0) {
                a.href = 'view_complaint.php?code=' + encodeURIComponent(code);
                a.title = 'Otevřít reklamaci ' + code;
            } else {
                a.href = 'view_order.php?scan=' + encodeURIComponent(code);
                a.title = 'Otevřít zakázku ' + code;
            }
            a.textContent = m[0];
            el.appendChild(a);
            last = m.index + m[0].length;
        }
        if (last < seg.length) el.appendChild(document.createTextNode(seg.slice(last)));
    }

    /* ── @zmínky: text bubliny se skládá z DOM uzlů (žádné innerHTML = žádné XSS);
       jména se hledají greedy od nejdelších, ať „Tomáš Zahradník" vyhraje nad „Tomáš".
       Prostý text mezi zmínkami prochází linkifikací čísel zakázek. */
    function appendTextWithMentions(el, text) {
        var i = 0;
        while (i < text.length) {
            var at = text.indexOf('@', i);
            if (at === -1) { appendLinkified(el, text.slice(i)); return; }
            var matched = null;
            for (var k = 0; k < membersByLen.length; k++) {
                var n = membersByLen[k];
                if (fold(text.substr(at + 1, n.length)) === fold(n)) { matched = text.substr(at + 1, n.length); break; }
            }
            if (matched) {
                if (at > i) appendLinkified(el, text.slice(i, at));
                var sp = document.createElement('span');
                sp.className = 'mention' + (ME && fold(matched) === fold(ME) ? ' me' : '');
                sp.textContent = '@' + matched;
                el.appendChild(sp);
                i = at + 1 + matched.length;
            } else {
                appendLinkified(el, text.slice(i, at + 1));
                i = at + 1;
            }
        }
    }

    /* Příloha ve zprávě: obrázek inline (klik = plná velikost), video/audio
       s přehrávačem, ostatní jako pilulka ke stažení. */
    function buildAttachment(f) {
        if (f.is_image) {
            var a = document.createElement('a'); a.href = f.url; a.target = '_blank'; a.rel = 'noopener';
            var img = document.createElement('img'); img.src = f.url; img.loading = 'lazy'; img.alt = f.name;
            a.appendChild(img); return a;
        }
        var mime = f.mime || '';
        if (mime.indexOf('video/') === 0) {
            var v = document.createElement('video'); v.controls = true; v.preload = 'metadata'; v.src = f.url; return v;
        }
        if (mime.indexOf('audio/') === 0) {
            var au = document.createElement('audio'); au.controls = true; au.preload = 'metadata'; au.src = f.url; return au;
        }
        var d = document.createElement('a'); d.className = 'chat-att-doc'; d.href = f.url + '&dl=1';
        var ic = document.createElement('i'); ic.className = 'fas ' + (mime === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-lines');
        d.appendChild(ic);
        var nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = f.name; d.appendChild(nm);
        var sz = document.createElement('span'); sz.className = 'sz'; sz.textContent = fmtSize(f.size || 0); d.appendChild(sz);
        return d;
    }

    /* Chipy vybraných souborů nad psací lištou */
    function setPending(list) {
        pending = list;
        pendingBox.innerHTML = '';
        pendingBox.hidden = pending.length === 0;
        pending.forEach(function (f, i) {
            var chip = document.createElement('span'); chip.className = 'afx-chat-chip';
            var ic = document.createElement('i');
            ic.className = 'fas ' + (f.type.indexOf('image/') === 0 ? 'fa-image' : (f.type.indexOf('video/') === 0 ? 'fa-video' : 'fa-file'));
            chip.appendChild(ic);
            var nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = f.name; chip.appendChild(nm);
            var sz = document.createElement('span'); sz.className = 'sz'; sz.textContent = fmtSize(f.size); chip.appendChild(sz);
            var x = document.createElement('button'); x.type = 'button'; x.innerHTML = '&times;';
            x.setAttribute('aria-label', 'Odebrat soubor');
            x.addEventListener('click', function () { setPending(pending.filter(function (_, j) { return j !== i; })); });
            chip.appendChild(x);
            pendingBox.appendChild(chip);
        });
    }

    /* ── Našeptávač @zmínek ──────────────────────────────────────────────── */
    function mHide() { mentionMenu.hidden = true; mItems = []; mIndex = -1; mQueryStart = -1; }
    function mRender(list) {
        mentionMenu.innerHTML = '';
        mItems = list; mIndex = 0;
        list.forEach(function (name, i) {
            var it = document.createElement('div');
            it.className = 'it' + (i === 0 ? ' active' : '');
            var ic = document.createElement('i'); ic.className = 'fas fa-at';
            it.appendChild(ic);
            it.appendChild(document.createTextNode(name));
            // mousedown místo click: nesmí stihnout blur inputu
            it.addEventListener('mousedown', function (ev) { ev.preventDefault(); mIndex = i; mPick(); });
            mentionMenu.appendChild(it);
        });
        mentionMenu.hidden = list.length === 0;
    }
    function mMove(d) {
        if (!mItems.length) return;
        mIndex = (mIndex + d + mItems.length) % mItems.length;
        Array.prototype.forEach.call(mentionMenu.children, function (c, i) { c.classList.toggle('active', i === mIndex); });
    }
    function mPick() {
        if (mIndex < 0 || !mItems[mIndex]) return;
        var name = mItems[mIndex];
        var v = input.value, caret = input.selectionStart || v.length;
        input.value = v.slice(0, mQueryStart) + '@' + name + ' ' + v.slice(caret);
        var pos = mQueryStart + name.length + 2;
        input.setSelectionRange(pos, pos);
        mHide();
        input.focus();
    }
    function mCheck() {
        if (!members.length) return;
        var v = input.value, caret = input.selectionStart || v.length;
        var at = v.lastIndexOf('@', caret - 1);
        if (at === -1 || (at > 0 && !/\s/.test(v[at - 1]))) { mHide(); return; }
        var q = v.slice(at + 1, caret);
        if (q.length > 30 || q.indexOf('@') !== -1) { mHide(); return; }
        var fq = fold(q);
        var list = members.filter(function (n) {
            var fn = fold(n);
            return fn.indexOf(fq) === 0 || fn.split(/\s+/).some(function (w) { return w.indexOf(fq) === 0; });
        }).slice(0, 6);
        mQueryStart = at;
        mRender(list);
    }

    function dayLabel(d) {
        var today = new Date().toISOString().slice(0, 10);
        var y = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
        if (d === today) return 'Dnes';
        if (d === y) return 'Včera';
        var p = d.split('-'); return p[2] + '. ' + p[1] + '. ' + p[0];
    }

    function render(msgs) {
        var nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
        var gotOther = false;
        msgs.forEach(function (m) {
            if (m.id <= lastId) return;
            lastId = m.id;
            if (m.day !== lastDay) {
                lastDay = m.day;
                var sep = document.createElement('div');
                sep.className = 'chat-day'; sep.textContent = dayLabel(m.day);
                box.appendChild(sep);
            }
            var el = document.createElement('div');
            el.className = 'chat-msg ' + (m.mine ? 'mine' : 'other');
            el.dataset.messageId = String(m.id);
            if (!m.mine) gotOther = true;
            if (m.text) {
                var b = document.createElement('div'); b.className = 'bubble';
                appendTextWithMentions(b, m.text);
                el.appendChild(b);
                // zpráva zmiňuje MĚ → zlaté zvýraznění celé bubliny
                if (!m.mine && ME && fold(m.text).indexOf('@' + fold(ME)) !== -1) { el.classList.add('mentions-me'); }
            }
            if (m.files && m.files.length) {
                var att = document.createElement('div'); att.className = 'chat-att';
                m.files.forEach(function (f) { att.appendChild(buildAttachment(f)); });
                el.appendChild(att);
            }
            // jméno odesílatele + čas POD bublinou (u každé zprávy)
            var meta = document.createElement('div'); meta.className = 'meta';
            var who = document.createElement('span'); who.className = 'who'; who.textContent = m.author;
            meta.appendChild(who);
            meta.appendChild(document.createTextNode(' · ' + m.time));
            if (m.mine) {
                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'chat-delete-btn';
                del.dataset.deleteMessageId = String(m.id);
                del.title = 'Smazat vlastní zprávu';
                del.setAttribute('aria-label', 'Smazat vlastní zprávu');
                del.innerHTML = '<i class="fas fa-trash-alt"></i>';
                meta.appendChild(del);
            }
            el.appendChild(meta);
            box.appendChild(el);
        });
        if (msgs.length && (nearBottom || firstLoad)) box.scrollTop = box.scrollHeight;
        if (gotOther && !firstLoad && window.afxChime) window.afxChime('chat');
        if (msgs.length) {
            // stránka chatu = přečteno (badge v menu zhasne)
            try { localStorage.setItem('afx_chat_seen', String(lastId)); } catch (e) {}
        }
        firstLoad = false;
    }

    function poll() {
        fetch('api/chat.php?after=' + lastId, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.ok) render(d.messages); })
            .catch(function () {});
    }

    function reloadChat() {
        return fetch('api/chat.php?after=0', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) throw new Error((d && d.message) || 'Načtení chatu selhalo');
                box.innerHTML = '';
                lastId = 0;
                lastDay = '';
                firstLoad = true;
                render(d.messages || []);
            });
    }

    box.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-delete-message-id]');
        if (!btn || !box.contains(btn)) return;
        var id = parseInt(btn.dataset.deleteMessageId || '0', 10);
        if (!id || !confirm('Smazat tuto zprávu?')) return;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', String(id));
        fd.append('csrf_token', csrf);
        fetch('api/chat.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) throw new Error((d && d.message) || 'Smazání selhalo');
                return reloadChat();
            })
            .catch(function (err) {
                btn.disabled = false;
                if (window.showAlert) showAlert(err.message || 'Smazání selhalo');
                else alert(err.message || 'Smazání selhalo');
            });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!mentionMenu.hidden) return;                    // Enter právě potvrzuje zmínku
        var text = input.value.trim();
        if (!text && !pending.length) return;
        var fd = new FormData();
        fd.append('message', text);
        fd.append('csrf_token', csrf);
        pending.forEach(function (f) { fd.append('files[]', f, f.name); });
        if (sendBtn) sendBtn.disabled = true;
        fetch('api/chat.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) { input.value = ''; setPending([]); poll(); }
                else { (window.showAlert || alert)((d && d.message) || 'Odeslání selhalo'); }
            })
            .catch(function () { (window.showAlert || alert)('Odeslání selhalo — zkus to znovu.'); })
            .finally(function () { if (sendBtn) sendBtn.disabled = false; input.focus(); });
    });

    /* výběr souborů sponkou (limit počtu a velikosti hlídá i server) */
    filesInput.addEventListener('change', function () {
        var list = pending.slice();
        Array.prototype.forEach.call(filesInput.files, function (f) {
            if (list.length >= 6) { (window.showAlert || alert)('Najednou lze poslat nejvýš 6 souborů.'); return; }
            if (f.size > FILE_CAP) { (window.showAlert || alert)('Soubor „' + f.name + '" je moc velký (limit 25 MB).'); return; }
            list.push(f);
        });
        filesInput.value = '';
        setPending(list);
    });

    /* klávesy našeptávače: šipky = výběr, Enter/Tab = potvrdit, Esc = zavřít */
    input.addEventListener('keydown', function (e) {
        if (mentionMenu.hidden) return;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); mMove(e.key === 'ArrowDown' ? 1 : -1); }
        else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); mPick(); }
        else if (e.key === 'Escape') { mHide(); }
    });
    input.addEventListener('input', mCheck);
    input.addEventListener('blur', function () { setTimeout(mHide, 150); });

    /* Jména pro zmínky načíst PŘED prvním vykreslením — jinak by prvních 60
       zpráv nemělo zvýrazněné @zmínky. Chat běží i při selhání (finally). */
    fetch('api/chat.php?op=members', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d && d.ok && d.members) {
                members = d.members;
                membersByLen = members.slice().sort(function (a, b) { return b.length - a.length; });
            }
        })
        .catch(function () {})
        .finally(function () { poll(); setInterval(poll, 4000); });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
