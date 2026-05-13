<?php
class UserHandler {
    private PDO $pdo;
    private Telegram $telegram;
    private array $settings;
    private array $config;
    private PaymentHandler $payment;

    public function __construct(
        PDO $pdo,
        Telegram $telegram,
        array $settings,
        array $config,
        PaymentHandler $payment
    ) {
        $this->pdo      = $pdo;
        $this->telegram = $telegram;
        $this->settings = $settings;
        $this->config   = $config;
        $this->payment  = $payment;
    }

    public function handle(array $update, int $user_id, int $chat_id, string $text, ?string $step, array $user): void {
        // مرحله: کد تخفیف
        if (strpos($step ?? '', 'ask_discount|') === 0) {
            $this->handleDiscountCode($user_id, $chat_id, $text, $step);
            return;
        }

        // مرحله: پلن دلخواه بر اساس گیگ
        if ($step === 'ask_custom_gb') {
            $this->handleCustomGb($user_id, $chat_id, $text);
            return;
        }

        // مرحله: پلن دلخواه بر اساس مبلغ
        if ($step === 'ask_custom_price') {
            $this->handleCustomPrice($user_id, $chat_id, $text);
            return;
        }

        // مرحله: نام کانفیگ
        if (strpos($step ?? '', 'ask_name|') === 0) {
            $this->handleConfigName($user_id, $chat_id, $text, $step, $user);
            return;
        }

        // مرحله: مبلغ شارژ کیف پول
        if ($step === 'user_charge_wallet') {
            $this->handleChargeWallet($user_id, $chat_id, $text);
            return;
        }

        // مرحله: ارسال رسید (کارت/ارزی)
        if ($step && (strpos($step, 'wait_receipt|') === 0 || strpos($step, 'wait_charge_receipt_') === 0 || $step === 'wait_reseller_receipt')) {
            $this->payment->handleReceiptSubmission($update, $user_id, $chat_id, $step);
            return;
        }

        // دستورات اصلی منو
        $this->handleMainCommands($update, $user_id, $chat_id, $text, $user);
    }

    // ========================

    private function handleDiscountCode(int $user_id, int $chat_id, string $text, string $step): void {
        $pid  = explode('|', $step)[1];
        $stmt = $this->pdo->prepare("SELECT percent FROM discounts WHERE code = ?");
        $stmt->execute([$text]);
        $disc = $stmt->fetchColumn();

        if ($disc) {
            $this->telegram->sendMessage($chat_id, "✅ کد تخفیف $disc درصدی اعمال شد!");
            $this->telegram->request('sendMessage', [
                'chat_id'      => $chat_id,
                'text'         => 'روی دکمه زیر کلیک کنید تا فاکتور جدید صادر شود:',
                'reply_markup' => json_encode(['inline_keyboard' => [[['text' => 'مشاهده فاکتور با تخفیف', 'callback_data' => "pay|{$pid}|{$text}|invoice"]]]]),
            ]);
        } else {
            $this->telegram->sendMessage($chat_id, '❌ کد تخفیف نامعتبر است.');
        }
        setStep($this->pdo, $user_id, null);
    }

    private function handleCustomGb(int $user_id, int $chat_id, string $text): void {
        $en = convert2English($text);
        if (!preg_match('/^\d+$/', $en)) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد وارد کنید!'); return; }
        $gb = (int)$en;
        if ($gb < 1) { $this->telegram->sendMessage($chat_id, '❌ حداقل ۱ گیگابایت.'); return; }
        setStep($this->pdo, $user_id, "ask_name|custom_{$gb}|none");
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
            'text' => "🔤 لطفاً یک نام انگلیسی برای کانفیگ وارد کنید:\n<i>(بدون فاصله)</i>",
            'reply_markup' => getCancelKeyboard()]);
    }

    private function handleCustomPrice(int $user_id, int $chat_id, string $text): void {
        $en = convert2English($text);
        if (!preg_match('/^\d+$/', $en)) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد وارد کنید!'); return; }
        $toman        = (int)$en;
        $price_per_gb = (int)($this->settings['custom_gb_price'] ?? 3000);
        $gb           = (int)floor($toman / $price_per_gb);
        if ($gb < 1) { $this->telegram->sendMessage($chat_id, '❌ با این مبلغ حتی ۱ گیگ هم نمی‌توان خرید!'); return; }
        setStep($this->pdo, $user_id, "ask_name|custom_{$gb}|none");
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
            'text' => "🔤 لطفاً یک نام انگلیسی برای کانفیگ وارد کنید:\n<i>(بدون فاصله)</i>",
            'reply_markup' => getCancelKeyboard()]);
    }

    private function handleConfigName(int $user_id, int $chat_id, string $text, string $step, array $user): void {
        $parts  = explode('|', $step);
        $pid    = $parts[1];
        $code   = $parts[2] ?? 'none';

        $config_name = preg_replace('/[^a-zA-Z0-9_]/', '', convert2English($text));
        if (empty($config_name)) {
            $this->telegram->sendMessage($chat_id, '❌ نام نامعتبر. فقط حروف انگلیسی و اعداد:');
            return;
        }

        $this->pdo->prepare("UPDATE users SET temp_name = ?, step = NULL WHERE chat_id = ?")->execute([$config_name, $user_id]);

        $plan   = getFinalPrice($this->pdo, $pid, $code, $user_id, $this->settings);
        $wallet = (int)$user['wallet'];

        $msg = "🧾 <b>پیش‌فاکتور:</b>\n🔸 {$plan['name']}\n👤 نام کانفیگ: <code>$config_name</code>\n"
             . "💵 مبلغ: <code>" . number_format($plan['final_price']) . "</code> تومان"
             . ($plan['final_price'] < $plan['price'] ? ' (با تخفیف)' : '')
             . "\n\nروش پرداخت را انتخاب کنید:";

        $keys = [];
        if ($wallet >= $plan['final_price'])          $keys[] = [['text' => '💰 پرداخت از کیف پول',       'callback_data' => "pay|{$pid}|{$code}|wallet"]];
        if ($this->settings['tetra_status'] == '1')  $keys[] = [['text' => '🌐 پرداخت آنلاین (تترا98)',  'callback_data' => "pay|{$pid}|{$code}|tetra"]];
        if ($this->settings['card_status'] == '1')   $keys[] = [['text' => '💳 کارت به کارت',             'callback_data' => "pay|{$pid}|{$code}|card"]];
        if ($this->settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی',              'callback_data' => "pay|{$pid}|{$code}|crypto"]];
        if ($code === 'none')                         $keys[] = [['text' => '🎟 استفاده از کد تخفیف',      'callback_data' => "apply_disc|{$pid}"]];

        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
    }

    private function handleChargeWallet(int $user_id, int $chat_id, string $text): void {
        $en = convert2English($text);
        if (!preg_match('/^\d+$/', $en)) { $this->telegram->sendMessage($chat_id, '❌ فقط عدد وارد کنید!'); return; }
        $amount = (int)$en;
        if ($amount < 1000) { $this->telegram->sendMessage($chat_id, '❌ حداقل مبلغ شارژ ۱۰۰۰ تومان است.'); return; }

        $keys = [];
        if ($this->settings['tetra_status'] == '1')  $keys[] = [['text' => '🌐 پرداخت آنلاین (تترا98)', 'callback_data' => "charge_method_{$amount}_tetra"]];
        if ($this->settings['card_status'] == '1')   $keys[] = [['text' => '💳 کارت به کارت',            'callback_data' => "charge_method_{$amount}_card"]];
        if ($this->settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی',             'callback_data' => "charge_method_{$amount}_crypto"]];

        if (empty($keys)) {
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id,
                'text' => '❌ هیچ درگاه پرداختی فعال نیست.', 'reply_markup' => getUserKeyboard($this->settings)]);
        } else {
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id,
                'text' => '💳 مبلغ: ' . number_format($amount) . " تومان\nروش پرداخت:",
                'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
        }
        setStep($this->pdo, $user_id, null);
    }

    // ========================
    // دستورات اصلی منو کاربر
    // ========================

    private function handleMainCommands(array $update, int $user_id, int $chat_id, string $text, array $user): void {
        if (strpos($text, '/start') === 0) {
            $this->handleStart($update, $user_id, $chat_id, $text, $user);
            return;
        }

        if ($text === $this->settings['buy_text'] && $this->settings['buy_status'] == '1') {
            $this->showPlans($user_id, $chat_id, $user);
            return;
        }

        if ($text === $this->settings['services_text'] && $this->settings['services_status'] == '1') {
            $this->showMyServices($user_id, $chat_id);
            return;
        }

        if ($text === $this->settings['account_text'] && $this->settings['account_status'] == '1') {
            $status = $user['is_reseller'] ? "💎 <b>نماینده فعال</b>" : "کاربر عادی";
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "👤 آیدی: <code>$user_id</code>\n💰 کیف پول: <code>" . number_format((int)$user['wallet']) . "</code> T\n🏅 سطح: $status"]);
            return;
        }

        if ($text === '💰 شارژ کیف پول') {
            setStep($this->pdo, $user_id, 'user_charge_wallet');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "💰 <b>شارژ کیف پول</b>\n\nمبلغ مورد نظر را به <b>تومان</b> وارد کنید:",
                'reply_markup' => getCancelKeyboard()]);
            return;
        }

        if ($text === '🤝 درخواست نمایندگی') {
            $this->showResellerOffer($user_id, $chat_id, $user);
            return;
        }

        if ($text === $this->settings['referral_text'] && $this->settings['referral_status'] == '1') {
            $this->showReferral($user_id, $chat_id);
            return;
        }

        if ($text === $this->settings['trial_text'] && $this->settings['trial_status'] == '1') {
            $this->handleTrial($user_id, $chat_id, $user);
            return;
        }

        if ($text === $this->settings['support_text'] && $this->settings['support_status'] == '1') {
            $this->telegram->sendMessage($chat_id, "👨‍💻 جهت پشتیبانی:\n{$this->settings['support_id']}");
            return;
        }

        if ($text === $this->settings['guide_text'] && $this->settings['guide_status'] == '1') {
            $keys = json_encode(['inline_keyboard' => [
                [['text' => '📱 Android', 'callback_data' => 'dl_and'], ['text' => '🍏 iOS', 'callback_data' => 'dl_ios']],
                [['text' => '💻 Windows', 'callback_data' => 'dl_win'], ['text' => '🐧 Linux', 'callback_data' => 'dl_lin']],
            ]]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '📥 سیستم‌عامل خود را انتخاب کنید:', 'reply_markup' => $keys]);
        }
    }

    private function handleStart(array $update, int $user_id, int $chat_id, string $text, array $user): void {
        $parts      = explode(' ', $text);
        $is_new     = empty($user['join_date']) || strtotime($user['join_date']) > (time() - 10);
        $join_status = checkForcedJoin($user_id, $this->telegram, $this->settings, false);

        // پردازش رفرال
        if ($is_new && count($parts) == 2 && is_numeric($parts[1]) && (int)$parts[1] !== $user_id) {
            $inviter = (int)$parts[1];
            $reward  = (int)($this->settings['referral_reward'] ?? 0);
            $this->pdo->prepare("UPDATE users SET invited_by = ? WHERE chat_id = ? AND invited_by IS NULL")->execute([$inviter, $user_id]);
            if ($reward > 0) {
                $this->pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$reward, $inviter]);
                $this->telegram->sendMessage($inviter, "🎉 <b>تبریک!</b>\nیک نفر با لینک شما ثبت‌نام کرد و <code>" . number_format($reward) . "</code> تومان به کیف پول شما اضافه شد.");
            } else {
                $this->telegram->sendMessage($inviter, "🎉 <b>تبریک!</b>\nیک نفر با لینک اختصاصی شما ثبت‌نام کرد.");
            }
        }

        if ($join_status !== true) {
            $join_keys = [];
            foreach ($join_status as $ch) { $join_keys[] = [['text' => "📢 عضویت در {$ch['name']}", 'url' => $ch['link']]]; }
            $join_keys[] = [['text' => '✅ عضو شدم', 'callback_data' => 'check_join']];
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "⛔️ <b>ابتدا در کانال‌های زیر عضو شوید:</b>",
                'reply_markup' => json_encode(['inline_keyboard' => $join_keys])]);
            return;
        }

        $start_msg = $this->settings['text_start'] ?? '👋 سلام! به فروشگاه ما خوش آمدید.';
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $start_msg,
            'parse_mode' => 'HTML', 'reply_markup' => getUserKeyboard($this->settings)]);
    }

    private function showPlans(int $user_id, int $chat_id, array $user): void {
        $plans = $this->pdo->query("SELECT * FROM plans")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($plans) && $this->settings['custom_plan_status'] == '0') {
            $this->telegram->sendMessage($chat_id, 'در حال حاضر پلنی موجود نیست.');
            return;
        }
        $keys = [];
        foreach ($plans as $p) {
            $fp     = getFinalPrice($this->pdo, (string)$p['id'], 'none', $user_id, $this->settings);
            $keys[] = [['text' => "🛒 {$p['name']} | " . number_format($fp['final_price']) . ' T', 'callback_data' => "pay|{$p['id']}|none|name"]];
        }
        if ($this->settings['custom_plan_status'] == '1') {
            $keys[] = [['text' => '🎛 ساخت پلن دلخواه', 'callback_data' => 'custom_plan_start']];
        }
        $header = $user['is_reseller'] ? "💎 <b>تعرفه‌های ویژه نمایندگان:</b>" : "پلن مورد نظر را انتخاب کنید:";
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $header,
            'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
    }

    private function showMyServices(int $user_id, int $chat_id): void {
        $stmt = $this->pdo->prepare("SELECT * FROM orders WHERE chat_id = ? ORDER BY id DESC");
        $stmt->execute([$user_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($orders)) { $this->telegram->sendMessage($chat_id, 'سرویسی ندارید.'); return; }
        $keys = [];
        foreach ($orders as $o) { $keys[] = [['text' => "🚀 {$o['plan_name']}", 'callback_data' => "myserv_info_{$o['id']}"]]; }
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => '📦 سرویس‌های شما:', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
    }

    private function showResellerOffer(int $user_id, int $chat_id, array $user): void {
        if ($user['is_reseller']) {
            $this->telegram->sendMessage($chat_id, '✅ شما در حال حاضر نماینده ما هستید!');
            return;
        }
        $fee  = $this->settings['reseller_fee'];
        $disc = $this->settings['reseller_discount'];
        $msg  = "🤝 <b>خرید اشتراک نمایندگی</b>\n\nبا پرداخت <code>" . number_format($fee) . "</code> تومان، <b>$disc% تخفیف دائم</b> روی همه خریدها برای شما اعمال می‌شود!\n\nروش پرداخت:";
        $keys = [];
        $keys[] = [['text' => '💰 پرداخت از کیف پول', 'callback_data' => 'reseller_pay_wallet']];
        if ($this->settings['tetra_status'] == '1')  $keys[] = [['text' => '🌐 پرداخت آنلاین', 'callback_data' => 'reseller_pay_tetra']];
        if ($this->settings['card_status'] == '1')   $keys[] = [['text' => '💳 کارت به کارت',  'callback_data' => 'reseller_pay_card']];
        if ($this->settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی',   'callback_data' => 'reseller_pay_crypto']];
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg,
            'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
    }

    private function showReferral(int $user_id, int $chat_id): void {
        $bot_info  = $this->telegram->request('getMe');
        $bot_uname = $bot_info['result']['username'] ?? '';
        $ref_link  = "https://t.me/{$bot_uname}?start={$user_id}";
        $stmt      = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE invited_by = ?");
        $stmt->execute([$user_id]);
        $ref_count = $stmt->fetchColumn();

        $msg = "👥 <b>زیرمجموعه‌گیری</b>\n\n🔗 لینک اختصاصی:\n<code>$ref_link</code>\n\n👤 دعوت‌های موفق: <b>$ref_count نفر</b>\n\n<i>با دعوت از دوستان موجودی کیف پول خود را افزایش دهید.</i>";
        $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML']);
    }

    private function handleTrial(int $user_id, int $chat_id, array $user): void {
        if ($user['trial_used']) {
            $this->telegram->sendMessage($chat_id, '⛔️ قبلاً سرویس تستی دریافت کرده‌اید.');
            return;
        }
        $this->telegram->sendMessage($chat_id, '⏳ در حال ساخت سرویس تست...');

        $uuid  = Utils::generateUUIDv4();
        $email = "trial_{$user_id}_" . rand(100, 999);
        $bytes = (int)$this->settings['trial_mb'] * 1048576;
        $exp   = (time() + (int)$this->settings['trial_mins'] * 60) * 1000;

        // برای سرویس تست از سرور پیش‌فرض یا اولین سرور فعال استفاده کن
        $servers = (new ServerManager($this->pdo))->getActiveServers();
        if (!empty($servers)) {
            $srv    = $servers[0];
            $xui    = new Xui($srv['url'], $srv['user'], $srv['pass']);
            $result = $xui->addClient((int)$srv['inbound_id'], $email, $uuid, $bytes, $exp, 0, $srv['sub_domain'] ?? '');
        } else {
            // fallback به تنظیمات پنل
            $xui    = new Xui($this->settings['panel_url'], $this->settings['panel_user'], $this->settings['panel_pass']);
            $result = $xui->addClient((int)$this->settings['inbound_id'], $email, $uuid, $bytes, $exp, 0, $this->settings['sub_domain'] ?? '');
        }

        if ($result['status']) {
            $this->pdo->prepare("UPDATE users SET trial_used = 1 WHERE chat_id = ?")->execute([$user_id]);
            $this->pdo->prepare("INSERT INTO orders (chat_id, plan_name, uuid, email, link) VALUES (?, ?, ?, ?, ?)")->execute([$user_id, 'تست رایگان', $uuid, $email, $result['link']]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "🎉 <b>تست شما آماده است!</b>\n\n" . $result['link']]);
            sendReport($this->telegram, $this->settings, "🎁 <b>دریافت تست:</b>\n👤 <code>$user_id</code>");
        } else {
            $this->telegram->sendMessage($chat_id, '❌ خطای سرور: ' . ($result['msg'] ?? 'ارتباط با پنل قطع است.'));
        }
    }
}
