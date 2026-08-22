<?php
/**
 * ZÁKAZNICKÝ DISPLEJ (druhý monitor na recepci) — most obsluha ↔ displej.
 *
 * Zaměstnanecká strana (session + CSRF u zápisů):
 *   GET  ?action=info                 → zajistí řádek+token své pobočky, vrátí
 *                                       {url, mode, state_id, reply_id}
 *   POST {action:'push', mode, payload?, csrf_token}
 *                                     → pošle stav na displej
 *                                       (mode: idle | client_form | cart | receipt)
 *   GET  ?action=reply&after=<id>     → odpověď klienta s reply_id > after
 *                                       ({reply_id, reply} nebo {reply_id: after})
 *
 * Strana displeje (token, ŽÁDNÁ session — klient nesmí do CRM):
 *   GET  ?token=..&action=state&after=<id> → {state_id, mode, payload} (jen změna)
 *   GET  ?token=..&action=products         → produkty pro reklamní kolotoč
 *   POST {token, action:'reply', data}     → klientem vyplněný formulář
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cdFail(int $http, string $error): void {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

ensureCustomerDisplayTable();

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = [];
if ($method === 'POST') {
    $raw = (string)file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) $body = $_POST;
}
$action = (string)($body['action'] ?? $_GET['action'] ?? '');
$token  = trim((string)($body['token'] ?? $_GET['token'] ?? ''));

$allowedModes = ['idle', 'client_form', 'cart', 'receipt'];

/* ── Strana displeje (token) ─────────────────────────────────────────── */
if ($token !== '') {
    if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) cdFail(403, 'forbidden');
    $sel = $pdo->prepare("SELECT branch_id, state_id, mode, payload, updated_at FROM customer_display WHERE token = ?");
    $sel->execute([$token]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) cdFail(403, 'forbidden');
    $branch = (int)$row['branch_id'];

    if ($action === 'state') {
        $after = isset($_GET['after']) ? max(0, (int)$_GET['after']) : 0;
        if ((int)$row['state_id'] <= $after) {
            echo json_encode(['ok' => true, 'state_id' => $after], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $mode = (string)$row['mode'];
        $payload = json_decode((string)($row['payload'] ?? ''), true);
        // Zatuchlý stav (čerstvě zapnutý monitor ráno): starší než 30 minut
        // nemá smysl přehrávat — místo včerejšího košíku rovnou reklama.
        // Účtenka expiruje po 30 s: displej se sám vrací k reklamě, server
        // musí stav dorovnat, jinak indikátor v CRM tvrdí „účtenka" donekonečna.
        $age = !empty($row['updated_at']) ? time() - strtotime((string)$row['updated_at']) : 0;
        if (($mode !== 'idle' && $age > 1800) || ($mode === 'receipt' && $age > 30)) {
            $mode = 'idle'; $payload = null;
        }
        echo json_encode([
            'ok' => true,
            'state_id' => (int)$row['state_id'],
            'mode' => $mode,
            'payload' => is_array($payload) ? $payload : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'products') {
        // Reklamní kolotoč: viditelné skladem-dostupné produkty e-shopu (bez cizích dat)
        ensureProductsTable();
        if (function_exists('ensureProductsHideEshopColumn')) ensureProductsHideEshopColumn();
        if (function_exists('ensureProductsLoanColumns')) ensureProductsLoanColumns();
        try {
            // 10 NEJNOVĚJI přidaných (přání majitele) — fotka s DĚDIČNOSTÍ přesně
            // jako e-shop feed: vlastní studiovka kusu → vlastní fotka → studiovka
            // MODELU přes productModelKey(model, color) + crmModelPhotoMap().
            // (Dřívější SQL filtr jen na sloupce products nechal v kolotoči
            // jedinou klávesnici — fotky tu dědí skoro všechno.)
            $q = $pdo->query("SELECT title, manufacturer, model, color, price,
                                     studio_image_url, image_url
                              FROM products
                              WHERE stock_qty > 0 AND (hide_eshop IS NULL OR hide_eshop = 0)
                                AND loan_at IS NULL
                              ORDER BY added_at DESC, id DESC LIMIT 60");
            $map = function_exists('crmModelPhotoMap') ? crmModelPhotoMap() : [];
            $abs = static function (string $u): string {
                $u = function_exists('productImageDisplayUrl') ? productImageDisplayUrl($u) : $u;
                if ($u !== '' && str_starts_with($u, 'media/products/')) { $u = 'https://admin.applefix.cloud/' . ltrim($u, '/'); }
                return $u;
            };
            $items = [];
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $img = trim((string)($p['studio_image_url'] ?? '')) ?: trim((string)($p['image_url'] ?? ''));
                if ($img === '' && $map && function_exists('productModelKey')) {
                    $mk = productModelKey($p['model'] ?? '', $p['color'] ?? '');
                    if ($mk !== '' && !empty($map[$mk])) { $img = (string)$map[$mk]; }
                }
                $img = $abs($img);
                if ($img === '') continue;   // bez fotky se na displeji nedá nic ukázat
                $items[] = [
                    'title' => (string)$p['title'],
                    'manufacturer' => (string)($p['manufacturer'] ?? ''),
                    'price' => (float)$p['price'],
                    'image' => $img,
                ];
                if (count($items) >= 10) break;
            }
        } catch (Throwable $e) { $items = []; }
        echo json_encode(['ok' => true, 'products' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reply' && $method === 'POST') {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        // Bílá listina polí + tvrdé délkové limity — nic jiného se neuloží.
        $clean = [];
        $fields = ['customer_type' => 20, 'first_name' => 80, 'last_name' => 80,
                   'phone' => 40, 'email' => 120, 'address' => 300,
                   'ico' => 20, 'dic' => 20, 'company_name' => 160];
        foreach ($fields as $k => $max) {
            $v = trim((string)($data[$k] ?? ''));
            if ($v !== '') $clean[$k] = mb_substr(strip_tags($v), 0, $max);
        }
        if (($clean['customer_type'] ?? '') !== 'company') $clean['customer_type'] = 'private';
        if (($clean['first_name'] ?? '') === '' && ($clean['company_name'] ?? '') === '') cdFail(400, 'empty');
        // Odpověď se přijme JEN když obsluha formulář opravdu vyžádala
        // (mode = client_form) a hned se „spotřebuje" (mode → idle bez zvednutí
        // state_id, displej dobíhá poděkování sám). Brání podvrženým odpovědím
        // od kohokoli, kdo zná URL displeje, i spamu mimo vyžádání.
        $upd = $pdo->prepare("UPDATE customer_display
                              SET reply_id = reply_id + 1, reply = ?, mode = 'idle', payload = NULL
                              WHERE branch_id = ? AND mode = 'client_form'");
        $upd->execute([json_encode($clean, JSON_UNESCAPED_UNICODE), $branch]);
        if ($upd->rowCount() === 0) cdFail(409, 'not_requested');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    cdFail(400, 'bad_action');
}

/* ── Zaměstnanecká strana (session) ──────────────────────────────────── */
if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) cdFail(401, 'unauthorized');
$branch = (int)getCurrentStaffBranchId();
if ($branch <= 0) cdFail(400, 'no_branch');

if ($method === 'POST' && !validateCsrfToken((string)($body['csrf_token'] ?? ''))) cdFail(403, 'csrf');

if ($action === 'info') {
    $sel = $pdo->prepare("SELECT token, state_id, mode, reply_id, updated_at FROM customer_display WHERE branch_id = ?");
    $sel->execute([$branch]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        try {
            $newToken = bin2hex(random_bytes(24));
            $pdo->prepare("INSERT INTO customer_display (branch_id, token, mode) VALUES (?, ?, 'idle')")
                ->execute([$branch, $newToken]);
            $row = ['token' => $newToken, 'state_id' => 0, 'mode' => 'idle', 'reply_id' => 0];
        } catch (Throwable $e) {
            // souběh dvou zařízení při prvním použití → řádek už existuje
            $sel->execute([$branch]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) cdFail(500, 'internal_error');
        }
    }
    // indikátor musí ukazovat totéž co displej: účtenka po 30 s (a cokoli po
    // 30 min) expiruje na reklamu — displej se k ní sám vrací
    $infoMode = (string)$row['mode'];
    $infoAge = !empty($row['updated_at']) ? time() - strtotime((string)$row['updated_at']) : 0;
    if (($infoMode !== 'idle' && $infoAge > 1800) || ($infoMode === 'receipt' && $infoAge > 30)) {
        $infoMode = 'idle';
    }
    echo json_encode([
        'ok' => true,
        'url' => 'display.php?token=' . $row['token'],
        'mode' => $infoMode,
        'state_id' => (int)$row['state_id'],
        'reply_id' => (int)$row['reply_id'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'push' && $method === 'POST') {
    $mode = (string)($body['mode'] ?? '');
    if (!in_array($mode, $allowedModes, true)) cdFail(400, 'bad_mode');
    $payload = $body['payload'] ?? null;
    $json = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    if ($json !== null && strlen($json) > 100000) cdFail(400, 'payload_too_big');
    // Řádek nemusí existovat (displej ještě nebyl nastaven) — pak push jen no-op info
    $upd = $pdo->prepare("UPDATE customer_display
                          SET state_id = state_id + 1, mode = ?, payload = ?
                          WHERE branch_id = ?");
    $upd->execute([$mode, $json, $branch]);
    if ($upd->rowCount() === 0) {
        $newToken = bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO customer_display (branch_id, token, state_id, mode, payload) VALUES (?, ?, 1, ?, ?)")
            ->execute([$branch, $newToken, $mode, $json]);
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'reply') {
    $after = isset($_GET['after']) ? max(0, (int)$_GET['after']) : 0;
    $sel = $pdo->prepare("SELECT reply_id, reply FROM customer_display WHERE branch_id = ?");
    $sel->execute([$branch]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    $replyId = (int)($row['reply_id'] ?? 0);
    if (!$row || $replyId <= $after) {
        echo json_encode(['ok' => true, 'reply_id' => max($after, $replyId)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $reply = json_decode((string)($row['reply'] ?? ''), true);
    echo json_encode(['ok' => true, 'reply_id' => $replyId,
                      'reply' => is_array($reply) ? $reply : null], JSON_UNESCAPED_UNICODE);
    exit;
}

cdFail(400, 'bad_action');
