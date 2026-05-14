<?php
/**
 * راه‌اندازی اولیه دیتابیس
 * این فایل از run.php فراخوانی می‌شود — ایمن برای اجرای مکرر (IF NOT EXISTS)
 */

// جدول کاربران
$pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
    `chat_id`       BIGINT(20)   NOT NULL PRIMARY KEY,
    `step`          VARCHAR(100) DEFAULT NULL,
    `last_msg_time` INT(11)      DEFAULT 0,
    `join_date`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `invited_by`    BIGINT(20)   DEFAULT NULL,
    `trial_used`    TINYINT(1)   DEFAULT 0,
    `wallet`        INT(11)      DEFAULT 0,
    `is_reseller`   TINYINT(1)   DEFAULT 0,
    `temp_name`     VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// جدول تنظیمات
$pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key`   VARCHAR(50) NOT NULL PRIMARY KEY,
    `setting_value` TEXT        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// جدول پلن‌ها
$pdo->exec("CREATE TABLE IF NOT EXISTS `plans` (
    `id`    INT(11)      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name`  VARCHAR(100) NOT NULL,
    `gb`    INT(11)      NOT NULL,
    `days`  INT(11)      NOT NULL,
    `price` INT(11)      NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// جدول سرورها (Task 4)
$pdo->exec("CREATE TABLE IF NOT EXISTS `servers` (
    `id`             INT(11)      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name`           VARCHAR(100) NOT NULL,
    `url`            VARCHAR(255) NOT NULL,
    `user`           VARCHAR(100) NOT NULL,
    `pass`           VARCHAR(100) NOT NULL,
    `inbound_id`     INT(11)      NOT NULL DEFAULT 1,
    `sub_domain`     VARCHAR(255) DEFAULT '',
    `remote_address` VARCHAR(255) DEFAULT '',
    `ws_host`        VARCHAR(255) DEFAULT '',
    `is_active`      TINYINT(1)   DEFAULT 1,
    `priority`       INT(11)      DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// جدول سفارشات
$pdo->exec("CREATE TABLE IF NOT EXISTS `orders` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `chat_id`       BIGINT(20)   NOT NULL,
    `plan_name`     VARCHAR(100) NOT NULL,
    `uuid`          VARCHAR(100) NOT NULL,
    `email`         VARCHAR(100) NOT NULL,
    `link`          TEXT         NOT NULL,
    `server_id`     INT(11)      DEFAULT NULL,
    `last_notified` TIMESTAMP    NULL DEFAULT NULL,
    `buy_date`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// جدول ادمین‌ها
$pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
    `chat_id` BIGINT(20) NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// جدول تخفیف‌ها
$pdo->exec("CREATE TABLE IF NOT EXISTS `discounts` (
    `code`    VARCHAR(50) NOT NULL PRIMARY KEY,
    `percent` INT(11)     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// جدول تراکنش‌ها (با فیلدهای ضد تقلب Task 5)
$pdo->exec("CREATE TABLE IF NOT EXISTS `transactions` (
    `id`                   INT(11)      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `chat_id`              BIGINT(20)   NOT NULL,
    `authority`            VARCHAR(100) NOT NULL,
    `amount`               INT(11)      NOT NULL,
    `type`                 VARCHAR(50)  NOT NULL,
    `plan_id`              VARCHAR(50)  DEFAULT NULL,
    `code`                 VARCHAR(50)  DEFAULT 'none',
    `status`               VARCHAR(30)  DEFAULT 'pending',
    `receipt_file_hash`    VARCHAR(64)  DEFAULT NULL,
    `receipt_submitted_at` TIMESTAMP    NULL DEFAULT NULL,
    `created_at`           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ادمین اصلی
$pdo->prepare("INSERT IGNORE INTO admins (chat_id) VALUES (?)")->execute([$config['admin_id']]);

// جدول broadcasts (پیام همگانی صف‌محور)
$pdo->exec("CREATE TABLE IF NOT EXISTS `broadcasts` (
    `id`           INT(11)      NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `message_json` TEXT         NOT NULL,
    `status`       ENUM('pending','done') DEFAULT 'pending',
    `sent`         INT          DEFAULT 0,
    `total`        INT          DEFAULT 0,
    `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// افزودن ستون‌های جدید به جداول قدیمی (برای ارتقاء از نسخه قبلی)
$alter_queries = [
    "ALTER TABLE orders ADD COLUMN server_id INT NULL DEFAULT NULL",
    "ALTER TABLE orders ADD COLUMN last_notified TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE orders ADD COLUMN volume_warned TINYINT(1) DEFAULT 0",
    "ALTER TABLE transactions ADD COLUMN receipt_file_hash VARCHAR(64) NULL DEFAULT NULL",
    "ALTER TABLE transactions ADD COLUMN receipt_submitted_at TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE transactions ADD COLUMN status VARCHAR(30) DEFAULT 'pending'",
    "ALTER TABLE servers ADD COLUMN remote_address VARCHAR(255) DEFAULT ''",
    "ALTER TABLE servers ADD COLUMN ws_host VARCHAR(255) DEFAULT ''",
];
foreach ($alter_queries as $q) {
    try { $pdo->exec($q); } catch (PDOException $e) { /* ستون قبلاً وجود دارد */ }
}

// مقادیر پیش‌فرض تنظیمات
$defaults = [
    'bot_status' => '1', 'card_status' => '1', 'crypto_status' => '1', 'force_join' => '[]',
    'card_number' => 'وارد نشده', 'card_name' => 'وارد نشده', 'crypto_address' => 'TRX: وارد نشده',
    'crypto_guide' => 'لطفاً معادل تومانی مبلغ را به تتر تبدیل کرده و به آدرس زیر واریز کنید (شبکه TRC20):',
    'tetra_api_key' => 'وارد نشده', 'tetra_status' => '0',
    'buy_text' => '🛒 خرید اکانت', 'buy_status' => '1',
    'account_text' => '👤 حساب کاربری من', 'account_status' => '1',
    'trial_text' => '🎁 تست رایگان', 'trial_status' => '1',
    'referral_text' => '👥 زیرمجموعه گیری', 'referral_status' => '1',
    'services_text' => '📦 سرویس‌های من', 'services_status' => '1',
    'support_text' => '👨‍💻 پشتیبانی', 'support_status' => '1',
    'guide_text' => '📚 آموزش و دانلود', 'guide_status' => '1',
    'extra_gb_status' => '1', 'extra_ip_status' => '1', 'renew_status' => '1',
    'trial_mb' => '500', 'trial_mins' => '60', 'support_id' => '@admin',
    'panel_url' => 'http://127.0.0.1:2053', 'panel_user' => 'admin', 'panel_pass' => 'admin', 'inbound_id' => '1',
    'admin_channel' => '', 'reseller_fee' => '150000', 'reseller_discount' => '20',
    'sub_domain' => '', 'remote_address' => '', 'ws_host' => '', 'referral_reward' => '0',
    'custom_plan_status' => '1', 'custom_gb_price' => '3000', 'custom_days' => '30',
    'guide_and' => 'آموزش اندروید (توسط ادمین تنظیم نشده)',
    'guide_ios' => 'آموزش آیفون (توسط ادمین تنظیم نشده)',
    'guide_win' => 'آموزش ویندوز (توسط ادمین تنظیم نشده)',
    'guide_lin' => 'آموزش لینوکس (توسط ادمین تنظیم نشده)',
    'text_start' => '👋 سلام! به فروشگاه ما خوش آمدید.',
    'channel_btn_text' => '🛒 خرید سرویس اختصاصی',
    'channel_btn_link' => 'https://t.me/',
    // کمپین تخفیف سراسری
    'campaign_status' => '0',
    'campaign_pct'    => '0',
    'campaign_label'  => '',
    // پاکسازی خودکار سرویس منقضی
    'cleanup_status' => '1',
    'cleanup_days'   => '7',
    // تایید خودکار رسید کارت
    'auto_confirm_hours' => '0',
    // دکمه‌های منوی جدید (برای backward compat با نام‌های قبلی)
    'account_text'  => '👤 حساب من',
    'buy_text'      => '🛒 خرید سرویس',
    'services_text' => '📦 سرویس‌های من',
    'support_text'  => '💬 پشتیبانی',
];
foreach ($defaults as $k => $v) {
    $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
}
