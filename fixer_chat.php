<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

if (!hasPermission('admin_access') && getCurrentStaffRole() !== 'manager') {
    header('Location: index.php');
    exit;
}

$staff = $pdo->query("SELECT id, name, role, telegram_id, is_active FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$selectedTechId = (int)($_GET['tech_id'] ?? 0);
if ($selectedTechId <= 0 && !empty($staff)) {
    $selectedTechId = (int)$staff[0]['id'];
}
$selectedTech = null;
foreach ($staff as $s) {
    if ((int)$s['id'] === $selectedTechId) { $selectedTech = $s; break; }
}
if (!$selectedTech && !empty($staff)) $selectedTech = $staff[0];

$chatTag = $selectedTech ? 'fixer_chat_' . (int)$selectedTech['id'] : '';
$chatMessages = [];
if ($chatTag) {
    try {
        $stmt = $pdo->prepare("SELECT direction, sender_type, sender_name, message, created_at FROM fixer_chat_messages WHERE chat_tag = ? ORDER BY created_at ASC, id ASC LIMIT 500");
        $stmt->execute([$chatTag]);
        $chatMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $chatMessages = [];
    }
}
?>
<div class="row g-4">
    <div class="col-12">
        <div class="card glass-card border-0 h-100">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><i class="fab fa-telegram-plane me-2"></i>Fixer Chat</h5>
                    <small class="text-white-75">Interní konverzace CRM ↔ Telegram. Bez reloadu.</small>
                </div>
                <?php if ($selectedTech): ?>
                    <span class="badge bg-primary"><?php echo htmlspecialchars($selectedTech['name']); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$selectedTech): ?>
                    <div class="alert alert-secondary mb-0">Žádný zaměstnanec nebyl nalezen.</div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <strong>Komu píšeš:</strong> <?php echo htmlspecialchars($selectedTech['name']); ?><br>
                        <strong>Telegram chat ID:</strong> <?php echo htmlspecialchars($selectedTech['telegram_id'] ?: 'není spárované'); ?><br>
                        <strong>Režim:</strong> CRM zprávy se ukládají sem a zároveň odcházejí na Telegram. Odpovědi z Telegramu se sem vrací automaticky.
                    </div>

                    <div id="fixerChatLog" class="border rounded p-3 mb-3 bg-black bg-opacity-25" style="min-height: 360px; max-height: 560px; overflow:auto;">
                        <?php foreach ($chatMessages as $m): ?>
                            <div class="mb-3 d-flex <?php echo ($m['direction'] ?? '') === 'outbound' ? 'justify-content-end' : 'justify-content-start'; ?>">
                                <div class="p-3 rounded-3 <?php echo ($m['direction'] ?? '') === 'outbound' ? 'bg-primary bg-opacity-25' : 'bg-light bg-opacity-10'; ?>" style="max-width: 82%; border:1px solid rgba(255,255,255,.15);">
                                    <div class="small text-white-50 mb-1">
                                        <?php echo htmlspecialchars((string)($m['sender_name'] ?: ($m['sender_type'] ?? ''))); ?> · <?php echo htmlspecialchars((string)($m['created_at'] ?? '')); ?>
                                    </div>
                                    <div><?php echo nl2br(htmlspecialchars((string)($m['message'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form id="fixerChatForm" class="row g-2 p-2" style="border:2px solid #d7ff00 !important; border-radius:12px; box-shadow:0 0 0 1px rgba(0,0,0,.15), 0 0 10px rgba(215,255,0,.18) !important;">
                        <input type="hidden" name="tech_id" value="<?php echo (int)$selectedTech['id']; ?>">
                        <div class="col-12">
                            <textarea class="form-control bg-transparent text-white shadow-none border-0" style="outline:none !important; box-shadow:none !important;" name="message" rows="3" placeholder="Napiš zprávu pro Fixer / zaměstnance..."></textarea>
                        </div>
                        <div class="col-12 d-grid d-md-flex justify-content-md-end">
                            <button class="btn btn-primary px-4" type="submit"><i class="fas fa-paper-plane me-2"></i>Odeslat</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
(function(){
    const chatForm = document.getElementById('fixerChatForm');
    const chatLog = document.getElementById('fixerChatLog');
    if (!chatForm || !chatLog) return;

    const techId = <?php echo (int)$selectedTech['id']; ?>;
    let lastSeen = <?php echo !empty($chatMessages) ? (int)strtotime(end($chatMessages)['created_at']) : 0; ?>;

    const escapeHtml = (s) => (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    const appendBubble = (who, text, outbound, meta='') => {
        const wrap = document.createElement('div');
        wrap.className = 'mb-3 d-flex ' + (outbound ? 'justify-content-end' : 'justify-content-start');
        wrap.innerHTML = `<div class="p-3 rounded-3 ${outbound ? 'bg-primary bg-opacity-25' : 'bg-light bg-opacity-10'}" style="max-width:82%; border:1px solid rgba(255,255,255,.15);"><div class="small text-white-50 mb-1">${escapeHtml(who)}${meta ? ' · ' + escapeHtml(meta) : ''}</div><div>${escapeHtml(text).replace(/\n/g,'<br>')}</div></div>`;
        chatLog.appendChild(wrap);
        chatLog.scrollTop = chatLog.scrollHeight;
    };

    const refreshThread = async () => {
        try {
            const r = await fetch(`api/fixer_thread.php?tech_id=${techId}&since=${lastSeen}`, { credentials:'same-origin' });
            const j = await r.json();
            if (!j || !j.success || !Array.isArray(j.messages)) return;
            for (const m of j.messages) {
                appendBubble(m.sender_name || m.sender_type, m.message || '', m.direction === 'outbound', m.created_at || '');
                const ts = Math.floor(new Date(m.created_at).getTime() / 1000);
                if (ts > lastSeen) lastSeen = ts;
            }
        } catch (e) {}
    };

    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(chatForm);
        const msg = (fd.get('message') || '').toString().trim();
        if (!msg) return;
        const resp = await fetch('api/fixer_send.php', { method:'POST', body: fd, credentials:'same-origin' });
        const json = await resp.json().catch(()=>({success:false,message:'invalid response'}));
        if (!json.success) {
            appendBubble('Error', json.message || 'Nepodařilo se odeslat', true);
            return;
        }
        chatForm.reset();
        await refreshThread();
    });

    refreshThread();
    setInterval(refreshThread, 4000);
})();
</script>
<?php require_once 'includes/footer.php'; ?>
