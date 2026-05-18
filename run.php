<?php
// عدم نمایش ارورها روی صفحه
ini_set('display_errors', 0);
error_reporting(E_ALL);

$update_raw = file_get_contents('php://input');
$update = json_decode($update_raw, true);

if (!$update) {
    echo "<h1>✅ ربات با موفقیت در حالت Webhook فعال است!</h1><p>قابلیت ارسال پست شیشه‌ای به کانال اضافه شد.</p>";
    exit;
}

// ارسال سریع تاییدیه به تلگرام
ob_start();
http_response_code(200);
echo "OK";
header('Connection: close');
header('Content-Length: ' . ob_get_length());
ob_end_flush();
@ob_flush();
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }

$storage_dir = __DIR__ . '/storage';
if (!is_dir($storage_dir)) mkdir($storage_dir, 0777, true);

require_once __DIR__ . '/config/settings.php';
require_once __DIR__ . '/core/Telegram.php';
require_once __DIR__ . '/core/Xui.php';
require_once __DIR__ . '/core/Utils.php';
require_once __DIR__ . '/core/DB.php';

$config = require __DIR__ . '/config/settings.php';
$telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');
$pdo = DB::connect($config);
$pdo->exec("SET NAMES utf8mb4");

function convert2English($string) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '۶', '۷', '۸', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace(array_merge($persian, $arabic), array_merge($english, $english), $string);
}

function formatBytes($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function tetraRequest($endpoint, $data, $proxy, $proxy_auth) {
    $ch = curl_init("https://tetra98.com/api/" . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
    if (!empty($proxy)) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        if (!empty($proxy_auth)) { curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_auth); }
    }
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false) { return ['status' => false, 'error_details' => "خطای cURL: " . $error]; }
    $decoded = json_decode($response, true);
    if ($decoded === null) { return ['status' => false, 'error_details' => "پاسخ نامعتبر سرور (HTTP $http_code)"]; }
    return $decoded;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
    `chat_id` bigint(20) NOT NULL PRIMARY KEY, `step` varchar(50) DEFAULT NULL,
    `last_msg_time` int(11) DEFAULT 0, `join_date` timestamp DEFAULT CURRENT_TIMESTAMP,
    `invited_by` bigint(20) DEFAULT NULL, `trial_used` tinyint(1) DEFAULT 0, `wallet` int(11) DEFAULT 0,
    `is_reseller` tinyint(1) DEFAULT 0, `temp_name` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key` varchar(50) NOT NULL PRIMARY KEY, `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$pdo->exec("CREATE TABLE IF NOT EXISTS `plans` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` varchar(100) NOT NULL,
    `gb` int(11) NOT NULL, `days` int(11) NOT NULL, `price` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$pdo->exec("CREATE TABLE IF NOT EXISTS `orders` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, `chat_id` bigint(20) NOT NULL,
    `plan_name` varchar(100) NOT NULL, `uuid` varchar(100) NOT NULL,
    `email` varchar(100) NOT NULL, `link` text NOT NULL, `buy_date` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

$pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (`chat_id` bigint(20) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$pdo->prepare("INSERT IGNORE INTO admins (chat_id) VALUES (?)")->execute([$config['admin_id']]);

$pdo->exec("CREATE TABLE IF NOT EXISTS `discounts` (
    `code` varchar(50) NOT NULL PRIMARY KEY, `percent` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$pdo->exec("CREATE TABLE IF NOT EXISTS `transactions` (
    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, `chat_id` bigint(20) NOT NULL,
    `authority` varchar(100) NOT NULL, `amount` int(11) NOT NULL, `type` varchar(50) NOT NULL,
    `plan_id` varchar(50) DEFAULT NULL, `code` varchar(50) DEFAULT 'none',
    `status` varchar(20) DEFAULT 'pending', `created_at` timestamp DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$defaults = [
    'bot_status' => '1', 'card_status' => '1', 'crypto_status' => '1', 'force_join' => '[]',
    'card_number' => 'وارد نشده', 'card_name' => 'وارد نشده', 'crypto_address' => 'TRX: وارد نشده',
    'crypto_guide' => 'لطفاً معادل تومانی مبلغ را به تتر تبدیل کرده و به آدرس زیر واریز کنید (شبکه TRC20):', 
    'tetra_api_key' => 'وارد نشده', 'tetra_status' => '0', 
    'buy_text' => '🛒 خرید اکانت', 'buy_status' => '1', 'account_text' => '👤 حساب کاربری من', 'account_status' => '1',
    'trial_text' => '🎁 تست رایگان', 'trial_status' => '1', 'referral_text' => '👥 زیرمجموعه گیری', 'referral_status' => '1',
    'services_text' => '📦 سرویس‌های من', 'services_status' => '1', 'support_text' => '👨‍💻 پشتیبانی', 'support_status' => '1',
    'guide_text' => '📚 آموزش و دانلود', 'guide_status' => '1',
    'extra_gb_status' => '1', 'extra_ip_status' => '1', 'renew_status' => '1',
    'trial_mb' => '500', 'trial_mins' => '60', 'support_id' => '@admin',
    'panel_url' => 'http://127.0.0.1:2053', 'panel_user' => 'admin', 'panel_pass' => 'admin', 'inbound_id' => '1',
    'admin_channel' => '', 'reseller_fee' => '150000', 'reseller_discount' => '20',
    'sub_domain' => '', 'referral_reward' => '0',
    'custom_plan_status' => '1', 'custom_gb_price' => '3000', 'custom_days' => '30',
    'guide_and' => 'آموزش اتصال اندروید (توسط ادمین تنظیم نشده)',
    'guide_ios' => 'آموزش اتصال آیفون (توسط ادمین تنظیم نشده)',
    'guide_win' => 'آموزش اتصال ویندوز (توسط ادمین تنظیم نشده)',
    'guide_lin' => 'آموزش اتصال لینوکس (توسط ادمین تنظیم نشده)',
    'text_start' => '👋 سلام! به فروشگاه ما خوش آمدید.',
    'channel_btn_text' => '🛒 خرید سرویس اختصاصی',
    'channel_btn_link' => 'https://t.me/'
];
foreach ($defaults as $k => $v) { $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]); }

function getSettings() {
    global $pdo; $rows = $pdo->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_ASSOC);
    $s = []; foreach ($rows as $row) $s[$row['setting_key']] = $row['setting_value']; return $s;
}

function checkForcedJoin($user_id, $telegram, $settings, $is_admin) {
    if ($is_admin) return true;
    $channels = json_decode($settings['force_join'], true) ?? [];
    if (empty($channels)) return true;
    $not_joined = [];
    foreach ($channels as $ch) {
        $res = $telegram->request('getChatMember', ['chat_id' => $ch['id'], 'user_id' => $user_id]);
        if (!isset($res['ok']) || !$res['ok'] || in_array($res['result']['status'], ['left', 'kicked'])) $not_joined[] = $ch;
    }
    return empty($not_joined) ? true : $not_joined;
}

function sendReport($telegram, $settings, $text) {
    if (!empty($settings['admin_channel'])) {
        $telegram->request('sendMessage', ['chat_id' => $settings['admin_channel'], 'text' => $text, 'parse_mode' => 'HTML']);
    }
}

function getFinalPrice($pdo, $pid, $code = 'none', $user_id = null, $settings = []) {
    if (strpos($pid, 'custom_') === 0) {
        $gb = (int)str_replace('custom_', '', $pid);
        $days = (int)($settings['custom_days'] ?? 30);
        $price = $gb * (int)($settings['custom_gb_price'] ?? 3000);
        $days_text = $days > 0 ? "$days روزه" : "زمان نامحدود"; 
        $plan = ['id' => $pid, 'name' => "🎛 پلن دلخواه $gb گیگ ($days_text)", 'gb' => $gb, 'days' => $days, 'price' => $price];
    } else {
        $plan = $pdo->prepare("SELECT * FROM plans WHERE id = ?"); $plan->execute([$pid]); $plan = $plan->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return false;
    }

    $price = $plan['price'];
    if ($user_id && !empty($settings)) {
        $is_reseller = $pdo->query("SELECT is_reseller FROM users WHERE chat_id = $user_id")->fetchColumn();
        if ($is_reseller == 1) {
            $r_disc = intval($settings['reseller_discount']);
            $price = $price - ($price * $r_disc / 100);
        }
    }
    if ($code !== 'none') {
        $disc = $pdo->prepare("SELECT percent FROM discounts WHERE code = ?"); $disc->execute([$code]); $disc = $disc->fetchColumn();
        if ($disc) $price = $price - ($price * $disc / 100);
    }
    $plan['final_price'] = max(0, $price);
    return $plan;
}

function getUserKeyboard($s) {
    $kb = []; $r1 = []; $r2 = []; $r3 = []; $r4 = []; $r5 = [];
    if ($s['buy_status'] == '1') $r1[] = ['text' => $s['buy_text']];
    if ($s['services_status'] == '1') $r1[] = ['text' => $s['services_text']];
    if ($s['account_status'] == '1') $r2[] = ['text' => $s['account_text']];
    if ($s['trial_status'] == '1') $r2[] = ['text' => $s['trial_text']];
    if ($s['referral_status'] == '1') $r3[] = ['text' => $s['referral_text']];
    if ($s['support_status'] == '1') $r3[] = ['text' => $s['support_text']];
    if ($s['guide_status'] == '1') $r4[] = ['text' => $s['guide_text']];
    $r4[] = ['text' => '💰 شارژ کیف پول']; $r5[] = ['text' => '🤝 درخواست نمایندگی'];
    if (!empty($r1)) $kb[] = $r1; if (!empty($r2)) $kb[] = $r2; if (!empty($r3)) $kb[] = $r3; if (!empty($r4)) $kb[] = $r4; if (!empty($r5)) $kb[] = $r5;
    return json_encode(['keyboard' => $kb, 'resize_keyboard' => true]);
}

function getCancelKeyboard() { return json_encode(['keyboard' => [[['text' => '🔙 انصراف']]], 'resize_keyboard' => true]); }

function getAdminMainKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '🛍 مدیریت فروشگاه'], ['text' => '👥 کاربران و آمار']],
        [['text' => '💳 مالی و کیف‌پول'], ['text' => '⚙️ تنظیمات ربات']],
        [['text' => '🔌 اتصال سرور (سنایی)'], ['text' => '🔴 وضعیت ربات 🟢']],
        [['text' => '🔙 بازگشت به ربات']]
    ], 'resize_keyboard' => true]);
}

function getStoreKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '➕ افزودن پلن جدید'], ['text' => '📋 لیست پلن‌ها']],
        [['text' => '🎁 تنظیمات تست رایگان'], ['text' => '🎟 کدهای تخفیف']],
        [['text' => '🎛 تنظیمات پلن دلخواه']],
        [['text' => '🔙 بازگشت به داشبورد']]
    ], 'resize_keyboard' => true]);
}

function getUsersSettingsKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '📊 آمار پیشرفته ربات'], ['text' => '🔍 مدیریت کاربر']],
        [['text' => '📢 مدیریت جوین اجباری'], ['text' => '👥 مدیریت ادمین‌ها']],
        [['text' => '📢 پیام همگانی'], ['text' => '🎁 پاداش زیرمجموعه‌گیری']],
        [['text' => '📢 ارسال به کانال'], ['text' => '⚙️ تنظیم دکمه کانال']],
        [['text' => '🔄 پاکسازی سابقه تست‌ها']],
        [['text' => '🔙 بازگشت به داشبورد']]
    ], 'resize_keyboard' => true]);
}

function getFinanceKeyboard() {
    $s = getSettings();
    return json_encode(['keyboard' => [
        [['text' => '🌐 تنظیمات درگاه آنلاین (Tetra98)']],
        [['text' => '💳 تنظیم کارت به کارت'], ['text' => '💲 تنظیم درگاه ارزی']],
        [['text' => 'وضعیت کارت: '.($s['card_status']=='1'?'روشن 🟢':'خاموش 🔴')], ['text' => 'وضعیت ارزی: '.($s['crypto_status']=='1'?'روشن 🟢':'خاموش 🔴')]],
        [['text' => '📝 تنظیم متن درگاه ارزی']],
        [['text' => '➕ شارژ کیف پول'], ['text' => '➖ کسر از کیف پول']],
        [['text' => '🎁 شارژ همگانی'], ['text' => '🔥 کسر همگانی']],
        [['text' => '🔙 بازگشت به داشبورد']]
    ], 'resize_keyboard' => true]);
}

function getBotSettingsKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '⚙️ روشن/خاموش دکمه‌ها'], ['text' => '🎛 دکمه‌های درون سرویس']],
        [['text' => '👨‍💻 تنظیم آیدی پشتیبانی'], ['text' => '📢 تنظیم چنل گزارشات']],
        [['text' => '💎 تنظیمات نمایندگی'], ['text' => '📝 تنظیم متن آموزش']],
        [['text' => '💬 تنظیم پیام خوش‌آمدگویی (/start)']],
        [['text' => '🔙 بازگشت به داشبورد']]
    ], 'resize_keyboard' => true]);
}

function getButtonsToggleKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '🛒 دکمه خرید'], ['text' => '🎁 دکمه تست']],
        [['text' => '👤 دکمه حساب'], ['text' => '👥 دکمه رفرال']],
        [['text' => '📦 دکمه سرویس'], ['text' => '👨‍💻 دکمه پشتیبانی']],
        [['text' => '📚 دکمه آموزش'], ['text' => '🔙 تنظیمات ربات']]
    ], 'resize_keyboard' => true]);
}

function getMyServicesSettingsKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '➕ دکمه حجم اضافه'], ['text' => '👥 دکمه کاربر اضافه']],
        [['text' => '🔄 دکمه تمدید سرویس']],
        [['text' => '🔙 تنظیمات ربات']]
    ], 'resize_keyboard' => true]);
}

function getTrialKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '⚙️ تنظیم حجم تست (MB)'], ['text' => '⏳ تنظیم زمان تست (دقیقه)']],
        [['text' => '🔙 مدیریت فروشگاه']]
    ], 'resize_keyboard' => true]);
}

function getPanelSettingsKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '🔗 تغییر آدرس پنل'], ['text' => '🆔 تغییر آیدی کانفیگ']],
        [['text' => '👤 تغییر یوزرنیم'], ['text' => '🔑 تغییر پسورد']],
        [['text' => '🌐 تنظیم دامنه لینک ساب']],
        [['text' => '🔙 بازگشت به داشبورد']]
    ], 'resize_keyboard' => true]);
}

function getForceJoinKeyboard() {
    return json_encode(['keyboard' => [
        [['text' => '➕ افزودن کانال قفل'], ['text' => '📋 لیست کانال‌های قفل']],
        [['text' => '🔙 بازگشت به داشبورد']]
    ], 'resize_keyboard' => true]);
}

function sendInvoiceMsg($telegram, $chat_id, $user_id, $pid, $code, $plan, $wallet, $settings, $config_name = null) {
    if ($config_name) {
        $msg = "🧾 <b>پیش‌فاکتور:</b>\n🔸 {$plan['name']}\n👤 نام کانفیگ: <code>$config_name</code>\n💵 مبلغ قابل پرداخت: <code>".number_format($plan['final_price'])."</code> تومان" . ($plan['final_price'] < $plan['price'] ? " (با تخفیف)" : "") . "\n\nلطفاً روش پرداخت را انتخاب کنید:";
    } else {
        $msg = "🧾 <b>پیش‌فاکتور:</b>\n🔸 {$plan['name']}\n💵 مبلغ قابل پرداخت: <code>".number_format($plan['final_price'])."</code> تومان" . ($plan['final_price'] < $plan['price'] ? " (با تخفیف)" : "") . "\n\nلطفاً روش پرداخت را انتخاب کنید:";
    }
    
    $keys = [];
    if ($wallet >= $plan['final_price']) $keys[] = [['text' => '💰 پرداخت آنی از کیف پول', 'callback_data' => "pay|{$pid}|{$code}|wallet"]];
    if ($settings['tetra_status'] == '1') $keys[] = [['text' => '🌐 پرداخت آنلاین (تترا98)', 'callback_data' => "pay|{$pid}|{$code}|tetra"]];
    if ($settings['card_status'] == '1') $keys[] = [['text' => '💳 کارت به کارت', 'callback_data' => "pay|{$pid}|{$code}|card"]];
    if ($settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی', 'callback_data' => "pay|{$pid}|{$code}|crypto"]];
    if ($code == 'none') $keys[] = [['text' => '🎟 استفاده از کد تخفیف', 'callback_data' => "apply_disc|{$pid}"]];
    $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
}

$settings = getSettings(); 
$panel = new Xui($settings['panel_url'], $settings['panel_user'], $settings['panel_pass']);

$user_id = $update['message']['from']['id'] ?? ($update['callback_query']['from']['id'] ?? 0);
$chat_id = $update['message']['chat']['id'] ?? ($update['callback_query']['message']['chat']['id'] ?? 0);

if (!$user_id) exit;

$is_admin = $pdo->query("SELECT 1 FROM admins WHERE chat_id = $user_id")->fetchColumn() || $user_id == $config['admin_id'];

if (isset($update['callback_query'])) {
    $call = $update['callback_query'];
    $data = $call['data'];
    $msg_id = $call['message']['message_id'];
    
    if ($data == 'add_new_discount' && $is_admin) {
        $pdo->prepare("UPDATE users SET step = 'add_disc_code' WHERE chat_id = ?")->execute([$user_id]);
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "لطفاً نام کد تخفیف جدید را وارد کنید:\n<i>(بهتر است برای جلوگیری از خطا از حروف انگلیسی و بدون فاصله استفاده کنید)</i>\n<i>(جهت انصراف /cancel را ارسال کنید)</i>", 'parse_mode' => 'HTML']);
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
        exit;
    }

    if (strpos($data, 'approve_') === 0 || strpos($data, 'reject_') === 0) {
        if (!$is_admin) {
            $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id'], 'text' => "⛔️ شما دسترسی تایید این درخواست را ندارید!", 'show_alert' => true]);
            exit;
        }
        
        $target_chat = $call['message']['chat']['id']; 
        $has_photo = isset($call['message']['photo']);
        $original_text = $has_photo ? ($call['message']['caption'] ?? '') : ($call['message']['text'] ?? '');

        if (strpos($data, 'approve_reseller_') === 0 || strpos($data, 'reject_reseller_') === 0) {
            $is_approve = strpos($data, 'approve_reseller_') === 0;
            $buyer_id = str_replace(['approve_reseller_', 'reject_reseller_'], '', $data);

            if ($is_approve) {
                $pdo->prepare("UPDATE users SET is_reseller = 1 WHERE chat_id = ?")->execute([$buyer_id]);
                $telegram->request('sendMessage', ['chat_id' => $buyer_id, 'text' => "🎉 <b>تبریک! درخواست نمایندگی شما تایید شد.</b>\nاز این پس تخفیف‌های ویژه نمایندگان به صورت خودکار روی تمامی سرویس‌ها برای شما اعمال می‌شود.", 'parse_mode' => 'HTML']);
                $new_text = "✅ درخواست نمایندگی تایید شد.\n" . $original_text;
                sendReport($telegram, $settings, "🤝 <b>نماینده جدید (تایید رسید):</b>\n👤 کاربر: <code>$buyer_id</code>");
            } else {
                $telegram->request('sendMessage', ['chat_id' => $buyer_id, 'text' => "❌ <b>رسید درخواست نمایندگی شما توسط مدیریت رد شد.</b>", 'parse_mode' => 'HTML']);
                $new_text = "❌ درخواست نمایندگی رد شد.\n" . $original_text;
            }
        }
        elseif (strpos($data, 'approve_charge_') === 0 || strpos($data, 'reject_charge_') === 0) {
            $is_approve = strpos($data, 'approve_charge_') === 0;
            $parts = explode('_', str_replace(['approve_charge_', 'reject_charge_'], '', $data));
            $buyer_id = $parts[0]; $amount = $parts[1];

            if ($is_approve) {
                $pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$amount, $buyer_id]);
                $telegram->request('sendMessage', ['chat_id' => $buyer_id, 'text' => "✅ <b>کیف پول شما با موفقیت " . number_format($amount) . " تومان شارژ شد!</b>\nاکنون میتوانید از بخش خرید اکانت، سرویس خود را آنی تحویل بگیرید.", 'parse_mode' => 'HTML']);
                $new_text = "✅ شارژ $amount تومانی تایید شد.\n" . $original_text;
                sendReport($telegram, $settings, "💰 <b>شارژ حساب (تایید رسید):</b>\n👤 کاربر: <code>$buyer_id</code>\n💵 مبلغ: ".number_format($amount)." تومان");
            } else {
                $telegram->request('sendMessage', ['chat_id' => $buyer_id, 'text' => "❌ <b>درخواست شارژ کیف پول شما توسط مدیریت رد شد.</b> در صورت نیاز به راهنمایی با پشتیبانی تماس بگیرید.", 'parse_mode' => 'HTML']);
                $new_text = "❌ درخواست شارژ رد شد.\n" . $original_text;
            }
        }
        elseif (strpos($data, 'approve_receipt|') === 0 || strpos($data, 'reject_receipt|') === 0) {
            $is_approve = strpos($data, 'approve_receipt|') === 0;
            $parts = explode('|', str_replace(['approve_receipt|', 'reject_receipt|'], '', $data));
            $buyer_id = $parts[0]; $pid = $parts[1]; $code = $parts[2] ?? 'none';

            if ($is_approve) {
                $plan = getFinalPrice($pdo, $pid, $code, $buyer_id, $settings);
                if ($plan) {
                    $uuid = Utils::generateUUIDv4(); 
                    $usr_t = $pdo->prepare("SELECT temp_name FROM users WHERE chat_id = ?"); $usr_t->execute([$buyer_id]); $tmp_name = $usr_t->fetchColumn();
                    $base_name = !empty($tmp_name) ? $tmp_name : "user_" . substr($buyer_id, -4);
                    $email = $base_name . "_" . rand(1000,9999);
                    
                    $gb_to_bytes = $plan['gb'] > 0 ? $plan['gb'] * 1073741824 : 0;
                    $days_to_ms = $plan['days'] > 0 ? (time() + ($plan['days'] * 86400)) * 1000 : 0;
                    $result = $panel->addClient($settings['inbound_id'], $email, $uuid, $gb_to_bytes, $days_to_ms, 0, $settings['sub_domain'] ?? '');
                    
                    if ($result['status']) {
                        $pdo->prepare("INSERT INTO orders (chat_id, plan_name, uuid, email, link) VALUES (?, ?, ?, ?, ?)")->execute([$buyer_id, $plan['name'], $uuid, $email, $result['link']]);
                        $cap = "✅ <b>رسید شما تایید شد!</b>\n\n🎉 سرویس شما:\n\n<code>" . $result['link'] . "</code>\n\n<i>(در بخش 'سرویس‌های من' نیز ذخیره شد)</i>";
                        $telegram->request('sendMessage', ['chat_id' => $buyer_id, 'text' => $cap, 'parse_mode' => 'HTML']);
                        
                        $new_text = "✅ تایید و تحویل مشتری شد.\n" . $original_text;
                        sendReport($telegram, $settings, "🛍 <b>خرید تایید شده (رسید):</b>\n👤 کاربر: <code>$buyer_id</code>\n📦 پلن: {$plan['name']}");
                    } else {
                        $telegram->request('sendMessage', ['chat_id' => $user_id, 'text' => "❌ خطا در سرور سنایی هنگام تحویل به مشتری.\nدلیل خطا: " . $result['msg']]);
                    }
                }
            } else {
                $telegram->request('sendMessage', ['chat_id' => $buyer_id, 'text' => "❌ <b>رسید خرید شما توسط مدیریت رد شد.</b> با پشتیبانی تماس بگیرید.", 'parse_mode' => 'HTML']);
                $new_text = "❌ این رسید رد شد.\n" . $original_text;
            }
        }

        if (isset($new_text)) {
            if ($has_photo) $telegram->request('editMessageCaption', ['chat_id' => $target_chat, 'message_id' => $msg_id, 'caption' => $new_text]);
            else $telegram->request('editMessageText', ['chat_id' => $target_chat, 'message_id' => $msg_id, 'text' => $new_text]);
        }
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
        exit;
    }

    if (strpos($data, 'revoke_reseller_') === 0 && $is_admin) {
        $uid = str_replace('revoke_reseller_', '', $data);
        $pdo->prepare("UPDATE users SET is_reseller = 0 WHERE chat_id = ?")->execute([$uid]);
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id'], 'text' => "✅ نمایندگی کاربر با موفقیت لغو شد.", 'show_alert' => true]);
        exit;
    }

    if (strpos($data, 'make_reseller_') === 0 && $is_admin) {
        $uid = str_replace('make_reseller_', '', $data);
        $pdo->prepare("UPDATE users SET is_reseller = 1 WHERE chat_id = ?")->execute([$uid]);
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id'], 'text' => "✅ کاربر با موفقیت به نماینده ارتقا یافت.", 'show_alert' => true]);
        exit;
    }

    if (strpos($data, 'edit_guide_') === 0 && $is_admin) {
        $os = str_replace('edit_guide_', '', $data);
        $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["set_guide_$os", $user_id]);
        $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "لطفاً متن جدید آموزش برای این بخش را بفرستید:\n<i>(میتوانید شامل لینک، متن و ایموجی باشد)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
        exit;
    }

    if (strpos($data, 'dl_') === 0) {
        $os = str_replace('dl_', '', $data);
        $guide_text = $settings["guide_$os"] ?? "آموزش این بخش در حال بروزرسانی است...";
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $guide_text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
        exit;
    }

    if ($data == 'custom_plan_start') {
        $keys = json_encode(['inline_keyboard' => [
            [['text' => '🔢 بر اساس حجم (گیگ)', 'callback_data' => 'custom_plan_by_gb']],
            [['text' => '💵 بر اساس مبلغ (تومان)', 'callback_data' => 'custom_plan_by_price']]
        ]]);
        $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "🎛 <b>ساخت پلن دلخواه</b>\n\nلطفاً مبنای محاسبه را انتخاب کنید:", 'parse_mode' => 'HTML', 'reply_markup' => $keys]);
        exit;
    }
    if ($data == 'custom_plan_by_gb') {
        $pdo->prepare("UPDATE users SET step = 'ask_custom_gb' WHERE chat_id = ?")->execute([$user_id]);
        $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔢 لطفاً حجم مورد نیاز خود را به <b>گیگابایت</b> وارد کنید:\n<i>(فقط عدد انگلیسی بفرستید، حداقل 1 گیگ)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
        exit;
    }
    if ($data == 'custom_plan_by_price') {
        $pdo->prepare("UPDATE users SET step = 'ask_custom_price' WHERE chat_id = ?")->execute([$user_id]);
        $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "💵 لطفاً مبلغی که می‌خواهید هزینه کنید را به <b>تومان</b> وارد کنید:\n<i>(فقط عدد انگلیسی بفرستید، حداقل " . number_format($settings['custom_gb_price']) . " تومان)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
        exit;
    }

    if (strpos($data, 'verify_tetra_') === 0) {
        $auth = str_replace('verify_tetra_', '', $data);
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id'], 'text' => "⏳ در حال بررسی و تایید پرداخت..."]);
        
        $txn = $pdo->prepare("SELECT * FROM transactions WHERE authority = ? AND status = 'pending'");
        $txn->execute([$auth]); $txn = $txn->fetch(PDO::FETCH_ASSOC);
        
        if ($txn) {
            $payload = ["authority" => $auth, "ApiKey" => $settings['tetra_api_key']];
            $res = tetraRequest('verify', $payload, $config['proxy_url'], $config['proxy_auth']);
            
            if (isset($res['status']) && $res['status'] == 100) {
                $pdo->prepare("UPDATE transactions SET status = 'paid' WHERE id = ?")->execute([$txn['id']]);
                
                if ($txn['type'] == 'charge') {
                    $pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$txn['amount'], $txn['chat_id']]);
                    $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "✅ پرداخت تایید شد! کیف پول شما مبلغ " . number_format($txn['amount']) . " تومان شارژ شد."]);
                } elseif ($txn['type'] == 'reseller') {
                    $pdo->prepare("UPDATE users SET is_reseller = 1 WHERE chat_id = ?")->execute([$txn['chat_id']]);
                    $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "✅ پرداخت موفق! شما به نماینده ارتقا یافتید."]);
                } elseif ($txn['type'] == 'plan') {
                    $plan = getFinalPrice($pdo, $txn['plan_id'], $txn['code'], $txn['chat_id'], $settings);
                    $uuid = Utils::generateUUIDv4(); 
                    $usr_t = $pdo->prepare("SELECT temp_name FROM users WHERE chat_id = ?"); $usr_t->execute([$txn['chat_id']]); $tmp_name = $usr_t->fetchColumn();
                    $base_name = !empty($tmp_name) ? $tmp_name : "user_" . substr($txn['chat_id'], -4);
                    $email = $base_name . "_" . rand(1000,9999);
                    
                    $gb_to_bytes = $plan['gb'] > 0 ? $plan['gb'] * 1073741824 : 0;
                    $days_to_ms = $plan['days'] > 0 ? (time() + ($plan['days'] * 86400)) * 1000 : 0;
                    $xui_res = $panel->addClient($settings['inbound_id'], $email, $uuid, $gb_to_bytes, $days_to_ms, 0, $settings['sub_domain'] ?? '');
                    
                    if ($xui_res['status']) {
                        $pdo->prepare("INSERT INTO orders (chat_id, plan_name, uuid, email, link) VALUES (?, ?, ?, ?, ?)")->execute([$txn['chat_id'], $plan['name'], $uuid, $email, $xui_res['link']]);
                        $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "✅ <b>پرداخت موفق!</b>\n\nسرویس شما ایجاد شد:\n<code>" . $xui_res['link'] . "</code>", 'parse_mode' => 'HTML']);
                    } else {
                        $pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$txn['amount'], $txn['chat_id']]);
                        $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "✅ پرداخت تایید شد اما سرور سنایی قطع است. مبلغ به کیف پول شما برگشت داده شد."]);
                    }
                }
                sendReport($telegram, $settings, "🟢 پرداخت آنلاین موفق (تترا98)\nمبلغ: " . number_format($txn['amount']) . " تومان\nکاربر: <code>$user_id</code>");
            } else {
                $err = $res['error_details'] ?? ($res['message'] ?? 'تراکنش پرداخت نشده یا منقضی شده است');
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ خطا در بررسی پرداخت:\n<code>$err</code>", 'parse_mode' => 'HTML']);
            }
        } else { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ تراکنش یافت نشد یا قبلاً تایید شده است."]); }
        exit;
    }

    if ($data == 'check_join') {
        $join_status = checkForcedJoin($user_id, $telegram, $settings, $is_admin);
        if ($join_status === true) {
            $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ عضویت شما تایید شد. به ربات خوش آمدید!", 'reply_markup' => getUserKeyboard($settings)]);
        } else {
            $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id'], 'text' => "❌ شما هنوز در همه کانال‌ها عضو نشده‌اید!", 'show_alert' => true]);
        }
        exit;
    }

    if (strpos($data, 'myserv_') === 0) {
        $action = explode('_', $data);
        $oid = $action[2];
        $order = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND chat_id = ?");
        $order->execute([$oid, $user_id]);
        $order = $order->fetch(PDO::FETCH_ASSOC);

        if ($order) {
            if ($action[1] == 'info') {
                $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id'], 'text' => "⏳ در حال دریافت آمار زنده از سرور..."]);
                $stats = $panel->getClientStats($order['email']);
                
                $msg = "📦 <b>اطلاعات زنده سرویس شما:</b>\n\n🔹 پلن: <b>{$order['plan_name']}</b>\n📧 شناسه: <code>{$order['email']}</code>\n";
                if ($stats) {
                    $total_bytes = $stats['total']; $used_bytes = $stats['up'] + $stats['down']; $rem_bytes = $total_bytes - $used_bytes;
                    $total_str = $total_bytes == 0 ? 'نامحدود' : formatBytes($total_bytes);
                    $used_str = formatBytes($used_bytes);
                    $rem_str = $total_bytes == 0 ? 'نامحدود' : formatBytes(max(0, $rem_bytes));
                    $expiry_str = $stats['expiryTime'] == 0 ? 'نامحدود' : date('Y-m-d H:i', $stats['expiryTime'] / 1000);
                    $status_str = $stats['enable'] ? "🟢 متصل و فعال" : "🔴 غیرفعال / مسدود";
                    
                    $msg .= "〰️〰️〰️〰️〰️〰️\n📊 وضعیت اکانت: $status_str\n📥 ترافیک مصرفی: <code>$used_str</code>\n🔋 ترافیک باقیمانده: <code>$rem_str</code> (از $total_str)\n⏳ تاریخ انقضا: <code>$expiry_str</code>\n";
                } else { $msg .= "\n⚠️ <i>در حال حاضر امکان دریافت آمار مصرف از سرور وجود ندارد.</i>"; }

                $keys = [];
                $keys[] = [['text' => '🔗 کپی لینک', 'callback_data' => "myserv_link_$oid"], ['text' => '📱 دریافت بارکد (QR)', 'callback_data' => "myserv_qr_$oid"]];
                $row2 = [];
                if ($settings['extra_gb_status'] == '1') $row2[] = ['text' => '➕ حجم اضافه', 'callback_data' => "myserv_extragb_$oid"];
                if ($settings['extra_ip_status'] == '1') $row2[] = ['text' => '👥 کاربر اضافه', 'callback_data' => "myserv_extraip_$oid"];
                if (!empty($row2)) $keys[] = $row2;
                if ($settings['renew_status'] == '1') $keys[] = [['text' => '🔄 تمدید سرویس', 'callback_data' => "myserv_renew_$oid"]];

                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
            }
            elseif ($action[1] == 'link') {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔗 <b>لینک سرویس شما:</b>\n\n<code>{$order['link']}</code>", 'parse_mode' => 'HTML']);
                $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
            }
            elseif ($action[1] == 'qr') {
                $clean_link = strip_tags($order['link']);
                $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode($clean_link);
                $telegram->request('sendPhoto', ['chat_id' => $chat_id, 'photo' => $qr_url, 'caption' => "📱 <b>QR Code سرویس شما</b>\nجهت اسکن در نرم‌افزار v2ray", 'parse_mode' => 'HTML']);
                $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
            }
            elseif (in_array($action[1], ['extragb', 'extraip', 'renew'])) {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "☎️ جهت انجام این عملیات روی این اکانت، لطفاً با ارسال شناسه (<code>{$order['email']}</code>) به پشتیبانی پیام دهید.", 'parse_mode' => 'HTML']);
                $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
            }
        }
        exit;
    }

    if (strpos($data, 'reseller_pay_') === 0) {
        $method = str_replace('reseller_pay_', '', $data);
        $fee = $settings['reseller_fee'];
        
        if ($method == 'wallet') {
            $u = $pdo->prepare("SELECT wallet FROM users WHERE chat_id = ?"); $u->execute([$user_id]); $wallet = $u->fetchColumn();
            if ($wallet >= $fee) {
                $pdo->prepare("UPDATE users SET wallet = wallet - ?, is_reseller = 1 WHERE chat_id = ?")->execute([$fee, $user_id]);
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "🎉 <b>تبریک!</b>\nمبلغ از کیف پول شما کسر شد و حساب شما با موفقیت به <b>نمایندگی</b> ارتقا یافت. از این پس $settings[reseller_discount]% تخفیف روی تمامی پلن‌ها برای شما محاسبه می‌شود.", 'parse_mode' => 'HTML']);
                sendReport($telegram, $settings, "🤝 <b>نماینده جدید (از کیف پول):</b>\n👤 کاربر: <code>$user_id</code>");
            } else { $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id'], 'text' => "❌ موجودی کافی نیست!", 'show_alert' => true]); }
        } elseif ($method == 'tetra') {
            if (empty(trim($settings['tetra_api_key'])) || $settings['tetra_api_key'] == 'وارد نشده') {
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "❌ خطای ادمین: کلید API تترا98 در ربات ثبت نشده است!"]); exit;
            }
            $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "⏳ در حال اتصال به درگاه آنلاین..."]);
            $payload = [ "ApiKey" => trim($settings['tetra_api_key']), "Hash_id" => "R_" . $user_id . "_" . time(), "Amount" => (int)$fee, "Description" => "ارتقا به نمایندگی", "Email" => "user@site.com", "Mobile" => "09120000000", "CallbackURL" => "https://t.me" ];
            $res = tetraRequest('create_order', $payload, $config['proxy_url'], $config['proxy_auth']);
            if (isset($res['status']) && $res['status'] == 100) {
                $pdo->prepare("INSERT INTO transactions (chat_id, authority, amount, type) VALUES (?, ?, ?, ?)")->execute([$user_id, $res['Authority'], $fee, 'reseller']);
                $keys = json_encode(['inline_keyboard' => [[['text' => '💳 پرداخت فاکتور', 'url' => $res['payment_url_web']]], [['text' => '✅ بررسی پرداخت', 'callback_data' => "verify_tetra_{$res['Authority']}"]]]]);
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "فاکتور صادر شد. پس از پرداخت روی بررسی کلیک کنید:", 'reply_markup' => $keys]);
            } else { 
                $err = $res['error_details'] ?? ($res['message'] ?? json_encode($res, JSON_UNESCAPED_UNICODE));
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "❌ خطا در اتصال به درگاه تترا98!\n\nدلیل خطا:\n<code>$err</code>", 'parse_mode' => 'HTML']); 
            }
        } else {
            if ($method == 'card') $msg = "💳 <b>واریز به کارت (هزینه نمایندگی):</b>\n<code>{$settings['card_number']}</code>\n👤 {$settings['card_name']}\n💵 مبلغ: ".number_format($fee)." تومان\n\n📸 <b>پس از واریز، عکس رسید را اینجا بفرستید.</b>";
            else $msg = "💲 <b>واریز ارزی:</b>\n{$settings['crypto_guide']}\n\n💳 <b>آدرس ولت:</b>\n<code>{$settings['crypto_address']}</code>\n💵 مبلغ: ".number_format($fee)." تومان\n\n📸 <b>پس از واریز، متن کد Hash (TxID) یا عکس رسید را بفرستید.</b>";
            
            $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["wait_reseller_receipt", $user_id]);
            $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
        }
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
        exit;
    }

    if (strpos($data, 'charge_method_') === 0) {
        $parts = explode('_', str_replace('charge_method_', '', $data));
        $amount = $parts[0]; $method = $parts[1];
        if ($method == 'tetra') {
            if (empty(trim($settings['tetra_api_key'])) || $settings['tetra_api_key'] == 'وارد نشده') {
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "❌ خطای ادمین: کلید API تترا98 در ربات ثبت نشده است!"]); exit;
            }
            $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "⏳ در حال اتصال به درگاه آنلاین..."]);
            $payload = [ "ApiKey" => trim($settings['tetra_api_key']), "Hash_id" => "C_" . $user_id . "_" . time(), "Amount" => (int)$amount, "Description" => "شارژ کیف پول", "Email" => "user@site.com", "Mobile" => "09120000000", "CallbackURL" => "https://t.me" ];
            $res = tetraRequest('create_order', $payload, $config['proxy_url'], $config['proxy_auth']);
            if (isset($res['status']) && $res['status'] == 100) {
                $pdo->prepare("INSERT INTO transactions (chat_id, authority, amount, type) VALUES (?, ?, ?, ?)")->execute([$user_id, $res['Authority'], $amount, 'charge']);
                $keys = json_encode(['inline_keyboard' => [[['text' => '💳 پرداخت فاکتور', 'url' => $res['payment_url_web']]], [['text' => '✅ بررسی پرداخت', 'callback_data' => "verify_tetra_{$res['Authority']}"]]]]);
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "فاکتور صادر شد. پس از پرداخت روی بررسی کلیک کنید:", 'reply_markup' => $keys]);
            } else { 
                $err = $res['error_details'] ?? ($res['message'] ?? json_encode($res, JSON_UNESCAPED_UNICODE));
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "❌ خطا در اتصال به درگاه تترا98!\n\nدلیل خطا:\n<code>$err</code>", 'parse_mode' => 'HTML']); 
            }
        } else {
            if ($method == 'card') $msg = "💳 <b>واریز به کارت:</b>\n<code>{$settings['card_number']}</code>\n👤 {$settings['card_name']}\n💵 مبلغ: ".number_format($amount)." تومان\n\n📸 <b>پس از واریز، عکس رسید را اینجا بفرستید.</b>";
            else $msg = "💲 <b>واریز ارزی:</b>\n{$settings['crypto_guide']}\n\n💳 <b>آدرس ولت:</b>\n<code>{$settings['crypto_address']}</code>\n💵 مبلغ: ".number_format($amount)." تومان\n\n📸 <b>پس از واریز، متن کد Hash (TxID) یا عکس رسید را بفرستید.</b>";
            
            $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["wait_charge_receipt_$amount", $user_id]);
            $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
        }
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
        exit;
    }

    if (strpos($data, 'apply_disc|') === 0) {
        $parts = explode('|', $data); $pid = $parts[1];
        $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["ask_discount|{$pid}", $user_id]);
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🎟 لطفاً کد تخفیف خود را ارسال کنید:", 'reply_markup' => getCancelKeyboard()]);
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
        exit;
    }

    if (strpos($data, 'pay|') === 0) {
        $parts = explode('|', $data);
        $pid = $parts[1]; $code = $parts[2] ?? 'none'; $method = $parts[3] ?? 'name';
        $plan = getFinalPrice($pdo, $pid, $code, $user_id, $settings);

        if ($plan) {
            if ($method == 'name') {
                $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["ask_name|{$pid}|{$code}", $user_id]);
                $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔤 لطفاً یک نام انگلیسی برای کانفیگ خود وارد کنید:\n<i>(این نام در برنامه اتصال شما نمایش داده می‌شود. لطفاً بدون فاصله بنویسید)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
            }
            elseif ($method == 'invoice') {
                $u = $pdo->prepare("SELECT wallet FROM users WHERE chat_id = ?"); $u->execute([$user_id]); $wallet = $u->fetchColumn();
                $usr_t = $pdo->prepare("SELECT temp_name FROM users WHERE chat_id = ?"); $usr_t->execute([$user_id]); $tmp_name = $usr_t->fetchColumn();
                $config_name = !empty($tmp_name) ? $tmp_name : "user_" . substr($user_id, -4);

                $msg = "🧾 <b>پیش‌فاکتور:</b>\n🔸 {$plan['name']}\n👤 نام کانفیگ: <code>$config_name</code>\n💵 مبلغ قابل پرداخت: <code>".number_format($plan['final_price'])."</code> تومان" . ($plan['final_price'] < $plan['price'] ? " (با تخفیف)" : "") . "\n\nلطفاً روش پرداخت را انتخاب کنید:";
                
                $keys = [];
                if ($wallet >= $plan['final_price']) $keys[] = [['text' => '💰 پرداخت آنی از کیف پول', 'callback_data' => "pay|{$pid}|{$code}|wallet"]];
                if ($settings['tetra_status'] == '1') $keys[] = [['text' => '🌐 پرداخت آنلاین (تترا98)', 'callback_data' => "pay|{$pid}|{$code}|tetra"]];
                if ($settings['card_status'] == '1') $keys[] = [['text' => '💳 کارت به کارت', 'callback_data' => "pay|{$pid}|{$code}|card"]];
                if ($settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی', 'callback_data' => "pay|{$pid}|{$code}|crypto"]];
                if ($code == 'none') $keys[] = [['text' => '🎟 استفاده از کد تخفیف', 'callback_data' => "apply_disc|{$pid}"]];
                
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
            }
            elseif ($method == 'tetra') {
                if (empty(trim($settings['tetra_api_key'])) || $settings['tetra_api_key'] == 'وارد نشده') {
                    $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "❌ خطای ادمین: کلید API تترا98 در ربات ثبت نشده است!"]); exit;
                }
                $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "⏳ در حال اتصال به درگاه آنلاین..."]);
                $payload = [ "ApiKey" => trim($settings['tetra_api_key']), "Hash_id" => "P_" . $pid . "_" . time(), "Amount" => (int)$plan['final_price'], "Description" => "خرید پلن", "Email" => "user@site.com", "Mobile" => "09120000000", "CallbackURL" => "https://t.me" ];
                $res = tetraRequest('create_order', $payload, $config['proxy_url'], $config['proxy_auth']);
                if (isset($res['status']) && $res['status'] == 100) {
                    $pdo->prepare("INSERT INTO transactions (chat_id, authority, amount, type, plan_id, code) VALUES (?, ?, ?, ?, ?, ?)")->execute([$user_id, $res['Authority'], $plan['final_price'], 'plan', $pid, $code]);
                    $keys = json_encode(['inline_keyboard' => [[['text' => '💳 پرداخت فاکتور', 'url' => $res['payment_url_web']]], [['text' => '✅ بررسی پرداخت', 'callback_data' => "verify_tetra_{$res['Authority']}"]]]]);
                    $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "فاکتور صادر شد. پس از پرداخت روی بررسی کلیک کنید:", 'reply_markup' => $keys]);
                } else { 
                    $err = $res['error_details'] ?? ($res['message'] ?? json_encode($res, JSON_UNESCAPED_UNICODE));
                    $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "❌ خطا در اتصال به درگاه تترا98!\n\nدلیل خطا:\n<code>$err</code>", 'parse_mode' => 'HTML']); 
                }
            }
            elseif ($method == 'wallet') {
                $u = $pdo->prepare("SELECT wallet FROM users WHERE chat_id = ?"); $u->execute([$user_id]); $wallet = $u->fetchColumn();
                if ($wallet >= $plan['final_price']) {
                    $pdo->prepare("UPDATE users SET wallet = wallet - ? WHERE chat_id = ?")->execute([$plan['final_price'], $user_id]);
                    $telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => "⏳ در حال ساخت سرویس در سرور..."]);
                    
                    $uuid = Utils::generateUUIDv4();
                    $usr_t = $pdo->prepare("SELECT temp_name FROM users WHERE chat_id = ?"); $usr_t->execute([$user_id]); $tmp_name = $usr_t->fetchColumn();
                    $base_name = !empty($tmp_name) ? $tmp_name : "user_" . substr($user_id, -4);
                    $email = $base_name . "_" . rand(1000,9999);
                    
                    $gb_to_bytes = $plan['gb'] > 0 ? $plan['gb'] * 1073741824 : 0;
                    $days_to_ms = $plan['days'] > 0 ? (time() + ($plan['days'] * 86400)) * 1000 : 0;
                    $result = $panel->addClient($settings['inbound_id'], $email, $uuid, $gb_to_bytes, $days_to_ms, 0, $settings['sub_domain'] ?? '');
                    
                    if ($result['status']) {
                        $pdo->prepare("INSERT INTO orders (chat_id, plan_name, uuid, email, link) VALUES (?, ?, ?, ?, ?)")->execute([$user_id, $plan['name'], $uuid, $email, $result['link']]);
                        $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
                        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ <b>خرید موفق!</b>\n\n{$result['link']}", 'parse_mode' => 'HTML', 'reply_markup' => getUserKeyboard($settings)]);
                        sendReport($telegram, $settings, "🛍 <b>خرید جدید (کیف پول):</b>\n👤 کاربر: <code>$user_id</code>\n📦 پلن: {$plan['name']}\n💵 مبلغ: ".number_format($plan['final_price']));
                    } else {
                        $pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$plan['final_price'], $user_id]);
                        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ خطا سرور سنایی! ارتباط قطع است. پول برگشت داده شد."]);
                    }
                } else {
                    $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id'], 'text' => "❌ موجودی کافی نیست!", 'show_alert' => true]);
                }
            }
            elseif ($method == 'card' || $method == 'crypto') {
                if ($method == 'card') $msg = "💳 <b>واریز به کارت:</b>\n<code>{$settings['card_number']}</code>\n👤 {$settings['card_name']}\n💵 مبلغ: ".number_format($plan['final_price'])." تومان\n\n📸 <b>پس از واریز، عکس رسید را اینجا بفرستید.</b>";
                else $msg = "💲 <b>واریز ارزی:</b>\n{$settings['crypto_guide']}\n\n💳 <b>آدرس ولت:</b>\n<code>{$settings['crypto_address']}</code>\n💵 مبلغ: ".number_format($plan['final_price'])." تومان\n\n📸 <b>پس از واریز، متن کد Hash (TxID) یا عکس رسید را بفرستید.</b>";
                
                $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["wait_receipt|{$pid}|{$code}|{$method}", $user_id]);
                $telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
            }
        }
        $telegram->request('answerCallbackQuery', ['callback_query_id' => $call['id']]);
        exit;
    }
}

// ==========================================
// ✉️ پردازش پیام‌های متنی
// ==========================================
if (isset($update['message'])) {
    $text = $update['message']['text'] ?? '';
    $username = $update['message']['from']['username'] ?? $user_id;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE chat_id = ?"); $stmt->execute([$user_id]); $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $is_new_user = false;
    if (!$user) {
        $is_new_user = true;
        $pdo->prepare("INSERT INTO users (chat_id, last_msg_time) VALUES (?, ?)")->execute([$user_id, time()]);
        $user = ['step' => null, 'last_msg_time' => 0, 'trial_used' => 0, 'wallet' => 0, 'is_reseller' => 0];
    }

    $step = $user['step'];
    $setStep = function($st) use ($pdo, $user_id) { $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute([$st, $user_id]); };
    
    $updateSetting = function($k, $v) use ($pdo) { 
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$k, $v, $v]); 
    };

    if ($settings['bot_status'] == '0' && !$is_admin) {
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🛠 ربات موقتاً در حال بروزرسانی است. لطفاً بعداً مراجعه کنید..."]);
        exit;
    }

    $join_status = checkForcedJoin($user_id, $telegram, $settings, $is_admin);

    $cancel_triggers = ['/cancel', '🔙 انصراف', '🔙 انصراف از خرید', '🔙 بازگشت به داشبورد', '🔙 بازگشت به ربات', '🔙 بازگشت به پنل اصلی', '🔙 تنظیمات ربات', '🔙 مدیریت فروشگاه', '🔙 مالی و کیف‌پول'];
    
    $main_menu_items = [
        $settings['buy_text'], $settings['services_text'], $settings['account_text'],
        $settings['trial_text'], $settings['referral_text'], $settings['support_text'],
        $settings['guide_text'], '💰 شارژ کیف پول', '🤝 درخواست نمایندگی', '/start'
    ];

    if (in_array($text, $cancel_triggers)) {
        $setStep(null);
        if ($is_admin && strpos($text, 'بازگشت') !== false && $text != '🔙 بازگشت به ربات') {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "👨‍💻 داشبورد مدیریت:", 'reply_markup' => getAdminMainKeyboard()]);
        } else {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "عملیات لغو شد و به منوی اصلی برگشتید.", 'reply_markup' => getUserKeyboard($settings)]);
        }
        exit;
    }
    
    if (in_array($text, $main_menu_items)) {
        $step = null;
        $setStep(null);
    }

    // ==========================================
    // 👑 مدیریت (Admin)
    // ==========================================
    if ($is_admin) {
        
        // --- بخش جدید ارسال پیام به کانال با دکمه شیشه‌ای ---
        if ($text == '⚙️ تنظیم دکمه کانال') {
            $setStep('set_ch_btn_text');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "متن دکمه شیشه‌ای را بفرستید:\n(مثلاً: 🛒 خرید سرویس اختصاصی)", 'reply_markup' => getCancelKeyboard()]);
            exit;
        }
        if ($step == 'set_ch_btn_text') {
            $updateSetting('channel_btn_text', $text);
            $setStep('set_ch_btn_link');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔗 حالا لینک دکمه شیشه‌ای را بفرستید:\n(مثلاً لینک رباتت: https://t.me/yourbot?start=...)"]);
            exit;
        }
        if ($step == 'set_ch_btn_link') {
            $updateSetting('channel_btn_link', $text);
            $setStep('admin_users');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ دکمه شیشه‌ای کانال با موفقیت تنظیم شد.", 'reply_markup' => getUsersSettingsKeyboard()]);
            exit;
        }

        if ($text == '📢 ارسال به کانال') {
            $setStep('ask_ch_id_for_post');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "📢 لطفاً آیدی کانال مقصد را با @ بفرستید:\n(نکته: ربات باید در آن کانال ادمین باشد)\n\nمثال: @MyChannel", 'reply_markup' => getCancelKeyboard()]);
            exit;
        }
        if ($step == 'ask_ch_id_for_post') {
            $target_ch = $text;
            $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["wait_ch_post|$target_ch", $user_id]);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کانال $target_ch انتخاب شد.\n\nحالا پیام خود (عکس، ویدیو، متن و ...) را بفرستید تا همراه با دکمه شیشه‌ای تنظیم شده، در کانال ارسال شود:", 'reply_markup' => getCancelKeyboard()]);
            exit;
        }
        if (strpos($step, 'wait_ch_post|') === 0) {
            $target_ch = explode('|', $step)[1];
            $msg_id_to_copy = $update['message']['message_id'];
            
            $btn_text = $settings['channel_btn_text'] ?? '🛒 خرید سرویس اختصاصی';
            $btn_link = $settings['channel_btn_link'] ?? 'https://t.me/';
            
            $keys = json_encode(['inline_keyboard' => [
                [['text' => $btn_text, 'url' => $btn_link]]
            ]]);
            
            $res = $telegram->request('copyMessage', [
                'chat_id' => $target_ch, 
                'from_chat_id' => $chat_id, 
                'message_id' => $msg_id_to_copy,
                'reply_markup' => $keys
            ]);
            
            if (isset($res['ok']) && $res['ok']) {
                $setStep('admin_users');
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام شما با موفقیت در کانال $target_ch ارسال شد.", 'reply_markup' => getUsersSettingsKeyboard()]);
            } else {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ خطا در ارسال! مطمئن شوید ربات در کانال $target_ch ادمین است و آیدی کانال صحیح است.\nدلیل خطا: " . ($res['description'] ?? 'نامشخص')]);
            }
            exit;
        }
        // --------------------------------------------------

        if ($text == '📢 پیام همگانی') {
            $setStep('admin_broadcast');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "📢 لطفاً پیام خود را برای ارسال به تمامی کاربران بفرستید:\n<i>(متن، عکس، ویدیو و... پشتیبانی می‌شود)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
            exit;
        }
        if ($step == 'admin_broadcast') {
            $users = $pdo->query("SELECT chat_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $count = 0;
            $msg_id_to_copy = $update['message']['message_id'];
            
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "⏳ در حال ارسال پیام به " . count($users) . " کاربر... لطفاً صبور باشید."]);
            
            foreach($users as $u) {
                $res = $telegram->request('copyMessage', ['chat_id' => $u, 'from_chat_id' => $chat_id, 'message_id' => $msg_id_to_copy]);
                if (isset($res['ok']) && $res['ok']) { $count++; }
            }
            
            $setStep('admin_users');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام شما با موفقیت به $count کاربر ارسال شد.", 'reply_markup' => getUsersSettingsKeyboard()]);
            exit;
        }

        if (strpos($text, '/delplan_') === 0) { 
            $pid = str_replace('/delplan_', '', $text); 
            $pdo->prepare("DELETE FROM plans WHERE id = ?")->execute([$pid]); 
            $setStep(null);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پلن با موفقیت حذف شد."]); 
            exit; 
        }
        
        if (strpos($text, '/delcode_') === 0) { 
            $hash = str_replace('/delcode_', '', $text); 
            $codes = $pdo->query("SELECT code FROM discounts")->fetchAll(PDO::FETCH_ASSOC);
            $deleted = false;
            foreach ($codes as $c) {
                if (md5($c['code']) === $hash) {
                    $pdo->prepare("DELETE FROM discounts WHERE code = ?")->execute([$c['code']]);
                    $deleted = true;
                    break;
                }
            }
            if ($deleted) {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کد تخفیف با موفقیت حذف شد."]); 
            } else {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ کد تخفیف یافت نشد یا قبلا حذف شده است."]); 
            }
            $setStep(null);
            exit; 
        }

        if ($text == '/admin') {
            $setStep('admin_main');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "👨‍💻 <b>داشبورد حرفه‌ای مدیریت:</b>\nلطفاً بخش مورد نظر را انتخاب کنید:", 'parse_mode' => 'HTML', 'reply_markup' => getAdminMainKeyboard()]);
            exit;
        }

        if ($text == '🛍 مدیریت فروشگاه') { $setStep('admin_store'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "📦 <b>بخش فروشگاه و پلن‌ها:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getStoreKeyboard()]); exit; }
        if ($text == '👥 کاربران و آمار') { $setStep('admin_users'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "👥 <b>بخش آمار و کاربران:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getUsersSettingsKeyboard()]); exit; }
        if ($text == '💳 مالی و کیف‌پول') { $setStep('admin_finance'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "💳 <b>بخش مالی و درگاه‌ها:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getFinanceKeyboard()]); exit; }
        if ($text == '⚙️ تنظیمات ربات') { $setStep('admin_settings'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "⚙️ <b>تنظیمات کلی ربات:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getBotSettingsKeyboard()]); exit; }
        if ($text == '🔌 اتصال سرور (سنایی)') { $setStep('admin_panel'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔌 <b>اطلاعات پنل سنایی:</b>", 'parse_mode' => 'HTML', 'reply_markup' => getPanelSettingsKeyboard()]); exit; }

        if ($text == '🔴 وضعیت ربات 🟢') {
            $new = $settings['bot_status'] == '1' ? '0' : '1'; $updateSetting('bot_status', $new);
            $msg = $new == '1' ? '🟢 <b>ربات روشن شد.</b>' : '🔴 <b>ربات موقتاً خاموش شد.</b>';
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']); exit;
        }

        if ($text == '🔍 مدیریت کاربر') {
            $setStep('admin_search_user');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "لطفاً آیدی عددی کاربر (Chat ID) را بفرستید:"]);
            exit;
        }
        if ($step == 'admin_search_user') {
            $en_text = convert2English($text);
            if (!preg_match('/^[0-9]+$/', $en_text)) {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً فقط عدد وارد کنید."]);
                exit;
            }
            $target_id = $en_text;
            $t_u = $pdo->prepare("SELECT * FROM users WHERE chat_id = ?"); $t_u->execute([$target_id]); $t_user = $t_u->fetch(PDO::FETCH_ASSOC);
            
            if (!$t_user) {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ کاربری با این آیدی در دیتابیس یافت نشد."]);
            } else {
                $ords = $pdo->prepare("SELECT * FROM orders WHERE chat_id = ? ORDER BY id DESC"); $ords->execute([$target_id]); $user_orders = $ords->fetchAll(PDO::FETCH_ASSOC);
                
                $msg = "👤 <b>اطلاعات کامل کاربر:</b> <code>$target_id</code>\n〰️〰️〰️〰️〰️〰️〰️\n";
                $msg .= "💰 موجودی کیف پول: <code>" . number_format($t_user['wallet']) . "</code> تومان\n";
                $msg .= "💎 وضعیت نمایندگی: " . ($t_user['is_reseller'] ? "✅ نماینده فعال" : "کاربر عادی") . "\n";
                $msg .= "🎁 دریافت تست: " . ($t_user['trial_used'] ? "بله" : "خیر") . "\n";
                $msg .= "🕒 تاریخ عضویت: <code>{$t_user['join_date']}</code>\n\n";
                
                if (empty($user_orders)) {
                    $msg .= "📦 کاربر هیچ سرویسی خریداری نکرده است.";
                } else {
                    $msg .= "📦 <b>لیست سرویس‌های کاربر:</b>\n";
                    foreach ($user_orders as $o) {
                        $msg .= "🔸 پلن: {$o['plan_name']} (شناسه: {$o['id']})\n📧 <code>{$o['email']}</code>\n";
                    }
                }
                $keys = [[['text' => ($t_user['is_reseller'] ? '❌ لغو نمایندگی' : '✅ ارتقا به نماینده'), 'callback_data' => ($t_user['is_reseller'] ? "revoke_reseller_{$target_id}" : "make_reseller_{$target_id}")]]];
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
            }
            $setStep('admin_users'); exit;
        }

        if ($text == '📊 آمار پیشرفته ربات') {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "⏳ محاسبه آمار..."]);
            $t_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $t_resellers = $pdo->query("SELECT COUNT(*) FROM users WHERE is_reseller = 1")->fetchColumn();
            $t_today = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(join_date) = CURDATE()")->fetchColumn();
            $t_orders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $t_trials = $pdo->query("SELECT COUNT(*) FROM users WHERE trial_used = 1")->fetchColumn();
            $t_wallets = $pdo->query("SELECT SUM(wallet) FROM users")->fetchColumn() ?? 0;
            $t_plans = $pdo->query("SELECT COUNT(*) FROM plans")->fetchColumn();

            $stats_msg = "📈 <b>گزارش جامع ربات:</b>\n\n👥 <b>کاربران:</b>\n🔸 کل کاربران: <code>$t_users</code>\n🔸 نمایندگان فعال: <code>$t_resellers</code>\n🔸 ورودی امروز: <code>$t_today</code>\n\n🛍 <b>سرویس‌ها:</b>\n🔸 کل سفارشات: <code>$t_orders</code>\n🔸 پلن‌های فعال: <code>$t_plans</code>\n🎁 تست‌های توزیع شده: <code>$t_trials</code>\n\n💰 <b>مالی:</b>\n🔸 مجموع کیف‌پول‌ها: <code>" . number_format($t_wallets) . "</code> تومان";
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $stats_msg, 'parse_mode' => 'HTML']);
            exit;
        }

        if ($text == '🌐 تنظیمات درگاه آنلاین (Tetra98)') {
            $setStep('admin_tetra');
            $keys = json_encode(['keyboard' => [[['text' => '🔑 تنظیم کلید API تترا98'], ['text' => '🟢 روشن/خاموش درگاه آنلاین']], [['text' => '🔙 مالی و کیف‌پول']]], 'resize_keyboard' => true]);
            $msg = "🌐 <b>تنظیمات درگاه تترا98:</b>\n\nوضعیت فعلی: " . ($settings['tetra_status'] == '1' ? '✅ فعال' : '❌ غیرفعال') . "\nکلید API: <code>" . $settings['tetra_api_key'] . "</code>";
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $keys]);
            exit;
        }
        if ($text == '🟢 روشن/خاموش درگاه آنلاین') {
            $new = $settings['tetra_status'] == '1' ? '0' : '1'; $updateSetting('tetra_status', $new);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ وضعیت درگاه تترا98 تغییر کرد."]); exit;
        }
        if ($text == '🔑 تنظیم کلید API تترا98') {
            $setStep('set_tetra_api');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "لطفا کلید API درگاه تترا98 را بفرستید:"]); exit;
        }
        if ($step == 'set_tetra_api') {
            $updateSetting('tetra_api_key', trim($text)); $setStep('admin_tetra');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کلید درگاه با موفقیت ثبت شد."]); exit;
        }

        if ($text == '📝 تنظیم متن درگاه ارزی') {
            $setStep('set_crypto_guide');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "لطفاً متن دلخواه برای راهنمای پرداخت ارزی را بفرستید:\n(مثلاً: لطفاً فاکتور را به تتر تبدیل کرده و به شبکه TRC20 واریز کنید.)"]); exit;
        }
        if ($step == 'set_crypto_guide') {
            $updateSetting('crypto_guide', $text); $setStep('admin_finance');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ متن راهنمای درگاه ارزی با موفقیت ثبت شد.", 'reply_markup' => getFinanceKeyboard()]); exit;
        }

        if (strpos($text, 'وضعیت کارت:') === 0) {
            $new = $settings['card_status'] == '1' ? '0' : '1'; $updateSetting('card_status', $new);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ وضعیت درگاه کارت به کارت تغییر کرد.", 'reply_markup' => getFinanceKeyboard()]); exit;
        }
        if (strpos($text, 'وضعیت ارزی:') === 0) {
            $new = $settings['crypto_status'] == '1' ? '0' : '1'; $updateSetting('crypto_status', $new);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ وضعیت درگاه ارزی تغییر کرد.", 'reply_markup' => getFinanceKeyboard()]); exit;
        }

        if ($text == '💳 تنظیم کارت به کارت') { $setStep('set_card_num'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "شماره کارت:"]); exit; }
        if ($step == 'set_card_num') { $updateSetting('card_number', convert2English($text)); $setStep('set_card_name'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "نام صاحب کارت:"]); exit; }
        if ($step == 'set_card_name') { $updateSetting('card_name', $text); $setStep('admin_finance'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کارت ثبت شد.", 'reply_markup' => getFinanceKeyboard()]); exit; }
        
        if ($text == '💲 تنظیم درگاه ارزی') { $setStep('set_crypto'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "آدرس ولت (TRX) را وارد کنید:"]); exit; }
        if ($step == 'set_crypto') { $updateSetting('crypto_address', $text); $setStep('admin_finance'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ولت ثبت شد.", 'reply_markup' => getFinanceKeyboard()]); exit; }

        if ($text == '💎 تنظیمات نمایندگی') {
            $setStep('admin_reseller');
            $r_keys = json_encode(['keyboard' => [[['text' => '💵 هزینه اشتراک نمایندگی'], ['text' => 'درصد تخفیف نمایندگی']], [['text' => '🔙 تنظیمات ربات']]], 'resize_keyboard' => true]);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "💎 <b>تنظیمات سیستم نمایندگی:</b>\n\nوضعیت فعلی:\nهزینه اشتراک: ".number_format($settings['reseller_fee'])." تومان\nدرصد تخفیف دائم: {$settings['reseller_discount']}%", 'parse_mode' => 'HTML', 'reply_markup' => $r_keys]);
            exit;
        }
        if ($text == '💵 هزینه اشتراک نمایندگی') { $setStep('set_r_fee'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "مبلغ مورد نظر برای اشتراک نمایندگی را به تومان وارد کنید:"]); exit; }
        if ($step == 'set_r_fee') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $updateSetting('reseller_fee', $num); $setStep('admin_reseller'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ثبت شد."]); exit; 
        }
        if ($text == 'درصد تخفیف نمایندگی') { $setStep('set_r_disc'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "درصد تخفیفی که روی پلن‌ها اعمال میشود را وارد کنید (فقط عدد، مثلا 20):"]); exit; }
        if ($step == 'set_r_disc') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $updateSetting('reseller_discount', $num); $setStep('admin_reseller'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ثبت شد."]); exit; 
        }

        if ($text == '🎁 پاداش زیرمجموعه‌گیری') {
            $setStep('set_ref_reward');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "مبلغ پاداش برای هر دعوت موفق را به تومان وارد کنید:\n<i>(جهت غیرفعال‌سازی، عدد 0 را بفرستید)</i>\n\nپاداش فعلی: " . number_format((int)($settings['referral_reward'] ?? 0)) . " تومان", 'parse_mode' => 'HTML']);
            exit;
        }
        if ($step == 'set_ref_reward') {
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $updateSetting('referral_reward', $num); $setStep('admin_users'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پاداش تنظیم شد."]); exit;
        }

        if ($text == '🎛 تنظیمات پلن دلخواه') {
            $setStep('admin_custom_plan');
            $keys = json_encode(['keyboard' => [[['text' => '💵 تعیین قیمت هر گیگ'], ['text' => '⏳ تعیین زمان پلن دلخواه']], [['text' => '🟢 روشن/خاموش پلن دلخواه']], [['text' => '🔙 مدیریت فروشگاه']]], 'resize_keyboard' => true]);
            $status = $settings['custom_plan_status'] == '1' ? '✅ روشن' : '❌ خاموش';
            $msg = "🎛 <b>تنظیمات پلن دلخواه:</b>\n\nوضعیت فعلی: $status\nقیمت هر گیگ: ".number_format($settings['custom_gb_price'])." تومان\nزمان پیش‌فرض پلن‌ها: {$settings['custom_days']} روز";
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $keys]);
            exit;
        }
        if ($text == '🟢 روشن/خاموش پلن دلخواه') {
            $new = $settings['custom_plan_status'] == '1' ? '0' : '1'; $updateSetting('custom_plan_status', $new);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ تغییر وضعیت انجام شد."]); exit;
        }
        if ($text == '💵 تعیین قیمت هر گیگ') { $setStep('set_custom_price'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "مبلغ هر 1 گیگابایت در پلن‌های دلخواه را به تومان وارد کنید:"]); exit; }
        if ($step == 'set_custom_price') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $updateSetting('custom_gb_price', $num); $setStep('admin_custom_plan'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ذخیره شد."]); exit; 
        }
        if ($text == '⏳ تعیین زمان پلن دلخواه') { $setStep('set_custom_days'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "تعداد روز اعتبار پیش‌فرض برای پلن‌های دلخواه را وارد کنید:\n<i>(برای زمان نامحدود عدد 0 را بفرستید)</i>", 'parse_mode' => 'HTML']); exit; }
        if ($step == 'set_custom_days') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $updateSetting('custom_days', $num); $setStep('admin_custom_plan'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ذخیره شد."]); exit; 
        }

        if ($text == '➕ افزودن پلن جدید') { $setStep('add_plan_name'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "نام پلن:"]); exit; }
        if ($step == 'add_plan_name') { $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["add_plan_gb|$text", $user_id]); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "حجم (گیگ):"]); exit; }
        if (strpos($step, 'add_plan_gb|') === 0) { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $name = explode('|', $step)[1]; $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["add_plan_days|$name|$num", $user_id]); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "زمان (روز):\n<i>(برای زمان نامحدود عدد 0 را بفرستید)</i>", 'parse_mode' => 'HTML']); exit; 
        }
        if (strpos($step, 'add_plan_days|') === 0) { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $parts = explode('|', $step); $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["add_plan_price|{$parts[1]}|{$parts[2]}|$num", $user_id]); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "قیمت (تومان):"]); exit; 
        }
        if (strpos($step, 'add_plan_price|') === 0) {
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $parts = explode('|', $step);
            $pdo->prepare("INSERT INTO plans (name, gb, days, price) VALUES (?, ?, ?, ?)")->execute([$parts[1], $parts[2], $parts[3], $num]); $setStep('admin_store');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پلن با موفقیت اضافه شد!", 'reply_markup' => getStoreKeyboard()]); exit;
        }
        if ($text == '📋 لیست پلن‌ها') {
            $plans = $pdo->query("SELECT * FROM plans")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($plans)) { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "خالی است."]); }
            else { $msg = "📋 <b>لیست پلن‌ها:</b>\n"; foreach ($plans as $p) { $msg .= "🔸 {$p['name']} | ".number_format($p['price'])." T\n🗑 حذف: /delplan_{$p['id']}\n\n"; } $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']); }
            exit;
        }

        if ($text == '🎟 کدهای تخفیف') {
            $codes = $pdo->query("SELECT * FROM discounts")->fetchAll(PDO::FETCH_ASSOC);
            $msg = empty($codes) ? "لیست کدهای تخفیف خالی است.\n\n" : "🎟 <b>لیست کدهای تخفیف:</b>\n\n"; 
            foreach ($codes as $c) { 
                $hash = md5($c['code']);
                $msg .= "🔸 کد: <code>{$c['code']}</code> | تخفیف: {$c['percent']}%\n🗑 حذف: /delcode_{$hash}\n\n"; 
            }
            $keys = json_encode(['inline_keyboard' => [[['text' => '➕ افزودن کد تخفیف جدید', 'callback_data' => 'add_new_discount']]]]);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $keys]); 
            exit; 
        }
        if ($step == 'add_disc_code') { 
            $clean_code = str_replace(' ', '', trim($text)); 
            if (empty($clean_code)) { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ نام وارد شده معتبر نیست. دوباره بفرستید:"]); exit; }
            $setStep("add_disc_percent|$clean_code"); 
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "درصد تخفیف (مثلا 20):"]); 
            exit; 
        }
        if (strpos($step, 'add_disc_percent|') === 0) { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $code = explode('|', $step)[1]; 
            $pdo->prepare("INSERT INTO discounts (code, percent) VALUES (?, ?)")->execute([$code, $num]); 
            $setStep('admin_store'); 
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کد تخفیف با موفقیت ثبت شد."]); 
            exit; 
        }

        if ($text == '🎁 تنظیمات تست رایگان') { $setStep('admin_trial'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "⚙️ تنظیمات سرویس تستی:", 'reply_markup' => getTrialKeyboard()]); exit; }
        if ($text == '⚙️ تنظیم حجم تست (MB)') { $setStep('set_trial_mb'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "حجم به مگابایت:"]); exit; }
        if ($step == 'set_trial_mb') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $updateSetting('trial_mb', $num); $setStep('admin_trial'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ثبت شد."]); exit; 
        }
        if ($text == '⏳ تنظیم زمان تست (دقیقه)') { $setStep('set_trial_mins'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "زمان به دقیقه:"]); exit; }
        if ($step == 'set_trial_mins') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $updateSetting('trial_mins', $num); $setStep('admin_trial'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ثبت شد."]); exit; 
        }
        if ($text == '🔄 پاکسازی سابقه تست‌ها') { $pdo->exec("UPDATE users SET trial_used = 0"); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ سوابق تست پاک شد."]); exit; }

        if ($text == '➕ شارژ کیف پول') { $setStep('charge_user_id'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "آیدی عددی کاربر:"]); exit; }
        if ($text == '➖ کسر از کیف پول') { $setStep('deduct_user_id'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "آیدی عددی کاربر:"]); exit; }
        if ($step == 'charge_user_id' || $step == 'deduct_user_id') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $type = ($step == 'charge_user_id') ? 'charge' : 'deduct'; $setStep("{$type}_am|$num"); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "مبلغ به تومان:"]); exit; 
        }
        if (strpos($step, 'charge_am|') === 0 || strpos($step, 'deduct_am|') === 0) {
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $parts = explode('|', $step); $type = strpos($step, 'charge_am|') === 0 ? '+' : '-';
            $uid = $parts[1];
            $pdo->prepare("UPDATE users SET wallet = wallet $type ? WHERE chat_id = ?")->execute([$num, $uid]);
            $setStep('admin_finance'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ انجام شد.", 'reply_markup' => getFinanceKeyboard()]); exit;
        }
        if ($text == '🎁 شارژ همگانی') { $setStep('gift_all'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "مبلغ هدیه به تومان:"]); exit; }
        if ($step == 'gift_all') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $pdo->prepare("UPDATE users SET wallet = wallet + ?")->execute([$num]); $setStep('admin_finance'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ به همه کاربران شارژ هدیه داده شد."]); exit; 
        }
        if ($text == '🔥 کسر همگانی') { $setStep('deduct_all'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "مبلغ کسر به تومان:"]); exit; }
        if ($step == 'deduct_all') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $pdo->prepare("UPDATE users SET wallet = wallet - ?")->execute([$num]); $setStep('admin_finance'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ از همه کاربران کسر شد."]); exit; 
        }

        if ($text == '⚙️ روشن/خاموش دکمه‌ها') { $setStep('admin_btn_toggles'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "دکمه‌ها:", 'reply_markup' => getButtonsToggleKeyboard()]); exit; }
        if ($text == '🎛 دکمه‌های درون سرویس') { $setStep('admin_myserv_btn'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "روشن/خاموش دکمه‌های داخلی:", 'reply_markup' => getMyServicesSettingsKeyboard()]); exit; }
        if ($text == '🔙 تنظیمات ربات') { $setStep('admin_settings'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "تنظیمات:", 'reply_markup' => getBotSettingsKeyboard()]); exit; }
        
        $btn_map = [
            '🛒 دکمه خرید'=>'buy', '🎁 دکمه تست'=>'trial', '👤 دکمه حساب'=>'account', '👥 دکمه رفرال'=>'referral', '📦 دکمه سرویس'=>'services', '👨‍💻 دکمه پشتیبانی'=>'support', '📚 دکمه آموزش'=>'guide',
            '➕ دکمه حجم اضافه'=>'extra_gb', '👥 دکمه کاربر اضافه'=>'extra_ip', '🔄 دکمه تمدید سرویس'=>'renew'
        ];
        if (array_key_exists($text, $btn_map)) {
            $k = $btn_map[$text]; $new = $settings[$k.'_status'] == '1' ? '0' : '1'; $updateSetting($k.'_status', $new);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "تغییر وضعیت انجام شد."]); exit;
        }

        if ($text == '👨‍💻 تنظیم آیدی پشتیبانی') { $setStep('set_support'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "آیدی پشتیبانی را با @ بفرستید:"]); exit; }
        if ($step == 'set_support') { $updateSetting('support_id', $text); $setStep('admin_settings'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ثبت شد.", 'reply_markup' => getBotSettingsKeyboard()]); exit; }
        if ($text == '📢 تنظیم چنل گزارشات') { $setStep('set_admin_channel'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "آیدی کانال را بفرستید:"]); exit; }
        if ($step == 'set_admin_channel') { $updateSetting('admin_channel', $text); $setStep('admin_settings'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ ثبت شد.", 'reply_markup' => getBotSettingsKeyboard()]); exit; }

        if ($text == '💬 تنظیم پیام خوش‌آمدگویی (/start)') {
            $setStep('set_text_start');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "💬 متن جدید برای پیام خوش‌آمدگویی را بفرستید:\n<i>(از ایموجی‌ها و اینتر برای خط بعدی می‌توانید استفاده کنید)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
            exit;
        }
        if ($step == 'set_text_start') {
            $updateSetting('text_start', $text);
            $setStep('admin_settings');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام خوش‌آمدگویی با موفقیت تغییر کرد.", 'reply_markup' => getBotSettingsKeyboard()]);
            exit;
        }

        if ($text == '📝 تنظیم متن آموزش') {
            $keys = json_encode(['inline_keyboard' => [
                [['text' => '📱 Android', 'callback_data' => 'edit_guide_and'], ['text' => '🍏 iOS', 'callback_data' => 'edit_guide_ios']],
                [['text' => '💻 Windows', 'callback_data' => 'edit_guide_win'], ['text' => '🐧 Linux', 'callback_data' => 'edit_guide_lin']]
            ]]);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "بخش مورد نظر برای تنظیم آموزش را انتخاب کنید:", 'reply_markup' => $keys]);
            exit;
        }
        if (strpos($step, 'set_guide_') === 0) {
            $os = str_replace('set_guide_', '', $step);
            $updateSetting("guide_$os", $text);
            $setStep('admin_settings');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ آموزش این بخش با موفقیت ذخیره شد.", 'reply_markup' => getBotSettingsKeyboard()]);
            exit;
        }

        if ($text == '👥 مدیریت ادمین‌ها' && $user_id == $config['admin_id']) { $setStep('admin_mngr'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "ارسال آیدی عددی برای افزودن مدیر جدید، یا ارسال /deladmin_ID برای حذف:"]); exit; }
        if ($step == 'admin_mngr') { 
            $num = preg_replace('/\D/', '', convert2English($text)); if($num === '') { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ فقط عدد وارد کنید!"]); exit; }
            $pdo->prepare("INSERT IGNORE INTO admins (chat_id) VALUES (?)")->execute([$num]); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ مدیر افزوده شد."]); exit; 
        }
        if (strpos($text, '/deladmin_') === 0 && $user_id == $config['admin_id']) { $aid = str_replace('/deladmin_', '', $text); $pdo->prepare("DELETE FROM admins WHERE chat_id = ?")->execute([$aid]); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ مدیر حذف شد."]); exit; }
        
        if ($text == '📢 مدیریت جوین اجباری') { $setStep('admin_fjoin'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "تنظیمات کانال‌های قفل:", 'reply_markup' => getForceJoinKeyboard()]); exit; }
        if ($text == '➕ افزودن کانال قفل') { $setStep('add_fj_id'); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "آیدی کانال را وارد کنید:"]); exit; }
        if ($step == 'add_fj_id') { $setStep("add_fj_name|$text"); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "نام نمایشی کانال را وارد کنید:"]); exit; }
        if (strpos($step, 'add_fj_name|') === 0) { $cid = explode('|', $step)[1]; $setStep("add_fj_link|$cid|$text"); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "لینک عضویت در کانال را بفرستید:"]); exit; }
        if (strpos($step, 'add_fj_link|') === 0) {
            $parts = explode('|', $step); $fj = json_decode($settings['force_join'], true) ?? [];
            $fj[] = ['id' => $parts[1], 'name' => $parts[2], 'link' => $text];
            $updateSetting('force_join', json_encode($fj, JSON_UNESCAPED_UNICODE)); $setStep('admin_users');
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کانال اضافه شد.", 'reply_markup' => getUsersSettingsKeyboard()]); exit;
        }
        if ($text == '📋 لیست کانال‌های قفل') {
            $fj = json_decode($settings['force_join'], true) ?? [];
            if (empty($fj)) { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "لیست خالی است."]); }
            else { $msg = "📋 لیست:\n"; foreach ($fj as $index => $ch) { $msg .= "▪️ {$ch['name']} ({$ch['id']})\n🗑 حذف: /deljoin_$index\n\n"; } $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg]); } exit;
        }
        if (strpos($text, '/deljoin_') === 0) { $idx = str_replace('/deljoin_', '', $text); $fj = json_decode($settings['force_join'], true) ?? []; if(isset($fj[$idx])){ unset($fj[$idx]); $updateSetting('force_join', json_encode(array_values($fj), JSON_UNESCAPED_UNICODE)); } $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ حذف شد."]); exit; }

        $panel_map = ['🔗 تغییر آدرس پنل' => 'set_panel_url', '👤 تغییر یوزرنیم' => 'set_panel_user', '🔑 تغییر پسورد' => 'set_panel_pass', '🆔 تغییر آیدی کانفیگ' => 'set_inbound_id', '🌐 تنظیم دامنه لینک ساب' => 'set_sub_domain'];
        if (array_key_exists($text, $panel_map)) { $setStep($panel_map[$text]); $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "مقدار جدید را بفرستید:"]); exit; }
        if ($step && strpos($step, 'set_panel_') === 0 || $step == 'set_inbound_id' || $step == 'set_sub_domain') { 
            $key = str_replace('set_', '', $step); 
            $updateSetting($key, trim($text)); 
            $setStep('admin_panel'); 
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ تنظیمات سرور بروزرسانی شد.", 'reply_markup' => getPanelSettingsKeyboard()]); exit; 
        }
    }

    // ==========================================
    // 🤖 دستورات کاربر عادی
    // ==========================================

    if ($step && strpos($step, 'ask_discount|') === 0) {
        $parts = explode('|', $step); $pid = $parts[1];
        $disc = $pdo->prepare("SELECT percent FROM discounts WHERE code = ?"); $disc->execute([$text]); $disc = $disc->fetchColumn();
        if ($disc) {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ کد تخفیف $disc درصدی اعمال شد!"]);
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "لطفاً روی دکمه زیر کلیک کنید تا فاکتور جدید صادر شود:", 'reply_markup' => json_encode(['inline_keyboard' => [[['text'=>'مشاهده فاکتور با تخفیف','callback_data'=>"pay|{$pid}|{$text}|invoice"]]]])]);
        } else {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ کد تخفیف نامعتبر است."]);
        }
        $setStep(null); exit;
    }

    if ($step == 'ask_custom_gb') {
        $en_text = convert2English($text);
        if (!preg_match('/^[0-9]+$/', $en_text)) { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً فقط عدد وارد کنید!"]); exit; }
        $gb = (int)$en_text;
        if ($gb < 1) { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ حداقل حجم 1 گیگابایت است. مجددا عدد وارد کنید:"]); exit; }
        $pid = "custom_" . $gb;
        $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["ask_name|{$pid}|none", $user_id]);
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔤 لطفاً یک نام انگلیسی برای کانفیگ خود وارد کنید:\n<i>(این نام در برنامه اتصال شما نمایش داده می‌شود. بدون فاصله بنویسید)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
        exit;
    }
    
    if ($step == 'ask_custom_price') {
        $en_text = convert2English($text);
        if (!preg_match('/^[0-9]+$/', $en_text)) { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً فقط عدد وارد کنید!"]); exit; }
        $toman = (int)$en_text;
        $price_per_gb = (int)($settings['custom_gb_price'] ?? 3000);
        $gb = floor($toman / $price_per_gb);
        if ($gb < 1) { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ با این مبلغ حتی 1 گیگ هم نمی‌توان خرید!\nمجددا مبلغ بیشتری وارد کنید:"]); exit; }
        $pid = "custom_" . $gb;
        $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute(["ask_name|{$pid}|none", $user_id]);
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "🔤 لطفاً یک نام انگلیسی برای کانفیگ خود وارد کنید:\n<i>(این نام در برنامه اتصال شما نمایش داده می‌شود. بدون فاصله بنویسید)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
        exit;
    }

    if ($step && strpos($step, 'ask_name|') === 0) {
        $parts = explode('|', $step);
        $pid = $parts[1]; 
        $code = $parts[2] ?? 'none';
        
        $config_name = preg_replace('/[^a-zA-Z0-9_]/', '', convert2English($text));
        if (empty($config_name)) { 
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ نام نامعتبر است. لطفاً فقط از حروف انگلیسی و اعداد استفاده کنید:"]); 
            exit; 
        }
        
        $pdo->prepare("UPDATE users SET temp_name = ?, step = NULL WHERE chat_id = ?")->execute([$config_name, $user_id]);
        
        $plan = getFinalPrice($pdo, $pid, $code, $user_id, $settings);
        $wallet = $user['wallet'];
        
        $msg = "🧾 <b>پیش‌فاکتور:</b>\n🔸 {$plan['name']}\n👤 نام کانفیگ: <code>$config_name</code>\n💵 مبلغ قابل پرداخت: <code>".number_format($plan['final_price'])."</code> تومان\n\nلطفاً روش پرداخت را انتخاب کنید:";
        $keys = [];
        if ($wallet >= $plan['final_price']) $keys[] = [['text' => '💰 پرداخت آنی از کیف پول', 'callback_data' => "pay|{$pid}|{$code}|wallet"]];
        if ($settings['tetra_status'] == '1') $keys[] = [['text' => '🌐 پرداخت آنلاین (تترا98)', 'callback_data' => "pay|{$pid}|{$code}|tetra"]];
        if ($settings['card_status'] == '1') $keys[] = [['text' => '💳 کارت به کارت', 'callback_data' => "pay|{$pid}|{$code}|card"]];
        if ($settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی', 'callback_data' => "pay|{$pid}|{$code}|crypto"]];
        if ($code == 'none') $keys[] = [['text' => '🎟 استفاده از کد تخفیف', 'callback_data' => "apply_disc|{$pid}"]];
        
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
        exit;
    }

    // ==========================================
    // 🛡 شبکه ایمنی و حل مشکل گیر کردن دکمه‌ها
    // ==========================================
    if ($step == 'user_charge_wallet') {
        $en_text = convert2English($text);
        if (!preg_match('/^[0-9]+$/', $en_text)) { $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً فقط عدد وارد کنید!"]); exit; }
        $amount = (int)$en_text;
        if ($amount < 1000) {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ حداقل مبلغ شارژ 1000 تومان است."]);
        } else {
            $keys = [];
            if ($settings['tetra_status'] == '1') $keys[] = [['text' => '🌐 پرداخت آنلاین (تترا98)', 'callback_data' => "charge_method_{$amount}_tetra"]];
            if ($settings['card_status'] == '1') $keys[] = [['text' => '💳 کارت به کارت', 'callback_data' => "charge_method_{$amount}_card"]];
            if ($settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی (تتر)', 'callback_data' => "charge_method_{$amount}_crypto"]];
            
            if (empty($keys)) {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ در حال حاضر درگاه پرداختی فعال نیست.", 'reply_markup' => getUserKeyboard($settings)]);
            } else {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "💳 مبلغ: " . number_format($amount) . " تومان\nلطفاً روش پرداخت را انتخاب کنید:", 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
            }
            $setStep(null); 
        }
        exit;
    }

    // پردازش دریافت پیام فیش یا متن هش تراکنش ارزی
    if ($step && (strpos($step, 'wait_receipt|') === 0 || strpos($step, 'wait_charge_receipt_') === 0 || $step == 'wait_reseller_receipt')) {
        $photo_id = isset($update['message']['photo']) ? end($update['message']['photo'])['file_id'] : null;
        
        $text_hash = null;
        if (isset($update['message']['text']) && $update['message']['text'] != '🔙 انصراف') {
            $text_hash = $update['message']['text'];
        } elseif (isset($update['message']['caption'])) {
            $text_hash = $update['message']['caption'];
        }

        if ($photo_id || $text_hash) {
            $admin_channel = !empty($settings['admin_channel']) ? $settings['admin_channel'] : $config['admin_id'];
            $username_text = !empty($update['message']['from']['username']) ? '@' . $update['message']['from']['username'] : 'ندارد';
            
            if ($step == 'wait_reseller_receipt') {
                $fee = $settings['reseller_fee'];
                $caption = "🧾 <b>رسید خرید اشتراک نمایندگی:</b>\n👤 آیدی: <code>$user_id</code> ($username_text)\n💵 مبلغ: ".number_format($fee)." تومان\n" . ($text_hash ? "\n🔗 <b>توضیحات / هش تراکنش:</b>\n<code>$text_hash</code>" : "");
                $keys = json_encode(['inline_keyboard' => [[['text' => '✅ تایید نمایندگی', 'callback_data' => "approve_reseller_{$user_id}"]], [['text' => '❌ رد کردن', 'callback_data' => "reject_reseller_{$user_id}"]]]]);
            }
            elseif (strpos($step, 'wait_charge_receipt_') === 0) {
                $amount = str_replace('wait_charge_receipt_', '', $step);
                $caption = "🧾 <b>رسید شارژ کیف پول:</b>\n👤 آیدی: <code>$user_id</code> ($username_text)\n💵 مبلغ: ".number_format($amount)." تومان\n" . ($text_hash ? "\n🔗 <b>توضیحات / هش تراکنش:</b>\n<code>$text_hash</code>" : "");
                $keys = json_encode(['inline_keyboard' => [[['text' => '✅ تایید شارژ', 'callback_data' => "approve_charge_{$user_id}_{$amount}"]], [['text' => '❌ رد کردن', 'callback_data' => "reject_charge_{$user_id}_{$amount}"]]]]);
            } 
            else {
                $parts = explode('|', str_replace('wait_receipt|', '', $step)); $pid = $parts[0]; $code = $parts[1] ?? 'none'; $method = $parts[2] ?? 'card';
                $plan = getFinalPrice($pdo, $pid, $code, $user_id, $settings);
                if (!$plan) exit;
                
                $discount_text = "";
                if ($code !== 'none') {
                    $disc_percent = $pdo->prepare("SELECT percent FROM discounts WHERE code = ?");
                    $disc_percent->execute([$code]);
                    $percent = $disc_percent->fetchColumn();
                    if ($percent) {
                        $discount_text = "\n🎟 <b>کد تخفیف استفاده شده:</b> <code>$code</code> ($percent%)";
                    }
                }

                $method_fa = ($method == 'crypto') ? 'ارزی (تتر)' : 'کارت به کارت';
                $caption = "🧾 <b>رسید واریز ($method_fa):</b>\n👤 آیدی: <code>$user_id</code> ($username_text)\n🛍 پلن: <b>{$plan['name']}</b>$discount_text\n💵 ".number_format($plan['final_price'])." تومان\n" . ($text_hash ? "\n🔗 <b>توضیحات / هش تراکنش:</b>\n<code>$text_hash</code>" : "");
                $keys = json_encode(['inline_keyboard' => [[['text' => '✅ تایید و تحویل اتوماتیک', 'callback_data' => "approve_receipt|{$user_id}|{$pid}|{$code}"]], [['text' => '❌ رد کردن', 'callback_data' => "reject_receipt|{$user_id}|{$pid}|{$code}"]]]]);
            }

            if ($photo_id) {
                $res = $telegram->request('sendPhoto', ['chat_id' => $admin_channel, 'photo' => $photo_id, 'caption' => $caption, 'parse_mode' => 'HTML', 'reply_markup' => $keys]);
            } else {
                $res = $telegram->request('sendMessage', ['chat_id' => $admin_channel, 'text' => $caption, 'parse_mode' => 'HTML', 'reply_markup' => $keys]);
            }

            if (isset($res['ok']) && $res['ok']) {
                $setStep(null);
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ رسید شما با موفقیت ارسال شد. پس از بررسی مدیریت، عملیات به صورت خودکار تایید خواهد شد.", 'reply_markup' => getUserKeyboard($settings)]);
            } else {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ متاسفانه در ارسال فیش به مدیریت خطایی رخ داد. لطفاً مجدداً تلاش کنید."]);
            }
        } else {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ لطفاً فقط **تصویر فیش واریزی** یا **متن کد Hash/TxID** را ارسال کنید."]);
        }
        exit;
    }

    if (strpos($text, '/start') === 0) {
        $setStep(null);
        
        $parts = explode(' ', $text);
        if ($is_new_user && count($parts) == 2 && is_numeric($parts[1]) && $parts[1] != $user_id) {
            $inviter = $parts[1];
            $reward = (int)($settings['referral_reward'] ?? 0);
            $pdo->prepare("UPDATE users SET invited_by = ? WHERE chat_id = ?")->execute([$inviter, $user_id]);
            
            if ($reward > 0) {
                $pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$reward, $inviter]);
                $telegram->request('sendMessage', ['chat_id' => $inviter, 'text' => "🎉 <b>تبریک!</b>\nیک نفر با لینک شما وارد ربات شد و <code>" . number_format($reward) . "</code> تومان به کیف پول شما اضافه شد.", 'parse_mode' => 'HTML']);
            } else {
                $telegram->request('sendMessage', ['chat_id' => $inviter, 'text' => "🎉 <b>تبریک!</b>\nیک نفر با لینک اختصاصی شما وارد ربات شد.", 'parse_mode' => 'HTML']);
            }
        }

        if ($join_status !== true) {
            $join_keys = []; foreach ($join_status as $ch) { $join_keys[] = [['text' => "📢 عضویت در {$ch['name']}", 'url' => $ch['link']]]; }
            $join_keys[] = [['text' => '✅ عضو شدم', 'callback_data' => 'check_join']];
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "⛔️ <b>ابتدا در کانال‌های زیر عضو شوید:</b>", 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $join_keys])]);
            exit;
        }
        
        $start_msg = $settings['text_start'] ?? "👋 سلام! به فروشگاه ما خوش آمدید.";
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $start_msg, 'parse_mode' => 'HTML', 'reply_markup' => getUserKeyboard($settings)]);
    } 
    elseif ($text == $settings['buy_text'] && $settings['buy_status'] == '1') {
        $plans = $pdo->query("SELECT * FROM plans")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($plans) && $settings['custom_plan_status'] == '0') {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "خالی است."]);
        } else {
            $keys = []; 
            foreach ($plans as $p) { 
                $final_p = getFinalPrice($pdo, $p['id'], 'none', $user_id, $settings);
                $keys[] = [['text' => "🛒 {$p['name']} | ".number_format($final_p['final_price'])." T", 'callback_data' => "pay|{$p['id']}|none|name"]]; 
            }
            if ($settings['custom_plan_status'] == '1') {
                $keys[] = [['text' => '🎛 ساخت پلن دلخواه (حجم/مبلغ)', 'callback_data' => "custom_plan_start"]];
            }
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => ($user['is_reseller'] == 1 ? "💎 <b>تعرفه‌های ویژه نمایندگان:</b>" : "پلن مورد نظر را انتخاب کنید:"), 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
        }
    }
    elseif ($text == $settings['services_text'] && $settings['services_status'] == '1') {
        $orders = $pdo->query("SELECT * FROM orders WHERE chat_id = $user_id ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($orders)) $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "سرویسی ندارید."]);
        else {
            $keys = []; foreach ($orders as $o) { $keys[] = [['text' => "🚀 {$o['plan_name']}", 'callback_data' => "myserv_info_{$o['id']}"]]; }
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "📦 سرویس‌ها:", 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
        }
    }
    elseif ($text == $settings['account_text'] && $settings['account_status'] == '1') {
        $status_text = $user['is_reseller'] == 1 ? "💎 <b>نماینده فعال</b>" : "کاربر عادی";
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "👤 آیدی: <code>$user_id</code>\n💰 کیف پول: <code>".number_format($user['wallet'])."</code> T\n🏅 سطح کاربری: $status_text", 'parse_mode' => 'HTML']);
    }
    elseif ($text == '💰 شارژ کیف پول') {
        $setStep('user_charge_wallet');
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "💰 <b>شارژ کیف پول</b>\n\nلطفاً مبلغ مورد نظر خود را به <b>تومان</b> وارد کنید:\n(مثلاً: 50000)", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
    }
    elseif ($text == '🤝 درخواست نمایندگی') {
        if ($user['is_reseller'] == 1) {
             $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ شما در حال حاضر نماینده ما هستید و تخفیف‌ها به صورت خودکار روی تمامی پلن‌ها برای شما اعمال می‌شود!"]);
        } else {
             $fee = $settings['reseller_fee'];
             $disc = $settings['reseller_discount'];
             $msg = "🤝 <b>خرید اشتراک نمایندگی</b>\n\nبا پرداخت <code>".number_format($fee)."</code> تومان، حساب شما به نماینده ارتقا می‌یابد و از این پس <b>$disc% تخفیف دائمی</b> روی تمامی خریدها برای شما محاسبه می‌شود!\n\nجهت پرداخت، یکی از روش‌های زیر را انتخاب کنید:";
             
             $keys = [];
             $keys[] = [['text' => '💰 پرداخت از کیف پول', 'callback_data' => "reseller_pay_wallet"]];
             if ($settings['tetra_status'] == '1') $keys[] = [['text' => '🌐 پرداخت آنلاین (تترا98)', 'callback_data' => "reseller_pay_tetra"]];
             if ($settings['card_status'] == '1') $keys[] = [['text' => '💳 کارت به کارت', 'callback_data' => "reseller_pay_card"]];
             if ($settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی', 'callback_data' => "reseller_pay_crypto"]];
             
             $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
        }
    }
    elseif ($text == $settings['referral_text'] && $settings['referral_status'] == '1') {
        $bot_info = $telegram->request('getMe');
        $bot_username = $bot_info['result']['username'] ?? '';
        $ref_link = "https://t.me/{$bot_username}?start={$user_id}";
        $ref_count = $pdo->query("SELECT COUNT(*) FROM users WHERE invited_by = {$user_id}")->fetchColumn();
        
        $msg = "👥 <b>بخش زیرمجموعه‌گیری</b>\n\n🔗 لینک اختصاصی دعوت شما:\n<code>$ref_link</code>\n\n👤 تعداد دعوت‌های موفق شما: <b>$ref_count نفر</b>\n\n<i>(با دعوت از دوستان خود می‌توانید موجودی کیف پولتان را افزایش دهید)</i>";
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']);
    }
    elseif ($text == $settings['trial_text'] && $settings['trial_status'] == '1') {
        if ($user['trial_used'] == 1) $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "⛔️ قبلاً دریافت کرده‌اید."]);
        else {
            $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "⏳ در حال ساخت... لطفا چند ثانیه صبر کنید."]);
            $uuid = Utils::generateUUIDv4(); $email = "trial_" . $user_id . "_" . rand(100,999);
            $b = $settings['trial_mb'] * 1048576; $t = (time() + ($settings['trial_mins'] * 60)) * 1000;
            $result = $panel->addClient($settings['inbound_id'], $email, $uuid, $b, $t, 0, $settings['sub_domain'] ?? ''); 
            if ($result['status']) {
                $pdo->prepare("UPDATE users SET trial_used = 1 WHERE chat_id = ?")->execute([$user_id]);
                $pdo->prepare("INSERT INTO orders (chat_id, plan_name, uuid, email, link) VALUES (?, ?, ?, ?, ?)")->execute([$user_id, "تست رایگان", $uuid, $email, $result['link']]);
                
                $msg_to_send = "🎉 <b>تست شما آماده است!</b>\n\n" . $result['link'];
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg_to_send, 'parse_mode' => 'HTML']);
                sendReport($telegram, $settings, "🎁 <b>دریافت تست رایگان:</b>\n👤 <code>$user_id</code>");
            } else {
                $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ خطای سرور: ارتباط با پنل سنایی قطع است!\nدلیل خطا: " . $result['msg']]);
            }
        }
    }
    elseif ($text == $settings['support_text'] && $settings['support_status'] == '1') {
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "👨‍💻 جهت ارتباط با پشتیبانی به آیدی زیر پیام دهید:\n{$settings['support_id']}"]);
    }
    elseif ($text == $settings['guide_text'] && $settings['guide_status'] == '1') {
        $keys = json_encode(['inline_keyboard' => [
            [['text' => '📱 Android', 'callback_data' => 'dl_and'], ['text' => '🍏 iOS', 'callback_data' => 'dl_ios']],
            [['text' => '💻 Windows', 'callback_data' => 'dl_win'], ['text' => '🐧 Linux', 'callback_data' => 'dl_lin']]
        ]]);
        $telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "📥 لطفاً سیستم‌عامل خود را انتخاب کنید:", 'reply_markup' => $keys]);
    }
}