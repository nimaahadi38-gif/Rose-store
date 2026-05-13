<?php
class AdminHandler {
    private PDO $pdo;
    private Telegram $telegram;
    private array $settings;
    private array $config;
    private ServerManager $serverManager;

    public function __construct(
        PDO $pdo,
        Telegram $telegram,
        array $settings,
        array $config,
        ServerManager $serverManager
    ) {
        $this->pdo           = $pdo;
        $this->telegram      = $telegram;
        $this->settings      = $settings;
        $this->config        = $config;
        $this->serverManager = $serverManager;
    }

    /** نقطه ورود اصلی — متن دریافتی از ادمین را پردازش می‌کند */
    public function handle(array $update, int $user_id, int $chat_id, string $text, ?string $step): bool {
        // دستورات حذف (پلن، کد تخفیف، کانال)
        if ($this->handleDeleteCommands($text, $user_id, $chat_id)) return true;

        // پنل ارسال به کانال
        if ($this->handleChannelPost($update, $user_id, $chat_id, $text, $step)) return true;

        // منوهای اصلی
        if ($this->handleMainMenus($text, $user_id, $chat_id)) return true;

        // مدیریت سرورها (Task 4)
        if ($this->handleServerManagement($update, $user_id, $chat_id, $text, $step)) return true;

        // تنظیمات مالی
        if ($this->handleFinanceSettings($user_id, $chat_id, $text, $step)) return true;

        // تنظیمات فروشگاه
        if ($this->handleStoreSettings($user_id, $chat_id, $text, $step)) return true;

        // تنظیمات ربات
        if ($this->handleBotSettings($user_id, $chat_id, $text, $step)) return true;

        // مدیریت کاربران
        if ($this->handleUserManagement($user_id, $chat_id, $text, $step)) return true;

        // تنظیمات پنل XUI
        if ($this->handlePanelSettings($user_id, $chat_id, $text, $step)) return true;

        return false;
    }

    // ========================
    // منوهای اصلی
    // ========================

    private function handleMainMenus(string $text, int $user_id, int $chat_id): bool {
        $map = [
            '/admin'                  => ['step' => 'admin_main',     'kb' => 'getAdminMainKeyboard',     'msg' => "👨‍💻 <b>داشبورد مدیریت:</b>"],
            '🛍 مدیریت فروشگاه'        => ['step' => 'admin_store',    'kb' => 'getStoreKeyboard',          'msg' => "📦 <b>فروشگاه و پلن‌ها:</b>"],
            '👥 کاربران و آمار'         => ['step' => 'admin_users',    'kb' => 'getUsersSettingsKeyboard',  'msg' => "👥 <b>آمار و کاربران:</b>"],
            '💳 مالی و کیف‌پول'         => ['step' => 'admin_finance',  'kb' => 'getFinanceKeyboard',        'msg' => "💳 <b>مالی و درگاه‌ها:</b>"],
            '⚙️ تنظیمات ربات'          => ['step' => 'admin_settings', 'kb' => 'getBotSettingsKeyboard',    'msg' => "⚙️ <b>تنظیمات کلی:</b>"],
            '🔌 اتصال سرور (سنایی)'    => ['step' => 'admin_panel',    'kb' => 'getPanelSettingsKeyboard', 'msg' => "🔌 <b>پنل سنایی:</b>"],
            '🖥 مدیریت سرورها'          => ['step' => 'admin_servers',  'kb' => 'getServersKeyboard',        'msg' => "🖥 <b>مدیریت سرورهای XUI:</b>"],
        ];

        if (isset($map[$text])) {
            $item = $map[$text];
            setStep($this->pdo, $user_id, $item['step']);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => $item['msg'], 'reply_markup' => $item['kb']()]);
            return true;
        }

        if ($text === '🔴 وضعیت ربات 🟢') {
            $new = $this->settings['bot_status'] == '1' ? '0' : '1';
            updateSetting($this->pdo, 'bot_status', $new);
            $msg = $new === '1' ? '🟢 <b>ربات روشن شد.</b>' : '🔴 <b>ربات موقتاً خاموش شد.</b>';
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']);
            return true;
        }

        return false;
    }

    // ========================
    // مدیریت سرورها (Task 4)
    // ========================

    private function handleServerManagement(array $update, int $user_id, int $chat_id, string $text, ?string $step): bool {
        if ($text === '📋 لیست سرورها') {
            $servers = $this->serverManager->getServerStats();
            if (empty($servers)) {
                $this->telegram->sendMessage($chat_id, "📋 هیچ سروری ثبت نشده است.\nبرای افزودن اولین سرور از منو استفاده کنید.");
            } else {
                $msg = "📋 <b>لیست سرورهای XUI:</b>\n\n";
                foreach ($servers as $s) {
                    $status = $s['is_active'] ? '🟢 فعال' : '🔴 غیرفعال';
                    $msg .= "🖥 <b>{$s['name']}</b>\n"
                          . "🔗 آدرس: <code>{$s['url']}</code>\n"
                          . "📊 کاربران: <code>{$s['client_count']}</code>\n"
                          . "وضعیت: $status | اولویت: {$s['priority']}\n"
                          . "🗑 حذف: /delsrv_{$s['id']}\n\n";
                }
                $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']);
            }
            return true;
        }

        if ($text === '➕ افزودن سرور جدید') {
            setStep($this->pdo, $user_id, 'add_srv_name');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "نام سرور جدید را وارد کنید:\n<i>(مثلاً: سرور آلمان ۱)</i>", 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
            return true;
        }

        if ($text === '🧪 تست اتصال سرور') {
            setStep($this->pdo, $user_id, 'test_srv_id');
            $this->telegram->sendMessage($chat_id, "شناسه عددی سرور مورد نظر را وارد کنید (از لیست سرورها):");
            return true;
        }

        if ($text === '🗑 حذف سرور') {
            setStep($this->pdo, $user_id, 'del_srv_id');
            $this->telegram->sendMessage($chat_id, "شناسه عددی سرور برای حذف را وارد کنید:");
            return true;
        }

        if (strpos($text, '/delsrv_') === 0) {
            $srv_id = (int)str_replace('/delsrv_', '', $text);
            $this->pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$srv_id]);
            setStep($this->pdo, $user_id, null);
            $this->telegram->sendMessage($chat_id, "✅ سرور حذف شد.");
            return true;
        }

        return $this->handleServerSteps($user_id, $chat_id, $text, $step);
    }

    private function handleServerSteps(int $user_id, int $chat_id, string $text, ?string $step): bool {
        if ($step === 'add_srv_name') {
            setStep($this->pdo, $user_id, "add_srv_url|$text");
            $this->telegram->sendMessage($chat_id, "آدرس URL پنل را وارد کنید:\n(مثلاً: http://1.2.3.4:2053)");
            return true;
        }
        if (strpos($step ?? '', 'add_srv_url|') === 0) {
            $name = explode('|', $step)[1];
            setStep($this->pdo, $user_id, "add_srv_user|{$name}|{$text}");
            $this->telegram->sendMessage($chat_id, "نام کاربری پنل:");
            return true;
        }
        if (strpos($step ?? '', 'add_srv_user|') === 0) {
            $parts = explode('|', $step);
            setStep($this->pdo, $user_id, "add_srv_pass|{$parts[1]}|{$parts[2]}|{$text}");
            $this->telegram->sendMessage($chat_id, "رمز عبور پنل:");
            return true;
        }
        if (strpos($step ?? '', 'add_srv_pass|') === 0) {
            $parts = explode('|', $step);
            setStep($this->pdo, $user_id, "add_srv_inbound|{$parts[1]}|{$parts[2]}|{$parts[3]}|{$text}");
            $this->telegram->sendMessage($chat_id, "شناسه Inbound (عدد):");
            return true;
        }
        if (strpos($step ?? '', 'add_srv_inbound|') === 0) {
            $parts     = explode('|', $step);
            $inbound   = (int)onlyNumber($text);
            setStep($this->pdo, $user_id, "add_srv_sub|{$parts[1]}|{$parts[2]}|{$parts[3]}|{$parts[4]}|{$inbound}");
            $this->telegram->sendMessage($chat_id, "دامنه Sub (اختیاری، اینتر برای رد):\n<i>مثلاً: https://sub.example.com</i>", 'HTML');
            return true;
        }
        if (strpos($step ?? '', 'add_srv_sub|') === 0) {
            $parts      = explode('|', $step);
            $sub_domain = trim($text) === '-' || empty(trim($text)) ? '' : $text;
            $this->pdo->prepare(
                "INSERT INTO servers (name, url, user, pass, inbound_id, sub_domain, is_active, priority)
                 VALUES (?, ?, ?, ?, ?, ?, 1, (SELECT COALESCE(MAX(priority),0)+1 FROM servers s2))"
            )->execute([$parts[1], $parts[2], $parts[3], $parts[4], (int)$parts[5], $sub_domain]);
            setStep($this->pdo, $user_id, 'admin_servers');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ سرور «{$parts[1]}» با موفقیت اضافه شد.", 'reply_markup' => getServersKeyboard()]);
            return true;
        }
        if ($step === 'test_srv_id') {
            $srv_id = (int)onlyNumber($text);
            $result = $this->serverManager->testServer($srv_id);
            setStep($this->pdo, $user_id, 'admin_servers');
            $this->telegram->sendMessage($chat_id, $result['msg']);
            return true;
        }
        if ($step === 'del_srv_id') {
            $srv_id = (int)onlyNumber($text);
            $this->pdo->prepare("DELETE FROM servers WHERE id = ?")->execute([$srv_id]);
            setStep($this->pdo, $user_id, 'admin_servers');
            $this->telegram->sendMessage($chat_id, "✅ سرور حذف شد.");
            return true;
        }
        return false;
    }

    // ========================
    // آمار پیشرفته (Task 3)
    // ========================

    private function handleUserManagement(int $user_id, int $chat_id, string $text, ?string $step): bool {
        if ($text === '📊 آمار پیشرفته ربات') {
            $this->sendAdvancedStats($chat_id);
            return true;
        }
        if ($text === '📢 پیام همگانی') {
            setStep($this->pdo, $user_id, 'admin_broadcast');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "📢 پیام همگانی:\n<i>متن، عکس، ویدیو... پشتیبانی می‌شود</i>", 'reply_markup' => getCancelKeyboard()]);
            return true;
        }
        if ($step === 'admin_broadcast') {
            $this->broadcastMessage($update_msg_id = 0, $chat_id, $user_id);
            return true;
        }
        if ($text === '🔍 مدیریت کاربر') {
            setStep($this->pdo, $user_id, 'admin_search_user');
            $this->telegram->sendMessage($chat_id, "آیدی عددی کاربر را وارد کنید:");
            return true;
        }
        if ($step === 'admin_search_user') {
            $this->searchUser($user_id, $chat_id, $text);
            return true;
        }
        if ($text === '👥 مدیریت ادمین‌ها' && $user_id === (int)$this->config['admin_id']) {
            setStep($this->pdo, $user_id, 'admin_mngr');
            $this->telegram->sendMessage($chat_id, "آیدی عددی برای افزودن ادمین، یا /deladmin_ID برای حذف:");
            return true;
        }
        if ($step === 'admin_mngr') {
            $num = (int)onlyNumber($text);
            if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد وارد کنید!'); return true; }
            $this->pdo->prepare("INSERT IGNORE INTO admins (chat_id) VALUES (?)")->execute([$num]);
            $this->telegram->sendMessage($chat_id, "✅ ادمین افزوده شد.");
            return true;
        }
        if (strpos($text, '/deladmin_') === 0 && $user_id === (int)$this->config['admin_id']) {
            $aid = (int)str_replace('/deladmin_', '', $text);
            $this->pdo->prepare("DELETE FROM admins WHERE chat_id = ?")->execute([$aid]);
            $this->telegram->sendMessage($chat_id, "✅ ادمین حذف شد.");
            return true;
        }
        if ($text === '🎁 پاداش زیرمجموعه‌گیری') {
            setStep($this->pdo, $user_id, 'set_ref_reward');
            $cur = number_format((int)($this->settings['referral_reward'] ?? 0));
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "مبلغ پاداش برای هر دعوت را به تومان وارد کنید (0 = غیرفعال):\nپاداش فعلی: $cur تومان"]);
            return true;
        }
        if ($step === 'set_ref_reward') {
            $num = onlyNumber($text);
            if ($num === '') { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; }
            updateSetting($this->pdo, 'referral_reward', $num);
            setStep($this->pdo, $user_id, 'admin_users');
            $this->telegram->sendMessage($chat_id, "✅ پاداش تنظیم شد.");
            return true;
        }
        if ($text === '🔄 پاکسازی سابقه تست‌ها') {
            $this->pdo->exec("UPDATE users SET trial_used = 0");
            $this->telegram->sendMessage($chat_id, "✅ سوابق تست پاک شد.");
            return true;
        }
        if ($text === '📢 مدیریت جوین اجباری') {
            setStep($this->pdo, $user_id, 'admin_fjoin');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => 'تنظیمات کانال‌های قفل:', 'reply_markup' => getForceJoinKeyboard()]);
            return true;
        }
        return $this->handleForceJoin($user_id, $chat_id, $text, $step);
    }

    private function sendAdvancedStats(int $chat_id): void {
        $this->telegram->sendMessage($chat_id, '⏳ در حال محاسبه آمار...');

        // آمار پایه کاربران
        $t_users    = $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $t_resellers = $this->pdo->query("SELECT COUNT(*) FROM users WHERE is_reseller = 1")->fetchColumn();
        $t_today    = $this->pdo->query("SELECT COUNT(*) FROM users WHERE DATE(join_date) = CURDATE()")->fetchColumn();
        $t_orders   = $this->pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
        $t_plans    = $this->pdo->query("SELECT COUNT(*) FROM plans")->fetchColumn();
        $t_trials   = $this->pdo->query("SELECT COUNT(*) FROM users WHERE trial_used = 1")->fetchColumn();
        $t_wallets  = $this->pdo->query("SELECT COALESCE(SUM(wallet), 0) FROM users")->fetchColumn();

        // درآمد امروز / هفته / ماه
        $rev_today = $this->pdo->query(
            "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='paid' AND DATE(created_at)=CURDATE()"
        )->fetchColumn();
        $rev_week = $this->pdo->query(
            "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='paid' AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)"
        )->fetchColumn();
        $rev_month = $this->pdo->query(
            "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())"
        )->fetchColumn();

        // محبوب‌ترین پلن‌ها (۳ تا)
        $top_plans = $this->pdo->query(
            "SELECT plan_name, COUNT(*) AS cnt FROM orders GROUP BY plan_name ORDER BY cnt DESC LIMIT 3"
        )->fetchAll(PDO::FETCH_ASSOC);

        // میانگین ورودی ۷ روزه
        $avg_7day = $this->pdo->query(
            "SELECT ROUND(COUNT(*)/7, 1) FROM users WHERE join_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )->fetchColumn();

        // ساعت اوج استفاده
        $peak_hours = $this->pdo->query(
            "SELECT HOUR(FROM_UNIXTIME(last_msg_time)) AS hr, COUNT(*) AS cnt FROM users WHERE last_msg_time > 0 GROUP BY hr ORDER BY cnt DESC LIMIT 3"
        )->fetchAll(PDO::FETCH_ASSOC);

        // آمار سرورها
        $servers = $this->serverManager->getServerStats();

        $msg = "📈 <b>گزارش جامع ربات:</b>\n\n";

        $msg .= "👥 <b>کاربران:</b>\n"
              . "🔸 کل: <code>$t_users</code>\n"
              . "🔸 نمایندگان: <code>$t_resellers</code>\n"
              . "🔸 ورودی امروز: <code>$t_today</code>\n"
              . "🔸 میانگین ۷ روزه: <code>$avg_7day</code> نفر/روز\n\n";

        $msg .= "💰 <b>درآمد:</b>\n"
              . "🔸 امروز: <code>" . number_format($rev_today) . "</code> تومان\n"
              . "🔸 ۷ روز اخیر: <code>" . number_format($rev_week) . "</code> تومان\n"
              . "🔸 این ماه: <code>" . number_format($rev_month) . "</code> تومان\n\n";

        $msg .= "🛍 <b>سرویس‌ها:</b>\n"
              . "🔸 کل سفارشات: <code>$t_orders</code>\n"
              . "🔸 پلن‌های موجود: <code>$t_plans</code>\n"
              . "🎁 تست‌های توزیع شده: <code>$t_trials</code>\n"
              . "💼 مجموع کیف‌پول‌ها: <code>" . number_format($t_wallets) . "</code> تومان\n\n";

        if (!empty($top_plans)) {
            $msg .= "🏆 <b>محبوب‌ترین پلن‌ها:</b>\n";
            foreach ($top_plans as $i => $p) {
                $msg .= ($i + 1) . ". {$p['plan_name']} — <code>{$p['cnt']}</code> فروش\n";
            }
            $msg .= "\n";
        }

        if (!empty($peak_hours)) {
            $msg .= "⏰ <b>ساعات اوج استفاده:</b>\n";
            foreach ($peak_hours as $h) {
                $msg .= "🔸 ساعت {$h['hr']}:00 — <code>{$h['cnt']}</code> پیام\n";
            }
            $msg .= "\n";
        }

        if (!empty($servers)) {
            $msg .= "🖥 <b>وضعیت سرورها:</b>\n";
            foreach ($servers as $s) {
                $status = $s['is_active'] ? '🟢' : '🔴';
                $msg .= "{$status} {$s['name']} — <code>{$s['client_count']}</code> کاربر\n";
            }
        }

        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']);
    }

    private function broadcastMessage(int $msg_id_src, int $chat_id, int $user_id): void {
        // نیاز به update کامل داریم؛ این متد از run.php فراخوانی می‌شود
        // برای این که update را داشته باشیم از global استفاده می‌کنیم
        global $update;
        $users      = $this->pdo->query("SELECT chat_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
        $msg_src_id = $update['message']['message_id'];
        $count      = 0;
        $this->telegram->sendMessage($chat_id, "⏳ در حال ارسال به " . count($users) . " کاربر...");
        foreach ($users as $uid) {
            $res = $this->telegram->request('copyMessage', ['chat_id' => $uid, 'from_chat_id' => $chat_id, 'message_id' => $msg_src_id]);
            if (isset($res['ok']) && $res['ok']) $count++;
        }
        setStep($this->pdo, $user_id, 'admin_users');
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ به $count کاربر ارسال شد.", 'reply_markup' => getUsersSettingsKeyboard()]);
    }

    private function searchUser(int $admin_id, int $chat_id, string $text): void {
        $num = onlyNumber($text);
        if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد وارد کنید.'); return; }
        $target = (int)$num;
        $stmt   = $this->pdo->prepare("SELECT * FROM users WHERE chat_id = ?");
        $stmt->execute([$target]);
        $t_user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$t_user) { $this->telegram->sendMessage($chat_id, '❌ کاربری با این آیدی یافت نشد.'); return; }

        $ords = $this->pdo->prepare("SELECT * FROM orders WHERE chat_id = ? ORDER BY id DESC");
        $ords->execute([$target]);
        $user_orders = $ords->fetchAll(PDO::FETCH_ASSOC);

        $msg = "👤 <b>اطلاعات کاربر:</b> <code>$target</code>\n〰️〰️\n"
             . "💰 کیف پول: <code>" . number_format((int)$t_user['wallet']) . "</code> تومان\n"
             . "💎 نمایندگی: " . ($t_user['is_reseller'] ? '✅ نماینده' : 'کاربر عادی') . "\n"
             . "🎁 تست: " . ($t_user['trial_used'] ? 'بله' : 'خیر') . "\n"
             . "🕒 عضویت: <code>{$t_user['join_date']}</code>\n\n";

        if (empty($user_orders)) {
            $msg .= "📦 هیچ سرویسی ندارد.";
        } else {
            $msg .= "📦 <b>سرویس‌ها:</b>\n";
            foreach ($user_orders as $o) {
                $msg .= "🔸 {$o['plan_name']} | <code>{$o['email']}</code>\n";
            }
        }

        $keys = [[['text' => $t_user['is_reseller'] ? '❌ لغو نمایندگی' : '✅ ارتقا به نماینده',
                   'callback_data' => $t_user['is_reseller'] ? "revoke_reseller_{$target}" : "make_reseller_{$target}"]]];
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
        setStep($this->pdo, $admin_id, 'admin_users');
    }

    // ========================
    // تنظیمات فروشگاه
    // ========================

    private function handleStoreSettings(int $user_id, int $chat_id, string $text, ?string $step): bool {
        if ($text === '➕ افزودن پلن جدید')  { setStep($this->pdo, $user_id, 'add_plan_name'); $this->telegram->sendMessage($chat_id, 'نام پلن:'); return true; }
        if ($step === 'add_plan_name')        { setStep($this->pdo, $user_id, "add_plan_gb|$text"); $this->telegram->sendMessage($chat_id, 'حجم (گیگ):'); return true; }
        if (strpos($step ?? '', 'add_plan_gb|') === 0) {
            $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; }
            $name = explode('|', $step)[1]; setStep($this->pdo, $user_id, "add_plan_days|$name|$num");
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML', 'text' => "زمان (روز):\n<i>(۰ = نامحدود)</i>"]); return true;
        }
        if (strpos($step ?? '', 'add_plan_days|') === 0) {
            $num = onlyNumber($text); if ($num === '') $num = '0';
            $parts = explode('|', $step); setStep($this->pdo, $user_id, "add_plan_price|{$parts[1]}|{$parts[2]}|$num");
            $this->telegram->sendMessage($chat_id, 'قیمت (تومان):'); return true;
        }
        if (strpos($step ?? '', 'add_plan_price|') === 0) {
            $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; }
            $parts = explode('|', $step);
            $this->pdo->prepare("INSERT INTO plans (name,gb,days,price) VALUES (?,?,?,?)")->execute([$parts[1], (int)$parts[2], (int)$parts[3], (int)$num]);
            setStep($this->pdo, $user_id, 'admin_store');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ پلن اضافه شد!', 'reply_markup' => getStoreKeyboard()]); return true;
        }
        if ($text === '📋 لیست پلن‌ها') {
            $plans = $this->pdo->query("SELECT * FROM plans")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($plans)) { $this->telegram->sendMessage($chat_id, 'لیست خالی است.'); return true; }
            $msg = "📋 <b>لیست پلن‌ها:</b>\n";
            foreach ($plans as $p) { $msg .= "🔸 {$p['name']} | " . number_format($p['price']) . " T | /delplan_{$p['id']}\n"; }
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']); return true;
        }
        if ($text === '🎟 کدهای تخفیف') {
            $codes = $this->pdo->query("SELECT * FROM discounts")->fetchAll(PDO::FETCH_ASSOC);
            $msg   = empty($codes) ? "لیست خالی است.\n\n" : "🎟 <b>کدهای تخفیف:</b>\n\n";
            foreach ($codes as $c) { $hash = md5($c['code']); $msg .= "🔸 <code>{$c['code']}</code> | {$c['percent']}% | /delcode_{$hash}\n\n"; }
            $keys = json_encode(['inline_keyboard' => [[['text' => '➕ افزودن کد تخفیف', 'callback_data' => 'add_new_discount']]]]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $keys]); return true;
        }
        if ($step === 'add_disc_code') {
            $clean = str_replace(' ', '', trim($text));
            if (empty($clean)) { $this->telegram->sendMessage($chat_id, '❌ نام نامعتبر.'); return true; }
            setStep($this->pdo, $user_id, "add_disc_percent|$clean");
            $this->telegram->sendMessage($chat_id, 'درصد تخفیف (مثلاً 20):'); return true;
        }
        if (strpos($step ?? '', 'add_disc_percent|') === 0) {
            $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; }
            $code = explode('|', $step)[1];
            $this->pdo->prepare("INSERT INTO discounts (code,percent) VALUES (?,?)")->execute([$code, (int)$num]);
            setStep($this->pdo, $user_id, 'admin_store');
            $this->telegram->sendMessage($chat_id, '✅ کد تخفیف ثبت شد.'); return true;
        }
        if ($text === '🎁 تنظیمات تست رایگان') { setStep($this->pdo, $user_id, 'admin_trial'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => 'تنظیمات تست:', 'reply_markup' => getTrialKeyboard()]); return true; }
        if ($text === '⚙️ تنظیم حجم تست (MB)')     { setStep($this->pdo, $user_id, 'set_trial_mb');   $this->telegram->sendMessage($chat_id, 'حجم به مگابایت:'); return true; }
        if ($step === 'set_trial_mb')   { $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; } updateSetting($this->pdo, 'trial_mb', $num); setStep($this->pdo, $user_id, 'admin_trial'); $this->telegram->sendMessage($chat_id, '✅ ثبت شد.'); return true; }
        if ($text === '⏳ تنظیم زمان تست (دقیقه)') { setStep($this->pdo, $user_id, 'set_trial_mins'); $this->telegram->sendMessage($chat_id, 'زمان به دقیقه:'); return true; }
        if ($step === 'set_trial_mins') { $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; } updateSetting($this->pdo, 'trial_mins', $num); setStep($this->pdo, $user_id, 'admin_trial'); $this->telegram->sendMessage($chat_id, '✅ ثبت شد.'); return true; }
        if ($text === '🎛 تنظیمات پلن دلخواه') {
            setStep($this->pdo, $user_id, 'admin_custom_plan');
            $status = $this->settings['custom_plan_status'] == '1' ? '✅ روشن' : '❌ خاموش';
            $keys = json_encode(['keyboard' => [[['text' => '💵 تعیین قیمت هر گیگ'], ['text' => '⏳ تعیین زمان پلن دلخواه']], [['text' => '🟢 روشن/خاموش پلن دلخواه']], [['text' => '🔙 مدیریت فروشگاه']]], 'resize_keyboard' => true]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML', 'reply_markup' => $keys,
                'text' => "🎛 <b>پلن دلخواه:</b>\nوضعیت: $status\nقیمت/گیگ: " . number_format((int)$this->settings['custom_gb_price']) . " تومان\nمدت: {$this->settings['custom_days']} روز"]); return true;
        }
        if ($text === '🟢 روشن/خاموش پلن دلخواه') { $new = $this->settings['custom_plan_status'] == '1' ? '0' : '1'; updateSetting($this->pdo, 'custom_plan_status', $new); $this->telegram->sendMessage($chat_id, '✅ تغییر انجام شد.'); return true; }
        if ($text === '💵 تعیین قیمت هر گیگ')     { setStep($this->pdo, $user_id, 'set_custom_price'); $this->telegram->sendMessage($chat_id, 'مبلغ هر گیگ به تومان:'); return true; }
        if ($step === 'set_custom_price') { $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; } updateSetting($this->pdo, 'custom_gb_price', $num); setStep($this->pdo, $user_id, 'admin_custom_plan'); $this->telegram->sendMessage($chat_id, '✅ ذخیره شد.'); return true; }
        if ($text === '⏳ تعیین زمان پلن دلخواه')  { setStep($this->pdo, $user_id, 'set_custom_days'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML', 'text' => "تعداد روز (۰ = نامحدود):"]); return true; }
        if ($step === 'set_custom_days') { $num = onlyNumber($text); if ($num === '') $num = '0'; updateSetting($this->pdo, 'custom_days', $num); setStep($this->pdo, $user_id, 'admin_custom_plan'); $this->telegram->sendMessage($chat_id, '✅ ذخیره شد.'); return true; }
        return false;
    }

    // ========================
    // تنظیمات مالی
    // ========================

    private function handleFinanceSettings(int $user_id, int $chat_id, string $text, ?string $step): bool {
        if ($text === '🌐 تنظیمات درگاه آنلاین (Tetra98)') {
            setStep($this->pdo, $user_id, 'admin_tetra');
            $keys = json_encode(['keyboard' => [[['text' => '🔑 تنظیم کلید API تترا98'], ['text' => '🟢 روشن/خاموش درگاه آنلاین']], [['text' => '🔙 مالی و کیف‌پول']]], 'resize_keyboard' => true]);
            $msg  = "🌐 <b>Tetra98:</b>\nوضعیت: " . ($this->settings['tetra_status'] == '1' ? '✅' : '❌') . "\nکلید API: <code>" . $this->settings['tetra_api_key'] . "</code>";
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => $keys]); return true;
        }
        if ($text === '🟢 روشن/خاموش درگاه آنلاین') { $new = $this->settings['tetra_status'] == '1' ? '0' : '1'; updateSetting($this->pdo, 'tetra_status', $new); $this->telegram->sendMessage($chat_id, '✅ وضعیت تغییر کرد.'); return true; }
        if ($text === '🔑 تنظیم کلید API تترا98')    { setStep($this->pdo, $user_id, 'set_tetra_api'); $this->telegram->sendMessage($chat_id, 'کلید API:'); return true; }
        if ($step === 'set_tetra_api') { updateSetting($this->pdo, 'tetra_api_key', trim($text)); setStep($this->pdo, $user_id, 'admin_tetra'); $this->telegram->sendMessage($chat_id, '✅ ثبت شد.'); return true; }
        if ($text === '📝 تنظیم متن درگاه ارزی') { setStep($this->pdo, $user_id, 'set_crypto_guide'); $this->telegram->sendMessage($chat_id, 'متن راهنمای پرداخت ارزی:'); return true; }
        if ($step === 'set_crypto_guide') { updateSetting($this->pdo, 'crypto_guide', $text); setStep($this->pdo, $user_id, 'admin_finance'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ ثبت شد.', 'reply_markup' => getFinanceKeyboard()]); return true; }
        if (strpos($text, 'وضعیت کارت:') === 0) { $new = $this->settings['card_status'] == '1' ? '0' : '1'; updateSetting($this->pdo, 'card_status', $new); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ تغییر کرد.', 'reply_markup' => getFinanceKeyboard()]); return true; }
        if (strpos($text, 'وضعیت ارزی:') === 0)  { $new = $this->settings['crypto_status'] == '1' ? '0' : '1'; updateSetting($this->pdo, 'crypto_status', $new); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ تغییر کرد.', 'reply_markup' => getFinanceKeyboard()]); return true; }
        if ($text === '💳 تنظیم کارت به کارت') { setStep($this->pdo, $user_id, 'set_card_num'); $this->telegram->sendMessage($chat_id, 'شماره کارت:'); return true; }
        if ($step === 'set_card_num') { updateSetting($this->pdo, 'card_number', convert2English($text)); setStep($this->pdo, $user_id, 'set_card_name'); $this->telegram->sendMessage($chat_id, 'نام صاحب کارت:'); return true; }
        if ($step === 'set_card_name') { updateSetting($this->pdo, 'card_name', $text); setStep($this->pdo, $user_id, 'admin_finance'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ کارت ثبت شد.', 'reply_markup' => getFinanceKeyboard()]); return true; }
        if ($text === '💲 تنظیم درگاه ارزی') { setStep($this->pdo, $user_id, 'set_crypto'); $this->telegram->sendMessage($chat_id, 'آدرس ولت (TRX):'); return true; }
        if ($step === 'set_crypto') { updateSetting($this->pdo, 'crypto_address', $text); setStep($this->pdo, $user_id, 'admin_finance'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ ولت ثبت شد.', 'reply_markup' => getFinanceKeyboard()]); return true; }
        if ($text === '➕ شارژ کیف پول') { setStep($this->pdo, $user_id, 'charge_user_id'); $this->telegram->sendMessage($chat_id, 'آیدی کاربر:'); return true; }
        if ($text === '➖ کسر از کیف پول') { setStep($this->pdo, $user_id, 'deduct_user_id'); $this->telegram->sendMessage($chat_id, 'آیدی کاربر:'); return true; }
        if ($step === 'charge_user_id' || $step === 'deduct_user_id') {
            $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; }
            $type = $step === 'charge_user_id' ? 'charge' : 'deduct'; setStep($this->pdo, $user_id, "{$type}_am|$num"); $this->telegram->sendMessage($chat_id, 'مبلغ به تومان:'); return true;
        }
        if (strpos($step ?? '', 'charge_am|') === 0 || strpos($step ?? '', 'deduct_am|') === 0) {
            $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; }
            $parts = explode('|', $step); $op = strpos($step, 'charge_am|') === 0 ? '+' : '-'; $uid = (int)$parts[1];
            $this->pdo->prepare("UPDATE users SET wallet = wallet $op ? WHERE chat_id = ?")->execute([(int)$num, $uid]);
            setStep($this->pdo, $user_id, 'admin_finance'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ انجام شد.', 'reply_markup' => getFinanceKeyboard()]); return true;
        }
        if ($text === '🎁 شارژ همگانی') { setStep($this->pdo, $user_id, 'gift_all'); $this->telegram->sendMessage($chat_id, 'مبلغ هدیه به تومان:'); return true; }
        if ($step === 'gift_all') { $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; } $this->pdo->prepare("UPDATE users SET wallet = wallet + ?")->execute([(int)$num]); setStep($this->pdo, $user_id, 'admin_finance'); $this->telegram->sendMessage($chat_id, '✅ شارژ همگانی انجام شد.'); return true; }
        if ($text === '🔥 کسر همگانی') { setStep($this->pdo, $user_id, 'deduct_all'); $this->telegram->sendMessage($chat_id, 'مبلغ کسر به تومان:'); return true; }
        if ($step === 'deduct_all') { $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; } $this->pdo->prepare("UPDATE users SET wallet = wallet - ?")->execute([(int)$num]); setStep($this->pdo, $user_id, 'admin_finance'); $this->telegram->sendMessage($chat_id, '✅ کسر همگانی انجام شد.'); return true; }
        if ($text === '💎 تنظیمات نمایندگی') {
            setStep($this->pdo, $user_id, 'admin_reseller');
            $keys = json_encode(['keyboard' => [[['text' => '💵 هزینه اشتراک نمایندگی'], ['text' => 'درصد تخفیف نمایندگی']], [['text' => '🔙 تنظیمات ربات']]], 'resize_keyboard' => true]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML', 'reply_markup' => $keys,
                'text' => "💎 <b>نمایندگی:</b>\nهزینه: " . number_format((int)$this->settings['reseller_fee']) . " تومان\nتخفیف: {$this->settings['reseller_discount']}%"]); return true;
        }
        if ($text === '💵 هزینه اشتراک نمایندگی') { setStep($this->pdo, $user_id, 'set_r_fee'); $this->telegram->sendMessage($chat_id, 'مبلغ به تومان:'); return true; }
        if ($step === 'set_r_fee') { $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; } updateSetting($this->pdo, 'reseller_fee', $num); setStep($this->pdo, $user_id, 'admin_reseller'); $this->telegram->sendMessage($chat_id, '✅ ثبت شد.'); return true; }
        if ($text === 'درصد تخفیف نمایندگی') { setStep($this->pdo, $user_id, 'set_r_disc'); $this->telegram->sendMessage($chat_id, 'درصد (مثلاً 20):'); return true; }
        if ($step === 'set_r_disc') { $num = onlyNumber($text); if (!$num) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد!'); return true; } updateSetting($this->pdo, 'reseller_discount', $num); setStep($this->pdo, $user_id, 'admin_reseller'); $this->telegram->sendMessage($chat_id, '✅ ثبت شد.'); return true; }
        return false;
    }

    // ========================
    // تنظیمات ربات
    // ========================

    private function handleBotSettings(int $user_id, int $chat_id, string $text, ?string $step): bool {
        if ($text === '⚙️ روشن/خاموش دکمه‌ها') { setStep($this->pdo, $user_id, 'admin_btn_toggles'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => 'دکمه‌ها:', 'reply_markup' => getButtonsToggleKeyboard()]); return true; }
        if ($text === '🎛 دکمه‌های درون سرویس')  { setStep($this->pdo, $user_id, 'admin_myserv_btn'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => 'دکمه‌های داخلی:', 'reply_markup' => getMyServicesSettingsKeyboard()]); return true; }
        if ($text === '🔙 تنظیمات ربات')          { setStep($this->pdo, $user_id, 'admin_settings'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => 'تنظیمات:', 'reply_markup' => getBotSettingsKeyboard()]); return true; }
        if ($text === '👨‍💻 تنظیم آیدی پشتیبانی') { setStep($this->pdo, $user_id, 'set_support'); $this->telegram->sendMessage($chat_id, 'آیدی پشتیبانی (با @):'); return true; }
        if ($step === 'set_support') { updateSetting($this->pdo, 'support_id', $text); setStep($this->pdo, $user_id, 'admin_settings'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ ثبت شد.', 'reply_markup' => getBotSettingsKeyboard()]); return true; }
        if ($text === '📢 تنظیم چنل گزارشات') { setStep($this->pdo, $user_id, 'set_admin_channel'); $this->telegram->sendMessage($chat_id, 'آیدی کانال:'); return true; }
        if ($step === 'set_admin_channel') { updateSetting($this->pdo, 'admin_channel', $text); setStep($this->pdo, $user_id, 'admin_settings'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ ثبت شد.', 'reply_markup' => getBotSettingsKeyboard()]); return true; }
        if ($text === '💬 تنظیم پیام خوش‌آمدگویی (/start)') {
            setStep($this->pdo, $user_id, 'set_text_start');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "💬 متن جدید پیام /start را بفرستید:", 'reply_markup' => getCancelKeyboard()]); return true;
        }
        if ($step === 'set_text_start') { updateSetting($this->pdo, 'text_start', $text); setStep($this->pdo, $user_id, 'admin_settings'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ پیام خوش‌آمدگویی تغییر کرد.', 'reply_markup' => getBotSettingsKeyboard()]); return true; }
        if ($text === '📝 تنظیم متن آموزش') {
            $keys = json_encode(['inline_keyboard' => [[['text' => '📱 Android', 'callback_data' => 'edit_guide_and'], ['text' => '🍏 iOS', 'callback_data' => 'edit_guide_ios']], [['text' => '💻 Windows', 'callback_data' => 'edit_guide_win'], ['text' => '🐧 Linux', 'callback_data' => 'edit_guide_lin']]]]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => 'بخش مورد نظر:', 'reply_markup' => $keys]); return true;
        }
        if (strpos($step ?? '', 'set_guide_') === 0) {
            $os = str_replace('set_guide_', '', $step);
            updateSetting($this->pdo, "guide_$os", $text);
            setStep($this->pdo, $user_id, 'admin_settings');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ آموزش ذخیره شد.', 'reply_markup' => getBotSettingsKeyboard()]); return true;
        }
        $btn_map = ['🛒 دکمه خرید'=>'buy','🎁 دکمه تست'=>'trial','👤 دکمه حساب'=>'account','👥 دکمه رفرال'=>'referral','📦 دکمه سرویس'=>'services','👨‍💻 دکمه پشتیبانی'=>'support','📚 دکمه آموزش'=>'guide','➕ دکمه حجم اضافه'=>'extra_gb','👥 دکمه کاربر اضافه'=>'extra_ip','🔄 دکمه تمدید سرویس'=>'renew'];
        if (array_key_exists($text, $btn_map)) {
            $k = $btn_map[$text]; $new = $this->settings[$k.'_status'] == '1' ? '0' : '1'; updateSetting($this->pdo, $k.'_status', $new);
            $this->telegram->sendMessage($chat_id, '✅ تغییر وضعیت انجام شد.'); return true;
        }
        return false;
    }

    // ========================
    // تنظیمات پنل
    // ========================

    private function handlePanelSettings(int $user_id, int $chat_id, string $text, ?string $step): bool {
        $panel_map = ['🔗 تغییر آدرس پنل'=>'set_panel_url','👤 تغییر یوزرنیم'=>'set_panel_user','🔑 تغییر پسورد'=>'set_panel_pass','🆔 تغییر آیدی کانفیگ'=>'set_inbound_id','🌐 تنظیم دامنه لینک ساب'=>'set_sub_domain'];
        if (array_key_exists($text, $panel_map)) {
            setStep($this->pdo, $user_id, $panel_map[$text]); $this->telegram->sendMessage($chat_id, 'مقدار جدید:'); return true;
        }
        if ($step && (strpos($step, 'set_panel_') === 0 || in_array($step, ['set_inbound_id', 'set_sub_domain']))) {
            $key = str_replace('set_', '', $step);
            updateSetting($this->pdo, $key, trim($text));
            setStep($this->pdo, $user_id, 'admin_panel');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ تنظیمات سرور بروزرسانی شد.', 'reply_markup' => getPanelSettingsKeyboard()]); return true;
        }
        return false;
    }

    // ========================
    // ارسال به کانال
    // ========================

    private function handleChannelPost(array $update, int $user_id, int $chat_id, string $text, ?string $step): bool {
        if ($text === '⚙️ تنظیم دکمه کانال') {
            setStep($this->pdo, $user_id, 'set_ch_btn_text');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => 'متن دکمه شیشه‌ای را بفرستید:', 'reply_markup' => getCancelKeyboard()]); return true;
        }
        if ($step === 'set_ch_btn_text') { updateSetting($this->pdo, 'channel_btn_text', $text); setStep($this->pdo, $user_id, 'set_ch_btn_link'); $this->telegram->sendMessage($chat_id, 'لینک دکمه شیشه‌ای:'); return true; }
        if ($step === 'set_ch_btn_link') { updateSetting($this->pdo, 'channel_btn_link', $text); setStep($this->pdo, $user_id, 'admin_users'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ دکمه کانال تنظیم شد.', 'reply_markup' => getUsersSettingsKeyboard()]); return true; }
        if ($text === '📢 ارسال به کانال') { setStep($this->pdo, $user_id, 'ask_ch_id_for_post'); $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "آیدی کانال مقصد با @:\n(ربات باید ادمین باشد)", 'reply_markup' => getCancelKeyboard()]); return true; }
        if ($step === 'ask_ch_id_for_post') { setStep($this->pdo, $user_id, "wait_ch_post|$text"); $this->telegram->sendMessage($chat_id, "✅ کانال $text انتخاب شد.\nپیام خود را بفرستید:"); return true; }
        if (strpos($step ?? '', 'wait_ch_post|') === 0) {
            $target_ch  = explode('|', $step)[1];
            $msg_src_id = $update['message']['message_id'];
            $btn_text   = $this->settings['channel_btn_text'] ?? '🛒 خرید سرویس اختصاصی';
            $btn_link   = $this->settings['channel_btn_link'] ?? 'https://t.me/';
            $keys       = json_encode(['inline_keyboard' => [[['text' => $btn_text, 'url' => $btn_link]]]]);
            $res        = $this->telegram->request('copyMessage', ['chat_id' => $target_ch, 'from_chat_id' => $chat_id, 'message_id' => $msg_src_id, 'reply_markup' => $keys]);
            if (isset($res['ok']) && $res['ok']) {
                setStep($this->pdo, $user_id, 'admin_users');
                $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => "✅ پیام در کانال $target_ch ارسال شد.", 'reply_markup' => getUsersSettingsKeyboard()]);
            } else {
                $this->telegram->sendMessage($chat_id, "❌ خطا. مطمئن شوید ربات ادمین کانال است.\nدلیل: " . ($res['description'] ?? '؟'));
            }
            return true;
        }
        return false;
    }

    // ========================
    // دستورات حذف
    // ========================

    private function handleDeleteCommands(string $text, int $user_id, int $chat_id): bool {
        if (strpos($text, '/delplan_') === 0) {
            $pid = (int)str_replace('/delplan_', '', $text);
            $this->pdo->prepare("DELETE FROM plans WHERE id = ?")->execute([$pid]);
            setStep($this->pdo, $user_id, null);
            $this->telegram->sendMessage($chat_id, '✅ پلن حذف شد.'); return true;
        }
        if (strpos($text, '/delcode_') === 0) {
            $hash  = str_replace('/delcode_', '', $text);
            $codes = $this->pdo->query("SELECT code FROM discounts")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($codes as $c) {
                if (md5($c['code']) === $hash) {
                    $this->pdo->prepare("DELETE FROM discounts WHERE code = ?")->execute([$c['code']]);
                    setStep($this->pdo, $user_id, null);
                    $this->telegram->sendMessage($chat_id, '✅ کد تخفیف حذف شد.'); return true;
                }
            }
            $this->telegram->sendMessage($chat_id, '❌ کد یافت نشد.'); return true;
        }
        if (strpos($text, '/deljoin_') === 0) {
            $idx = (int)str_replace('/deljoin_', '', $text);
            $fj  = json_decode($this->settings['force_join'] ?? '[]', true) ?? [];
            if (isset($fj[$idx])) { unset($fj[$idx]); updateSetting($this->pdo, 'force_join', json_encode(array_values($fj), JSON_UNESCAPED_UNICODE)); }
            $this->telegram->sendMessage($chat_id, '✅ حذف شد.'); return true;
        }
        return false;
    }

    // ========================
    // جوین اجباری
    // ========================

    private function handleForceJoin(int $user_id, int $chat_id, string $text, ?string $step): bool {
        if ($text === '➕ افزودن کانال قفل') { setStep($this->pdo, $user_id, 'add_fj_id'); $this->telegram->sendMessage($chat_id, 'آیدی کانال:'); return true; }
        if ($step === 'add_fj_id') { setStep($this->pdo, $user_id, "add_fj_name|$text"); $this->telegram->sendMessage($chat_id, 'نام نمایشی کانال:'); return true; }
        if (strpos($step ?? '', 'add_fj_name|') === 0) { $cid = explode('|', $step)[1]; setStep($this->pdo, $user_id, "add_fj_link|$cid|$text"); $this->telegram->sendMessage($chat_id, 'لینک عضویت:'); return true; }
        if (strpos($step ?? '', 'add_fj_link|') === 0) {
            $parts = explode('|', $step);
            $fj    = json_decode($this->settings['force_join'] ?? '[]', true) ?? [];
            $fj[]  = ['id' => $parts[1], 'name' => $parts[2], 'link' => $text];
            updateSetting($this->pdo, 'force_join', json_encode($fj, JSON_UNESCAPED_UNICODE));
            setStep($this->pdo, $user_id, 'admin_users');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '✅ کانال اضافه شد.', 'reply_markup' => getUsersSettingsKeyboard()]); return true;
        }
        if ($text === '📋 لیست کانال‌های قفل') {
            $fj = json_decode($this->settings['force_join'] ?? '[]', true) ?? [];
            if (empty($fj)) { $this->telegram->sendMessage($chat_id, 'لیست خالی است.'); return true; }
            $msg = "📋 لیست:\n";
            foreach ($fj as $i => $ch) { $msg .= "▪️ {$ch['name']} ({$ch['id']})\n🗑 /deljoin_$i\n\n"; }
            $this->telegram->sendMessage($chat_id, $msg); return true;
        }
        return false;
    }
}
