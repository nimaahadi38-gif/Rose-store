<?php
require_once __DIR__ . '/Xui.php';

/**
 * مدیریت چند سرور XUI با load-balancing و fallback خودکار
 */
class ServerManager {
    private PDO $pdo;
    private string $storage_dir;

    public function __construct(PDO $pdo, string $storage_dir = '') {
        $this->pdo         = $pdo;
        $this->storage_dir = $storage_dir ?: __DIR__ . '/../storage';
    }

    /** برترین سرور را بر اساس تعداد کمترین کاربر فعال برمی‌گرداند */
    public function getBestServer(): ?array {
        $stmt = $this->pdo->query(
            "SELECT s.*, COUNT(o.id) AS active_count
             FROM servers s
             LEFT JOIN orders o ON o.server_id = s.id
             WHERE s.is_active = 1
             GROUP BY s.id
             ORDER BY active_count ASC, s.priority ASC
             LIMIT 1"
        );
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** همه سرورهای فعال را برمی‌گرداند */
    public function getActiveServers(): array {
        return $this->pdo
            ->query("SELECT * FROM servers WHERE is_active = 1 ORDER BY priority ASC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** سرویس را روی بهترین سرور می‌سازد و در صورت شکست به سرور بعدی می‌رود */
    public function addClientWithFallback(
        string $email,
        string $uuid,
        int $bytes,
        int $timestamp_ms,
        int $ip_limit = 0
    ): array {
        $servers = $this->getActiveServers();

        if (empty($servers)) {
            return ['status' => false, 'msg' => '❌ هیچ سرور فعالی تعریف نشده است.'];
        }

        // مرتب‌سازی بر اساس تعداد کاربران (کمترین اول)
        usort($servers, function ($a, $b) {
            return ((int)$a['active_count'] ?? 0) - ((int)$b['active_count'] ?? 0);
        });

        foreach ($servers as $server) {
            $xui = $this->buildXuiForServer($server);
            $result = $xui->addClient(
                (int)$server['inbound_id'],
                $email,
                $uuid,
                $bytes,
                $timestamp_ms,
                $ip_limit,
                $server['sub_domain'] ?? ''
            );

            if ($result['status']) {
                $result['server_id'] = (int)$server['id'];
                return $result;
            }

            error_log("[ServerManager] سرور {$server['name']} شکست خورد: " . ($result['msg'] ?? ''));
        }

        return ['status' => false, 'msg' => '❌ همه سرورها در دسترس نیستند. لطفاً بعداً تلاش کنید.'];
    }

    /** ساخت شیء Xui برای یک سرور مشخص */
    public function buildXuiForServer(array $server): Xui {
        $cookie = $this->storage_dir . '/cookie_' . (int)$server['id'] . '.txt';
        return new Xui(
            $server['url'],
            $server['user'],
            $server['pass'],
            $cookie,
            $server['remote_address'] ?? '',
            $server['ws_host'] ?? ''
        );
    }

    /** تست اتصال یک سرور و بازگشت نتیجه */
    public function testServer(int $server_id): array {
        $stmt = $this->pdo->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$server_id]);
        $server = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$server) return ['status' => false, 'msg' => '❌ سرور یافت نشد.'];

        $xui   = $this->buildXuiForServer($server);
        $ok    = $xui->testConnection();
        $msg   = $ok ? "✅ اتصال به سرور «{$server['name']}» برقرار شد." : "❌ اتصال به سرور «{$server['name']}» ناموفق بود.";
        return ['status' => $ok, 'msg' => $msg];
    }

    /** آمار تعداد کاربران روی هر سرور */
    public function getServerStats(): array {
        return $this->pdo->query(
            "SELECT s.id, s.name, s.url, s.is_active, s.priority,
                    COUNT(o.id) AS client_count
             FROM servers s
             LEFT JOIN orders o ON o.server_id = s.id
             GROUP BY s.id
             ORDER BY s.priority ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
