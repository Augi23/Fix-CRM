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
    <div class="d-flex flex-column afx-chat-box" style="height: calc(100vh - 200px); height: calc(100dvh - 200px); min-height: 380px;">
        <div id="chatMessages" class="flex-grow-1 overflow-auto d-flex flex-column gap-2"></div>
        <form id="chatForm" class="afx-chat-inputbar" autocomplete="off">
            <input type="text" id="chatInput" placeholder="Napiš zprávu týmu…" maxlength="2000" autofocus>
            <button type="submit" aria-label="Odeslat zprávu"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>
</div>

<style>
/* Chat bez panelu: průhledné pozadí, žádný rámeček — jen bubliny na stránce. */
.afx-chat-box { background: transparent !important; border: 0 !important; box-shadow: none !important; border-radius: 0 !important; }
#chatMessages { padding: 6px 2px; }
/* Popisek zmizel → box začíná výš; kompenzace výšky (desktop 240→200 v inline
   stylu výše, mobil 330→290 tady — stejný breakpoint jako fix-crm-v2.css). */
@media (max-width: 1080px) {
  .afx-chat-box { height: calc(100dvh - 290px) !important; }
}
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
            var b = document.createElement('div'); b.className = 'bubble'; b.textContent = m.text;
            el.appendChild(b);
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
        var text = input.value.trim();
        if (!text) return;
        input.value = '';
        var fd = new FormData();
        fd.append('message', text);
        fd.append('csrf_token', csrf);
        fetch('api/chat.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function () { poll(); })
            .catch(function () {});
        input.focus();
    });

    poll();
    setInterval(poll, 4000);
})();
</script>

<?php require_once 'includes/footer.php'; ?>
