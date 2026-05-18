<?php
declare(strict_types=1);

use Core\Config;

/**
 * پیکربندی اتصال دیتابیس MySQL
 *
 * تمام مقادیر از .env خوانده می‌شوند.
 * این آرایه را Core\Database::configure() مصرف می‌کند.
 */
return [
    'host'    => Config::string('DB_HOST', 'localhost'),
    'port'    => Config::int('DB_PORT', 3306),
    'name'    => Config::string('DB_NAME', ''),
    'user'    => Config::string('DB_USER', ''),
    'pass'    => Config::string('DB_PASS', ''),
    'charset' => Config::string('DB_CHARSET', 'utf8mb4'),
];
