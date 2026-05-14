<?php
class Router {
    private Telegram $telegram;
    private PDO $pdo;
    private array $config;
    private array $settings;
    private CallbackHandler $callbackHandler;
    private AdminHandler $adminHandler;
    private UserHandler $userHandler;

    public function __construct(
        Telegram $telegram,
        PDO $pdo,
        array $config,
        array $settings,
        ServerManager $serverManager
    ) {
        $this->telegram = $telegram;
        $this->pdo      = $pdo;
        $this->config   = $config;
        $this->settings = $settings;

        $payment = new PaymentHandler($pdo, $telegram, $settings, $config, $serverManager);

        $this->callbackHandler = new CallbackHandler($pdo, $telegram, $settings, $config, $payment);
        $this->adminHandler    = new AdminHandler($pdo, $telegram, $settings, $config, $serverManager);
        $this->userHandler     = new UserHandler($pdo, $telegram, $settings, $config, $payment);
    }

    public function dispatch(array $update): void {
        $user_id = (int)($update['message']['from']['id'] ?? ($update['callback_query']['from']['id'] ?? 0));
        $chat_id = (int)($update['message']['chat']['id'] ?? ($update['callback_query']['message']['chat']['id'] ?? 0));

        if (!$user_id) return;

        $stmt = $this->pdo->prepare("SELECT 1 FROM admins WHERE chat_id = ?");
        $stmt->execute([$user_id]);
        $is_admin = (bool)$stmt->fetchColumn() || $user_id === (int)$this->config['admin_id'];

        // Callback query
        if (isset($update['callback_query'])) {
            $this->callbackHandler->handle($update, $user_id, $is_admin);
            return;
        }

        // پیام متنی
        if (!isset($update['message'])) return;

        $text = $update['message']['text'] ?? '';

        // Rate limiting: حداکثر ۳۰ پیام در ۶۰ ثانیه برای غیر ادمین
        if (!$is_admin && !empty($text)) {
            $stmt_rl = $this->pdo->prepare("SELECT last_msg_time FROM users WHERE chat_id = ?");
            $stmt_rl->execute([$user_id]);
            $rl_row = $stmt_rl->fetch(PDO::FETCH_ASSOC);
            if ($rl_row) {
                $stmt_cnt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM users WHERE chat_id = ? AND last_msg_time > ?"
                );
                // ساده‌ترین روش: یک فیلد rate_count + rate_window در users
                // فعلاً بررسی می‌کنیم آیا کاربر در ۱ ثانیه اخیر پیام فرستاده
                // برای rate limiting کامل نیاز به ستون اضافه است
                // این پیاده‌سازی ابتدایی: بلاک در صورت last_msg_time < 1 ثانیه قبل
                if (time() - (int)($rl_row['last_msg_time'] ?? 0) < 1) {
                    // صبر کوتاه — بدون پاسخ برای جلوگیری از flood
                    return;
                }
            }
        }

        $stmt2 = $this->pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
        $stmt2->execute([$user_id]);
        $user = $stmt2->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $this->pdo->prepare("INSERT INTO users (chat_id, last_msg_time) VALUES (?, ?)")->execute([$user_id, time()]);
            $user = ['step' => null, 'wallet' => 0, 'is_reseller' => 0, 'trial_used' => 0, 'join_date' => date('Y-m-d H:i:s')];
        } else {
            $this->pdo->prepare("UPDATE users SET last_msg_time = ? WHERE chat_id = ?")->execute([time(), $user_id]);
        }

        $step = $user['step'];

        // ربات خاموش
        if ($this->settings['bot_status'] == '0' && !$is_admin) {
            $this->telegram->sendMessage($chat_id, '🛠 ربات موقتاً در حال بروزرسانی است. لطفاً بعداً مراجعه کنید...');
            return;
        }

        // لغو / بازگشت — شامل دکمه‌های جدید و قدیمی
        $cancel_triggers = ['/cancel', '🔙 انصراف', '🔙 انصراف از خرید', '🔙 بازگشت به داشبورد',
                            '🔙 بازگشت به ربات', '🔙 بازگشت به پنل اصلی', '🔙 تنظیمات ربات',
                            '🔙 مدیریت فروشگاه', '🔙 مالی و کیف‌پول', '🔙 بازگشت'];
        if (in_array($text, $cancel_triggers)) {
            setStep($this->pdo, $user_id, null);
            if ($is_admin && strpos($text, 'بازگشت') !== false && $text !== '🔙 بازگشت به ربات') {
                $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '👨‍💻 داشبورد مدیریت:', 'reply_markup' => getAdminMainKeyboard($this->settings)]);
            } else {
                $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => 'عملیات لغو شد.', 'reply_markup' => getUserKeyboard($this->settings)]);
            }
            return;
        }

        // ریست step برای دکمه‌های منوی اصلی (جدید + قدیمی)
        $main_menu_items = [
            $this->settings['buy_text'], $this->settings['services_text'], $this->settings['account_text'],
            $this->settings['support_text'], '📖 راهنما و تست', '/start',
            // سازگاری با قدیمی
            $this->settings['trial_text'], $this->settings['referral_text'], $this->settings['guide_text'],
            '💰 شارژ کیف پول', '🤝 درخواست نمایندگی',
        ];
        if (in_array($text, $main_menu_items)) {
            $step = null;
            setStep($this->pdo, $user_id, null);
        }

        // ادمین
        if ($is_admin) {
            $user['step'] = $step;
            $handled = $this->adminHandler->handle($update, $user_id, $chat_id, $text, $step);
            if ($handled) return;
        }

        // کاربر
        $user['step'] = $step;
        $this->userHandler->handle($update, $user_id, $chat_id, $text, $step, $user);
    }
}
