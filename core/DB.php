<?php
declare(strict_types=1);

/**
 * Wrapper سازگاری برای run.php قدیمی
 *
 * این کلاس در فضای global قرار دارد (نه namespace) و فقط
 * فراخوانی‌های قدیمی DB::connect($config) را به Core\Database
 * جدید delegate می‌کند.
 *
 * مزیت: تنظیمات امن جدید (EMULATE_PREPARES=false, بدون die)
 * بلافاصله روی run.php قدیمی هم اعمال می‌شود.
 */

require_once __DIR__ . '/../src/Core/Database.php';

class DB
{
    /**
     * اتصال به دیتابیس و بازگشت PDO
     *
     * @param array{db_host:string,db_name:string,db_user:string,db_pass:string} $config
     * @return \PDO
     * @throws \RuntimeException در صورت شکست اتصال (به‌جای die)
     */
    public static function connect(array $config): \PDO
    {
        \Core\Database::configure([
            'host'    => $config['db_host'],
            'port'    => 3306,
            'name'    => $config['db_name'],
            'user'    => $config['db_user'],
            'pass'    => $config['db_pass'],
            'charset' => 'utf8mb4',
        ]);

        return \Core\Database::pdo();
    }
}
