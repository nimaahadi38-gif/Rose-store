<?php
class Xui {
    private string $url;
    private string $user;
    private string $pass;
    private string $cookie_file;

    public function __construct(string $url, string $user, string $pass, string $cookie_file = '') {
        $this->url         = rtrim($url, '/');
        $this->user        = $user;
        $this->pass        = $pass;
        $this->cookie_file = $cookie_file ?: __DIR__ . '/../storage/cookie.txt';

        $dir = dirname($this->cookie_file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    private function curlRequest(string $endpoint, ?array $post_data = null, bool $is_json = false): ?array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($post_data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($is_json) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
            }
        }

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("[Xui] cURL error on {$endpoint}: {$error}");
            return null;
        }

        return json_decode($response, true);
    }

    public function login(): array {
        $res = $this->curlRequest('/login', ['username' => $this->user, 'password' => $this->pass]);
        if ($res && isset($res['success']) && $res['success']) {
            return ['status' => true, 'msg' => '✅ اتصال به پنل موفقیت‌آمیز بود.'];
        }
        return ['status' => false, 'msg' => '❌ ورود به پنل ناموفق بود.'];
    }

    public function addClient(int $inbound_id, string $email, string $uuid, int $bytes = 0, int $timestamp_ms = 0, int $ip_limit = 0, string $sub_domain = ''): array {
        $login = $this->login();
        if (!$login['status']) return $login;

        $sub_id = bin2hex(random_bytes(8));

        $payload = [
            'id'       => $inbound_id,
            'settings' => json_encode(['clients' => [[
                'id'         => $uuid,
                'email'      => $email,
                'enable'     => true,
                'limitIp'    => $ip_limit,
                'totalGB'    => $bytes,
                'expiryTime' => $timestamp_ms,
                'tgId'       => '',
                'subId'      => $sub_id,
            ]]]),
        ];

        $res = $this->curlRequest('/panel/api/inbounds/addClient', $payload, true);

        if ($res && isset($res['success']) && $res['success']) {
            return $this->buildClientResult($inbound_id, $email, $uuid, $sub_id, $sub_domain);
        }

        return ['status' => false, 'msg' => '❌ خطا در ساخت کاربر: ' . ($res['msg'] ?? 'ناشناخته')];
    }

    private function buildClientResult(int $inbound_id, string $email, string $uuid, string $sub_id, string $sub_domain): array {
        $clean_sub = $this->resolveSubDomain($sub_domain);
        $sub_link  = rtrim($clean_sub, '/') . '/sub/' . $sub_id;

        $extracted_config = $this->fetchRawConfig($sub_id, $clean_sub);

        if (empty($extracted_config)) {
            $inbound_data     = $this->curlRequest('/panel/api/inbounds/get/' . $inbound_id);
            $extracted_config = $this->buildConfigFromInbound($inbound_data, $uuid, $email, $clean_sub);
        }

        $final_msg = "🌐 <b>لینک سابسکریپشن (پیشنهاد ویژه):</b>\n<code>{$sub_link}</code>\n\n"
                   . "⚡️ <b>کانفیگ خام:</b>\n<code>{$extracted_config}</code>\n\n"
                   . "<i>(توصیه می‌شود لینک اول (سابسکریپشن) را در برنامه خود آپدیت کنید)</i>";

        return ['status' => true, 'link' => $final_msg, 'sub_link' => $sub_link];
    }

    private function resolveSubDomain(string $sub_domain): string {
        $clean = trim($sub_domain);
        if (empty($clean) || $clean === 'وارد نشده') {
            $parsed = parse_url($this->url);
            $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            return $parsed['scheme'] . '://' . $parsed['host'] . $port;
        }
        if (!preg_match("~^https?://~i", $clean)) {
            $clean = 'http://' . $clean;
        }
        return $clean;
    }

    private function fetchRawConfig(string $sub_id, string $clean_sub): string {
        $internal_sub = rtrim($this->url, '/') . '/sub/' . $sub_id;
        $ch           = curl_init($internal_sub);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $sub_content = curl_exec($ch);
        curl_close($ch);

        if (!$sub_content) return '';

        $decoded = base64_decode(trim($sub_content));
        if (!$decoded || !preg_match('/^(vless|vmess|trojan|ss):\/\//i', $decoded)) return '';

        $lines  = explode("\n", trim($decoded));
        $config = trim($lines[0]);

        $domain_host = parse_url($clean_sub, PHP_URL_HOST);
        if ($domain_host) {
            $config = preg_replace('/(@)([^:]+)(:)/', '${1}' . $domain_host . '${3}', $config);
        }
        return $config;
    }

    private function buildConfigFromInbound(?array $inbound_data, string $uuid, string $email, string $clean_sub): string {
        $domain_host = parse_url($clean_sub, PHP_URL_HOST);
        $host        = $domain_host ?: parse_url($this->url, PHP_URL_HOST);
        $port        = 443;
        $protocol    = 'vless';
        $params      = [];

        if ($inbound_data && isset($inbound_data['success']) && $inbound_data['success'] && isset($inbound_data['obj'])) {
            $obj    = $inbound_data['obj'];
            $port   = $obj['port'];
            $protocol = $obj['protocol'] ?? 'vless';
            $stream = json_decode($obj['streamSettings'], true) ?? [];

            $net = $stream['network'] ?? 'tcp';
            $sec = $stream['security'] ?? 'none';
            $params['type']     = $net;
            $params['security'] = $sec;

            if ($sec === 'tls') {
                $tls = $stream['tlsSettings'] ?? [];
                $params['sni'] = $tls['serverName'] ?? '';
                $params['fp']  = $tls['fingerprint'] ?? 'chrome';
                if (!empty($tls['alpn'])) {
                    $params['alpn'] = is_array($tls['alpn']) ? implode(',', $tls['alpn']) : $tls['alpn'];
                }
            } elseif ($sec === 'reality') {
                $real = $stream['realitySettings'] ?? [];
                $params['sni'] = $real['serverNames'][0] ?? '';
                $params['fp']  = $real['fingerprint'] ?? 'chrome';
                $params['pbk'] = $real['settings']['publicKey'] ?? '';
                $params['sid'] = $real['shortIds'][0] ?? '';
                $params['spx'] = $real['settings']['spiderX'] ?? '/';
            }

            if ($net === 'ws') {
                $ws = $stream['wsSettings'] ?? [];
                $params['path'] = $ws['path'] ?? '/';
                $params['host'] = $ws['headers']['Host'] ?? $host;
            } elseif ($net === 'grpc') {
                $params['serviceName'] = $stream['grpcSettings']['serviceName'] ?? '';
                $params['mode']        = 'multi';
            }
        }

        $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
        $query  = http_build_query($params);
        return "{$protocol}://{$uuid}@{$host}:{$port}?{$query}#{$email}";
    }

    public function getClientStats(string $email): ?array {
        $login = $this->login();
        if (!$login['status']) return null;

        $res = $this->curlRequest('/panel/api/inbounds/getClientTraffics/' . urlencode($email));
        if ($res && isset($res['success']) && $res['success'] && isset($res['obj'])) {
            return $res['obj'];
        }
        return null;
    }

    public function getInboundClients(int $inbound_id): array {
        $login = $this->login();
        if (!$login['status']) return [];

        $res = $this->curlRequest('/panel/api/inbounds/get/' . $inbound_id);
        if (!$res || !isset($res['success']) || !$res['success'] || !isset($res['obj'])) return [];

        $settings = json_decode($res['obj']['settings'] ?? '{}', true);
        return $settings['clients'] ?? [];
    }

    public function getInboundList(): array {
        $login = $this->login();
        if (!$login['status']) return [];

        $res = $this->curlRequest('/panel/api/inbounds/list');
        if ($res && isset($res['success']) && $res['success'] && isset($res['obj'])) {
            return $res['obj'];
        }
        return [];
    }

    public function testConnection(): bool {
        $res = $this->login();
        return $res['status'];
    }
}
