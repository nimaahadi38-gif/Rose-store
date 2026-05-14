<?php
class Telegram {
    private string $token;
    private string $proxy;
    private string $proxy_auth;

    public function __construct(string $token, string $proxy, string $proxy_auth = '') {
        $this->token      = $token;
        $this->proxy      = $proxy;
        $this->proxy_auth = $proxy_auth;
    }

    public function request(string $method, array $data = [], int $max_retries = 3): array {
        $url = "https://api.telegram.org/bot{$this->token}/{$method}";

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }

            curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);

            if (!empty($this->proxy_auth)) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy_auth);
            }

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);

            $response   = curl_exec($ch);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                error_log("[Telegram] cURL error on {$method}: {$curl_error}");
                return ['ok' => false, 'description' => $curl_error];
            }

            $decoded = json_decode($response, true);
            if ($decoded === null) {
                return ['ok' => false, 'description' => 'Invalid JSON response'];
            }

            // مدیریت محدودیت نرخ (429)
            if (!$decoded['ok'] && ($decoded['error_code'] ?? 0) === 429) {
                $wait = (int)($decoded['parameters']['retry_after'] ?? (2 ** $attempt));
                sleep(min($wait, 30));
                continue;
            }

            return $decoded;
        }

        return ['ok' => false, 'description' => 'Max retries exceeded'];
    }

    public function sendMessage(int $chat_id, string $text, string $parse_mode = 'HTML'): array {
        return $this->request('sendMessage', [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => $parse_mode,
        ]);
    }

    public function answerCallback(string $callback_id, string $text = '', bool $alert = false): void {
        $this->request('answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text'              => $text,
            'show_alert'        => $alert,
        ]);
    }

    public function getUpdates(int $offset = 0, int $timeout = 60): array {
        $url = "https://api.telegram.org/bot{$this->token}/getUpdates";
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'offset'  => $offset,
            'timeout' => $timeout,
            'limit'   => 100,
        ]));
        curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        if (!empty($this->proxy_auth)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy_auth);
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout + 15);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("[Telegram::getUpdates] cURL error: {$err}");
            return [];
        }
        $decoded = json_decode($response, true);
        if (!$decoded || !$decoded['ok'] || empty($decoded['result'])) {
            return [];
        }
        return $decoded['result'];
    }

    public function deleteWebhook(): void {
        $this->request('deleteWebhook', ['drop_pending_updates' => false]);
    }
}
