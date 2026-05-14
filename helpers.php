<?php
/**
 * توابع کمکی مشترک بین تمام Handler ها
 * توجه: این فایل توسط Router و Handler ها require می‌شود
 */

// ========================
// توابع تبدیل و فرمت‌بندی
// ========================

function formatCard(string $title, array $rows, string $footer = ''): string {
    $bar = str_repeat('─', mb_strlen($title, 'UTF-8') + 4);
    $lines = ["┌─ <b>$title</b> ─┐"];
    foreach ($rows as $row) {
        $lines[] = "│ $row";
    }
    $lines[] = "└$bar┘";
    if ($footer !== '') $lines[] = "\n<i>$footer</i>";
    return implode("\n", $lines);
}

function convert2English(string $str): string {
    return Utils::toEnglishDigits($str);
}

function formatBytes(int $bytes, int $precision = 2): string {
    return Utils::formatBytes($bytes, $precision);
}

function onlyNumber(string $input): string {
    return preg_replace('/\D/', '', Utils::toEnglishDigits($input));
}

// ========================
// درگاه پرداخت Tetra98
// ========================

function tetraRequest(string $endpoint, array $data, string $proxy, string $proxy_auth): array {
    $ch = curl_init("https://tetra98.com/api/" . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);

    if (!empty($proxy)) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        if (!empty($proxy_auth)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_auth);
        }
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response  = curl_exec($ch);
    $error     = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['status' => false, 'error_details' => "خطای cURL: " . $error];
    }

    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['status' => false, 'error_details' => "پاسخ نامعتبر سرور (HTTP $http_code)"];
    }

    return $decoded;
}

// ========================
// تنظیمات دیتابیس
// ========================

function getSettings(): array {
    global $pdo;
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
    $s = [];
    foreach ($rows as $row) {
        $s[$row['setting_key']] = $row['setting_value'];
    }
    return $s;
}

function updateSetting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare(
        "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?"
    )->execute([$key, $value, $value]);
}

function setStep(PDO $pdo, int $user_id, ?string $step): void {
    $pdo->prepare("UPDATE users SET step = ? WHERE chat_id = ?")->execute([$step, $user_id]);
}

// ========================
// بررسی عضویت اجباری
// ========================

function checkForcedJoin(int $user_id, Telegram $telegram, array $settings, bool $is_admin) {
    if ($is_admin) return true;
    $channels = json_decode($settings['force_join'] ?? '[]', true) ?? [];
    if (empty($channels)) return true;

    $not_joined = [];
    foreach ($channels as $ch) {
        $res = $telegram->request('getChatMember', ['chat_id' => $ch['id'], 'user_id' => $user_id]);
        if (!isset($res['ok']) || !$res['ok'] || in_array($res['result']['status'] ?? '', ['left', 'kicked'])) {
            $not_joined[] = $ch;
        }
    }

    return empty($not_joined) ? true : $not_joined;
}

// ========================
// گزارش به کانال ادمین
// ========================

function sendReport(Telegram $telegram, array $settings, string $text): void {
    if (!empty($settings['admin_channel'])) {
        $telegram->request('sendMessage', [
            'chat_id'    => $settings['admin_channel'],
            'text'       => $text,
            'parse_mode' => 'HTML',
        ]);
    }
}

// ========================
// محاسبه قیمت نهایی
// ========================

function getFinalPrice(PDO $pdo, string $pid, string $code, int $user_id, array $settings): ?array {
    if (strpos($pid, 'custom_') === 0) {
        $gb    = (int)str_replace('custom_', '', $pid);
        $days  = (int)($settings['custom_days'] ?? 30);
        $price = $gb * (int)($settings['custom_gb_price'] ?? 3000);
        $days_text = $days > 0 ? "$days روزه" : "زمان نامحدود";
        $plan = ['id' => $pid, 'name' => "🎛 پلن دلخواه $gb گیگ ($days_text)", 'gb' => $gb, 'days' => $days, 'price' => $price];
    } else {
        $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
        $stmt->execute([(int)$pid]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) return null;
    }

    $price = (int)$plan['price'];
    $plan['original_price'] = $price;

    // تخفیف نمایندگی
    if ($user_id > 0 && !empty($settings)) {
        $stmt = $pdo->prepare("SELECT is_reseller FROM users WHERE chat_id = ?");
        $stmt->execute([$user_id]);
        if ($stmt->fetchColumn()) {
            $disc  = (int)($settings['reseller_discount'] ?? 0);
            $price = (int)($price - ($price * $disc / 100));
        }
    }

    // تخفیف کمپین سراسری
    $campaign_pct = 0;
    if (!empty($settings['campaign_status']) && $settings['campaign_status'] == '1') {
        $campaign_pct = max(0, min(99, (int)($settings['campaign_pct'] ?? 0)));
        if ($campaign_pct > 0) {
            $price = (int)round($price * (100 - $campaign_pct) / 100);
        }
    }
    $plan['campaign_pct'] = $campaign_pct;

    // کد تخفیف
    if ($code !== 'none') {
        $stmt = $pdo->prepare("SELECT percent FROM discounts WHERE code = ?");
        $stmt->execute([$code]);
        $disc = $stmt->fetchColumn();
        if ($disc) {
            $price = (int)($price - ($price * (int)$disc / 100));
        }
    }

    $plan['final_price'] = max(0, $price);
    return $plan;
}

// ========================
// کیبوردهای ربات
// ========================

function getUserKeyboard(array $s): string {
    $kb = [];
    $r1 = [];
    $r2 = [];
    $r3 = [];

    if ($s['buy_status'] == '1')      $r1[] = ['text' => $s['buy_text']];
    if ($s['services_status'] == '1') $r1[] = ['text' => $s['services_text']];
    if ($s['account_status'] == '1')  $r2[] = ['text' => $s['account_text']];
    if ($s['support_status'] == '1')  $r2[] = ['text' => $s['support_text']];
    // راهنما و تست ترکیب می‌شوند
    if ($s['guide_status'] == '1' || $s['trial_status'] == '1') {
        $r3[] = ['text' => '📖 راهنما و تست'];
    }

    if (!empty($r1)) $kb[] = $r1;
    if (!empty($r2)) $kb[] = $r2;
    if (!empty($r3)) $kb[] = $r3;

    return json_encode(['keyboard' => $kb, 'resize_keyboard' => true]);
}

function getCancelKeyboard(): string {
    return json_encode(['keyboard' => [[['text' => '🔙 انصراف']]], 'resize_keyboard' => true]);
}

function getAdminMainKeyboard(array $s = []): string {
    $status_label = (!empty($s['bot_status']) && $s['bot_status'] == '1')
        ? '⬤ ربات: روشن — کلیک برای خاموش'
        : '⬤ ربات: خاموش — کلیک برای روشن';
    return json_encode(['keyboard' => [
        [['text' => '🛍 فروشگاه'],   ['text' => '👥 کاربران']],
        [['text' => '💳 مالی'],      ['text' => '⚙️ تنظیمات']],
        [['text' => $status_label]],
        [['text' => '🔙 بازگشت به ربات']],
    ], 'resize_keyboard' => true]);
}

function getStoreKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '➕ افزودن پلن جدید'], ['text' => '📋 لیست پلن‌ها']],
        [['text' => '🎁 تنظیمات تست رایگان'], ['text' => '🎟 کدهای تخفیف']],
        [['text' => '🎛 تنظیمات پلن دلخواه']],
        [['text' => '🔙 بازگشت به داشبورد']],
    ], 'resize_keyboard' => true]);
}

function getUsersSettingsKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '📊 آمار'],              ['text' => '🔍 جستجوی کاربر']],
        [['text' => '📢 پیام همگانی'],       ['text' => '🔒 کانال‌های قفل']],
        [['text' => '📢 ارسال به کانال'],    ['text' => '👥 مدیران']],
        [['text' => '🔙 بازگشت به داشبورد']],
    ], 'resize_keyboard' => true]);
}

function getFinanceKeyboard(): string {
    $s = getSettings();
    $campaign_label = (!empty($s['campaign_status']) && $s['campaign_status'] == '1')
        ? '🔥 کمپین فعال: ' . ($s['campaign_pct'] ?? '0') . '٪ — کلیک برای مدیریت'
        : '🔥 کمپین تخفیف (غیرفعال)';
    return json_encode(['keyboard' => [
        [['text' => '🌐 تنظیمات درگاه آنلاین (Tetra98)']],
        [['text' => '💳 تنظیم کارت به کارت'],  ['text' => '💲 تنظیم درگاه ارزی']],
        [['text' => 'وضعیت کارت: ' . ($s['card_status'] == '1' ? 'روشن 🟢' : 'خاموش 🔴')],
         ['text' => 'وضعیت ارزی: ' . ($s['crypto_status'] == '1' ? 'روشن 🟢' : 'خاموش 🔴')]],
        [['text' => '📝 تنظیم متن درگاه ارزی']],
        [['text' => '➕ شارژ کیف پول'],  ['text' => '➖ کسر از کیف پول']],
        [['text' => '🎁 شارژ همگانی'],   ['text' => '💸 کسر همگانی']],
        [['text' => $campaign_label]],
        [['text' => '🔙 بازگشت به داشبورد']],
    ], 'resize_keyboard' => true]);
}

function getBotSettingsKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '⚙️ دکمه‌های منو'],          ['text' => '🎛 دکمه‌های درون سرویس']],
        [['text' => '👤 پشتیبانی'],               ['text' => '📢 کانال گزارشات']],
        [['text' => '💎 نمایندگی'],               ['text' => '📝 متن آموزش‌ها']],
        [['text' => '🔌 پنل اصلی (XUI)'],        ['text' => '🖥 سرورها']],
        [['text' => '💬 پیام خوش‌آمدگویی']],
        [['text' => '🔙 بازگشت به داشبورد']],
    ], 'resize_keyboard' => true]);
}

function getButtonsToggleKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '🛒 دکمه خرید'],     ['text' => '🎁 دکمه تست']],
        [['text' => '👤 دکمه حساب'],     ['text' => '👥 دکمه رفرال']],
        [['text' => '📦 دکمه سرویس'],    ['text' => '👨‍💻 دکمه پشتیبانی']],
        [['text' => '📚 دکمه آموزش'],    ['text' => '🔙 تنظیمات ربات']],
    ], 'resize_keyboard' => true]);
}

function getMyServicesSettingsKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '➕ دکمه حجم اضافه'], ['text' => '👥 دکمه کاربر اضافه']],
        [['text' => '🔄 دکمه تمدید سرویس']],
        [['text' => '🔙 تنظیمات ربات']],
    ], 'resize_keyboard' => true]);
}

function getTrialKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '⚙️ تنظیم حجم تست (MB)'], ['text' => '⏳ تنظیم زمان تست (دقیقه)']],
        [['text' => '🔙 مدیریت فروشگاه']],
    ], 'resize_keyboard' => true]);
}

function getPanelSettingsKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '🔗 تغییر آدرس پنل'],   ['text' => '🆔 تغییر آیدی کانفیگ']],
        [['text' => '👤 تغییر یوزرنیم'],     ['text' => '🔑 تغییر پسورد']],
        [['text' => '🌐 تنظیم دامنه لینک ساب']],
        [['text' => '🔙 بازگشت به داشبورد']],
    ], 'resize_keyboard' => true]);
}

function getForceJoinKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '➕ افزودن کانال قفل'], ['text' => '📋 لیست کانال‌های قفل']],
        [['text' => '🔙 بازگشت به داشبورد']],
    ], 'resize_keyboard' => true]);
}

function getServersKeyboard(): string {
    return json_encode(['keyboard' => [
        [['text' => '➕ افزودن سرور جدید'], ['text' => '📋 لیست سرورها']],
        [['text' => '🧪 تست اتصال سرور'],   ['text' => '🗑 حذف سرور']],
        [['text' => '🔙 بازگشت به داشبورد']],
    ], 'resize_keyboard' => true]);
}

// ========================
// پیام فاکتور
// ========================

function sendInvoiceMsg(
    Telegram $telegram,
    int $chat_id,
    int $user_id,
    string $pid,
    string $code,
    array $plan,
    int $wallet,
    array $settings,
    ?string $config_name = null
): void {
    $rows = ["🛍 پلن: {$plan['name']}"];
    if ($config_name) $rows[] = "👤 نام کانفیگ: <code>$config_name</code>";

    $original = (int)($plan['original_price'] ?? $plan['price']);
    $final    = (int)$plan['final_price'];

    if ($final < $original) {
        $rows[] = "💵 قیمت: <s>" . number_format($original) . "</s>  " . number_format($final) . " تومان";
        if (!empty($plan['campaign_pct']) && $plan['campaign_pct'] > 0) {
            $label = !empty($settings['campaign_label']) ? $settings['campaign_label'] : 'کمپین';
            $rows[] = "🔥 {$label} ({$plan['campaign_pct']}٪ تخفیف)";
        }
    } else {
        $rows[] = "💵 مبلغ: " . number_format($final) . " تومان";
    }

    $msg = formatCard('پیش‌فاکتور', $rows) . "\n\nروش پرداخت را انتخاب کنید:";

    $keys = [];
    if ($wallet >= $final) {
        $keys[] = [['text' => '💰 پرداخت از موجودی (' . number_format($wallet) . ' تومان)', 'callback_data' => "pay|{$pid}|{$code}|wallet"]];
    }
    if ($settings['tetra_status'] == '1') {
        $keys[] = [['text' => '🌐 پرداخت آنلاین', 'callback_data' => "pay|{$pid}|{$code}|tetra"]];
    }
    if ($settings['card_status'] == '1') {
        $keys[] = [['text' => '💳 کارت به کارت', 'callback_data' => "pay|{$pid}|{$code}|card"]];
    }
    if ($settings['crypto_status'] == '1') {
        $keys[] = [['text' => '💲 ارزی (Crypto)', 'callback_data' => "pay|{$pid}|{$code}|crypto"]];
    }
    if ($code == 'none') {
        $keys[] = [['text' => '🎟 کد تخفیف', 'callback_data' => "apply_disc|{$pid}"]];
    }

    $telegram->request('sendMessage', [
        'chat_id'      => $chat_id,
        'text'         => $msg,
        'parse_mode'   => 'HTML',
        'reply_markup' => json_encode(['inline_keyboard' => $keys]),
    ]);
}
