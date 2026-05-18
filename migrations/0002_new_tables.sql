-- =================================================================
-- Migration 0002: جداول جدید
-- =================================================================
-- این فایل فقط CREATE TABLE IF NOT EXISTS دارد. idempotent است.
--
-- جداول:
--   * schema_migrations: تاریخچهٔ اجرای migrations (داخلی runner)
--   * wallet_logs: لاگ تمام تغییرات کیف پول (audit)
--   * broadcast_queue: صف ارسال پیام همگانی (به‌جای foreach)
--   * force_join_channels: کانال‌های قفل (به‌جای settings.force_join JSON)
--   * discount_usages: ثبت هر بار استفاده از کد تخفیف
--   * panels: پشتیبانی از چند پنل X-UI در آینده
-- =================================================================

-- ─── schema_migrations ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `name` VARCHAR(255) NOT NULL PRIMARY KEY,
    `ran_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── wallet_logs ─────────────────────────────────────────────────
-- هر تغییر کیف پول اینجا ثبت می‌شود. ستون change نام رزرو MySQL است
-- پس از change_amount استفاده می‌کنیم.
CREATE TABLE IF NOT EXISTS `wallet_logs` (
    `id` BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL,
    `change_amount` BIGINT NOT NULL COMMENT 'مثبت=شارژ، منفی=کسر',
    `balance_after` BIGINT NOT NULL,
    `reason` VARCHAR(120) NOT NULL COMMENT 'مثلاً: charge_tetra, purchase_plan, admin_adjust',
    `ref_type` VARCHAR(40) NULL COMMENT 'transaction|order|admin',
    `ref_id` INT NULL,
    `actor_id` BIGINT NULL COMMENT 'اگر ادمین این تغییر را اعمال کرده',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_chat_time` (`chat_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── broadcast_queue ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `broadcast_queue` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `chat_id` BIGINT NOT NULL COMMENT 'گیرنده',
    `from_chat_id` BIGINT NOT NULL COMMENT 'فرستنده اصلی (برای copyMessage)',
    `source_msg_id` INT NOT NULL,
    `inline_markup` JSON NULL,
    `status` ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    `attempt` TINYINT NOT NULL DEFAULT 0,
    `error` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    KEY `idx_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── force_join_channels ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `force_join_channels` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `channel_id` VARCHAR(120) NOT NULL COMMENT '@username یا -100... عددی',
    `title` VARCHAR(120) NULL,
    `join_url` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── discount_usages ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `discount_usages` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `discount_code` VARCHAR(50) NOT NULL,
    `chat_id` BIGINT NOT NULL,
    `order_id` INT NULL,
    `used_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_code_user` (`discount_code`, `chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── panels ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `panels` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(80) NOT NULL,
    `url` VARCHAR(255) NOT NULL,
    `username` VARCHAR(80) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `sub_domain` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `priority` INT NOT NULL DEFAULT 100,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
