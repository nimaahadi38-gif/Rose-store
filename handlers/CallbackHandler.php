<?php
class CallbackHandler {
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

    public function handle(array $update, int $user_id, bool $is_admin): void {
        $call    = $update['callback_query'];
        $data    = $call['data'];
        $chat_id = (int)$call['message']['chat']['id'];
        $msg_id  = (int)$call['message']['message_id'];
        $call_id = $call['id'];

        // ادمین: افزودن کد تخفیف
        if ($data === 'add_new_discount' && $is_admin) {
            setStep($this->pdo, $user_id, 'add_disc_code');
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "لطفاً نام کد تخفیف جدید را وارد کنید:\n<i>(بدون فاصله، حروف انگلیسی)</i>\n<i>(/cancel برای انصراف)</i>"]);
            $this->telegram->answerCallback($call_id);
            return;
        }

        // ادمین: تایید/رد درخواست‌ها
        if (strpos($data, 'approve_') === 0 || strpos($data, 'reject_') === 0) {
            $this->handleApproval($call, $user_id, $chat_id, $msg_id, $call_id, $is_admin);
            return;
        }

        // ادمین: لغو/اعطای نمایندگی
        if (strpos($data, 'revoke_reseller_') === 0 && $is_admin) {
            $uid = (int)str_replace('revoke_reseller_', '', $data);
            $this->pdo->prepare("UPDATE users SET is_reseller = 0 WHERE chat_id = ?")->execute([$uid]);
            $this->telegram->answerCallback($call_id, '✅ نمایندگی لغو شد.', true);
            return;
        }
        if (strpos($data, 'make_reseller_') === 0 && $is_admin) {
            $uid = (int)str_replace('make_reseller_', '', $data);
            $this->pdo->prepare("UPDATE users SET is_reseller = 1 WHERE chat_id = ?")->execute([$uid]);
            $this->telegram->answerCallback($call_id, '✅ کاربر به نماینده ارتقا یافت.', true);
            return;
        }

        // ادمین: ویرایش متن آموزش
        if (strpos($data, 'edit_guide_') === 0 && $is_admin) {
            $os = str_replace('edit_guide_', '', $data);
            setStep($this->pdo, $user_id, "set_guide_$os");
            $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "لطفاً متن جدید آموزش را بفرستید:", 'reply_markup' => getCancelKeyboard()]);
            $this->telegram->answerCallback($call_id);
            return;
        }

        // کاربر: آموزش‌ها
        if (strpos($data, 'dl_') === 0) {
            $os         = str_replace('dl_', '', $data);
            $guide_text = $this->settings["guide_$os"] ?? 'آموزش این بخش در حال بروزرسانی است...';
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $guide_text,
                'parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
            $this->telegram->answerCallback($call_id);
            return;
        }

        // کاربر: پلن دلخواه
        if (strpos($data, 'custom_plan') === 0) {
            $this->handleCustomPlan($data, $user_id, $chat_id, $msg_id, $call_id);
            return;
        }

        // پرداخت: تایید Tetra98
        if (strpos($data, 'verify_tetra_') === 0) {
            $this->telegram->answerCallback($call_id, '⏳ در حال بررسی پرداخت...');
            $auth = str_replace('verify_tetra_', '', $data);
            $this->payment->verifyTetraPayment($auth, $user_id, $chat_id, $msg_id);
            return;
        }

        // کاربر: بررسی عضویت
        if ($data === 'check_join') {
            $status = checkForcedJoin($user_id, $this->telegram, $this->settings, $is_admin);
            if ($status === true) {
                $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
                $this->telegram->request('sendMessage', ['chat_id' => $chat_id,
                    'text' => '✅ عضویت شما تایید شد. به ربات خوش آمدید!',
                    'reply_markup' => getUserKeyboard($this->settings)]);
            } else {
                $this->telegram->answerCallback($call_id, '❌ شما هنوز در همه کانال‌ها عضو نشده‌اید!', true);
            }
            return;
        }

        // کاربر: مدیریت سرویس‌ها
        if (strpos($data, 'myserv_') === 0) {
            $this->handleMyService($data, $user_id, $chat_id, $msg_id, $call_id);
            return;
        }

        // پرداخت: نمایندگی
        if (strpos($data, 'reseller_pay_') === 0) {
            $this->handleResellerPay($data, $user_id, $chat_id, $msg_id, $call_id);
            return;
        }

        // پرداخت: شارژ کیف‌پول
        if (strpos($data, 'charge_method_') === 0) {
            $this->handleChargePay($data, $user_id, $chat_id, $msg_id, $call_id);
            return;
        }

        // کاربر: اعمال کد تخفیف
        if (strpos($data, 'apply_disc|') === 0) {
            $pid = explode('|', $data)[1];
            setStep($this->pdo, $user_id, "ask_discount|{$pid}");
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id,
                'text' => '🎟 لطفاً کد تخفیف خود را ارسال کنید:', 'reply_markup' => getCancelKeyboard()]);
            $this->telegram->answerCallback($call_id);
            return;
        }

        // پرداخت: روش خرید پلن
        if (strpos($data, 'pay|') === 0) {
            $this->handlePlanPay($data, $user_id, $chat_id, $msg_id, $call_id);
            return;
        }

        $this->telegram->answerCallback($call_id);
    }

    // ========================

    private function handleApproval(array $call, int $user_id, int $chat_id, int $msg_id, string $call_id, bool $is_admin): void {
        $data = $call['data'];
        if (!$is_admin) {
            $this->telegram->answerCallback($call_id, '⛔️ شما دسترسی لازم را ندارید!', true);
            return;
        }

        $has_photo    = isset($call['message']['photo']);
        $original_txt = $has_photo ? ($call['message']['caption'] ?? '') : ($call['message']['text'] ?? '');
        $new_text     = null;

        if (strpos($data, 'approve_reseller_') === 0 || strpos($data, 'reject_reseller_') === 0) {
            $is_approve = strpos($data, 'approve_') === 0;
            $buyer_id   = (int)str_replace(['approve_reseller_', 'reject_reseller_'], '', $data);
            if ($is_approve) {
                $this->pdo->prepare("UPDATE users SET is_reseller = 1 WHERE chat_id = ?")->execute([$buyer_id]);
                $this->telegram->sendMessage($buyer_id, "🎉 <b>تبریک! درخواست نمایندگی تایید شد.</b>");
                $new_text = "✅ درخواست نمایندگی تایید شد.\n" . $original_txt;
                sendReport($this->telegram, $this->settings, "🤝 <b>نماینده جدید:</b>\n👤 <code>$buyer_id</code>");
            } else {
                $this->telegram->sendMessage($buyer_id, "❌ <b>رسید نمایندگی شما رد شد.</b>");
                $new_text = "❌ درخواست نمایندگی رد شد.\n" . $original_txt;
            }

        } elseif (strpos($data, 'approve_charge_') === 0 || strpos($data, 'reject_charge_') === 0) {
            $is_approve = strpos($data, 'approve_') === 0;
            $parts      = explode('_', str_replace(['approve_charge_', 'reject_charge_'], '', $data));
            $buyer_id   = (int)$parts[0];
            $amount     = (int)$parts[1];
            if ($is_approve) {
                $this->pdo->prepare("UPDATE users SET wallet = wallet + ? WHERE chat_id = ?")->execute([$amount, $buyer_id]);
                $this->telegram->sendMessage($buyer_id, "✅ <b>کیف پول شما " . number_format($amount) . " تومان شارژ شد!</b>");
                $new_text = "✅ شارژ " . number_format($amount) . " تومانی تایید شد.\n" . $original_txt;
                sendReport($this->telegram, $this->settings, "💰 <b>شارژ تایید شده:</b>\n👤 <code>$buyer_id</code>\n💵 " . number_format($amount) . " تومان");
            } else {
                $this->telegram->sendMessage($buyer_id, "❌ <b>درخواست شارژ کیف پول رد شد.</b>");
                $new_text = "❌ درخواست شارژ رد شد.\n" . $original_txt;
            }

        } elseif (strpos($data, 'approve_receipt|') === 0 || strpos($data, 'reject_receipt|') === 0) {
            $is_approve = strpos($data, 'approve_') === 0;
            $parts      = explode('|', str_replace(['approve_receipt|', 'reject_receipt|'], '', $data));
            $buyer_id   = (int)$parts[0];
            $pid        = $parts[1];
            $code       = $parts[2] ?? 'none';
            if ($is_approve) {
                $status_msg = $this->payment->approveReceiptOrder($buyer_id, $pid, $code, $user_id);
                $new_text   = $status_msg . "\n" . $original_txt;
            } else {
                $this->telegram->sendMessage($buyer_id, "❌ <b>رسید خرید شما رد شد.</b> با پشتیبانی تماس بگیرید.");
                $new_text = "❌ این رسید رد شد.\n" . $original_txt;
            }
        }

        if ($new_text !== null) {
            $method = $has_photo ? 'editMessageCaption' : 'editMessageText';
            $field  = $has_photo ? 'caption' : 'text';
            $this->telegram->request($method, ['chat_id' => $chat_id, 'message_id' => $msg_id, $field => $new_text]);
        }

        $this->telegram->answerCallback($call_id);
    }

    private function handleCustomPlan(string $data, int $user_id, int $chat_id, int $msg_id, string $call_id): void {
        if ($data === 'custom_plan_start') {
            $keys = json_encode(['inline_keyboard' => [
                [['text' => '🔢 بر اساس حجم (گیگ)',     'callback_data' => 'custom_plan_by_gb']],
                [['text' => '💵 بر اساس مبلغ (تومان)', 'callback_data' => 'custom_plan_by_price']],
            ]]);
            $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id,
                'text' => "🎛 <b>ساخت پلن دلخواه</b>\n\nمبنای محاسبه را انتخاب کنید:",
                'parse_mode' => 'HTML', 'reply_markup' => $keys]);
        } elseif ($data === 'custom_plan_by_gb') {
            setStep($this->pdo, $user_id, 'ask_custom_gb');
            $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "🔢 حجم مورد نیاز خود را به <b>گیگابایت</b> وارد کنید (حداقل ۱):",
                'reply_markup' => getCancelKeyboard()]);
        } elseif ($data === 'custom_plan_by_price') {
            setStep($this->pdo, $user_id, 'ask_custom_price');
            $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "💵 مبلغی که می‌خواهید هزینه کنید را به <b>تومان</b> وارد کنید (حداقل " . number_format((int)$this->settings['custom_gb_price']) . " تومان):",
                'reply_markup' => getCancelKeyboard()]);
        }
        $this->telegram->answerCallback($call_id);
    }

    private function handleMyService(string $data, int $user_id, int $chat_id, int $msg_id, string $call_id): void {
        $action = explode('_', $data);
        $oid    = (int)($action[2] ?? 0);
        $stmt   = $this->pdo->prepare("SELECT o.*, s.url AS server_url, s.user AS server_user, s.pass AS server_pass, s.inbound_id AS server_inbound FROM orders o LEFT JOIN servers s ON s.id = o.server_id WHERE o.id = ? AND o.chat_id = ?");
        $stmt->execute([$oid, $user_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $this->telegram->answerCallback($call_id, '❌ سرویس یافت نشد.', true);
            return;
        }

        $sub_action = $action[1] ?? '';

        if ($sub_action === 'info') {
            $this->showServiceInfo($order, $user_id, $chat_id, $msg_id, $call_id);
        } elseif ($sub_action === 'link') {
            $this->telegram->sendMessage($chat_id, "🔗 <b>لینک سرویس شما:</b>\n\n<code>{$order['link']}</code>");
            $this->telegram->answerCallback($call_id);
        } elseif ($sub_action === 'qr') {
            $clean_link = strip_tags($order['link']);
            $qr_url     = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=" . urlencode($clean_link);
            $this->telegram->request('sendPhoto', ['chat_id' => $chat_id, 'photo' => $qr_url,
                'caption' => "📱 <b>QR Code سرویس شما</b>", 'parse_mode' => 'HTML']);
            $this->telegram->answerCallback($call_id);
        } else {
            $this->telegram->sendMessage($chat_id, "☎️ جهت این عملیات با ارسال شناسه <code>{$order['email']}</code> به پشتیبانی پیام دهید.", 'HTML');
            $this->telegram->answerCallback($call_id);
        }
    }

    private function showServiceInfo(array $order, int $user_id, int $chat_id, int $msg_id, string $call_id): void {
        $this->telegram->answerCallback($call_id, '⏳ در حال دریافت آمار زنده...');

        // اتصال به سرور مناسب (سرور مشخص شده یا پیش‌فرض از تنظیمات)
        if (!empty($order['server_url'])) {
            $xui = new Xui($order['server_url'], $order['server_user'], $order['server_pass']);
        } else {
            $xui = new Xui($this->settings['panel_url'], $this->settings['panel_user'], $this->settings['panel_pass']);
        }

        $stats = $xui->getClientStats($order['email']);
        $msg   = "📦 <b>اطلاعات زنده سرویس:</b>\n\n🔹 پلن: <b>{$order['plan_name']}</b>\n📧 شناسه: <code>{$order['email']}</code>\n";

        if ($stats) {
            $total   = (int)$stats['total'];
            $used    = (int)$stats['up'] + (int)$stats['down'];
            $rem     = $total - $used;
            $expiry  = $stats['expiryTime'] == 0 ? 'نامحدود' : date('Y-m-d H:i', (int)$stats['expiryTime'] / 1000);
            $status  = $stats['enable'] ? '🟢 فعال' : '🔴 غیرفعال';
            $msg .= "〰️〰️〰️〰️〰️\n"
                  . "📊 وضعیت: $status\n"
                  . "📥 مصرفی: <code>" . formatBytes($used) . "</code>\n"
                  . "🔋 باقیمانده: <code>" . ($total == 0 ? 'نامحدود' : formatBytes(max(0, $rem))) . "</code>"
                  . ($total > 0 ? " (از " . formatBytes($total) . ")" : '') . "\n"
                  . "⏳ انقضا: <code>$expiry</code>\n";
        } else {
            $msg .= "\n⚠️ <i>امکان دریافت آمار از سرور وجود ندارد.</i>";
        }

        $oid  = (int)$order['id'];
        $keys = [[['text' => '🔗 کپی لینک', 'callback_data' => "myserv_link_{$oid}"],
                  ['text' => '📱 بارکد (QR)', 'callback_data' => "myserv_qr_{$oid}"]]];
        $row2 = [];
        if ($this->settings['extra_gb_status'] == '1') $row2[] = ['text' => '➕ حجم اضافه',  'callback_data' => "myserv_extragb_{$oid}"];
        if ($this->settings['extra_ip_status'] == '1') $row2[] = ['text' => '👥 کاربر اضافه', 'callback_data' => "myserv_extraip_{$oid}"];
        if (!empty($row2)) $keys[] = $row2;
        if ($this->settings['renew_status'] == '1') $keys[] = [['text' => '🔄 تمدید سرویس', 'callback_data' => "myserv_renew_{$oid}"]];

        $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id,
            'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);
    }

    private function handleResellerPay(string $data, int $user_id, int $chat_id, int $msg_id, string $call_id): void {
        $method = str_replace('reseller_pay_', '', $data);
        $fee    = (int)$this->settings['reseller_fee'];

        if ($method === 'wallet') {
            $stmt = $this->pdo->prepare("SELECT wallet FROM users WHERE chat_id = ?");
            $stmt->execute([$user_id]);
            $wallet = (int)$stmt->fetchColumn();
            if ($wallet >= $fee) {
                $this->pdo->prepare("UPDATE users SET wallet = wallet - ?, is_reseller = 1 WHERE chat_id = ?")->execute([$fee, $user_id]);
                $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'parse_mode' => 'HTML',
                    'text' => "🎉 <b>تبریک!</b>\nحساب شما با موفقیت به <b>نمایندگی</b> ارتقا یافت. {$this->settings['reseller_discount']}% تخفیف دائم روی پلن‌ها اعمال می‌شود."]);
                sendReport($this->telegram, $this->settings, "🤝 <b>نماینده جدید (کیف پول):</b>\n👤 <code>$user_id</code>");
            } else {
                $this->telegram->answerCallback($call_id, '❌ موجودی کافی نیست!', true);
            }
        } elseif ($method === 'tetra') {
            $this->payment->initiateTetraPayment('reseller', $fee, $user_id, $chat_id, $msg_id);
        } elseif (in_array($method, ['card', 'crypto'])) {
            $msg = $method === 'card'
                ? "💳 <b>واریز به کارت (هزینه نمایندگی):</b>\n<code>{$this->settings['card_number']}</code>\n👤 {$this->settings['card_name']}\n💵 " . number_format($fee) . " تومان\n\n📸 پس از واریز عکس رسید را بفرستید."
                : "💲 <b>واریز ارزی:</b>\n{$this->settings['crypto_guide']}\n\n💳 <code>{$this->settings['crypto_address']}</code>\n💵 " . number_format($fee) . " تومان\n\n📸 رسید یا TxID را بفرستید.";
            setStep($this->pdo, $user_id, 'wait_reseller_receipt');
            $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => getCancelKeyboard()]);
        }

        $this->telegram->answerCallback($call_id);
    }

    private function handleChargePay(string $data, int $user_id, int $chat_id, int $msg_id, string $call_id): void {
        $parts  = explode('_', str_replace('charge_method_', '', $data));
        $amount = (int)$parts[0];
        $method = $parts[1] ?? 'card';

        if ($method === 'tetra') {
            $this->payment->initiateTetraPayment('charge', $amount, $user_id, $chat_id, $msg_id);
        } else {
            $this->payment->initiateChargeReceipt($method, $user_id, $chat_id, $msg_id, $amount);
        }
        $this->telegram->answerCallback($call_id);
    }

    private function handlePlanPay(string $data, int $user_id, int $chat_id, int $msg_id, string $call_id): void {
        $parts  = explode('|', $data);
        $pid    = $parts[1] ?? '';
        $code   = $parts[2] ?? 'none';
        $method = $parts[3] ?? 'name';
        $plan   = getFinalPrice($this->pdo, $pid, $code, $user_id, $this->settings);
        if (!$plan) {
            $this->telegram->answerCallback($call_id, '❌ پلن یافت نشد.', true);
            return;
        }

        if ($method === 'name') {
            setStep($this->pdo, $user_id, "ask_name|{$pid}|{$code}");
            $this->telegram->request('deleteMessage', ['chat_id' => $chat_id, 'message_id' => $msg_id]);
            $this->telegram->request('sendMessage', ['chat_id' => $chat_id, 'parse_mode' => 'HTML',
                'text' => "🔤 لطفاً یک نام انگلیسی برای کانفیگ خود وارد کنید:\n<i>(بدون فاصله، حروف انگلیسی و اعداد)</i>",
                'reply_markup' => getCancelKeyboard()]);
        } elseif ($method === 'invoice') {
            $stmt = $this->pdo->prepare("SELECT wallet, temp_name FROM users WHERE chat_id = ?");
            $stmt->execute([$user_id]);
            $row         = $stmt->fetch(PDO::FETCH_ASSOC);
            $wallet      = (int)($row['wallet'] ?? 0);
            $config_name = !empty($row['temp_name']) ? $row['temp_name'] : 'user_' . substr((string)$user_id, -4);

            $disc_note = $plan['final_price'] < $plan['price'] ? ' (با تخفیف)' : '';
            $msg = "🧾 <b>پیش‌فاکتور:</b>\n🔸 {$plan['name']}\n👤 نام: <code>$config_name</code>\n💵 مبلغ: <code>" . number_format($plan['final_price']) . "</code> تومان{$disc_note}\n\nروش پرداخت را انتخاب کنید:";

            $keys = [];
            if ($wallet >= $plan['final_price']) $keys[] = [['text' => '💰 پرداخت از کیف پول', 'callback_data' => "pay|{$pid}|{$code}|wallet"]];
            if ($this->settings['tetra_status'] == '1') $keys[] = [['text' => '🌐 پرداخت آنلاین (تترا98)', 'callback_data' => "pay|{$pid}|{$code}|tetra"]];
            if ($this->settings['card_status'] == '1')  $keys[] = [['text' => '💳 کارت به کارت', 'callback_data' => "pay|{$pid}|{$code}|card"]];
            if ($this->settings['crypto_status'] == '1') $keys[] = [['text' => '💲 پرداخت ارزی', 'callback_data' => "pay|{$pid}|{$code}|crypto"]];
            if ($code === 'none') $keys[] = [['text' => '🎟 کد تخفیف', 'callback_data' => "apply_disc|{$pid}"]];
            $this->telegram->request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id,
                'text' => $msg, 'parse_mode' => 'HTML', 'reply_markup' => json_encode(['inline_keyboard' => $keys])]);

        } elseif ($method === 'wallet') {
            $this->payment->processWalletPayment($user_id, $chat_id, $msg_id, $pid, $code);
        } elseif ($method === 'tetra') {
            $this->payment->initiateTetraPayment('plan', (int)$plan['final_price'], $user_id, $chat_id, $msg_id, $pid, $code);
        } elseif (in_array($method, ['card', 'crypto'])) {
            $this->payment->initiateReceiptPayment($method, $user_id, $chat_id, $msg_id, $pid, $code, (int)$plan['final_price']);
        }

        $this->telegram->answerCallback($call_id);
    }
}
