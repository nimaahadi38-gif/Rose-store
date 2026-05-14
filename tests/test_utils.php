<?php
/**
 * تست‌های واحد برای Utils
 * اجرا: php tests/test_utils.php
 */
require_once __DIR__ . '/../core/Utils.php';

$pass = 0;
$fail = 0;

function assert_eq($actual, $expected, string $label): void {
    global $pass, $fail;
    if ($actual === $expected) {
        echo "  ✅ $label\n";
        $pass++;
    } else {
        echo "  ❌ $label\n     expected: " . var_export($expected, true) . "\n     actual:   " . var_export($actual, true) . "\n";
        $fail++;
    }
}

echo "=== تست Utils::toEnglishDigits ===\n";
assert_eq(Utils::toEnglishDigits('۶٧8'),         '678',       'ترکیب فارسی، عربی، انگلیسی');
assert_eq(Utils::toEnglishDigits('۰۱۲۳۴۵۶۷۸۹'), '0123456789', 'تمام ارقام فارسی');
assert_eq(Utils::toEnglishDigits('٠١٢٣٤٥٦٧٨٩'), '0123456789', 'تمام ارقام عربی');
assert_eq(Utils::toEnglishDigits('hello123'),    'hello123',   'متن بدون تغییر');
assert_eq(Utils::toEnglishDigits(''),            '',           'رشته خالی');

echo "\n=== تست Utils::formatBytes ===\n";
assert_eq(Utils::formatBytes(0),          '0 B',    'صفر بایت');
assert_eq(Utils::formatBytes(1024),       '1 KB',   '۱ کیلوبایت');
assert_eq(Utils::formatBytes(1048576),    '1 MB',   '۱ مگابایت');
assert_eq(Utils::formatBytes(1073741824), '1 GB',   '۱ گیگابایت');
assert_eq(Utils::formatBytes(1536),       '1.5 KB', '۱.۵ کیلوبایت');

echo "\n=== تست Utils::onlyDigits ===\n";
assert_eq(Utils::onlyDigits('abc123def'), '123',  'حذف حروف');
assert_eq(Utils::onlyDigits('۵۰۰۰۰'),    '50000', 'ارقام فارسی');
assert_eq(Utils::onlyDigits('test'),      '',      'بدون عدد');

echo "\n=== تست Utils::generateUUIDv4 ===\n";
$uuid1 = Utils::generateUUIDv4();
$uuid2 = Utils::generateUUIDv4();
$is_valid_uuid = (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid1);
assert_eq($is_valid_uuid, true,          'فرمت UUID v4');
assert_eq($uuid1 !== $uuid2, true,       'یکتا بودن UUID');
assert_eq(strlen($uuid1), 36,            'طول UUID');

echo "\n=== تست Utils::generateAuthority ===\n";
$auth = Utils::generateAuthority('CARD', 12345);
assert_eq(strpos($auth, 'CARD_12345_') === 0, true, 'فرمت authority');

echo "\n=== خلاصه ===\n";
echo "✅ موفق: $pass\n";
echo "❌ ناموفق: $fail\n";

exit($fail > 0 ? 1 : 0);
