<?php
class Telegram {
    private $token;
    private $proxy;
    private $proxy_auth;

    public function __construct($token, $proxy, $proxy_auth = '') {
        $this->token = $token;
        $this->proxy = $proxy;
        $this->proxy_auth = $proxy_auth;
    }

    public function request($method, $data = []) {
        $url = "https://api.telegram.org/bot" . $this->token . "/" . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // تغییر مهم: فقط اگر دیتایی داریم از POST استفاده کن
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        
        // تغییر مهم: استفاده از SOCKS5_HOSTNAME برای رفع مشکل DNS در هاست ایران
        curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        
        if (!empty($this->proxy_auth)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy_auth);
        }
        
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // تعیین تایم‌اوت اختصاصی

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // سیستم نمایش خطای خاموش
        if ($response === false) {
            return ['ok' => false, 'error_code' => 'CURL_ERROR', 'description' => $curl_error];
        }
        
        return json_decode($response, true);
    }

    public function sendMessage($chat_id, $text, $parse_mode = 'Markdown') {
        return $this->request('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => $parse_mode
        ]);
    }
}