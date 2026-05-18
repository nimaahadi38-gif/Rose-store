<?php
declare(strict_types=1);

namespace Core;

/**
 * سیستم لاگ ساده با چرخش روزانه
 *
 * هر روز یک فایل لاگ جدید ساخته می‌شود (error-YYYY-MM-DD.log).
 * در صورت تنظیم LOG_TO_STDOUT=true، خروجی روی stdout هم می‌رود
 * که journald آن را برای systemd ضبط می‌کند.
 */
final class Logger
{
    public const DEBUG   = 'DEBUG';
    public const INFO    = 'INFO';
    public const WARNING = 'WARNING';
    public const ERROR   = 'ERROR';

    /** ترتیب سطح‌ها برای فیلترکردن */
    private const LEVELS = [
        self::DEBUG   => 0,
        self::INFO    => 1,
        self::WARNING => 2,
        self::ERROR   => 3,
    ];

    private static string $logDir = '';
    private static bool $toStdout = true;
    private static string $minLevel = self::INFO;

    /**
     * مقداردهی اولیه لاگر (یک بار در زمان بوت)
     *
     * @param string $logDir مسیر مطلق پوشهٔ ذخیرهٔ لاگ‌ها
     * @param string $minLevel حداقل سطحی که نوشته می‌شود
     * @param bool $toStdout آیا روی stdout هم بنویسد؟
     */
    public static function init(string $logDir, string $minLevel = self::INFO, bool $toStdout = true): void
    {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        self::$logDir = rtrim($logDir, '/');
        self::$minLevel = isset(self::LEVELS[$minLevel]) ? $minLevel : self::INFO;
        self::$toStdout = $toStdout;
    }

    /**
     * لاگ سطح debug — فقط در محیط توسعه فعال
     *
     * @param string $message پیام
     * @param array<string,mixed> $context اطلاعات اضافی
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write(self::DEBUG, $message, $context);
    }

    /**
     * لاگ سطح اطلاعات معمولی
     */
    public static function info(string $message, array $context = []): void
    {
        self::write(self::INFO, $message, $context);
    }

    /**
     * لاگ هشدار — برای رفتارهای غیرعادی غیربحرانی
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write(self::WARNING, $message, $context);
    }

    /**
     * لاگ خطا — برای exception ها و failureهای واقعی
     */
    public static function error(string $message, array $context = []): void
    {
        self::write(self::ERROR, $message, $context);
    }

    /**
     * ثبت یک Exception با stack trace کامل در فایل
     */
    public static function exception(\Throwable $e, string $context = ''): void
    {
        $msg = $context !== '' ? "{$context}: " : '';
        $msg .= sprintf(
            '%s in %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        self::write(self::ERROR, $msg, ['trace' => $e->getTraceAsString()]);
    }

    /**
     * نوشتن سطر لاگ
     */
    private static function write(string $level, string $message, array $context): void
    {
        // فیلتر بر اساس حداقل سطح تنظیم‌شده
        if (self::LEVELS[$level] < self::LEVELS[self::$minLevel]) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $line = sprintf("[%s] [%s] %s%s\n", $timestamp, $level, $message, $contextStr);

        // نوشتن روی فایل با چرخش روزانه
        if (self::$logDir !== '') {
            $file = self::$logDir . '/app-' . date('Y-m-d') . '.log';
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }

        // نوشتن روی stdout/stderr برای journald
        if (self::$toStdout) {
            $stream = $level === self::ERROR ? STDERR : STDOUT;
            // در CLI Long Polling: مستقیم به stdout
            if (defined('STDOUT')) {
                @fwrite($stream, $line);
            }
        }
    }
}
