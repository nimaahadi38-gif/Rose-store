<?php
// فایل: radiology.php
require_once __DIR__ . '/config/settings.php';
$config = require __DIR__ . '/config/settings.php';

echo "<h2>🩺 سیستم رادیولوژی و عیب‌یابی پروکسی</h2>";
echo "<pre>";

$url = "https://api.telegram.org/bot" . $config['bot_token'] . "/getMe";
echo "🔗 <b>Target URL:</b> " . "api.telegram.org/bot.../getMe\n";
echo "🔌 <b>Proxy Config:</b> " . $config['proxy_url'] . " (SOCKS5)\n";
if (!empty($config['proxy_auth'])) {
    echo "🔐 <b>Proxy Auth:</b> تنظیم شده است\n";
}
echo "--------------------------------------------------\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

// تنظیمات پروکسی
curl_setopt($ch, CURLOPT_PROXY, $config['proxy_url']);
curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);

// اعمال یوزرنیم و پسورد پروکسی
if (!empty($config['proxy_auth'])) {
    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $config['proxy_auth']);
}

// برای هاست ایران
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$start_time = microtime(true);
$response = curl_exec($ch);
$end_time = microtime(true);

$curl_error = curl_error($ch);
$curl_info = curl_getinfo($ch);
curl_close($ch);

// --- تحلیل نتایج ---
$execution_time = round($end_time - $start_time, 2);

echo "⏱️ <b>Time Taken:</b> {$execution_time} seconds\n\n";

echo "📊 <b>CURL Info:</b>\n";
echo "HTTP Code: " . $curl_info['http_code'] . "\n";
echo "Connect Time: " . $curl_info['connect_time'] . "\n";
echo "Total Time: " . $curl_info['total_time'] . "\n\n";

if ($response === false) {
    echo "❌ <b>Status:</b> FAILED!\n";
    echo "🩸 <b>Fatal Error:</b> " . $curl_error . "\n";
    echo "\n💡 <b>تشخیص:</b> پروکسی کار نمی‌کند، یا پورت بسته است، یا آدرس/یوزر/پسورد پروکسی اشتباه است.";
} else {
    $json = json_decode($response, true);
    if (isset($json['ok']) && $json['ok'] == true) {
        echo "✅ <b>Status:</b> SUCCESS!\n";
        echo "🟢 <b>Bot Name:</b> " . $json['result']['first_name'] . " (@" . $json['result']['username'] . ")\n";
        echo "\n💡 <b>تشخیص:</b> پروکسی در سلامت کامل است و ربات به تلگرام متصل است.";
    } else {
        echo "⚠️ <b>Status:</b> CONNECTED BUT TELEGRAM REJECTED!\n";
        echo "Response: " . $response . "\n";
        echo "\n💡 <b>تشخیص:</b> پروکسی وصل است اما توکن ربات اشتباه است یا تلگرام ارور داده.";
    }
}
echo "</pre>";