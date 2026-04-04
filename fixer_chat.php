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
    <div class="col-12">
        <div class="card glass-card border-0 h-100">
            <div class="card-header bg-transparent border-bottom-0 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><i class="fab fa-telegram-plane me-2"></i>Fixer Chat</h5>
                    <small class="text-white-75">Interní panel pro komunikaci přes Telegram bota.</small>
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
                        <strong>Jak používat:</strong> Posílej text dole. Odpověď přijde přes bot do Telegramu.
                    </div>
                    <div id="fixerChatLog" class="border rounded p-3 mb-3 bg-black bg-opacity-25" style="min-height: 320px; max-height: 520px; overflow:auto;"></div>
                    <div class="small text-white-50 mb-2">Pozn.: Zpráva se v tomto panelu zatím nezobrazuje jako historie, ale odešle se do Telegramu. Pokud chceš skutečnou konverzaci v CRM, doplním databázový log a bubliny obou stran.</div>
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
<?php require_once 'includes/footer.php'; ?>
