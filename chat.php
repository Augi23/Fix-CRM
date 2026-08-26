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
        <div id="chatAttachBar" class="afx-chat-attachbar" style="display:none;"></div>
        <form id="chatForm" class="afx-chat-inputbar" autocomplete="off">
            <input type="file" id="chatFiles" multiple hidden
                   accept="image/*,application/pdf,.txt,.csv,.zip,.doc,.docx,.xls,.xlsx,.pptx,video/mp4,video/quicktime,audio/*">
            <button type="button" id="chatAttachBtn" class="attach" aria-label="Přidat přílohu" title="Přidat přílohu (jde i přetáhnout nebo vložit Cmd+V)">
                <i class="fas fa-paperclip"></i>
            </button>
            <input type="text" id="chatInput" placeholder="Napiš zprávu týmu…" maxlength="2000" autofocus>
            <button type="submit" aria-label="Odeslat zprávu"><i class="fas fa-paper-plane"></i></button>
        </form>
        <div id="chatDrop" class="afx-chat-drop"><div><i class="fas fa-cloud-arrow-up"></i><br>Pusť soubory sem</div></div>
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
/* ── přílohy ── */
.afx-chat-inputbar button.attach {
  width: 38px; height: 38px; background: rgba(255,255,255,.08); box-shadow: none;
  border: 1px solid rgba(255,255,255,.14); color: rgba(255,255,255,.72); font-size: 15px;
}
.afx-chat-inputbar button.attach:hover { color: #fff; background: rgba(255,255,255,.14); }
.afx-chat-inputbar button.attach.has { color: #7ce39a; border-color: rgba(124,227,154,.5); }
.afx-chat-attachbar { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 2px 0; }
.afx-chip {
  display: inline-flex; align-items: center; gap: 7px; max-width: 260px;
  background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.14);
  border-radius: 12px; padding: 5px 8px 5px 6px; font-size: .8rem; color: #fff;
}
.afx-chip img { width: 30px; height: 30px; object-fit: cover; border-radius: 7px; flex: 0 0 auto; }
.afx-chip .ic { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;
  border-radius: 7px; background: rgba(255,255,255,.10); color: rgba(255,255,255,.75); flex: 0 0 auto; }
.afx-chip .nm { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.afx-chip .sz { color: rgba(255,255,255,.45); font-size: .72rem; flex: 0 0 auto; }
.afx-chip .rm { border: 0; background: transparent; color: rgba(255,255,255,.5); padding: 0 2px; line-height: 1; }
.afx-chip .rm:hover { color: #ff6b6b; }
/* přílohy uvnitř bubliny */
.chat-att { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.chat-msg .chat-att:first-child { margin-top: 0; }
.chat-att .thumb { max-width: 210px; max-height: 210px; border-radius: 12px; display: block; cursor: zoom-in;
  border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.05); }
.chat-att a.afx-chip { text-decoration: none; }
.chat-att a.afx-chip:hover { background: rgba(255,255,255,.15); }
.chat-msg.mine .chat-att .afx-chip { background: rgba(255,255,255,.16); border-color: rgba(255,255,255,.22); }
/* přetažení souboru nad chat */
.afx-chat-drop {
  position: fixed; inset: 0; z-index: 1080; display: none; align-items: center; justify-content: center;
  background: rgba(10,12,18,.72); backdrop-filter: blur(6px); color: #fff; text-align: center; font-size: 1.1rem;
}
.afx-chat-drop.show { display: flex; }
.afx-chat-drop i { font-size: 2.4rem; opacity: .8; margin-bottom: 10px; }
/* prohlížeč obrázku */
.afx-lightbox { position: fixed; inset: 0; z-index: 1090; display: none; align-items: center; justify-content: center;
  background: rgba(0,0,0,.9); padding: 24px; }
.afx-lightbox.show { display: flex; }
.afx-lightbox img { max-width: 100%; max-height: 100%; border-radius: 10px; }
.afx-lightbox .cls { position: absolute; top: 16px; right: 20px; color: #fff; font-size: 1.6rem;
  background: transparent; border: 0; }
.chat-day { align-self: center; font-size: .72rem; color: rgba(255,255,255,.45); background: rgba(255,255,255,.06); border-radius: 10px; padding: 2px 12px; margin: 6px 0; }
</style>

<script>
(function () {
    var box = document.getElementById('chatMessages');
    var form = document.getElementById('chatForm');
    var input = document.getElementById('chatInput');
    var lastId = 0, lastDay = '', firstLoad = true;
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // ── přílohy ────────────────────────────────────────────────────────────
    var MAX_BYTES = <?php echo crmChatMaxUploadBytes(); ?>;   // limit PHP na serveru
    var MAX_FILES = 10;
    var pending = [];
    var $files = document.getElementById('chatFiles');
    var $attachBtn = document.getElementById('chatAttachBtn');
    var $attachBar = document.getElementById('chatAttachBar');
    var $drop = document.getElementById('chatDrop');
    var sending = false;

    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return Math.round(b / 1024) + ' kB';
        return (b / 1048576).toFixed(1).replace('.', ',') + ' MB';
    }
    function fileIcon(mime, name) {
        var m = (mime || '').toLowerCase(), n = (name || '').toLowerCase();
        if (m === 'application/pdf') return 'fa-file-pdf';
        if (m.indexOf('video/') === 0) return 'fa-file-video';
        if (m.indexOf('audio/') === 0) return 'fa-file-audio';
        if (m.indexOf('zip') !== -1) return 'fa-file-zipper';
        if (/\.(xlsx?|csv)$/.test(n)) return 'fa-file-excel';
        if (/\.docx?$/.test(n)) return 'fa-file-word';
        if (/\.pptx?$/.test(n)) return 'fa-file-powerpoint';
        if (m.indexOf('image/') === 0) return 'fa-file-image';
        return 'fa-file-lines';
    }

    // Fotky z telefonu mají klidně 5 MB, server bere <?php echo round(crmChatMaxUploadBytes()/1048576, 1); ?> MB.
    // Zmenšit je v prohlížeči je rychlejší i milosrdnější než hláška „moc velké".
    function shrinkImage(file) {
        return new Promise(function (resolve) {
            if (file.size <= MAX_BYTES * 0.9 || file.type.indexOf('image/') !== 0
                || file.type === 'image/gif') { resolve(file); return; }
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                var max = 1800;
                var sc = Math.min(1, max / Math.max(img.width, img.height));
                var c = document.createElement('canvas');
                c.width = Math.max(1, Math.round(img.width * sc));
                c.height = Math.max(1, Math.round(img.height * sc));
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                c.toBlob(function (blob) {
                    URL.revokeObjectURL(url);
                    if (!blob || blob.size >= file.size) { resolve(file); return; }
                    var nm = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                    resolve(new File([blob], nm, { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', 0.82);
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };   // HEIC apod. → poslat原
            img.src = url;
        });
    }

    function renderChips() {
        $attachBar.innerHTML = '';
        $attachBar.style.display = pending.length ? 'flex' : 'none';
        $attachBtn.classList.toggle('has', pending.length > 0);
        pending.forEach(function (f, i) {
            var chip = document.createElement('div');
            chip.className = 'afx-chip';
            if (f.type.indexOf('image/') === 0) {
                var im = document.createElement('img');
                im.src = URL.createObjectURL(f);
                im.onload = function () { URL.revokeObjectURL(im.src); };
                chip.appendChild(im);
            } else {
                var ic = document.createElement('span');
                ic.className = 'ic';
                ic.innerHTML = '<i class="fas ' + fileIcon(f.type, f.name) + '"></i>';
                chip.appendChild(ic);
            }
            var nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = f.name;
            var sz = document.createElement('span'); sz.className = 'sz'; sz.textContent = fmtSize(f.size);
            var rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'rm'; rm.innerHTML = '<i class="fas fa-xmark"></i>';
            rm.title = 'Odebrat';
            rm.addEventListener('click', function () { pending.splice(i, 1); renderChips(); });
            chip.appendChild(nm); chip.appendChild(sz); chip.appendChild(rm);
            $attachBar.appendChild(chip);
        });
    }

    function addFiles(list) {
        var arr = Array.prototype.slice.call(list || []);
        if (!arr.length) return;
        var msgs = [];
        var chain = Promise.resolve();
        arr.forEach(function (f) {
            chain = chain.then(function () {
                if (pending.length >= MAX_FILES) { msgs.push('Najednou jde poslat nejvýš ' + MAX_FILES + ' příloh.'); return; }
                return shrinkImage(f).then(function (out) {
                    if (out.size > MAX_BYTES) {
                        msgs.push(out.name + ' — ' + fmtSize(out.size) + ' je nad limit ' + fmtSize(MAX_BYTES) + '.');
                        return;
                    }
                    pending.push(out);
                });
            });
        });
        chain.then(function () {
            renderChips();
            if (msgs.length) { alert(msgs.join('\n')); }
        });
    }

    $attachBtn.addEventListener('click', function () { $files.click(); });
    $files.addEventListener('change', function () { addFiles($files.files); $files.value = ''; });
    // vložení screenshotu z ⌘V rovnou do chatu
    input.addEventListener('paste', function (e) {
        var f = e.clipboardData && e.clipboardData.files;
        if (f && f.length) { e.preventDefault(); addFiles(f); }
    });
    // přetažení souboru kamkoli nad stránku
    var dragDepth = 0;
    window.addEventListener('dragenter', function (e) {
        if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') === -1) return;
        dragDepth++; $drop.classList.add('show');
    });
    window.addEventListener('dragover', function (e) { if ($drop.classList.contains('show')) e.preventDefault(); });
    window.addEventListener('dragleave', function () { if (--dragDepth <= 0) { dragDepth = 0; $drop.classList.remove('show'); } });
    window.addEventListener('drop', function (e) {
        if (!$drop.classList.contains('show')) return;
        e.preventDefault(); dragDepth = 0; $drop.classList.remove('show');
        if (e.dataTransfer && e.dataTransfer.files) { addFiles(e.dataTransfer.files); }
    });

    // ── prohlížeč obrázku ──
    var lb = document.createElement('div');
    lb.className = 'afx-lightbox';
    lb.innerHTML = '<button type="button" class="cls" aria-label="Zavřít"><i class="fas fa-xmark"></i></button><img alt="">';
    document.body.appendChild(lb);
    lb.addEventListener('click', function () { lb.classList.remove('show'); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.classList.remove('show'); });
    function openLightbox(url, alt) {
        var im = lb.querySelector('img');
        im.src = url; im.alt = alt || '';
        lb.classList.add('show');
    }

    /** Přílohy zprávy → prvky do bubliny (obrázek = náhled, ostatní = štítek ke stažení). */
    function renderFiles(m) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-att';
        (m.files || []).forEach(function (f) {
            if (f.is_image) {
                var im = document.createElement('img');
                im.className = 'thumb'; im.loading = 'lazy'; im.alt = f.name; im.src = f.url;
                im.addEventListener('click', function () { openLightbox(f.url, f.name); });
                // HEIC mimo Safari se nevykreslí — místo rozbité ikony nabídnout stažení
                im.addEventListener('error', function () { im.replaceWith(fileChip(f)); });
                wrap.appendChild(im);
            } else {
                wrap.appendChild(fileChip(f));
            }
        });
        return wrap;
    }
    function fileChip(f) {
        var a = document.createElement('a');
        a.className = 'afx-chip'; a.href = f.url + '&dl=1'; a.target = '_blank'; a.rel = 'noopener';
        a.title = 'Stáhnout ' + f.name;
        var ic = document.createElement('span');
        ic.className = 'ic'; ic.innerHTML = '<i class="fas ' + fileIcon(f.mime, f.name) + '"></i>';
        var nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = f.name;
        var sz = document.createElement('span'); sz.className = 'sz'; sz.textContent = fmtSize(f.size);
        a.appendChild(ic); a.appendChild(nm); a.appendChild(sz);
        return a;
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
            var hasFiles = m.files && m.files.length;
            if (m.text) {
                var b = document.createElement('div'); b.className = 'bubble'; b.textContent = m.text;
                if (hasFiles) { b.appendChild(renderFiles(m)); }
                el.appendChild(b);
            } else if (hasFiles) {
                // samotná příloha bez textu — prázdná bublina by byla jen šedý flek
                el.appendChild(renderFiles(m));
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
        if (sending) return;
        var text = input.value.trim();
        if (!text && !pending.length) return;

        var fd = new FormData();
        fd.append('message', text);
        fd.append('csrf_token', csrf);
        pending.forEach(function (f) { fd.append('files[]', f, f.name); });

        // vstup vyprázdnit až po sestavení dat, ale hned — ať se nedá odeslat dvakrát
        var sentFiles = pending.slice();
        input.value = '';
        pending = [];
        renderChips();
        sending = true;
        var btn = form.querySelector('button[type="submit"]');
        var btnHtml = btn.innerHTML;
        if (sentFiles.length) { btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; btn.disabled = true; }

        fetch('api/chat.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok === false) {
                    // neúspěch → vrátit rozepsané zpět, ať se nic neztratí
                    input.value = text;
                    pending = sentFiles;
                    renderChips();
                    alert(d.message || 'Zprávu se nepodařilo odeslat.');
                    return;
                }
                if (d && d.rejected && d.rejected.length) {
                    alert('Některé přílohy neprošly:\n· ' + d.rejected.join('\n· '));
                }
                poll();
            })
            .catch(function () {
                input.value = text;
                pending = sentFiles;
                renderChips();
                alert('Zprávu se nepodařilo odeslat — zkontroluj připojení.');
            })
            .then(function () {
                sending = false;
                btn.innerHTML = btnHtml; btn.disabled = false;
                input.focus();
            });
    });

    poll();
    setInterval(poll, 4000);
})();
</script>

<?php require_once 'includes/footer.php'; ?>
