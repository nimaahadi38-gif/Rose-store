<?php
/**
 * پیام همگانی صف‌محور
 * اجرای هر دقیقه: * * * * * php /path/to/cron/broadcast_worker.php
 *
 * ۲۰ کاربر در هر اجرا ارسال می‌کند تا از محدودیت Telegram جلوگیری شود.
 */

require_once __DIR__ . '/../core/DB.php';
require_once __DIR__ . '/../core/Telegram.php';
require_once __DIR__ . '/../helpers.php';

$config   = require __DIR__ . '/../config/settings.php';
$pdo      = DB::connect($config);
$telegram = new Telegram($config['bot_token'], $config['proxy_url'], $config['proxy_auth'] ?? '');

const BATCH_SIZE = 20;

// یافتن اولین broadcast در انتظار
$stmt = $pdo->query("SELECT * FROM broadcasts WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
$bc   = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bc) {
    echo "هیچ broadcast در انتظار نیست.\n";
    exit;
}

$bc_id   = (int)$bc['id'];
$msg_obj = json_decode($bc['message_json'], true);
$sent    = (int)$bc['sent'];
$total   = (int)$bc['total'];

// اگر هنوز total تنظیم نشده، total را از DB بخوانیم
if ($total == 0) {
    $total = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $pdo->prepare("UPDATE broadcasts SET total = ? WHERE id = ?")->execute([$total, $bc_id]);
}

// دریافت batch بعدی از کاربران
$stmt_users = $pdo->prepare("SELECT chat_id FROM users ORDER BY chat_id ASC LIMIT ? OFFSET ?");
$stmt_users->bindValue(1, BATCH_SIZE, PDO::PARAM_INT);
$stmt_users->bindValue(2, $sent, PDO::PARAM_INT);
$stmt_users->execute();
$users = $stmt_users->fetchAll(PDO::FETCH_COLUMN);

if (empty($users)) {
    $pdo->prepare("UPDATE broadcasts SET status = 'done', sent = ? WHERE id = ?")->execute([$sent, $bc_id]);
    echo "Broadcast #{$bc_id} کامل شد: {$sent} پیام ارسال شد.\n";
    exit;
}

$this_batch = 0;
foreach ($users as $chat_id) {
    $params            = $msg_obj;
    $params['chat_id'] = (int)$chat_id;
    $telegram->request('sendMessage', $params);
    $this_batch++;
    usleep(50000); // 50ms بین هر پیام
}

$new_sent = $sent + $this_batch;
if ($new_sent >= $total) {
    $pdo->prepare("UPDATE broadcasts SET status = 'done', sent = ? WHERE id = ?")->execute([$new_sent, $bc_id]);
    echo "Broadcast #{$bc_id} کامل شد: {$new_sent}/{$total} ارسال شد.\n";
} else {
    $pdo->prepare("UPDATE broadcasts SET sent = ? WHERE id = ?")->execute([$new_sent, $bc_id]);
    echo "Broadcast #{$bc_id}: {$new_sent}/{$total} ارسال شد.\n";
}
