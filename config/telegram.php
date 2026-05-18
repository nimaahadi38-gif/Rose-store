<?php
declare(strict_types=1);

use Core\Config;

/**
 * پیکربندی ربات تلگرام
 *
 * شامل توکن، تنظیمات پروکسی SOCKS5 (برای هاست داخل ایران)،
 * و پارامترهای long polling.
 *
 * اگر PROXY_HOST خالی باشد، ربات بدون پروکسی به تلگرام وصل می‌شود
 * (مناسب سرورهای خارجی).
 */
$proxyHost = Config::string('PROXY_HOST', '');
$proxyPort = Config::int('PROXY_PORT', 0);
$proxyUser = Config::string('PROXY_USER', '');
$proxyPass = Config::string('PROXY_PASS', '');

return [
    'token' => Config::string('BOT_TOKEN', ''),

    // ─── پروکسی ───────────────────────────────────────────────
    'proxy' => [
        'enabled' => $proxyHost !== '' && $proxyPort > 0,
        'host'    => $proxyHost,
        'port'    => $proxyPort,
        'url'     => $proxyHost !== '' && $proxyPort > 0 ? "{$proxyHost}:{$proxyPort}" : '',
        'auth'    => $proxyUser !== '' ? "{$proxyUser}:{$proxyPass}" : '',
    ],

    // ─── Long Polling ─────────────────────────────────────────
    'poll' => [
        'timeout' => Config::int('POLL_TIMEOUT', 30),
        'limit'   => Config::int('POLL_LIMIT', 100),
    ],

    // مسیر ذخیرهٔ offset روی دیسک — برای ادامهٔ کار پس از restart
    'offset_file' => dirname(__DIR__) . '/storage/offset.txt',
];
