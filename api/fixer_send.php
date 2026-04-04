<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
if (!hasPermission('admin_access')) { echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }
$techId = (int)($_POST['tech_id'] ?? 0);
$message = trim((string)($_POST['message'] ?? ''));
if ($techId <= 0 || $message === '') { echo json_encode(['success'=>false,'message'=>'Missing data']); exit; }
$stmt = $pdo->prepare('SELECT telegram_id, name FROM technicians WHERE id = ? LIMIT 1');
$stmt->execute([$techId]);
$tech = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tech || empty($tech['telegram_id'])) { echo json_encode(['success'=>false,'message'=>'Technik nemá Telegram ID']); exit; }
$safe = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$ok = sendTelegramNotification($tech['telegram_id'], '💬 <b>Fixer / CRM</b>\n\n' . $safe);
echo json_encode(['success'=>$ok,'message'=>$ok ? 'Odesláno do Telegramu' : 'Telegram message failed']);
