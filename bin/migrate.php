#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * اجرای migrations دیتابیس
 *
 * استفاده:
 *   php bin/migrate.php           # اجرای تمام migrationهای جدید
 *   php bin/migrate.php --status  # نمایش وضعیت بدون اجرا
 *   php bin/migrate.php --force   # اجرای مجدد همه (idempotent است)
 *
 * این runner از table schema_migrations استفاده می‌کند تا بداند
 * کدام فایل قبلاً اجرا شده. خطاهای idempotent (مثل Duplicate column)
 * را skip می‌کند تا migrations امن چندبار قابل اجرا باشند.
 */

// بارگذاری autoloader ساده
$baseDir = dirname(__DIR__);
require_once $baseDir . '/src/Core/Config.php';
require_once $baseDir . '/src/Core/Database.php';
require_once $baseDir . '/src/Core/Logger.php';

use Core\Config;
use Core\Database;
use Core\Logger;

// ─── بارگذاری env و config ─────────────────────────────────────
$envPath = $baseDir . '/.env';
if (!is_file($envPath)) {
    fwrite(STDERR, "❌ فایل .env یافت نشد: {$envPath}\n");
    exit(1);
}
Config::load($envPath);

$dbConfig = require $baseDir . '/config/database.php';
$appConfig = require $baseDir . '/config/app.php';

Logger::init($appConfig['logs_dir'], 'info', true);
Database::configure($dbConfig);

// ─── پارس آرگومان‌ها ───────────────────────────────────────────
$args = array_slice($argv, 1);
$showStatusOnly = in_array('--status', $args, true);
$force = in_array('--force', $args, true);

// ─── خطاهای idempotent که باید skip شوند ────────────────────────
// مرجع: https://dev.mysql.com/doc/mysql-errors/8.0/en/server-error-reference.html
$idempotentErrors = [
    1050, // Table already exists
    1060, // Duplicate column name
    1061, // Duplicate key name
    1062, // Duplicate entry (unique key)
    1091, // Can't DROP - check that column/key exists
    1146, // Table doesn't exist (در ALTER غیرضروری)
];

/**
 * تقسیم یک فایل SQL به statementهای جداگانه
 *
 * توضیح: ; را به‌عنوان separator می‌گیریم. خط‌های شروع‌شده با --
 * را به‌عنوان کامنت skip می‌کنیم. این parser ساده برای فایل‌های
 * migration ما کافی است (هیچ statement از نوع DELIMITER، trigger
 * یا procedure نداریم).
 *
 * @return string[]
 */
function splitSqlFile(string $content): array
{
    $statements = [];
    $current = '';
    $lines = preg_split('/\r\n|\n|\r/', $content);

    foreach ($lines as $line) {
        $trimmed = trim($line);
        // skip سطرهای کامنت
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $current .= $line . "\n";
        // اگر سطر با ; تمام شود، یک statement کامل شده
        if (substr($trimmed, -1) === ';') {
            $stmt = trim($current);
            if ($stmt !== '' && $stmt !== ';') {
                $statements[] = $stmt;
            }
            $current = '';
        }
    }
    // باقیماندهٔ بدون ; (در صورت وجود)
    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * چاپ پیام رنگی روی ترمینال
 */
function out(string $msg, string $color = ''): void
{
    $colors = ['green' => "\033[32m", 'yellow' => "\033[33m", 'red' => "\033[31m", 'cyan' => "\033[36m", 'gray' => "\033[90m"];
    $reset = "\033[0m";
    if ($color !== '' && isset($colors[$color])) {
        echo $colors[$color] . $msg . $reset . "\n";
    } else {
        echo $msg . "\n";
    }
}

// ─── اتصال به دیتابیس ────────────────────────────────────────
try {
    $pdo = Database::pdo();
} catch (\Throwable $e) {
    out('❌ اتصال به دیتابیس ناموفق: ' . $e->getMessage(), 'red');
    exit(1);
}

// ─── ساخت جدول schema_migrations اگر وجود ندارد ───────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `name` VARCHAR(255) NOT NULL PRIMARY KEY,
        `ran_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ─── خواندن لیست migrationهای اجراشده ─────────────────────────
$applied = [];
$rows = $pdo->query("SELECT name FROM schema_migrations ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
foreach ($rows as $name) {
    $applied[$name] = true;
}

// ─── لیست فایل‌های migration ──────────────────────────────────
$migrationsDir = $baseDir . '/migrations';
$files = glob($migrationsDir . '/*.sql');
if ($files === false || empty($files)) {
    out("⚠️ هیچ فایل migration یافت نشد در: {$migrationsDir}", 'yellow');
    exit(0);
}
sort($files);

// ─── نمایش وضعیت ──────────────────────────────────────────────
out('', '');
out('═══════════════════════════════════════════════════', 'cyan');
out('  📦 وضعیت Migrations', 'cyan');
out('═══════════════════════════════════════════════════', 'cyan');
foreach ($files as $file) {
    $name = basename($file);
    $status = isset($applied[$name]) ? '✓ اجرا شده' : '○ منتظر اجرا';
    $color = isset($applied[$name]) ? 'green' : 'yellow';
    out(sprintf("  %-30s %s", $name, $status), $color);
}
out('═══════════════════════════════════════════════════', 'cyan');

if ($showStatusOnly) {
    exit(0);
}

// ─── اجرای migrationهای جدید ──────────────────────────────────
$ranCount = 0;
foreach ($files as $file) {
    $name = basename($file);

    if (isset($applied[$name]) && !$force) {
        continue;
    }

    out('', '');
    out("▶ اجرای {$name} ...", 'cyan');

    $content = file_get_contents($file);
    if ($content === false) {
        out("  ❌ نمی‌توان فایل را خواند: {$file}", 'red');
        exit(1);
    }

    $statements = splitSqlFile($content);
    $skipped = 0;
    $executed = 0;
    $failed = 0;

    foreach ($statements as $idx => $sql) {
        $shortSql = mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 80);
        try {
            $pdo->exec($sql);
            $executed++;
            out("  ✓ #" . ($idx + 1) . ": {$shortSql}", 'gray');
        } catch (\PDOException $e) {
            $errorCode = (int) $e->errorInfo[1];
            if (in_array($errorCode, $idempotentErrors, true)) {
                $skipped++;
                out("  ⊙ #" . ($idx + 1) . " [skipped {$errorCode}]: {$shortSql}", 'gray');
            } else {
                $failed++;
                out("  ❌ #" . ($idx + 1) . " [ERROR {$errorCode}]: {$shortSql}", 'red');
                out("     " . $e->getMessage(), 'red');
                Logger::error("Migration {$name} statement #" . ($idx + 1) . " failed", [
                    'sql'   => $shortSql,
                    'error' => $e->getMessage(),
                ]);
                exit(1);
            }
        }
    }

    // ثبت در schema_migrations
    if (!isset($applied[$name])) {
        $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)")->execute([$name]);
    }
    $applied[$name] = true;
    $ranCount++;

    out(sprintf(
        "  ↳ خلاصه: %d اجرا، %d skip، %d خطا",
        $executed, $skipped, $failed
    ), 'green');
    Logger::info("Migration applied: {$name}", [
        'executed' => $executed,
        'skipped'  => $skipped,
    ]);
}

out('', '');
if ($ranCount === 0) {
    out('✓ همه migrations از قبل اجرا شده‌اند. کاری برای انجام نیست.', 'green');
} else {
    out("✅ {$ranCount} migration با موفقیت اجرا شد.", 'green');
}
out('', '');
