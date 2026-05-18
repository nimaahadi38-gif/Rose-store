<?php
declare(strict_types=1);

/**
 * فایل سازگاری با run.php قدیمی
 *
 * این فایل دیگر مقادیر را hardcode نمی‌کند. به‌جای آن، .env را
 * بارگذاری می‌کند و یک آرایه با همان کلیدهای قدیمی برمی‌گرداند
 * تا run.php قدیمی بدون تغییر کار کند.
 *
 * در ساختار جدید (bin/bot.php)، مستقیماً از Core\Config و
 * config/{app,database,telegram}.php استفاده می‌شود.
 */

// بارگذاری autoloader سادهٔ src/
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Logger.php';

// بارگذاری .env اگر هنوز نشده است
if (!\Core\Config::isLoaded()) {
    $envPath = __DIR__ . '/../.env';
    if (is_file($envPath)) {
        \Core\Config::load($envPath);
    }
}

$proxyHost = \Core\Config::string('PROXY_HOST', '');
$proxyPort = \Core\Config::int('PROXY_PORT', 0);
$proxyUser = \Core\Config::string('PROXY_USER', '');
$proxyPass = \Core\Config::string('PROXY_PASS', '');

return [
    // ─── تلگرام ────────────────────────────────────────────
    'bot_token' => \Core\Config::string('BOT_TOKEN', ''),
    'admin_id'  => \Core\Config::int('ADMIN_ID', 0),

    // ─── پروکسی (همان فرمت قدیمی برای سازگاری) ─────────────
    'proxy_url'  => $proxyHost !== '' && $proxyPort > 0 ? "{$proxyHost}:{$proxyPort}" : '',
    'proxy_auth' => $proxyUser !== '' ? "{$proxyUser}:{$proxyPass}" : '',

    // ─── دیتابیس ────────────────────────────────────────────
    'db_host' => \Core\Config::string('DB_HOST', 'localhost'),
    'db_name' => \Core\Config::string('DB_NAME', ''),
    'db_user' => \Core\Config::string('DB_USER', ''),
    'db_pass' => \Core\Config::string('DB_PASS', ''),
];
