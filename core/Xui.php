<?php
class Xui {
    private $url;
    private $user;
    private $pass;
    private $cookie_file;

    public function __construct($url, $user, $pass) {
        $this->url = rtrim($url, '/');
        $this->user = $user;
        $this->pass = $pass;
        $this->cookie_file = __DIR__ . '/../storage/cookie.txt'; 
    }

    private function curl_request($endpoint, $post_data = null, $is_json = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->url . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

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
        curl_close($ch);
        return json_decode($response, true);
    }

    public function login() {
        $data = ['username' => $this->user, 'password' => $this->pass];
        $res = $this->curl_request('/login', $data);
        if (isset($res['success']) && $res['success'] == true) return ['status' => true, 'msg' => '✅ اتصال به پنل موفقیت‌آمیز بود.'];
        return ['status' => false, 'msg' => '❌ ورود به پنل ناموفق بود.'];
    }

    public function addClient($inbound_id, $email, $uuid, $bytes = 0, $timestamp_ms = 0, $ip_limit = 0, $sub_domain = '') {
        $login = $this->login();
        if (!$login['status']) return $login;

        $sub_id = bin2hex(random_bytes(8));

        $client_settings = [
            "id" => $uuid,
            "email" => $email,
            "enable" => true,
            "limitIp" => (int)$ip_limit,
            "totalGB" => $bytes,
            "expiryTime" => $timestamp_ms,
            "tgId" => "",
            "subId" => $sub_id
        ];

        $payload = [
            "id" => (int)$inbound_id, 
            "settings" => json_encode(["clients" => [$client_settings]])
        ];

        $res = $this->curl_request('/panel/api/inbounds/addClient', $payload, true);

        if (isset($res['success']) && $res['success'] == true) {
            $inbound_data = $this->curl_request('/panel/api/inbounds/get/' . $inbound_id);
            
            $clean_sub_domain = trim($sub_domain);
            if (empty($clean_sub_domain) || $clean_sub_domain == 'وارد نشده') {
                $parsed_url = parse_url($this->url);
                $clean_sub_domain = $parsed_url['scheme'] . '://' . $parsed_url['host'] . (isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
            } else {
                if (!preg_match("~^(?:f|ht)tps?://~i", $clean_sub_domain)) {
                    $clean_sub_domain = "http://" . $clean_sub_domain;
                }
            }
            
            $clean_sub_domain = rtrim($clean_sub_domain, '/');
            $sub_link = $clean_sub_domain . '/sub/' . $sub_id;
            
            $extracted_config = "";
            $internal_sub = rtrim($this->url, '/') . '/sub/' . $sub_id;
            $ch = curl_init($internal_sub);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $sub_content = curl_exec($ch);
            curl_close($ch);
            
            if ($sub_content) {
                $decoded = base64_decode(trim($sub_content));
                if ($decoded && preg_match('/^(vless|vmess|trojan|ss):\/\//i', $decoded)) {
                    $lines = explode("\n", trim($decoded));
                    $extracted_config = trim($lines[0]);
                    
                    $domain_host = parse_url($clean_sub_domain, PHP_URL_HOST);
                    if ($domain_host) {
                        $extracted_config = preg_replace('/(@)([^:]+)(:)/', '${1}' . $domain_host . '${3}', $extracted_config);
                    }
                }
            }
            
            if (empty($extracted_config)) {
                $domain_host = parse_url($clean_sub_domain, PHP_URL_HOST);
                $host = $domain_host ? $domain_host : parse_url($this->url, PHP_URL_HOST);
                $port = 443;
                $protocol = 'vless';
                $params = [];
                
                if (isset($inbound_data['success']) && $inbound_data['success'] && isset($inbound_data['obj'])) {
                    $obj = $inbound_data['obj'];
                    $port = $obj['port'];
                    $protocol = $obj['protocol'] ?? 'vless';
                    $stream = json_decode($obj['streamSettings'], true);
                    
                    $net = $stream['network'] ?? 'tcp';
                    $sec = $stream['security'] ?? 'none';
                    
                    $params['type'] = $net;
                    $params['security'] = $sec;
                    
                    if ($sec === 'tls') {
                        $params['sni'] = $stream['tlsSettings']['serverName'] ?? '';
                        $params['fp'] = $stream['tlsSettings']['fingerprint'] ?? 'chrome';
                        if (!empty($stream['tlsSettings']['alpn'])) {
                            $params['alpn'] = is_array($stream['tlsSettings']['alpn']) ? implode(',', $stream['tlsSettings']['alpn']) : $stream['tlsSettings']['alpn'];
                        }
                    } elseif ($sec === 'reality') {
                        $params['sni'] = $stream['realitySettings']['serverNames'][0] ?? '';
                        $params['fp'] = $stream['realitySettings']['fingerprint'] ?? 'chrome';
                        $params['pbk'] = $stream['realitySettings']['settings']['publicKey'] ?? '';
                        $params['sid'] = $stream['realitySettings']['shortIds'][0] ?? '';
                        $params['spx'] = $stream['realitySettings']['settings']['spiderX'] ?? '/';
                    }

                    if ($net === 'ws') {
                        $params['path'] = $stream['wsSettings']['path'] ?? '/';
                        $params['host'] = $stream['wsSettings']['headers']['Host'] ?? $host;
                    } elseif ($net === 'grpc') {
                        $params['serviceName'] = $stream['grpcSettings']['serviceName'] ?? '';
                        $params['mode'] = 'multi';
                    }
                }
                
                $params = array_filter($params, function($v) { return $v !== '' && $v !== null; });
                $query = http_build_query($params);
                $extracted_config = "{$protocol}://{$uuid}@{$host}:{$port}?{$query}#{$email}";
            }
            
            // استفاده از تگ‌های HTML برای قابلیت کپی شدن و زیبایی
            $final_msg = "🌐 <b>لینک سابسکریپشن (پیشنهاد ویژه):</b>\n<code>{$sub_link}</code>\n\n⚡️ <b>کانفیگ خام:</b>\n<code>{$extracted_config}</code>\n\n<i>(توصیه می‌شود لینک اول (سابسکریپشن) را در برنامه خود آپدیت کنید تا همیشه جدیدترین کانفیگ‌ها را دریافت کنید)</i>";

            return ['status' => true, 'link' => $final_msg];
        }
        return ['status' => false, 'msg' => '❌ خطا در ساخت کاربر: ' . ($res['msg'] ?? 'ناشناخته')];
    }

    public function getClientStats($email) {
        $login = $this->login();
        if (!$login['status']) return false;
        
        $res = $this->curl_request('/panel/api/inbounds/getClientTraffics/' . urlencode($email));
        if (isset($res['success']) && $res['success'] == true && isset($res['obj'])) {
            return $res['obj']; 
        }
        return false;
    }
}