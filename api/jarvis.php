<?php
/**
 * Rozhraní pro hlasového asistenta Jarvis (server-to-server).
 *
 * Příjem zakázky je jinak jen formulář s přihlášením a CSRF (api/add_order.php), který na konci
 * přesměruje, takže ho program volat neumí. Tady je totéž pro diktovaný příjem: stejná pole,
 * stejná kontrola duplicit, číslování, audit i upozornění.
 *
 * Auth: jen hlavička X-Jarvis-Token proti settingu `jarvis_api_token`, který vytváří admin
 * v Nastavení. Prázdný setting = rozhraní vypnuté. Žádná výjimka pro localhost (za reverzní
 * proxy tak vypadá každý požadavek) a žádný token v URL (skončil by v logu).
 *
 * Jarvis nemá v CRM vlastní účet: token patří profilu, který ho vytvořil (`jarvis_api_profile`),
 * a požadavek běží pod jeho identitou. Audit, pobočka i upozornění jsou tak stejné jako u ručního
 * příjmu. Identita platí jen po dobu požadavku, do uložené session se nezapisuje.
 *
 * GET  ?action=find_customers&q=…      → {ok, customers:[{id,name,phone,company}]}
 * POST ?action=create_customer  {first_name,last_name,phone,email?} → {ok,id,existing}
 * POST ?action=create_order     {customer_id,device_type?,device_brand?,device_model,pin_code,
 *                                problem_description,serial_number?,serial_number_2?,appearance?,
 *                                technician_notes?,order_type?,priority?} → {ok,order_id,order_code,url,duplicate}
 */
ob_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';
if (ob_get_length()) ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jarvisReply(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jarvisFail(string $error, string $message, int $status): void {
    jarvisReply(['ok' => false, 'error' => $error, 'message' => $message], $status);
}

function jarvisText(array $body, string $key, int $max = 500): string {
    $value = $body[$key] ?? '';
    return is_scalar($value) ? mb_substr(trim((string)$value), 0, $max) : '';
}

function jarvisProfileIsActive(array $profile): bool {
    global $pdo;
    $id = (string)$profile['user_id'];
    if ($id[0] === 't') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM technicians WHERE id = ? AND COALESCE(is_active, 1) = 1');
        $st->execute([(int)substr($id, 1)]);
    } else {
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
        $st->execute([(int)$id]);
    }
    return (int)$st->fetchColumn() > 0;
}

$expected = (string)get_setting('jarvis_api_token', '');
$provided = (string)($_SERVER['HTTP_X_JARVIS_TOKEN'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    jarvisFail('forbidden', 'Neplatný token.', 403);
}

$profile = json_decode((string)get_setting('jarvis_api_profile', ''), true);
if (!is_array($profile) || empty($profile['user_id']) || !jarvisProfileIsActive($profile)) {
    jarvisFail('forbidden', 'Profil, kterému token patří, už v CRM není aktivní.', 403);
}
session_abort();
$_SESSION = $profile;
$action = (string)($_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($action === 'find_customers' && $method === 'GET') {
    $term = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    if (mb_strlen($term) < 2) {
        jarvisReply(['ok' => true, 'customers' => []]);
    }
    // Každé slovo musí sedět na některé pole; telefon se porovnává bez mezer, jak se diktuje.
    $where = [];
    $params = [];
    foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) as $word) {
        $like = '%' . $word . '%';
        $where[] = "(first_name LIKE ? OR last_name LIKE ? OR company LIKE ? OR email LIKE ? OR REPLACE(phone, ' ', '') LIKE ?)";
        array_push($params, $like, $like, $like, $like, $like);
    }
    $st = $pdo->prepare('SELECT id, first_name, last_name, phone, company FROM customers WHERE '
        . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 5');
    $st->execute($params);
    $customers = array_map(static fn($c) => [
        'id' => (int)$c['id'],
        'name' => trim($c['first_name'] . ' ' . $c['last_name']),
        'phone' => (string)$c['phone'],
        'company' => (string)$c['company'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    jarvisReply(['ok' => true, 'customers' => $customers]);
}

if ($method !== 'POST') {
    jarvisFail('bad_request', 'Neznámá akce.', 400);
}
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    jarvisFail('bad_request', 'Tělo požadavku musí být JSON.', 400);
}

if ($action === 'create_customer') {
    $first = jarvisText($body, 'first_name', 100);
    $last = jarvisText($body, 'last_name', 100);
    $phone = jarvisText($body, 'phone', 30);
    $email = jarvisText($body, 'email', 150);
    $digits = preg_replace('/\D+/', '', $phone);
    if ($first === '' || $last === '' || strlen($digits) < 9) {
        jarvisFail('missing_fields', 'Klient potřebuje jméno, příjmení a telefon.', 422);
    }
    // Stejný telefon = stejný klient: diktovaný příjem nesmí vyrábět duplicity.
    $st = $pdo->prepare("SELECT id FROM customers
        WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), 9) = ? ORDER BY id DESC LIMIT 1");
    $st->execute([substr($digits, -9)]);
    $existing = (int)$st->fetchColumn();
    if ($existing > 0) {
        jarvisReply(['ok' => true, 'id' => $existing, 'existing' => true]);
    }
    ensureCustomerLanguageColumn();
    $pdo->prepare('INSERT INTO customers (customer_type, first_name, last_name, phone, email, address, ico, dic, company, preferred_language)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['private', $first, $last, $phone, $email, '', '', '', '', 'cs']);
    $id = (int)$pdo->lastInsertId();
    crmGetOrCreateCardToken($id);
    crmAuditLog('customer.create', [
        'entity_type' => 'customer', 'entity_id' => $id,
        'entity_label' => trim($first . ' ' . $last),
        'summary' => 'Vytvořen klient ' . trim($first . ' ' . $last) . ' — hlasem přes Jarvise',
    ]);
    jarvisReply(['ok' => true, 'id' => $id, 'existing' => false]);
}

if ($action === 'create_order') {
    $customerId = filter_var($body['customer_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $model = jarvisText($body, 'device_model', 150);
    $pin = jarvisText($body, 'pin_code', 100);
    $problem = jarvisText($body, 'problem_description', 2000);
    if ($customerId < 1 || $model === '' || $pin === '' || $problem === '') {
        jarvisFail('missing_fields', 'Zakázka potřebuje klienta, model zařízení, PIN a popis závady.', 422);
    }
    $st = $pdo->prepare('SELECT id FROM customers WHERE id = ?');
    $st->execute([$customerId]);
    if (!$st->fetchColumn()) {
        jarvisFail('unknown_customer', 'Klient v CRM neexistuje.', 422);
    }
    $type = jarvisText($body, 'device_type', 20);
    $type = in_array($type, ['Phone', 'Notebook', 'PC', 'Tablet', 'HDD', 'Other'], true) ? $type : 'Other';
    $orderType = jarvisText($body, 'order_type', 20) === 'Warranty' ? 'Warranty' : 'Non-Warranty';
    $brand = jarvisText($body, 'device_brand', 100);
    $serial = jarvisText($body, 'serial_number', 100);
    $serial2 = jarvisText($body, 'serial_number_2', 100);
    $appearance = jarvisText($body, 'appearance', 255);
    $notes = jarvisText($body, 'technician_notes', 2000);
    $priority = normalizeOrderPriority($body['priority'] ?? '');
    $status = getDefaultOrderStatus();
    $branch = isBranchGlobalViewer() ? getDefaultBranchId() : getCurrentStaffBranchId();
    $createdBy = trim((string)($_SESSION['full_name'] ?? '')) ?: trim((string)($_SESSION['username'] ?? ''));

    // Stejná ochrana jako ruční příjem: týž příjem odeslaný dvakrát vrátí první zakázku.
    $st = $pdo->prepare("SELECT id, order_code FROM orders
        WHERE customer_id = ? AND device_model = ? AND COALESCE(problem_description, '') = ?
          AND created_at >= (NOW() - INTERVAL 30 SECOND) ORDER BY id DESC LIMIT 1");
    $st->execute([$customerId, $model, $problem]);
    if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
        jarvisReply(['ok' => true, 'order_id' => (int)$dup['id'], 'order_code' => (string)$dup['order_code'],
                     'url' => 'view_order.php?id=' . (int)$dup['id'], 'duplicate' => true]);
    }

    ensureOrderPriorityLowValue();
    ensureOrderCreatedByColumn();
    $orderId = 0;
    $code = null;
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            "INSERT INTO orders (customer_id, technician_id, branch_id, device_type, order_type, device_brand, device_model,
             problem_description, technician_notes, serial_number, serial_number_2, pin_code, appearance, priority, estimated_cost, shipping_method, status, order_code, created_by_name)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?, ?)"
        );
        // Souběžný příjem může dostat stejné číslo (viz add_order.php), další se počítá lokálně.
        $code = generateNextOrderCode($pdo);
        for ($attempt = 1; ; $attempt++) {
            try {
                $insert->execute([$customerId, $branch, $type, $orderType, $brand, $model, $problem, $notes,
                                  $serial, $serial2, $pin, $appearance, $priority, $status, $code, $createdBy !== '' ? $createdBy : null]);
                $orderId = (int)$pdo->lastInsertId();
                break;
            } catch (PDOException $e) {
                $next = preg_match('/^([A-Za-z]+)([0-9]+)$/', (string)$code, $m)
                    ? $m[1] . str_pad((string)((int)$m[2] + 1), strlen($m[2]), '0', STR_PAD_LEFT) : null;
                if ((int)($e->errorInfo[1] ?? 0) !== 1062 || $attempt >= 5 || $next === null) {
                    throw $e;
                }
                $code = $next;
                usleep(random_int(40000, 150000));
            }
        }
        assignmentSegmentSync($orderId, null, (string)$status);
        logOrderStatusChange($orderId, '', $status);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('jarvis create_order: ' . $e->getMessage());
        jarvisFail('save_failed', 'Zakázku se nepodařilo uložit.', 500);
    }

    // Zakázka už existuje: chyba auditu ani upozornění nesmí vypadat jako neúspěšné založení.
    try {
        require_once __DIR__ . '/../includes/product_catalog.php';
        crmCatalogRegisterOrderDevice($brand, $type, $model);
        crmAuditLog('order.create', [
            'entity_type' => 'order', 'entity_id' => $orderId,
            'entity_label' => $code ?: ('#' . $orderId),
            'summary' => 'Vytvořena zakázka ' . ($code ?: ('#' . $orderId)) . ' — ' . trim($brand . ' ' . $model) . ' — hlasem přes Jarvise',
            'branch_id' => $branch,
        ]);
        crmNotifyOrderLifecycleEvent([
            'type' => 'order_created', 'order_id' => $orderId, 'technician_id' => 0, 'new_status' => $status,
            'actor_role' => (string)($_SESSION['role'] ?? ''), 'actor_tech_id' => (int)($_SESSION['tech_id'] ?? 0),
            'actor_name' => (string)($_SESSION['full_name'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('jarvis post-commit, zakázka #' . $orderId . ' vznikla: ' . $e->getMessage());
    }
    jarvisReply(['ok' => true, 'order_id' => $orderId, 'order_code' => (string)$code,
                 'url' => 'view_order.php?id=' . $orderId, 'duplicate' => false]);
}

jarvisFail('bad_request', 'Neznámá akce.', 400);
