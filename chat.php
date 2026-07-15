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
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0 text-white"><i class="fas fa-comments me-2 text-info"></i>Týmový chat</h4>
            <div class="small text-white-75">Společná místnost pro všechny zaměstnance — nové zprávy se ohlásí zvukem kdekoliv v CRM.</div>
        </div>
    </div>

    <div class="glass-panel border-secondary d-flex flex-column" style="height: calc(100vh - 240px); min-height: 420px;">
        <div id="chatMessages" class="flex-grow-1 overflow-auto p-3 d-flex flex-column gap-2"></div>
        <div class="border-top border-secondary p-2">
            <form id="chatForm" class="d-flex gap-2" autocomplete="off">
                <input type="text" id="chatInput" class="form-control" placeholder="Napiš zprávu týmu…" maxlength="2000" autofocus>
                <button type="submit" class="btn btn-primary px-4"><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>
</div>

<style>
.chat-msg { max-width: 72%; }
.chat-msg .bubble { padding: 8px 14px; border-radius: 16px; font-size: .95rem; line-height: 1.45; white-space: pre-wrap; word-break: break-word; }
.chat-msg.other { align-self: flex-start; }
.chat-msg.other .bubble { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.10); color: #fff; border-bottom-left-radius: 5px; }
.chat-msg.mine { align-self: flex-end; text-align: right; }
.chat-msg.mine .bubble { background: rgba(10,132,255,.85); color: #fff; border-bottom-right-radius: 5px; text-align: left; }
.chat-msg .meta { font-size: .72rem; color: rgba(255,255,255,.5); margin: 2px 6px 0; }
.chat-msg .who { font-size: .74rem; font-weight: 600; color: rgba(140,200,255,.9); margin: 0 6px 2px; }
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
            if (!m.mine) {
                var who = document.createElement('div'); who.className = 'who'; who.textContent = m.author;
                el.appendChild(who);
                gotOther = true;
            }
            var b = document.createElement('div'); b.className = 'bubble'; b.textContent = m.text;
            el.appendChild(b);
            var meta = document.createElement('div'); meta.className = 'meta'; meta.textContent = m.time;
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
