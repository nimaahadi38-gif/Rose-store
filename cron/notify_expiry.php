<?php
/**
 * اطلاع‌رسانی انقضای سرویس
 * اجرای روزانه: 0 9 * * * php /path/to/cron/notify_expiry.php
 */

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Telegram.php';
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/../core/Xui.php';
require_once __DIR__ . '/../core/Jalali.php';
require_once __DIR__ . '/../core/ServerManager.php';
require_once __DIR__ . '/../helpers.php';

$config   = require __DIR__ . '/../config/settings.php';
$pdo      = DB::connect($config);
$telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');
$settings = getSettings();

$storage_dir   = __DIR__ . '/../storage';
$serverManager = new ServerManager($pdo, $storage_dir);

const NOTIFY_COOLDOWN_HOURS = 24;
const WARN_DAYS_BEFORE      = 3;

$stmt = $pdo->query(
    "SELECT o.id, o.chat_id, o.email, o.plan_name, o.server_id, o.last_notified,
            s.url AS server_url, s.user AS server_user, s.pass AS server_pass,
            s.inbound_id AS server_inbound, s.sub_domain,
            s.remote_address AS server_remote, s.ws_host AS server_ws_host
     FROM orders o
     LEFT JOIN servers s ON s.id = o.server_id
     WHERE (o.last_notified IS NULL OR o.last_notified < DATE_SUB(NOW(), INTERVAL " . NOTIFY_COOLDOWN_HOURS . " HOUR))"
);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sent   = 0;
$errors = 0;

echo "[" . date('Y-m-d H:i:s') . "] بررسی " . count($orders) . " سفارش...\n";

foreach ($orders as $order) {
    $stats = fetchClientStats($order, $settings);
    if (!$stats) { $errors++; continue; }

    $expiry_ms = (int)($stats['expiryTime'] ?? 0);
    if ($expiry_ms === 0) continue;

    $expiry_ts   = (int)($expiry_ms / 1000);
    $remain_ms   = $expiry_ms - (time() * 1000);
    $remain_days = $remain_ms / (86400 * 1000);

    if ($remain_days > WARN_DAYS_BEFORE || $remain_days < 0) continue;

    $used_bytes  = (int)($stats['up'] ?? 0) + (int)($stats['down'] ?? 0);
    $total_bytes = (int)($stats['total'] ?? 0);
    $rem_bytes   = $total_bytes > 0 ? max(0, $total_bytes - $used_bytes) : -1;

    $rows = [
        "📦 پلن: <b>{$order['plan_name']}</b>",
        "🔑 شناسه: <code>{$order['email']}</code>",
        ($stats['enable'] ? '🟢' : '🔴') . " وضعیت: " . ($stats['enable'] ? 'فعال' : 'غیرفعال'),
        "⚡ باقیمانده: <code>" . ($rem_bytes < 0 ? 'نامحدود' : Utils::formatBytes($rem_bytes)) . "</code>",
        "⏳ انقضا: " . Jalali::toJalali($expiry_ts),
        "📅 زمان باقیمانده: " . Jalali::daysUntil($expiry_ts),
    ];
    $msg = formatCard('هشدار انقضای سرویس', $rows);

    $oid  = (int)$order['id'];
    $keys = json_encode(['inline_keyboard' => [
        [['text' => '🔄 تمدید سرویس', 'callback_data' => "myserv_renew_{$oid}"]],
    ]]);

    $res = $telegram->request('sendMessage', [
        'chat_id'      => (int)$order['chat_id'],
        'text'         => $msg,
        'parse_mode'   => 'HTML',
        'reply_markup' => $keys,
    ]);

    if (isset($res['ok']) && $res['ok']) {
        $pdo->prepare("UPDATE orders SET last_notified = NOW() WHERE id = ?")->execute([$order['id']]);
        $sent++;
        echo "  ✅ ارسال به {$order['chat_id']} برای {$order['email']}\n";
    } else {
        $errors++;
        echo "  ❌ خطا برای {$order['chat_id']}: " . ($res['description'] ?? 'ناشناخته') . "\n";
    }

    usleep(100000);
}

echo "[" . date('Y-m-d H:i:s') . "] پایان: $sent اطلاعیه ارسال شد، $errors خطا.\n";

function fetchClientStats(array $order, array $settings): ?array {
    if (!empty($order['server_url'])) {
        $xui = new Xui(
            $order['server_url'], $order['server_user'], $order['server_pass'], '',
            $order['server_remote'] ?? '', $order['server_ws_host'] ?? ''
        );
    } else {
        $xui = new Xui(
            $settings['panel_url']  ?? 'http://127.0.0.1:2053',
            $settings['panel_user'] ?? 'admin',
            $settings['panel_pass'] ?? 'admin',
            '',
            $settings['remote_address'] ?? '',
            $settings['ws_host'] ?? ''
        );
    }
    return $xui->getClientStats($order['email']);
}
