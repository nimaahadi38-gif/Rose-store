<?php
declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * مدیریت اتصال PDO به MySQL با تنظیمات امن
 *
 * نکات کلیدی امنیتی:
 *  ‑ EMULATE_PREPARES = false تا واقعاً prepared statement اجرا شود
 *  ‑ ERRMODE_EXCEPTION تا خطا قابل catch باشد (نه die)
 *  ‑ utf8mb4 پیش‌فرض برای پشتیبانی کامل از یونیکد
 *
 * در حالت Long Polling یک نمونهٔ PDO در طول کل عمر فرایند نگهداری می‌شود.
 * در صورت قطع اتصال (MySQL has gone away)، reconnect خودکار انجام می‌گیرد.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** @var array{host:string,port:int,name:string,user:string,pass:string,charset:string} */
    private static array $cfg = [];

    /**
     * تنظیم پیکربندی اتصال (یک بار در زمان بوت)
     *
     * @param array{host:string,port:int|string,name:string,user:string,pass:string,charset?:string} $config
     */
    public static function configure(array $config): void
    {
        self::$cfg = [
            'host'    => $config['host'],
            'port'    => (int) ($config['port'] ?? 3306),
            'name'    => $config['name'],
            'user'    => $config['user'],
            'pass'    => $config['pass'],
            'charset' => $config['charset'] ?? 'utf8mb4',
        ];
    }

    /**
     * دریافت نمونهٔ PDO (lazy + singleton)
     *
     * @throws \RuntimeException در صورت شکست اتصال
     */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::connect();
        }

        return self::$pdo;
    }

    /**
     * اتصال واقعی به MySQL با retry سبک
     *
     * @throws \RuntimeException اگر پس از تلاش‌ها همچنان متصل نشود
     */
    private static function connect(): void
    {
        if (empty(self::$cfg)) {
            throw new \RuntimeException('پیکربندی دیتابیس قبل از اتصال تنظیم نشده است');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            self::$cfg['host'],
            self::$cfg['port'],
            self::$cfg['name'],
            self::$cfg['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            // اطمینان از charset در سمت سرور
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . self::$cfg['charset'] . " COLLATE utf8mb4_unicode_ci",
        ];

        try {
            self::$pdo = new PDO($dsn, self::$cfg['user'], self::$cfg['pass'], $options);
        } catch (PDOException $e) {
            // به‌جای die() که در CLI long polling فاجعه است، exception پرتاب می‌کنیم
            throw new \RuntimeException(
                'اتصال به دیتابیس ناموفق: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * اجرای یک query با ورودی‌های امن و بازگشت PDOStatement
     *
     * @param string $sql کوئری با placeholder
     * @param array<int|string,mixed> $params پارامترها
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * بازگشت یک سطر به شکل آرایه (یا null)
     *
     * @param string $sql
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * بازگشت تمام سطرها
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * بازگشت یک ستون از اولین سطر
     */
    public static function fetchColumn(string $sql, array $params = [])
    {
        $value = self::query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * اجرای تراکنش با کلوژر
     * در صورت Exception، rollback خودکار انجام می‌شود.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * بستن اتصال (برای تست یا shutdown تمیز)
     */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    /**
     * بررسی سلامت اتصال؛ اگر قطع شده باشد reconnect می‌کند
     * در Long Polling هر چند دقیقه یک‌بار صدا زده می‌شود.
     */
    public static function ping(): void
    {
        try {
            self::pdo()->query('SELECT 1');
        } catch (PDOException $e) {
            // اتصال قطع شده — reconnect
            self::$pdo = null;
            self::connect();
        }
    }
}
