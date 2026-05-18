<?php
declare(strict_types=1);

use Core\Config;

/**
 * پیکربندی عمومی برنامه
 *
 * این فایل آرایه‌ای از تنظیمات سطح بالا را برمی‌گرداند.
 * تمام مقادیر از .env خوانده می‌شوند تا هیچ credential در کد نباشد.
 */
return [
    'env'         => Config::string('APP_ENV', 'production'),
    'debug'       => Config::bool('APP_DEBUG', false),
    'timezone'    => Config::string('APP_TIMEZONE', 'Asia/Tehran'),

    // مسیرهای حیاتی
    'base_path'   => dirname(__DIR__),
    'storage'     => dirname(__DIR__) . '/storage',
    'logs_dir'    => dirname(__DIR__) . '/storage/logs',
    'cache_dir'   => dirname(__DIR__) . '/storage/cache',
    'cookies_dir' => dirname(__DIR__) . '/storage/cookies',

    // لاگ
    'log_level'      => Config::string('LOG_LEVEL', 'info'),
    'log_to_stdout'  => Config::bool('LOG_TO_STDOUT', true),

    // عیب‌یابی
    'diag_token' => Config::string('DIAG_TOKEN', ''),

    // ادمین‌ها
    'admin_id'        => Config::int('ADMIN_ID', 0),
    'extra_admin_ids' => array_filter(array_map(
        'intval',
        explode(',', Config::string('EXTRA_ADMIN_IDS', ''))
    )),
];
