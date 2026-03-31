<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

clientRequireAuth();

$customerId = (int)($_SESSION['client_customer_id'] ?? 0);
$selectedOrderId = isset($_GET['order']) ? (int)$_GET['order'] : (int)($_SESSION['client_order_id'] ?? 0);

$customer = null;
$orders = [];

if (isset($pdo) && $customerId > 0) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC');
        $stmt->execute([$customerId]);
        $orders = $stmt->fetchAll();

        if (!$orders) {
            $selectedOrderId = 0;
        } elseif ($selectedOrderId <= 0) {
            $selectedOrderId = (int)$orders[0]['id'];
        } else {
            $belongsToCustomer = false;
            foreach ($orders as $o) {
                if ((int)$o['id'] === $selectedOrderId) {
                    $belongsToCustomer = true;
                    break;
                }
            }
            if (!$belongsToCustomer) {
                $selectedOrderId = (int)$orders[0]['id'];
            }
        }
    } catch (Exception $e) {
        $customer = null;
        $orders = [];
    }
}

$selectedOrder = null;
foreach ($orders as $order) {
    if ((int)$order['id'] === $selectedOrderId) {
        $selectedOrder = $order;
        break;
    }
}
if (!$selectedOrder && !empty($orders)) {
    $selectedOrder = $orders[0];
}

function clientStatusMeta(string $status): array {
    $map = [
        'New' => ['Přijato', 'primary'],
        'Pending Approval' => ['Čeká na schválení', 'info'],
        'In Progress' => ['V procesu', 'warning'],
        'Waiting for Parts' => ['Čeká na díly', 'secondary'],
        'Completed' => ['Připraveno k vyzvednutí', 'success'],
        'Collected' => ['Vydáno', 'dark'],
        'Cancelled' => ['Zrušeno', 'danger'],
    ];

    return $map[$status] ?? [trim((string)$status), 'light'];
}

function clientMoney($amount): string {
    if ($amount === null || $amount === '') {
        return '—';
    }
    return number_format((float)$amount, 2, ',', ' ') . ' Kč';
}

function clientOrderAmount(array $order): ?float {
    if (isset($order['final_cost']) && $order['final_cost'] !== null && $order['final_cost'] !== '') {
        return (float)$order['final_cost'];
    }
    if (isset($order['estimated_cost']) && $order['estimated_cost'] !== null && $order['estimated_cost'] !== '') {
        return (float)$order['estimated_cost'];
    }
    return null;
}

function clientOrderAmountLabel(array $order): string {
    if (isset($order['final_cost']) && $order['final_cost'] !== null && $order['final_cost'] !== '') {
        return 'Konečná cena opravy';
    }
    if (isset($order['estimated_cost']) && $order['estimated_cost'] !== null && $order['estimated_cost'] !== '') {
        return 'Orientační cena opravy';
    }
    return 'Cena';
}

$customerDisplayName = trim((string)($_SESSION['client_full_name'] ?? ''));
if ($customerDisplayName === '' && $customer) {
    $customerDisplayName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
}
if ($customerDisplayName === '' && !empty($customer['company'])) {
    $customerDisplayName = $customer['company'];
}

$today = date('d.m.Y');
?>
<!DOCTYPE html>
<html lang="<?php echo e($_SESSION['lang'] ?? 'cs'); ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klientská sekce - AppleFix</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #05070b 0%, #090d12 48%, #12161b 100%);
            color: #eef4ff;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .client-shell {
            width: min(100%, 1440px);
            margin: 0 auto;
            padding: 24px 18px 32px;
        }

        .client-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 22px;
            padding: 18px 20px;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.08);
            background: rgba(255,255,255,0.045);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .client-topbar-brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .client-topbar-brand img {
            width: 150px;
            height: auto;
            object-fit: contain;
        }

        .client-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: flex-end;
            color: rgba(243,247,255,0.84);
            font-size: 0.92rem;
        }

        .client-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.055);
            border: 1px solid rgba(255,255,255,0.08);
            font-weight: 600;
        }

        .client-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.8fr);
            gap: 20px;
        }

        .client-card {
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,0.08);
            background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.045));
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 18px 54px rgba(0,0,0,0.24);
            overflow: hidden;
        }

        .client-card-inner {
            padding: 24px;
        }

        .client-hero h1 {
            margin: 0;
            font-size: clamp(2rem, 3vw, 3.2rem);
            line-height: 0.95;
            letter-spacing: -0.06em;
            font-weight: 800;
            color: #f4f7fb;
        }

        .client-hero p {
            margin: 14px 0 0;
            color: rgba(243,247,255,0.76);
            max-width: 640px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 22px;
        }

        .stat-box {
            padding: 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .stat-box .label {
            font-size: 0.76rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(243,247,255,0.62);
            font-weight: 800;
        }

        .stat-box .value {
            margin-top: 8px;
            font-size: 1.25rem;
            font-weight: 800;
            color: #fff;
        }

        .repair-status {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }

        .status-badge-large {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 16px;
            font-weight: 800;
            border: 1px solid transparent;
        }

        .status-badge-large.primary { background: rgba(13,110,253,0.15); border-color: rgba(13,110,253,0.25); color: #7ab2ff; }
        .status-badge-large.info { background: rgba(13,202,240,0.15); border-color: rgba(13,202,240,0.25); color: #82e8ff; }
        .status-badge-large.warning { background: rgba(255,193,7,0.15); border-color: rgba(255,193,7,0.25); color: #ffd86a; }
        .status-badge-large.secondary { background: rgba(108,117,125,0.15); border-color: rgba(108,117,125,0.25); color: #c8d1da; }
        .status-badge-large.success { background: rgba(25,135,84,0.15); border-color: rgba(25,135,84,0.25); color: #7fe6ad; }
        .status-badge-large.danger { background: rgba(220,53,69,0.15); border-color: rgba(220,53,69,0.25); color: #ff8d99; }
        .status-badge-large.dark { background: rgba(52,58,64,0.3); border-color: rgba(255,255,255,0.08); color: #f1f4f8; }
        .status-badge-large.light { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.08); color: #fff; }

        .repair-list {
            display: grid;
            gap: 12px;
        }

        .repair-item {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(255,255,255,0.045);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(243,247,255,0.86);
            text-decoration: none;
            transition: transform .15s ease, background .15s ease, border-color .15s ease;
        }

        .repair-item:hover {
            transform: translateY(-1px);
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.12);
            color: #fff;
        }

        .repair-item.active {
            border-color: rgba(25,167,255,0.3);
            background: rgba(25,167,255,0.09);
        }

        .repair-item .title {
            font-weight: 800;
            color: #fff;
        }

        .repair-item .sub {
            font-size: 0.88rem;
            color: rgba(243,247,255,0.66);
        }

        .repair-details-grid {
            display: grid;
            gap: 12px;
            margin-top: 18px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(255,255,255,0.035);
            border: 1px solid rgba(255,255,255,0.07);
        }

        .detail-row .label {
            color: rgba(243,247,255,0.68);
            font-size: 0.85rem;
            font-weight: 700;
        }

        .detail-row .value {
            color: #fff;
            font-weight: 700;
            text-align: right;
        }

        .order-notice {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(25,135,84,0.12);
            border: 1px solid rgba(25,135,84,0.25);
            color: #b4f4d1;
            font-weight: 600;
        }

        .empty-state {
            padding: 28px;
            text-align: center;
            color: rgba(243,247,255,0.68);
        }

        @media (max-width: 1100px) {
            .client-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .client-topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .client-meta {
                justify-content: flex-start;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="client-shell">
        <div class="client-topbar">
            <div class="client-topbar-brand">
                <img src="../assets/img/applefix-logo.png" alt="AppleFix logo">
                <div>
                    <div class="client-chip mb-2"><i class="fas fa-user-shield"></i> Klientská sekce</div>
                    <div class="text-white-50 small">AppleFix — oddělený přístup pro zákazníky</div>
                </div>
            </div>
            <div class="client-meta">
                <span class="client-chip"><i class="fas fa-calendar-day"></i> <?php echo e($today); ?></span>
                <span class="client-chip"><i class="fas fa-user"></i> <?php echo e($customerDisplayName ?: 'Klient'); ?></span>
                <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fas fa-right-from-bracket me-2"></i>Odhlásit</a>
            </div>
        </div>

        <div class="client-grid">
            <section class="client-card client-hero">
                <div class="client-card-inner">
                    <h1>Stav opravy<br>a cena na jednom místě.</h1>
                    <p>
                        Tohle je oddělená klientská sekce pro AppleFix. Přihlašuješ se přes číslo zakázky a PIN kód z protokolu,
                        takže admin login zůstává čistě pro servis.
                    </p>

                    <?php if ($selectedOrder): ?>
                        <?php [$statusLabel, $statusClass] = clientStatusMeta((string)$selectedOrder['status']); ?>
                        <div class="repair-status">
                            <span class="status-badge-large <?php echo e($statusClass); ?>">
                                <i class="fas fa-circle-info"></i>
                                <?php echo e($statusLabel); ?>
                            </span>
                            <span class="status-badge-large light">
                                <i class="fas fa-hashtag"></i>
                                Zakázka #<?php echo (int)$selectedOrder['id']; ?>
                            </span>
                        </div>

                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="label">Zařízení</div>
                                <div class="value"><?php echo e(trim(($selectedOrder['device_brand'] ?? '') . ' ' . ($selectedOrder['device_model'] ?? '')) ?: '—'); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="label"><?php echo e(clientOrderAmountLabel($selectedOrder)); ?></div>
                                <div class="value"><?php echo e(clientMoney(clientOrderAmount($selectedOrder))); ?></div>
                            </div>
                            <div class="stat-box">
                                <div class="label">Poslední změna</div>
                                <div class="value"><?php echo e(date('d.m.Y H:i', strtotime((string)($selectedOrder['updated_at'] ?? $selectedOrder['created_at'] ?? 'now')))); ?></div>
                            </div>
                        </div>

                        <div class="repair-details-grid">
                            <div class="detail-row">
                                <div class="label">Popis závady</div>
                                <div class="value"><?php echo e($selectedOrder['problem_description'] ?: '—'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="label">Sériové číslo / IMEI</div>
                                <div class="value"><?php echo e($selectedOrder['serial_number'] ?: '—'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="label">Druhé číslo / IMEI 2</div>
                                <div class="value"><?php echo e($selectedOrder['serial_number_2'] ?: '—'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="label">Přijato</div>
                                <div class="value"><?php echo e(date('d.m.Y', strtotime((string)($selectedOrder['created_at'] ?? 'now')))); ?></div>
                            </div>
                        </div>

                        <?php if (in_array($selectedOrder['status'], ['Completed', 'Collected'], true)): ?>
                            <div class="order-notice">
                                <i class="fas fa-box-open me-2"></i>
                                Zařízení je připravené k vyzvednutí nebo už bylo vydáno.
                            </div>
                        <?php elseif ($selectedOrder['status'] === 'Waiting for Parts'): ?>
                            <div class="order-notice" style="background: rgba(255,193,7,0.12); border-color: rgba(255,193,7,0.25); color: #ffe08a;">
                                <i class="fas fa-screwdriver-wrench me-2"></i>
                                Oprava čeká na díly.
                            </div>
                        <?php elseif ($selectedOrder['status'] === 'In Progress'): ?>
                            <div class="order-notice" style="background: rgba(13,202,240,0.12); border-color: rgba(13,202,240,0.25); color: #8be8ff;">
                                <i class="fas fa-gears me-2"></i>
                                Technik na zakázce pracuje.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-folder-open fa-2x mb-3"></i>
                            <div>Pro tento účet zatím nemáme žádnou aktivní zakázku.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="client-card">
                <div class="client-card-inner">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <div class="client-chip mb-2"><i class="fas fa-list-check"></i> Moje zakázky</div>
                            <h3 class="mb-0 text-white fw-bold" style="letter-spacing: -0.04em;">Přehled oprav</h3>
                        </div>
                    </div>

                    <div class="repair-list">
                        <?php if (empty($orders)): ?>
                            <div class="empty-state">Zatím žádné zakázky.</div>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php [$statusLabel, $statusClass] = clientStatusMeta((string)$order['status']); ?>
                                <a class="repair-item <?php echo (int)$order['id'] === (int)$selectedOrderId ? 'active' : ''; ?>" href="?order=<?php echo (int)$order['id']; ?>">
                                    <div>
                                        <div class="title">#<?php echo (int)$order['id']; ?> · <?php echo e(trim(($order['device_brand'] ?? '') . ' ' . ($order['device_model'] ?? '')) ?: 'Zařízení'); ?></div>
                                        <div class="sub">
                                            <?php echo e($statusLabel); ?> · <?php echo e(clientMoney(clientOrderAmount($order))); ?>
                                        </div>
                                    </div>
                                    <span class="status-badge-large <?php echo e($statusClass); ?> py-2 px-3">
                                        <?php echo e($statusLabel); ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
