<?php
/**
 * Long Polling Runner — Rose Store
 *
 * استفاده: php polling.php
 * برای اجرای دائمی: nohup php polling.php >> storage/polling.log 2>&1 &
 * برای اجرای با Supervisor، تنظیمات supervisor را در docs/supervisor.conf ببینید.
 *
 * توجه: این فایل جایگزین run.php (webhook) می‌شود.
 * برای استفاده از webhook به‌جای polling، از run.php استفاده کنید.
 */

// CLI فقط
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('این فایل فقط از CLI اجرا می‌شود.');
}

set_time_limit(0);
ini_set('display_errors', 1);
error_reporting(E_ALL);

// بارگذاری وابستگی‌ها
require_once __DIR__ . '/core/DB.php';
require_once __DIR__ . '/core/Telegram.php';
require_once __DIR__ . '/core/Utils.php';
require_once __DIR__ . '/core/Xui.php';
require_once __DIR__ . '/core/Jalali.php';
require_once __DIR__ . '/core/ServerManager.php';
require_once __DIR__ . '/core/Router.php';
require_once __DIR__ . '/handlers/PaymentHandler.php';
require_once __DIR__ . '/handlers/CallbackHandler.php';
require_once __DIR__ . '/handlers/AdminHandler.php';
require_once __DIR__ . '/handlers/UserHandler.php';
require_once __DIR__ . '/helpers.php';

$config   = require __DIR__ . '/config/settings.php';
$pdo      = DB::connect($config);
$telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');

$storage_dir = __DIR__ . '/storage';
if (!is_dir($storage_dir)) {
    mkdir($storage_dir, 0755, true);
}

// ایجاد جداول پایه
require_once __DIR__ . '/install.php';

$settings      = getSettings();
$serverManager = new ServerManager($pdo, $storage_dir);
$router        = new Router($telegram, $pdo, $config, $settings, $serverManager);

// حذف Webhook قبلی تا تداخل نداشته باشد
$telegram->deleteWebhook();

// فایل ذخیره آخرین offset
$offset_file = $storage_dir . '/polling_offset.txt';
$offset      = is_file($offset_file) ? (int)file_get_contents($offset_file) : 0;

echo "[" . date('Y-m-d H:i:s') . "] 🌹 Rose Store polling started (offset: {$offset})\n";

// حلقه اصلی Long Polling
while (true) {
    try {
        $updates = $telegram->getUpdates($offset, 60);

        foreach ($updates as $update) {
            $update_id = (int)$update['update_id'];

            try {
                $router->dispatch($update);
            } catch (Throwable $e) {
                error_log("[polling] dispatch error on update {$update_id}: " . $e->getMessage());
            }

            $offset = $update_id + 1;
        }

        // ذخیره offset برای ادامه پس از ری‌استارت
        if (!empty($updates)) {
            file_put_contents($offset_file, $offset);
        }

    } catch (Throwable $e) {
        error_log("[polling] loop error: " . $e->getMessage());
        sleep(5);
    }
}
