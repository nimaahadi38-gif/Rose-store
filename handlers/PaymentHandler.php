<?php
/**
 * سرویس پرداخت — منطق کیف پول، Tetra98، کارت، ارزی
 * تمام متدها توسط CallbackHandler و UserHandler فراخوانی می‌شوند
 */
class PaymentHandler {
    private PDO $pdo;
    private Telegram $telegram;
    private array $settings;
    private array $config;
    private ServerManager $serverManager;

    // پنجره زمانی معتبر برای ارسال رسید (ثانیه)
    const RECEIPT_WINDOW_SECONDS = 1800;

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

    // ========================
    // ساخت سرویس روی XUI
    // ========================

    public function createService(array $plan, int $buyer_id): array {
        $uuid   = Utils::generateUUIDv4();
        $stmt   = $this->pdo->prepare("SELECT temp_name FROM users WHERE chat_id = ?");
        $stmt->execute([$buyer_id]);
        $tmp_name  = $stmt->fetchColumn();
        $base_name = !empty($tmp_name) ? $tmp_name : 'user_' . substr((string)$buyer_id, -4);
        $email     = $base_name . '_' . rand(1000, 9999);

        $bytes   = $plan['gb'] > 0 ? $plan['gb'] * 1073741824 : 0;
        $exp_ms  = $plan['days'] > 0 ? (time() + $plan['days'] * 86400) * 1000 : 0;

        $result = $this->serverManager->addClientWithFallback($email, $uuid, $bytes, $exp_ms);

        if ($result['status']) {
            $server_id = (int)($result['server_id'] ?? 0);
            $this->pdo->prepare(
                "INSERT INTO orders (chat_id, plan_name, uuid, email, link, server_id)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$buyer_id, $plan['name'], $uuid, $email, $result['link'], $server_id ?: null]);

            // پاداش زیرمجموعه برای اولین خرید
            $this->maybeRewardReferrer($buyer_id);
        }

        return $result;
    }

    private function maybeRewardReferrer(int $buyer_id): void {
        $reward = (int)($this->settings['referral_reward'] ?? 0);
        if ($reward <= 0) return;

        $stmt = $this->pdo->prepare("SELECT invited_by FROM users WHERE chat_id = ?");
        $stmt->execute([$buyer_id]);
        $inviter = $stmt->fetchColumn();
        if (!$inviter) return;

        // فقط برای اولین خرید پاداش بده
        $stmt2 = $this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE chat_id = ?");
        $stmt2->execute([$buyer_id]);
        if ($stmt2->fetchColumn() != 1) return;

        $this->pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$reward, $inviter]);
        $this->telegram->sendMessage((int)$inviter, "🎉 <b>تبریک!</b>\nیکی از زیرمجموعه‌های شما اولین خرید را انجام داد و <code>" . number_format($reward) . "</code> تومان به کیف پول شما اضافه شد.");
    }

    // ========================
    // پرداخت از کیف پول
    // ========================

    public function processWalletPayment(int $user_id, int $chat_id, int $msg_id, string $pid, string $code): void {
        $plan = getFinalPrice($this->pdo, $pid, $code, $user_id, $this->settings);
        if (!$plan) return;

        $stmt = $this->pdo->prepare("SELECT wallet FROM users WHERE chat_id = ?");
        $stmt->execute([$user_id]);
        $wallet = (int)$stmt->fetchColumn();

        if ($wallet < $plan['final_price']) {
            $this->telegram->answerCallback('', '❌ موجودی کافی نیست!', true);
            return;
        }

        $this->pdo->prepare("UPDATE users SET wallet = wallet - ? WHERE chat_id = ?")->execute([$plan['final_price'], $user_id]);
        $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => '⏳ در حال ساخت سرویس در سرور...']);

        $result = $this->createService($plan, $user_id);

        if ($result['status']) {
            $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $this->telegram->request('sendMessage', [
                'chat_id'      => $chat_id,
                'text'         => "✅ <b>خرید موفق!</b>\n\n{$result['link']}",
                'parse_mode'   => 'HTML',
                'reply_markup' => getUserKeyboard($this->settings),
            ]);
            sendReport($this->telegram, $this->settings,
                "🛍 <b>خرید جدید (کیف پول):</b>\n👤 کاربر: <code>$user_id</code>\n📦 پلن: {$plan['name']}\n💵 " . number_format($plan['final_price']) . " تومان");
        } else {
            // بازگشت پول در صورت شکست
            $this->pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$plan['final_price'], $user_id]);
            $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => '❌ خطا سرور! ارتباط قطع است. پول برگشت داده شد.']);
        }
    }

    // ========================
    // درگاه Tetra98
    // ========================

    public function initiateTetraPayment(
        string $type,
        int $amount,
        int $user_id,
        int $chat_id,
        int $msg_id,
        string $plan_id = '',
        string $code = 'none'
    ): void {
        $key = trim($this->settings['tetra_api_key'] ?? '');
        if (empty($key) || $key === 'وارد نشده') {
            $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => '❌ خطای ادمین: کلید API تترا98 تنظیم نشده!']);
            return;
        }

        $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => '⏳ در حال اتصال به درگاه آنلاین...']);

        $hash_prefix = strtoupper(substr($type, 0, 1)) . '_';
        $payload = [
            'ApiKey'      => $key,
            'Hash_id'     => $hash_prefix . $user_id . '_' . time(),
            'Amount'      => $amount,
            'Description' => $this->tetraDescription($type),
            'Email'       => 'user@site.com',
            'Mobile'      => '09120000000',
            'CallbackURL' => 'https://t.me',
        ];

        $res = tetraRequest('create_order', $payload, $this->config['proxy_url'], $this->config['proxy_auth'] ?? '');

        if (isset($res['status']) && $res['status'] == 100) {
            $this->pdo->prepare(
                "INSERT INTO transactions (chat_id, authority, amount, type, plan_id, code, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([$user_id, $res['Authority'], $amount, $type, $plan_id, $code]);

            $keys = json_encode(['inline_keyboard' => [
                [['text' => '💳 پرداخت فاکتور', 'url' => $res['payment_url_web']]],
                [['text' => '✅ بررسی پرداخت', 'callback_data' => "verify_tetra_{$res['Authority']}"]],
            ]]);
            $this->telegram->request('editMessageText', [
                'chat_id'      => $chat_id,
                'message_id'   => $msg_id,
                'text'         => 'فاکتور صادر شد. پس از پرداخت روی بررسی کلیک کنید:',
                'reply_markup' => $keys,
            ]);
        } else {
            $err = $res['error_details'] ?? ($res['message'] ?? json_encode($res, JSON_UNESCAPED_UNICODE));
            $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id,
                'text' => "❌ خطا در اتصال به درگاه تترا98!\n\n<code>$err</code>", 'parse_mode' => 'HTML']);
        }
    }

    private function tetraDescription(string $type): string {
        $map = ['plan' => 'خرید پلن', 'charge' => 'شارژ کیف پول', 'reseller' => 'ارتقا به نمایندگی'];
        return $map[$type] ?? 'پرداخت';
    }

    public function verifyTetraPayment(string $authority, int $user_id, int $chat_id, int $msg_id): void {
        $stmt = $this->pdo->prepare("SELECT * FROM transactions WHERE authority = ? AND status = 'pending'");
        $stmt->execute([$authority]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$txn) {
            $this->telegram->sendMessage($chat_id, '❌ تراکنش یافت نشد یا قبلاً تایید شده است.');
            return;
        }

        $payload = ['authority' => $authority, 'ApiKey' => trim($this->settings['tetra_api_key'] ?? '')];
        $res     = tetraRequest('verify', $payload, $this->config['proxy_url'], $this->config['proxy_auth'] ?? '');

        if (isset($res['status']) && $res['status'] == 100) {
            $this->pdo->prepare("UPDATE transactions SET status = 'paid' WHERE id = ?")->execute([$txn['id']]);
            $this->processVerifiedTransaction($txn, $chat_id, $msg_id);
            sendReport($this->telegram, $this->settings,
                "🟢 پرداخت آنلاین موفق (تترا98)\nمبلغ: " . number_format($txn['amount']) . " تومان\nکاربر: <code>{$txn['chat_id']}</code>");
        } else {
            $err = $res['error_details'] ?? ($res['message'] ?? 'تراکنش پرداخت نشده یا منقضی شده است');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id,
                'text' => "❌ خطا در بررسی پرداخت:\n<code>$err</code>", 'parse_mode' => 'HTML']);
        }
    }

    private function processVerifiedTransaction(array $txn, int $chat_id, int $msg_id): void {
        $buyer_id = (int)$txn['chat_id'];

        if ($txn['type'] === 'charge') {
            $this->pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$txn['amount'], $buyer_id]);
            $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id,
                'text' => '✅ پرداخت تایید شد! کیف پول شما مبلغ ' . number_format($txn['amount']) . ' تومان شارژ شد.']);

        } elseif ($txn['type'] === 'reseller') {
            $this->pdo->prepare("UPDATE users SET is_reseller = 1 WHERE chat_id = ?")->execute([$buyer_id]);
            $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id,
                'text' => '✅ پرداخت موفق! شما به نماینده ارتقا یافتید.']);

        } elseif ($txn['type'] === 'plan') {
            $plan   = getFinalPrice($this->pdo, $txn['plan_id'], $txn['code'], $buyer_id, $this->settings);
            $result = $this->createService($plan, $buyer_id);

            if ($result['status']) {
                $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id,
                    'text' => "✅ <b>پرداخت موفق!</b>\n\nسرویس شما ایجاد شد:\n" . $result['link'],
                    'parse_mode' => 'HTML']);
            } else {
                $this->pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$txn['amount'], $buyer_id]);
                $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id,
                    'text' => '✅ پرداخت تایید شد اما سرور قطع است. مبلغ به کیف پول شما برگشت داده شد.']);
            }
        }
    }

    // ========================
    // پرداخت کارت/ارزی — شروع فرآیند رسید
    // ========================

    /**
     * شروع فرآیند پرداخت کارت یا ارزی برای خرید پلن
     * یک تراکنش pending ایجاد می‌کند و پنجره زمانی را تثبیت می‌کند
     */
    public function initiateReceiptPayment(
        string $method,
        int $user_id,
        int $chat_id,
        int $msg_id,
        string $pid,
        string $code,
        int $amount
    ): void {
        // ثبت تراکنش pending برای تایمر ضدتقلب
        $authority = Utils::generateAuthority(strtoupper($method), $user_id);
        $this->pdo->prepare(
            "INSERT INTO transactions (chat_id, authority, amount, type, plan_id, code, status)
             VALUES (?, ?, ?, 'plan', ?, ?, 'awaiting_receipt')"
        )->execute([$user_id, $authority, $amount, $pid, $code]);

        $msg = $this->buildPaymentInstructionMsg($method, $amount);

        setStep($this->pdo, $user_id, "wait_receipt|{$pid}|{$code}|{$method}|{$authority}");
        $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
        $this->telegram->request('sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => getCancelKeyboard(),
        ]);
    }

    public function initiateChargeReceipt(
        string $method,
        int $user_id,
        int $chat_id,
        int $msg_id,
        int $amount
    ): void {
        $authority = Utils::generateAuthority('CHG_' . strtoupper($method), $user_id);
        $this->pdo->prepare(
            "INSERT INTO transactions (chat_id, authority, amount, type, status) VALUES (?, ?, ?, 'charge', 'awaiting_receipt')"
        )->execute([$user_id, $authority, $amount]);

        $msg = $this->buildPaymentInstructionMsg($method, $amount);
        setStep($this->pdo, $user_id, "wait_charge_receipt_{$amount}|{$authority}");
        $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
        $this->telegram->request('sendMessage', [
            'chat_id'      => $chat_id,
            'text'         => $msg,
            'parse_mode'   => 'HTML',
            'reply_markup' => getCancelKeyboard(),
        ]);
    }

    private function buildPaymentInstructionMsg(string $method, int $amount): string {
        if ($method === 'card') {
            return "💳 <b>واریز به کارت:</b>\n<code>{$this->settings['card_number']}</code>\n"
                 . "👤 {$this->settings['card_name']}\n💵 مبلغ: " . number_format($amount) . " تومان\n\n"
                 . "📸 <b>پس از واریز، عکس رسید را اینجا بفرستید.</b>\n"
                 . "<i>(رسید فقط تا ۳۰ دقیقه معتبر است)</i>";
        }
        return "💲 <b>واریز ارزی:</b>\n{$this->settings['crypto_guide']}\n\n"
             . "💳 <b>آدرس ولت:</b>\n<code>{$this->settings['crypto_address']}</code>\n"
             . "💵 مبلغ: " . number_format($amount) . " تومان\n\n"
             . "📸 <b>پس از واریز، متن کد Hash (TxID) یا عکس رسید را بفرستید.</b>\n"
             . "<i>(رسید فقط تا ۳۰ دقیقه معتبر است)</i>";
    }

    // ========================
    // پردازش رسید ارسالی توسط کاربر (Task 5)
    // ========================

    public function handleReceiptSubmission(array $update, int $user_id, int $chat_id, string $step): void {
        $photo_id       = isset($update['message']['photo'])
                          ? end($update['message']['photo'])['file_id']
                          : null;
        $file_unique_id = isset($update['message']['photo'])
                          ? end($update['message']['photo'])['file_unique_id']
                          : null;
        $text_hash      = $update['message']['text'] ?? ($update['message']['caption'] ?? null);

        if (!$photo_id && empty($text_hash)) {
            $this->telegram->sendMessage($chat_id, '❌ لطفاً فقط **تصویر فیش واریزی** یا **متن کد Hash/TxID** را ارسال کنید.');
            return;
        }

        [$type, $pid, $code, $method, $authority] = $this->parseReceiptStep($step);

        if ($authority && !$this->validateReceiptTiming($authority)) {
            setStep($this->pdo, $user_id, null);
            $this->telegram->request('sendMessage', [
                'chat_id'      => $chat_id,
                'text'         => "⏰ <b>مهلت ارسال رسید منقضی شد.</b>\nلطفاً مجدداً از منوی خرید اقدام کنید.",
                'parse_mode'   => 'HTML',
                'reply_markup' => getUserKeyboard($this->settings),
            ]);
            return;
        }

        if ($photo_id && $file_unique_id && !$this->validateReceiptHash($file_unique_id)) {
            setStep($this->pdo, $user_id, null);
            $this->telegram->request('sendMessage', [
                'chat_id'      => $chat_id,
                'text'         => "⚠️ <b>این رسید قبلاً ثبت شده است.</b>\nلطفاً با پشتیبانی تماس بگیرید.",
                'parse_mode'   => 'HTML',
                'reply_markup' => getUserKeyboard($this->settings),
            ]);
            return;
        }

        // ذخیره هش فایل در تراکنش
        if ($file_unique_id && $authority) {
            $receipt_hash = hash('sha256', $file_unique_id);
            $this->pdo->prepare(
                "UPDATE transactions SET receipt_file_hash = ?, receipt_submitted_at = NOW() WHERE authority = ?"
            )->execute([$receipt_hash, $authority]);
        }

        $this->forwardReceiptToAdmin($update, $user_id, $chat_id, $type, $pid, $code, $method, $photo_id, $text_hash);
    }

    private function parseReceiptStep(string $step): array {
        if (strpos($step, 'wait_receipt|') === 0) {
            $parts     = explode('|', str_replace('wait_receipt|', '', $step));
            $pid       = $parts[0] ?? '';
            $code      = $parts[1] ?? 'none';
            $method    = $parts[2] ?? 'card';
            $authority = $parts[3] ?? null;
            return ['plan', $pid, $code, $method, $authority];
        }

        if (strpos($step, 'wait_charge_receipt_') === 0) {
            $raw    = str_replace('wait_charge_receipt_', '', $step);
            $parts  = explode('|', $raw);
            $amount = $parts[0] ?? '0';
            $auth   = $parts[1] ?? null;
            return ['charge', $amount, 'none', 'card', $auth];
        }

        if ($step === 'wait_reseller_receipt') {
            return ['reseller', '', 'none', 'card', null];
        }

        return ['unknown', '', 'none', 'card', null];
    }

    /** بررسی پنجره ۳۰ دقیقه‌ای */
    private function validateReceiptTiming(string $authority): bool {
        $stmt = $this->pdo->prepare("SELECT created_at FROM transactions WHERE authority = ?");
        $stmt->execute([$authority]);
        $created_at = $stmt->fetchColumn();
        if (!$created_at) return true; // اگر تراکنش یافت نشد، اجازه ارسال بده
        return (time() - strtotime($created_at)) <= self::RECEIPT_WINDOW_SECONDS;
    }

    /** بررسی تکراری نبودن رسید */
    private function validateReceiptHash(string $file_unique_id): bool {
        $hash = hash('sha256', $file_unique_id);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM transactions WHERE receipt_file_hash = ? AND status IN ('pending','paid','awaiting_receipt')");
        $stmt->execute([$hash]);
        return $stmt->fetchColumn() == 0;
    }

    private function forwardReceiptToAdmin(
        array $update,
        int $user_id,
        int $chat_id,
        string $type,
        string $pid,
        string $code,
        string $method,
        ?string $photo_id,
        ?string $text_hash
    ): void {
        $admin_dest   = !empty($this->settings['admin_channel']) ? $this->settings['admin_channel'] : $this->config['admin_id'];
        $username_raw = $update['message']['from']['username'] ?? '';
        $username_txt = !empty($username_raw) ? '@' . htmlspecialchars($username_raw) : 'ندارد';
        $hash_block   = $text_hash ? "\n🔗 <b>توضیحات/Hash:</b>\n<code>" . htmlspecialchars($text_hash) . "</code>" : '';

        if ($type === 'reseller') {
            $fee     = $this->settings['reseller_fee'];
            $caption = "🧾 <b>رسید خرید اشتراک نمایندگی:</b>\n👤 آیدی: <code>$user_id</code> ($username_txt)\n💵 مبلغ: " . number_format($fee) . " تومان{$hash_block}";
            $keys    = json_encode(['inline_keyboard' => [
                [['text' => '✅ تایید نمایندگی', 'callback_data' => "approve_reseller_{$user_id}"]],
                [['text' => '❌ رد کردن',        'callback_data' => "reject_reseller_{$user_id}"]],
            ]]);

        } elseif ($type === 'charge') {
            $amount  = (int)$pid;
            $caption = "🧾 <b>رسید شارژ کیف پول:</b>\n👤 آیدی: <code>$user_id</code> ($username_txt)\n💵 مبلغ: " . number_format($amount) . " تومان{$hash_block}";
            $keys    = json_encode(['inline_keyboard' => [
                [['text' => '✅ تایید شارژ', 'callback_data' => "approve_charge_{$user_id}_{$amount}"]],
                [['text' => '❌ رد کردن',    'callback_data' => "reject_charge_{$user_id}_{$amount}"]],
            ]]);

        } else {
            $plan        = getFinalPrice($this->pdo, $pid, $code, $user_id, $this->settings);
            if (!$plan) return;
            $method_fa   = $method === 'crypto' ? 'ارزی (تتر)' : 'کارت به کارت';
            $disc_text   = '';
            if ($code !== 'none') {
                $stmt = $this->pdo->prepare("SELECT percent FROM discounts WHERE code = ?");
                $stmt->execute([$code]);
                $pct = $stmt->fetchColumn();
                if ($pct) $disc_text = "\n🎟 <b>کد تخفیف:</b> <code>$code</code> ($pct%)";
            }
            $caption = "🧾 <b>رسید واریز ($method_fa):</b>\n👤 آیدی: <code>$user_id</code> ($username_txt)\n🛍 پلن: <b>{$plan['name']}</b>{$disc_text}\n💵 " . number_format($plan['final_price']) . " تومان{$hash_block}";
            $keys    = json_encode(['inline_keyboard' => [
                [['text' => '✅ تایید و تحویل اتوماتیک', 'callback_data' => "approve_receipt|{$user_id}|{$pid}|{$code}"]],
                [['text' => '❌ رد کردن',                'callback_data' => "reject_receipt|{$user_id}|{$pid}|{$code}"]],
            ]]);
        }

        if ($photo_id) {
            $res = $this->telegram->request('sendPhoto', ['chat_id' => $admin_dest, 'photo' => $photo_id, 'caption' => $caption, 'parse_mode' => 'HTML', 'reply_markup' => $keys]);
        } else {
            $res = $this->telegram->request('sendMessage', ['chat_id' => $admin_dest, 'text' => $caption, 'parse_mode' => 'HTML', 'reply_markup' => $keys]);
        }

        if (isset($res['ok']) && $res['ok']) {
            setStep($this->pdo, $user_id, null);
            $this->telegram->request('sendMessage', [
                'chat_id'      => $chat_id,
                'text'         => '✅ رسید شما با موفقیت ارسال شد. پس از بررسی مدیریت، عملیات به صورت خودکار تایید خواهد شد.',
                'reply_markup' => getUserKeyboard($this->settings),
            ]);
        } else {
            $this->telegram->sendMessage($chat_id, '❌ متاسفانه در ارسال فیش به مدیریت خطایی رخ داد. لطفاً مجدداً تلاش کنید.');
        }
    }

    // ========================
    // تایید/رد رسید توسط ادمین
    // ========================

    public function approveReceiptOrder(int $buyer_id, string $pid, string $code, int $admin_chat_id): string {
        $plan = getFinalPrice($this->pdo, $pid, $code, $buyer_id, $this->settings);
        if (!$plan) return '❌ پلن یافت نشد.';

        $result = $this->createService($plan, $buyer_id);

        if ($result['status']) {
            $this->telegram->request('sendMessage', ['chat_id' => $buyer_id,
                'text' => "✅ <b>رسید شما تایید شد!</b>\n\n🎉 سرویس شما:\n\n" . $result['link'] . "\n\n<i>(در بخش «سرویس‌های من» نیز ذخیره شد)</i>",
                'parse_mode' => 'HTML']);
            sendReport($this->telegram, $this->settings, "🛍 <b>خرید تایید شده (رسید):</b>\n👤 کاربر: <code>$buyer_id</code>\n📦 پلن: {$plan['name']}");
            return '✅ تایید و تحویل مشتری شد.';
        }

        $this->telegram->sendMessage($buyer_id, '❌ خطا در سرور هنگام تحویل. لطفاً با پشتیبانی تماس بگیرید.');
        return '❌ خطا در ساخت سرویس.';
    }
}
