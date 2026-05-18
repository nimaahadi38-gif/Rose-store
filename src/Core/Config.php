<?php
declare(strict_types=1);

namespace Core;

/**
 * بارگذاری و دسترسی به متغیرهای محیطی از فایل .env
 *
 * این کلاس یک پارسر سادهٔ .env است که نیازی به وابستگی خارجی ندارد.
 * مقادیر یک‌بار در حافظه کش می‌شوند و در طول عمر فرایند بدون I/O
 * تکرارشونده در دسترس هستند (برای Long Polling حیاتی است).
 */
final class Config
{
    /** @var array<string,string> مقادیر کش‌شدهٔ env */
    private static array $values = [];

    /** آیا فایل env بارگذاری شده است؟ */
    private static bool $loaded = false;

    /**
     * بارگذاری فایل .env در حافظه
     * در صورت بارگذاری مجدد، مقادیر قبلی بازنویسی می‌شوند.
     *
     * @param string $path مسیر مطلق فایل env
     * @throws \RuntimeException اگر فایل وجود نداشته باشد
     */
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new \RuntimeException("فایل env یافت نشد: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException("امکان خواندن فایل env وجود ندارد: {$path}");
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // عبور از خطوط کامنت یا خالی
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            // حذف کوتیشن دور مقدار (در صورت وجود)
            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$values[$key] = $value;
            // در $_ENV هم قرار می‌دهیم تا getenv و $_ENV هم کار کنند
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }

        self::$loaded = true;
    }

    /**
     * گرفتن مقدار یک کلید env
     *
     * @param string $key نام متغیر
     * @param string|int|bool|null $default مقدار پیش‌فرض در صورت نبود
     * @return string|int|bool|null
     */
    public static function get(string $key, $default = null)
    {
        if (!self::$loaded) {
            return $default;
        }

        return self::$values[$key] ?? $default;
    }

    /**
     * گرفتن مقدار به‌عنوان عدد صحیح
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * گرفتن مقدار به‌عنوان بولین
     * مقادیر "true", "1", "yes", "on" → true
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower((string) $value);
        return in_array($value, ['true', '1', 'yes', 'on'], true);
    }

    /**
     * گرفتن مقدار به‌صورت رشته (با اطمینان از نوع)
     */
    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return (string) $value;
    }

    /**
     * آیا env بارگذاری شده است؟
     */
    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * مقادیر env را پاک می‌کند (فقط برای تست)
     */
    public static function reset(): void
    {
        self::$values = [];
        self::$loaded = false;
    }
}
