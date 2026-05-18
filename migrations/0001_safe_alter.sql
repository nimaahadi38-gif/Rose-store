-- =================================================================
-- Migration 0001: افزودن ستون‌های جدید به جداول موجود
-- =================================================================
-- این فایل فقط ALTER TABLE ADD COLUMN دارد. هیچ DROP در آن نیست.
-- اگر این migration دوباره اجرا شود، runner خطاهای "Duplicate column"
-- را skip می‌کند (idempotent).
--
-- چرا این تغییرات لازم‌اند:
--   * users: داشتن username/first_name برای جستجو + ban + step_data
--     ساختاریافته‌تر + last_seen برای حذف کاربران غیرفعال + wallet
--     از INT به BIGINT برای پشتیبانی مبالغ بالا
--   * orders: جدا کردن sub_link از HTML قدیمی، اضافه‌کردن status،
--     price_paid، payment_method و expiry_at برای reporting
--   * discounts: اضافه‌کردن id برای حذف امن (به‌جای md5(code))،
--     محدودیت استفاده، انقضا و فعال/غیرفعال
--   * transactions: gateway, gateway_response برای debug و
--     updated_at برای ردگیری
--   * admins: role + added_by + added_at
-- =================================================================

-- ─── users ───────────────────────────────────────────────────────
ALTER TABLE `users` ADD COLUMN `username` VARCHAR(64) NULL AFTER `chat_id`;
ALTER TABLE `users` ADD COLUMN `first_name` VARCHAR(128) NULL AFTER `username`;
ALTER TABLE `users` ADD COLUMN `last_name` VARCHAR(128) NULL AFTER `first_name`;
ALTER TABLE `users` ADD COLUMN `banned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_reseller`;
ALTER TABLE `users` ADD COLUMN `last_seen` TIMESTAMP NULL DEFAULT NULL AFTER `banned`;
ALTER TABLE `users` ADD COLUMN `step_data` JSON NULL AFTER `step`;
ALTER TABLE `users` MODIFY COLUMN `wallet` BIGINT NOT NULL DEFAULT 0;

-- ─── orders ──────────────────────────────────────────────────────
-- نکته مهم: ستون قدیمی `link` نگه داشته می‌شود برای backward compat.
-- در سرویس جدید فقط sub_link/raw_config نوشته می‌شود.
ALTER TABLE `orders` ADD COLUMN `sub_link` TEXT NULL AFTER `link`;
ALTER TABLE `orders` ADD COLUMN `raw_config` TEXT NULL AFTER `sub_link`;
ALTER TABLE `orders` ADD COLUMN `status` ENUM('active','expired','disabled','refunded') NOT NULL DEFAULT 'active' AFTER `raw_config`;
ALTER TABLE `orders` ADD COLUMN `price_paid` BIGINT NULL AFTER `status`;
ALTER TABLE `orders` ADD COLUMN `payment_method` VARCHAR(20) NULL AFTER `price_paid`;
ALTER TABLE `orders` ADD COLUMN `expiry_at` TIMESTAMP NULL DEFAULT NULL AFTER `payment_method`;
ALTER TABLE `orders` ADD COLUMN `plan_id` INT NULL AFTER `chat_id`;
ALTER TABLE `orders` ADD COLUMN `transaction_id` INT NULL AFTER `expiry_at`;

-- ─── discounts ───────────────────────────────────────────────────
-- اضافه‌کردن id به‌عنوان AUTO_INCREMENT UNIQUE (بدون تغییر PK فعلی).
-- بعداً در صورت لزوم می‌توان PK را به id منتقل کرد.
ALTER TABLE `discounts` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT UNIQUE FIRST;
ALTER TABLE `discounts` ADD COLUMN `usage_limit` INT NULL;
ALTER TABLE `discounts` ADD COLUMN `used_count` INT NOT NULL DEFAULT 0;
ALTER TABLE `discounts` ADD COLUMN `per_user_limit` INT NOT NULL DEFAULT 1;
ALTER TABLE `discounts` ADD COLUMN `expires_at` TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `discounts` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1;

-- ─── transactions ────────────────────────────────────────────────
ALTER TABLE `transactions` ADD COLUMN `gateway` VARCHAR(20) NULL AFTER `type`;
ALTER TABLE `transactions` ADD COLUMN `gateway_response` JSON NULL;
ALTER TABLE `transactions` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- ─── admins ──────────────────────────────────────────────────────
ALTER TABLE `admins` ADD COLUMN `role` ENUM('super_admin','admin') NOT NULL DEFAULT 'admin';
ALTER TABLE `admins` ADD COLUMN `added_by` BIGINT NULL;
ALTER TABLE `admins` ADD COLUMN `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ─── settings ────────────────────────────────────────────────────
-- اضافه‌کردن نوع‌بندی برای cast درست در SettingsService
ALTER TABLE `settings` ADD COLUMN `setting_type` ENUM('string','int','bool','json') NOT NULL DEFAULT 'string';
ALTER TABLE `settings` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
