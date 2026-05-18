-- =================================================================
-- Migration 0003: Indexهای حیاتی برای performance
-- =================================================================
-- بدون این index ها در مقیاس ۱۰هزار کاربر، کوئری‌های زیر بسیار کند می‌شوند:
--   * شمارش رفرال یک کاربر (WHERE invited_by = ?)
--   * لیست سفارش‌های یک کاربر (WHERE chat_id = ? ORDER BY)
--   * lookup تراکنش با authority در verify_tetra
--   * پیداکردن سفارش با email یا uuid
--
-- runner خطاهای "Duplicate key name" را skip می‌کند.
-- =================================================================

-- ─── users ───────────────────────────────────────────────────────
CREATE INDEX `idx_users_invited` ON `users`(`invited_by`);
CREATE INDEX `idx_users_step` ON `users`(`step`);
CREATE INDEX `idx_users_banned` ON `users`(`banned`);
CREATE INDEX `idx_users_last_seen` ON `users`(`last_seen`);

-- ─── orders ──────────────────────────────────────────────────────
CREATE INDEX `idx_orders_chat` ON `orders`(`chat_id`, `buy_date`);
CREATE INDEX `idx_orders_email` ON `orders`(`email`);
CREATE INDEX `idx_orders_uuid` ON `orders`(`uuid`);
CREATE INDEX `idx_orders_status` ON `orders`(`status`);
CREATE INDEX `idx_orders_plan` ON `orders`(`plan_id`);

-- ─── transactions ────────────────────────────────────────────────
CREATE INDEX `idx_txn_auth` ON `transactions`(`authority`);
CREATE INDEX `idx_txn_chat_status` ON `transactions`(`chat_id`, `status`);
CREATE INDEX `idx_txn_type` ON `transactions`(`type`, `status`);

-- ─── discount_usages ─────────────────────────────────────────────
-- این index در migration 0002 هم تعریف شد، اینجا برای اطمینان مجدد.
CREATE INDEX `idx_disc_usage` ON `discount_usages`(`discount_code`, `chat_id`);
