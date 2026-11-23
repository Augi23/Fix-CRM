<?php
/**
 * Úkoly — čtení i zápis pro stránku ukoly.php a odznak v menu.
 *
 * Veškerá oprávnění řeší ukoly/lib.php, ne tenhle soubor: na endpoint se dá
 * poslat cokoli a UI se dá obejít, takže kontrola musí být u dat.
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once __DIR__ . '/lib.php';
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = static function (bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(['success' => $ok, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
};

if (afxTaskMe() === '' || (empty($_SESSION['user_id']) && empty($_SESSION['tech_id']))) {
    http_response_code(403);
    $reply(false, __('unauthorized'));
}

$action = (string)($_REQUEST['action'] ?? '');

$snapshot = static fn(bool $markSeen): array => afxTaskSnapshot($markSeen);

// ── čtení (bez CSRF, nic nemění kromě značky „viděno") ──────────────────────
if ($action === 'badge') {
    $reply(true, '', ['badge' => afxTaskBadge()]);
}
if ($action === 'list') {
    $reply(true, '', $snapshot(!empty($_GET['seen'])));
}

// ── zápis ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    $reply(false, 'Neplatný bezpečnostní token — načti stránku znovu.');
}

switch ($action) {
    case 'create': {
        $assignees = $_POST['assignees'] ?? [];
        if (!is_array($assignees)) { $assignees = explode(',', (string)$assignees); }
        [$ok, $msg, $ids] = afxTaskCreate(
            (string)($_POST['title'] ?? ''),
            (string)($_POST['detail'] ?? ''),
            $assignees,
            (string)($_POST['due_date'] ?? ''),
            (string)($_POST['due_time'] ?? ''),
            !empty($_POST['urgent'])
        );
        $reply($ok, $msg, $ok ? ['ids' => $ids] + $snapshot(true) : []);
    }

    case 'update': {
        [$ok, $msg] = afxTaskUpdate(
            (int)($_POST['id'] ?? 0),
            (string)($_POST['title'] ?? ''),
            (string)($_POST['detail'] ?? ''),
            (string)($_POST['assignee'] ?? ''),
            (string)($_POST['due_date'] ?? ''),
            (string)($_POST['due_time'] ?? ''),
            !empty($_POST['urgent'])
        );
        $reply($ok, $msg, $ok ? $snapshot(true) : []);
    }

    case 'done':
    case 'reopen': {
        [$ok, $msg] = afxTaskSetDone((int)($_POST['id'] ?? 0), $action === 'done', (string)($_POST['note'] ?? ''));
        $reply($ok, $msg, $ok ? $snapshot(true) : []);
    }

    case 'comment': {
        [$ok, $msg] = afxTaskComment((int)($_POST['id'] ?? 0), (string)($_POST['body'] ?? ''));
        $reply($ok, $msg, $ok ? $snapshot(true) : []);
    }

    case 'delete': {
        [$ok, $msg] = afxTaskDelete((int)($_POST['id'] ?? 0));
        $reply($ok, $msg, $ok ? $snapshot(true) : []);
    }
}

$reply(false, 'Neznámá akce.');
