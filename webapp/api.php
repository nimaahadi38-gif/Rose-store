<?php
/**
 * Rose Store WebApp API
 * همه endpoint ها نیاز به X-Telegram-Init-Data header دارند
 */

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/../core/Xui.php';
require_once __DIR__ . '/../core/Jalali.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Telegram-Init-Data, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ========================
// Authentication
// ========================

$config  = require __DIR__ . '/../config/settings.php';
$auth    = new TelegramWebAuth($config['bot_token']);
$raw     = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
$tg_user = $auth->validate($raw);

if (!$tg_user) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo      = DB::connect($config);
$settings = getSettings();
$user_id  = (int)$tg_user['id'];

// بررسی ادمین
$stmt = $pdo->prepare("SELECT 1 FROM admins WHERE chat_id = ?");
$stmt->execute([$user_id]);
$is_admin = (bool)$stmt->fetchColumn() || $user_id === (int)$config['admin_id'];

// ========================
// Router
// ========================

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_user_context':
        echo json_encode(['ok' => true, 'data' => getUserContext($pdo, $user_id, $is_admin, $tg_user)]);
        break;

    case 'get_services':
        echo json_encode(['ok' => true, 'data' => getUserServices($pdo, $user_id, $settings)]);
        break;

    case 'get_stats':
        if (!$is_admin) { forbidden(); break; }
        echo json_encode(['ok' => true, 'data' => getAdminStats($pdo, $settings)]);
        break;

    case 'get_pending_receipts':
        if (!$is_admin) { forbidden(); break; }
        echo json_encode(['ok' => true, 'data' => getPendingReceipts($pdo)]);
        break;

    case 'approve_receipt':
        if (!$is_admin) { forbidden(); break; }
        $tx_id = (int)($_POST['tx_id'] ?? 0);
        echo json_encode(approveReceipt($pdo, $config, $settings, $tx_id, $user_id));
        break;

    case 'reject_receipt':
        if (!$is_admin) { forbidden(); break; }
        $tx_id = (int)($_POST['tx_id'] ?? 0);
        echo json_encode(rejectReceipt($pdo, $config, $tx_id));
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}

// ========================
// Helpers
// ========================

function forbidden(): void {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
}

function getUserContext(PDO $pdo, int $user_id, bool $is_admin, array $tg_user): array {
    $stmt = $pdo->prepare("SELECT wallet, is_reseller, trial_used, join_date FROM users WHERE chat_id = ?");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?? [];

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE chat_id = ?");
    $stmt2->execute([$user_id]);
    $service_count = (int)$stmt2->fetchColumn();

    return [
        'user_id'       => $user_id,
        'name'          => trim(($tg_user['first_name'] ?? '') . ' ' . ($tg_user['last_name'] ?? '')),
        'username'      => $tg_user['username'] ?? '',
        'wallet'        => (int)($row['wallet'] ?? 0),
        'is_reseller'   => (bool)($row['is_reseller'] ?? false),
        'trial_used'    => (bool)($row['trial_used'] ?? false),
        'service_count' => $service_count,
        'join_date'     => $row['join_date'] ?? null,
        'is_admin'      => $is_admin,
    ];
}

function getUserServices(PDO $pdo, int $user_id, array $settings): array {
    $stmt = $pdo->prepare(
        "SELECT o.id, o.email, o.plan_name, o.link, o.buy_date,
                s.url AS server_url, s.user AS server_user, s.pass AS server_pass
         FROM orders o
         LEFT JOIN servers s ON s.id = o.server_id
         WHERE o.chat_id = ?
         ORDER BY o.buy_date DESC"
    );
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $services = [];
    foreach ($orders as $order) {
        if (!empty($order['server_url'])) {
            $xui = new Xui($order['server_url'], $order['server_user'], $order['server_pass']);
        } else {
            $xui = new Xui($settings['panel_url'] ?? '', $settings['panel_user'] ?? '', $settings['panel_pass'] ?? '');
        }

        $stats = $xui->getClientStats($order['email']);

        $service = [
            'id'        => (int)$order['id'],
            'plan_name' => $order['plan_name'],
            'email'     => $order['email'],
            'link'      => $order['link'],
            'buy_date'  => $order['buy_date'],
        ];

        if ($stats) {
            $total  = (int)$stats['total'];
            $used   = (int)$stats['up'] + (int)$stats['down'];
            $rem    = max(0, $total - $used);
            $exp_ts = $stats['expiryTime'] ? (int)($stats['expiryTime'] / 1000) : 0;

            $service['status']        = $stats['enable'] ? 'active' : 'disabled';
            $service['total_bytes']   = $total;
            $service['used_bytes']    = $used;
            $service['remain_bytes']  = $rem;
            $service['used_pct']      = $total > 0 ? min(100, (int)(($used / $total) * 100)) : 0;
            $service['total_fmt']     = $total == 0 ? 'نامحدود' : Utils::formatBytes($total);
            $service['used_fmt']      = Utils::formatBytes($used);
            $service['remain_fmt']    = $total == 0 ? 'نامحدود' : Utils::formatBytes($rem);
            $service['expiry_jalali'] = $exp_ts > 0 ? Jalali::toJalali($exp_ts) : 'نامحدود';
            $service['days_until']    = $exp_ts > 0 ? Jalali::daysUntil($exp_ts) : 'نامحدود';
        } else {
            $service['status'] = 'unknown';
        }

        $services[] = $service;
        usleep(50000);
    }

    return $services;
}

function getAdminStats(PDO $pdo, array $settings): array {
    $total_users    = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $total_orders   = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $total_revenue  = (int)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status IN ('confirmed','auto_confirmed')")->fetchColumn();
    $pending_count  = (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'awaiting_receipt'")->fetchColumn();
    $resellers      = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_reseller = 1")->fetchColumn();

    $today_revenue = (int)$pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM transactions
         WHERE status IN ('confirmed','auto_confirmed') AND DATE(created_at) = CURDATE()"
    )->fetchColumn();

    return [
        'total_users'    => $total_users,
        'total_orders'   => $total_orders,
        'total_revenue'  => $total_revenue,
        'today_revenue'  => $today_revenue,
        'pending_count'  => $pending_count,
        'resellers'      => $resellers,
        'bot_status'     => $settings['bot_status'] ?? '1',
        'campaign_active' => ($settings['campaign_status'] ?? '0') == '1',
        'campaign_pct'   => (int)($settings['campaign_pct'] ?? 0),
        'campaign_label' => $settings['campaign_label'] ?? '',
    ];
}

function getPendingReceipts(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT id, chat_id, amount, plan_id, code, created_at, type
         FROM transactions
         WHERE status = 'awaiting_receipt'
         ORDER BY created_at ASC
         LIMIT 50"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function approveReceipt(PDO $pdo, array $config, array $settings, int $tx_id, int $admin_id): array {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND status = 'awaiting_receipt'");
    $stmt->execute([$tx_id]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) return ['ok' => false, 'error' => 'تراکنش یافت نشد'];

    // برای تایید رسید نیاز به Telegram + ServerManager + PaymentHandler داریم
    require_once __DIR__ . '/../core/Telegram.php';
    require_once __DIR__ . '/../core/ServerManager.php';
    require_once __DIR__ . '/../handlers/PaymentHandler.php';
    $telegram      = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');
    $storage_dir   = __DIR__ . '/../storage';
    $serverManager = new ServerManager($pdo, $storage_dir);
    $payment       = new PaymentHandler($pdo, $telegram, $settings, $config, $serverManager);

    $msg = $payment->approveReceiptOrder((int)$tx['chat_id'], $tx['plan_id'], $tx['code'] ?? 'none', $admin_id);
    $pdo->prepare("UPDATE transactions SET status = 'confirmed' WHERE id = ?")->execute([$tx_id]);
    return ['ok' => true, 'message' => $msg];
}

function rejectReceipt(PDO $pdo, array $config, int $tx_id): array {
    $stmt = $pdo->prepare("SELECT chat_id FROM transactions WHERE id = ?");
    $stmt->execute([$tx_id]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) return ['ok' => false, 'error' => 'تراکنش یافت نشد'];

    require_once __DIR__ . '/../core/Telegram.php';
    $telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');
    $telegram->sendMessage((int)$tx['chat_id'], '❌ <b>رسید شما رد شد.</b> با پشتیبانی تماس بگیرید.', 'HTML');
    $pdo->prepare("UPDATE transactions SET status = 'rejected' WHERE id = ?")->execute([$tx_id]);
    return ['ok' => true];
}
