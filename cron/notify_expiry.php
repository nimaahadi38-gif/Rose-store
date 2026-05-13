<?php
/**
 * اطلاع‌رسانی انقضای سرویس
 * اجرای روزانه: 0 9 * * * php /path/to/cron/notify_expiry.php
 *
 * منطق:
 *   - برای هر سفارش فعال، وضعیت انقضا را از XUI دریافت کن
 *   - اگر ≤ 3 روز تا انقضا مانده و هنوز در 24 ساعت گذشته اطلاع داده نشده، پیام بفرست
 */

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Telegram.php';
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/../core/Xui.php';
require_once __DIR__ . '/../core/ServerManager.php';
require_once __DIR__ . '/../helpers.php';

$config   = require __DIR__ . '/../config/settings.php';
$pdo      = DB::connect($config);
$telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');
$settings = getSettings();

$storage_dir   = __DIR__ . '/../storage';
$serverManager = new ServerManager($pdo, $storage_dir);

// ساعت‌های ارسال مجدد (۲۴ ساعت = روزی یکبار)
const NOTIFY_COOLDOWN_HOURS = 24;
const WARN_DAYS_BEFORE      = 3;

// دریافت همه سفارشات فعال که هنوز notified نشده‌اند یا cooldown گذشته
$stmt = $pdo->query(
    "SELECT o.id, o.chat_id, o.email, o.plan_name, o.server_id, o.last_notified,
            s.url AS server_url, s.user AS server_user, s.pass AS server_pass,
            s.inbound_id AS server_inbound, s.sub_domain,
            st.setting_value AS panel_url
     FROM orders o
     LEFT JOIN servers s ON s.id = o.server_id
     LEFT JOIN settings st ON st.setting_key = 'panel_url'
     WHERE (o.last_notified IS NULL OR o.last_notified < DATE_SUB(NOW(), INTERVAL " . NOTIFY_COOLDOWN_HOURS . " HOUR))"
);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sent   = 0;
$errors = 0;

echo "[" . date('Y-m-d H:i:s') . "] بررسی " . count($orders) . " سفارش...\n";

foreach ($orders as $order) {
    $stats = fetchClientStats($order, $settings, $serverManager);

    if (!$stats) {
        $errors++;
        continue;
    }

    $expiry_ms = (int)($stats['expiryTime'] ?? 0);

    // نامحدود — نیازی به اطلاع نیست
    if ($expiry_ms === 0) continue;

    $now_ms      = time() * 1000;
    $remain_ms   = $expiry_ms - $now_ms;
    $remain_days = $remain_ms / (86400 * 1000);

    // فقط اگر ≤ 3 روز مانده و هنوز منقضی نشده
    if ($remain_days > WARN_DAYS_BEFORE || $remain_days < 0) continue;

    $expiry_date = date('Y-m-d H:i', $expiry_ms / 1000);
    $days_text   = $remain_days < 1
        ? 'کمتر از یک روز'
        : 'حدود ' . (int)ceil($remain_days) . ' روز';

    $used_bytes  = (int)($stats['up'] ?? 0) + (int)($stats['down'] ?? 0);
    $total_bytes = (int)($stats['total'] ?? 0);
    $rem_bytes   = $total_bytes > 0 ? max(0, $total_bytes - $used_bytes) : -1;
    $rem_text    = $rem_bytes < 0 ? 'نامحدود' : Utils::formatBytes($rem_bytes);
    $status_text = $stats['enable'] ? '🟢 فعال' : '🔴 غیرفعال';

    $msg = "⏰ <b>هشدار انقضای سرویس</b>\n\n"
         . "📦 پلن: <b>{$order['plan_name']}</b>\n"
         . "📧 شناسه: <code>{$order['email']}</code>\n"
         . "📊 وضعیت: $status_text\n"
         . "🔋 ترافیک باقیمانده: <code>$rem_text</code>\n"
         . "⏳ انقضا: <code>$expiry_date</code>\n"
         . "⚠️ زمان باقیمانده: <b>$days_text</b>\n\n"
         . "<i>برای تمدید سرویس از منو استفاده کنید.</i>";

    $res = $telegram->request('sendMessage', [
        'chat_id'    => (int)$order['chat_id'],
        'text'       => $msg,
        'parse_mode' => 'HTML',
    ]);

    if (isset($res['ok']) && $res['ok']) {
        $pdo->prepare("UPDATE orders SET last_notified = NOW() WHERE id = ?")->execute([$order['id']]);
        $sent++;
        echo "  ✅ ارسال به {$order['chat_id']} برای سرویس {$order['email']}\n";
    } else {
        $errors++;
        $desc = $res['description'] ?? 'ناشناخته';
        echo "  ❌ خطا برای {$order['chat_id']}: $desc\n";
    }

    // جلوگیری از flood با تلگرام
    usleep(100000); // 100ms
}

echo "[" . date('Y-m-d H:i:s') . "] پایان: $sent اطلاعیه ارسال شد، $errors خطا.\n";

// ========================
// تابع کمکی
// ========================

function fetchClientStats(array $order, array $settings, ServerManager $serverManager): ?array {
    if (!empty($order['server_url'])) {
        $xui = new Xui($order['server_url'], $order['server_user'], $order['server_pass']);
    } else {
        // fallback به تنظیمات پیش‌فرض
        $xui = new Xui(
            $settings['panel_url'] ?? 'http://127.0.0.1:2053',
            $settings['panel_user'] ?? 'admin',
            $settings['panel_pass'] ?? 'admin'
        );
    }

    return $xui->getClientStats($order['email']);
}
