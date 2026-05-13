<?php
class DB {
    private static ?PDO $pdo = null;

    public static function connect(array $config): PDO {
        if (self::$pdo === null) {
            try {
                $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
                self::$pdo = new PDO($dsn, $config['db_user'], $config['db_pass']);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->exec("SET NAMES utf8mb4");
            } catch (PDOException $e) {
                error_log('[DB] اتصال ناموفق: ' . $e->getMessage());
                die("خطا در اتصال به دیتابیس.");
            }
        }
        return self::$pdo;
    }
}
