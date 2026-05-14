<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

$update_raw = file_get_contents('php://input');
$update     = json_decode($update_raw, true);

if (!$update) {
    echo "<h1>✅ ربات در حالت Webhook فعال است.</h1>";
    exit;
}

// ارسال فوری 200 OK به تلگرام
ob_start();
http_response_code(200);
echo "OK";
header('Connection: close');
header('Content-Length: ' . ob_get_length());
ob_end_flush();
@ob_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

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

// اطمینان از وجود پوشه storage
$storage_dir = __DIR__ . '/storage';
if (!is_dir($storage_dir)) {
    mkdir($storage_dir, 0755, true);
}

// ایجاد جداول پایه
require_once __DIR__ . '/install.php';

$settings      = getSettings();
$serverManager = new ServerManager($pdo, $storage_dir);
$router        = new Router($telegram, $pdo, $config, $settings, $serverManager);
$router->dispatch($update);
