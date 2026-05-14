<?php
/**
 * Telegram WebApp HMAC Authentication
 * الگوریتم رسمی Telegram برای اعتبارسنجی initData
 */
class TelegramWebAuth {
    private string $bot_token;
    private int    $max_age_seconds;

    public function __construct(string $bot_token, int $max_age_seconds = 86400) {
        $this->bot_token       = $bot_token;
        $this->max_age_seconds = $max_age_seconds;
    }

    /**
     * اعتبارسنجی initData که از Telegram WebApp می‌آید
     * @return array|null — آرایه user یا null در صورت شکست
     */
    public function validate(string $init_data): ?array {
        parse_str($init_data, $params);

        $received_hash = $params['hash'] ?? '';
        if (empty($received_hash)) return null;

        // بررسی زمان (anti-replay)
        $auth_date = (int)($params['auth_date'] ?? 0);
        if ((time() - $auth_date) > $this->max_age_seconds) return null;

        // ساخت data-check-string: همه پارامترها به جز hash، مرتب‌شده
        unset($params['hash']);
        ksort($params);
        $data_check = implode("\n", array_map(fn($k, $v) => "{$k}={$v}", array_keys($params), $params));

        // محاسبه secret_key و hash
        $secret_key    = hash_hmac('sha256', $this->bot_token, 'WebAppData', true);
        $computed_hash = hash_hmac('sha256', $data_check, $secret_key);

        if (!hash_equals($computed_hash, strtolower($received_hash))) return null;

        // استخراج اطلاعات کاربر
        $user_json = $params['user'] ?? '{}';
        $user      = json_decode($user_json, true);
        if (!$user || empty($user['id'])) return null;

        $user['auth_date'] = $auth_date;
        return $user;
    }
}
