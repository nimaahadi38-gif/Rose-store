<?php
/**
 * هشدار حجم کم
 * اجرای هر ۶ ساعت: 0 */6 * * * php /path/to/cron/notify_volume.php
 *
 * اگر حجم باقیمانده < ۱ گیگ یا < ۱۰٪ از کل بود، یک بار هشدار می‌دهد (volume_warned=1)
 */

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Telegram.php';
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/../core/Xui.php';
require_once __DIR__ . '/../core/Jalali.php';
require_once __DIR__ . '/../helpers.php';

$config   = require __DIR__ . '/../config/settings.php';
$pdo      = DB::connect($config);
$telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');
$settings = getSettings();

$LOW_GB        = 1 * 1024 * 1024 * 1024; // 1 GB در byte
$LOW_PCT       = 10;                       // 10 درصد

$stmt = $pdo->query(
    "SELECT o.id, o.chat_id, o.email, o.plan_name,
            s.url AS server_url, s.user AS server_user, s.pass AS server_pass
     FROM orders o
     LEFT JOIN servers s ON s.id = o.server_id
     WHERE (o.volume_warned = 0 OR o.volume_warned IS NULL)"
);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sent   = 0;
$errors = 0;

echo "[" . date('Y-m-d H:i:s') . "] بررسی حجم " . count($orders) . " سرویس...\n";

foreach ($orders as $order) {
    if (!empty($order['server_url'])) {
        $xui = new Xui($order['server_url'], $order['server_user'], $order['server_pass']);
    } else {
        $xui = new Xui($settings['panel_url'] ?? 'http://127.0.0.1:2053',
                       $settings['panel_user'] ?? 'admin', $settings['panel_pass'] ?? 'admin');
    }

    $stats = $xui->getClientStats($order['email']);
    if (!$stats) { $errors++; continue; }

    $total = (int)($stats['total'] ?? 0);
    if ($total == 0) continue; // نامحدود

    $used = (int)($stats['up'] ?? 0) + (int)($stats['down'] ?? 0);
    $rem  = max(0, $total - $used);
    $pct  = $total > 0 ? (int)(($rem / $total) * 100) : 100;

    if ($rem > $LOW_GB && $pct > $LOW_PCT) continue;

    $rows = [
        "📦 پلن: <b>{$order['plan_name']}</b>",
        "🔑 شناسه: <code>{$order['email']}</code>",
        "📊 مصرف: <code>" . Utils::formatBytes($used) . "</code> از " . Utils::formatBytes($total),
        "⚡ باقیمانده: <code>" . Utils::formatBytes($rem) . "</code> ({$pct}٪)",
        "⚠️ حجم شما رو به اتمام است!",
    ];
    $msg  = formatCard('هشدار حجم کم', $rows);
    $oid  = (int)$order['id'];
    $keys = json_encode(['inline_keyboard' => [
        [['text' => '➕ خرید حجم اضافه / تمدید', 'callback_data' => "myserv_renew_{$oid}"]],
    ]]);

    $res = $telegram->request('sendMessage', [
        'chat_id'      => (int)$order['chat_id'],
        'text'         => $msg,
        'parse_mode'   => 'HTML',
        'reply_markup' => $keys,
    ]);

    if (isset($res['ok']) && $res['ok']) {
        $pdo->prepare("UPDATE orders SET volume_warned = 1 WHERE id = ?")->execute([$order['id']]);
        $sent++;
        echo "  ✅ هشدار حجم به {$order['chat_id']} برای {$order['email']}\n";
    } else {
        $errors++;
        echo "  ❌ خطا برای {$order['chat_id']}: " . ($res['description'] ?? 'ناشناخته') . "\n";
    }

    usleep(100000);
}

echo "[" . date('Y-m-d H:i:s') . "] پایان: $sent هشدار ارسال شد، $errors خطا.\n";
