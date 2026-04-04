<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/header.php';

if (!hasPermission('admin_access')) {
    header('Location: index.php');
    exit;
}

$staff = $pdo->query("SELECT id, name, role, telegram_id, is_active FROM technicians WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$selectedTechId = (int)($_GET['tech_id'] ?? ($_SESSION['tech_id'] ?? 0));
$selectedTech = null;
foreach ($staff as $s) {
    if ((int)$s['id'] === $selectedTechId) { $selectedTech = $s; break; }
}
if (!$selectedTech && !empty($staff)) $selectedTech = $staff[0];
?>
<div class="row g-4">
    <div class="col-12 col-xl-3">
        <div class="card glass-card border-0 h-100">
            <div class="card-header bg-transparent border-bottom-0">
                <h5 class="mb-0"><i class="fas fa-users me-2"></i> Zaměstnanci</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($staff as $s): ?>
                    <a class="list-group-item list-group-item-action <?php echo $selectedTech && (int)$selectedTech['id'] === (int)$s['id'] ? 'active' : ''; ?>" href="fixer_chat.php?tech_id=<?php echo (int)$s['id']; ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?php echo htmlspecialchars($s['name']); ?></strong><br>
                                <small>@<?php echo htmlspecialchars($s['telegram_id'] ?: 'nepropojeno'); ?></small>
                            </div>
                            <span class="badge bg-<?php echo ($s['role'] ?? '') === 'manager' ? 'info' : 'secondary'; ?>"><?php echo htmlspecialchars($s['role'] ?? ''); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-9">
        <div class="card glass-card border-0 h-100">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><i class="fab fa-telegram-plane me-2"></i>Fixer Chat</h5>
                    <small class="text-white-75">Decentní interní panel pro komunikaci se zaměstnanci přes Telegram bota.</small>
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
                        <strong>Telegram chat ID:</strong> <?php echo htmlspecialchars($selectedTech['telegram_id'] ?: 'není spárované'); ?><br>
                        <strong>Jak používat:</strong> Posílej text dole. Odpověď přijde přes bot do Telegramu.
                    </div>
                    <div id="fixerChatLog" class="border rounded p-3 mb-3 bg-black bg-opacity-25" style="min-height: 320px; max-height: 520px; overflow:auto;"></div>
                    <form id="fixerChatForm" class="row g-2">
                        <input type="hidden" name="tech_id" value="<?php echo (int)$selectedTech['id']; ?>">
                        <div class="col-12 col-md-10">
                            <textarea class="form-control" name="message" rows="3" placeholder="Napiš zprávu pro Fixer / zaměstnance..."></textarea>
                        </div>
                        <div class="col-12 col-md-2 d-grid">
                            <button class="btn btn-primary h-100" type="submit"><i class="fas fa-paper-plane me-2"></i>Odeslat</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
const chatForm = document.getElementById('fixerChatForm');
const chatLog = document.getElementById('fixerChatLog');
if (chatForm && chatLog) {
  const appendLine = (who, text) => {
    const div = document.createElement('div');
    div.className = 'mb-2';
    div.innerHTML = `<strong>${who}:</strong> <span>${text.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`;
    chatLog.appendChild(div);
    chatLog.scrollTop = chatLog.scrollHeight;
  };
  chatForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(chatForm);
    const msg = (fd.get('message') || '').toString().trim();
    if (!msg) return;
    appendLine('CRM', msg);
    const techId = fd.get('tech_id');
    const resp = await fetch('api/fixer_send.php', { method:'POST', body: fd, credentials:'same-origin' });
    const json = await resp.json().catch(()=>({success:false,message:'invalid response'}));
    appendLine(json.success ? 'Fixer' : 'Error', json.message || (json.success ? 'Odesláno' : 'Nepodařilo se odeslat'));
    chatForm.reset();
  });
}
</script>
<?php require_once 'includes/footer.php'; ?>
