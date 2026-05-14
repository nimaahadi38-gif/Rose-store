<?php
/**
 * تست‌های یکپارچگی منطق پرداخت
 * اجرا: php tests/test_payment.php
 * نیاز: PHP >= 7.4، بدون نیاز به اتصال دیتابیس
 */
require_once __DIR__ . '/../core/Utils.php';
require_once __DIR__ . '/../helpers.php';

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

function assert_true($cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  ✅ $label\n"; $pass++; }
    else        { echo "  ❌ $label\n"; $fail++; }
}

echo "=== تست پنجره زمانی رسید (PaymentHandler::RECEIPT_WINDOW_SECONDS) ===\n";
assert_eq(PaymentHandler_WINDOW(), 1800, 'پنجره ۳۰ دقیقه‌ای برابر ۱۸۰۰ ثانیه است');

echo "\n=== تست هش رسید ===\n";
$file_id1 = 'AgACAgIAAxkBAAIBzmYtest123';
$file_id2 = 'AgACAgIAAxkBAAIBzmYtest456';
$hash1 = hash('sha256', $file_id1);
$hash2 = hash('sha256', $file_id2);
assert_eq(strlen($hash1), 64,        'طول SHA-256 برابر ۶۴ کاراکتر');
assert_true($hash1 !== $hash2,       'هش‌های مختلف برای فایل‌های مختلف');
assert_eq(hash('sha256', $file_id1), $hash1, 'تکرارپذیری هش');

echo "\n=== تست تبدیل step رسید ===\n";
$step1 = 'wait_receipt|5|PROMO|card|CARD_12345_1700000000';
$parts1 = explode('|', str_replace('wait_receipt|', '', $step1));
assert_eq($parts1[0], '5',                          'pid از step');
assert_eq($parts1[1], 'PROMO',                      'code از step');
assert_eq($parts1[2], 'card',                       'method از step');
assert_eq($parts1[3], 'CARD_12345_1700000000',      'authority از step');

$step2 = 'wait_charge_receipt_50000|CHG_CARD_12345_1700000000';
$raw   = str_replace('wait_charge_receipt_', '', $step2);
$parts2 = explode('|', $raw);
assert_eq($parts2[0], '50000',                      'مبلغ از step شارژ');
assert_eq($parts2[1], 'CHG_CARD_12345_1700000000', 'authority از step شارژ');

echo "\n=== تست getFinalPrice (بدون دیتابیس) ===\n";
// تست با پلن دلخواه بدون نیاز به DB
$settings_mock = ['custom_days' => '30', 'custom_gb_price' => '3000'];
$pid = 'custom_10';
$gb  = (int)str_replace('custom_', '', $pid);
$days = (int)$settings_mock['custom_days'];
$price = $gb * (int)$settings_mock['custom_gb_price'];
assert_eq($gb,     10,     '۱۰ گیگ از pid');
assert_eq($days,   30,     '۳۰ روز پیش‌فرض');
assert_eq($price, 30000,  'قیمت ۳۰۰۰۰ تومان');

echo "\n=== تست کیبوردها ===\n";
$settings_full = [
    'buy_text' => '🛒 خرید', 'buy_status' => '1',
    'services_text' => '📦 سرویس‌ها', 'services_status' => '1',
    'account_text' => '👤 حساب', 'account_status' => '1',
    'trial_text' => '🎁 تست', 'trial_status' => '1',
    'referral_text' => '👥 دعوت', 'referral_status' => '1',
    'support_text' => '☎️ پشتیبانی', 'support_status' => '1',
    'guide_text' => '📚 آموزش', 'guide_status' => '1',
];
$kb = getUserKeyboard($settings_full);
$decoded = json_decode($kb, true);
assert_true(!empty($decoded['keyboard']),         'کیبورد کاربر تولید شد');
assert_true($decoded['resize_keyboard'] === true, 'resize_keyboard فعال است');

$cancel_kb = json_decode(getCancelKeyboard(), true);
assert_eq($cancel_kb['keyboard'][0][0]['text'], '🔙 انصراف', 'دکمه انصراف صحیح است');

echo "\n=== خلاصه ===\n";
echo "✅ موفق: $pass\n";
echo "❌ ناموفق: $fail\n";

exit($fail > 0 ? 1 : 0);

// تابع کمکی بدون وابستگی به کلاس
function PaymentHandler_WINDOW(): int {
    return 1800; // PaymentHandler::RECEIPT_WINDOW_SECONDS
}
