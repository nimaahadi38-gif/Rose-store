<?php
/**
 * پاکسازی خودکار سرویس‌های منقضی
 * اجرای روزانه: 0 3 * * * php /path/to/cron/cleanup_expired.php
 *
 * سرویس‌هایی که X روز از انقضایشان گذشته را از XUI حذف می‌کند.
 */

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Telegram.php';
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/../core/Xui.php';
require_once __DIR__ . '/../helpers.php';

$config   = require __DIR__ . '/../config/settings.php';
$pdo      = DB::connect($config);
$telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');
$settings = getSettings();

if (($settings['cleanup_status'] ?? '1') != '1') {
    echo "پاکسازی خودکار غیرفعال است.\n";
    exit;
}

$cleanup_days = max(1, (int)($settings['cleanup_days'] ?? 7));

$stmt = $pdo->query(
    "SELECT o.id, o.chat_id, o.email, o.plan_name, o.server_id, o.uuid,
            s.url AS server_url, s.user AS server_user, s.pass AS server_pass,
            s.inbound_id AS server_inbound,
            s.remote_address AS server_remote, s.ws_host AS server_ws_host
     FROM orders o
     LEFT JOIN servers s ON s.id = o.server_id"
);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$deleted = 0;
$errors  = 0;

echo "[" . date('Y-m-d H:i:s') . "] بررسی " . count($orders) . " سرویس برای پاکسازی...\n";

foreach ($orders as $order) {
    if (!empty($order['server_url'])) {
        $xui        = new Xui(
            $order['server_url'], $order['server_user'], $order['server_pass'], '',
            $order['server_remote'] ?? '', $order['server_ws_host'] ?? ''
        );
        $inbound_id = (int)$order['server_inbound'];
    } else {
        $xui        = new Xui(
            $settings['panel_url']  ?? 'http://127.0.0.1:2053',
            $settings['panel_user'] ?? 'admin',
            $settings['panel_pass'] ?? 'admin',
            '',
            $settings['remote_address'] ?? '',
            $settings['ws_host'] ?? ''
        );
        $inbound_id = (int)($settings['inbound_id'] ?? 1);
    }

    $stats = $xui->getClientStats($order['email']);
    if (!$stats) { $errors++; continue; }

    $expiry_ms = (int)($stats['expiryTime'] ?? 0);
    if ($expiry_ms == 0) continue; // نامحدود

    $expired_seconds = time() - (int)($expiry_ms / 1000);
    if ($expired_seconds < ($cleanup_days * 86400)) continue;

    // حذف از XUI
    $uuid = $stats['id'] ?? $order['uuid'] ?? '';
    if (empty($uuid)) { $errors++; continue; }

    $ok = $xui->deleteClient($inbound_id, $uuid);
    if ($ok) {
        $pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$order['id']]);
        $deleted++;
        echo "  🗑 حذف شد: {$order['email']} (chat: {$order['chat_id']})\n";
    } else {
        $errors++;
        echo "  ❌ خطا در حذف: {$order['email']}\n";
    }

    usleep(200000);
}

// گزارش به ادمین
if ($deleted > 0) {
    $admin_id = (int)$config['admin_id'];
    $telegram->sendMessage($admin_id,
        "🗑 <b>پاکسازی خودکار انجام شد</b>\n\n"
        . "✅ حذف شده: {$deleted} سرویس\n"
        . "❌ خطا: {$errors} مورد\n"
        . "<i>سرویس‌هایی که بیش از {$cleanup_days} روز منقضی بودند حذف شدند.</i>",
        'HTML');
}

echo "[" . date('Y-m-d H:i:s') . "] پایان: $deleted سرویس حذف شد، $errors خطا.\n";
