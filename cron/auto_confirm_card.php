<?php
/**
 * تایید خودکار رسید کارت
 * اجرای هر ساعت: 0 * * * * php /path/to/cron/auto_confirm_card.php
 *
 * اگر auto_confirm_hours > 0 باشد، رسیدهای در انتظار را پس از آن مدت تایید می‌کند.
 */

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Telegram.php';
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/../core/Xui.php';
require_once __DIR__ . '/../core/ServerManager.php';
require_once __DIR__ . '/../handlers/PaymentHandler.php';
require_once __DIR__ . '/../helpers.php';

$config   = require __DIR__ . '/../config/settings.php';
$pdo      = DB::connect($config);
$telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');
$settings = getSettings();

$hours = (int)($settings['auto_confirm_hours'] ?? 0);
if ($hours <= 0) {
    echo "تایید خودکار غیرفعال است (auto_confirm_hours = 0).\n";
    exit;
}

$storage_dir   = __DIR__ . '/../storage';
$serverManager = new ServerManager($pdo, $storage_dir);
$payment       = new PaymentHandler($pdo, $telegram, $settings, $config, $serverManager);
$admin_id      = (int)$config['admin_id'];

$stmt = $pdo->prepare(
    "SELECT id, chat_id, plan_id, code, amount
     FROM transactions
     WHERE status = 'awaiting_receipt'
       AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
);
$stmt->execute([$hours]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

$confirmed = 0;
$errors    = 0;

echo "[" . date('Y-m-d H:i:s') . "] بررسی " . count($pending) . " رسید در انتظار...\n";

foreach ($pending as $tx) {
    try {
        $result = $payment->approveReceiptOrder(
            (int)$tx['chat_id'],
            $tx['plan_id'],
            $tx['code'] ?? 'none',
            $admin_id
        );
        $pdo->prepare("UPDATE transactions SET status = 'auto_confirmed' WHERE id = ?")->execute([$tx['id']]);
        $confirmed++;
        echo "  ✅ تایید خودکار برای user {$tx['chat_id']}, plan {$tx['plan_id']}\n";
    } catch (Throwable $e) {
        $errors++;
        error_log("[auto_confirm_card] error for tx {$tx['id']}: " . $e->getMessage());
        echo "  ❌ خطا برای tx {$tx['id']}: " . $e->getMessage() . "\n";
    }

    usleep(300000);
}

if ($confirmed > 0) {
    $telegram->sendMessage($admin_id,
        "✅ <b>تایید خودکار کارت</b>\n\n"
        . "{$confirmed} رسید پس از {$hours} ساعت تایید خودکار شد.\n"
        . ($errors > 0 ? "❌ {$errors} مورد خطا داشت." : ''),
        'HTML');
}

echo "[" . date('Y-m-d H:i:s') . "] پایان: $confirmed تایید شد، $errors خطا.\n";
