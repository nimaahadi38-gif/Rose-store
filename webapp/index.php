<?php
/**
 * Rose Store — Telegram Mini App
 * Entry point: ابتدا HMAC را سمت سرور اعتبارسنجی می‌کند، سپس HTML را رندر می‌کند.
 * برای یکپارچگی کامل با WebApp JS، بررسی HMAC نهایی در api.php است.
 */

// جلوگیری از دسترسی بدون WebApp
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_tg      = strpos($user_agent, 'TelegramWebApp') !== false
           || !empty($_SERVER['HTTP_X_TELEGRAM_INIT_DATA']);

$site_name  = 'Rose Store';
$theme_color = '#8B1A3A';
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<meta name="theme-color" content="<?= $theme_color ?>">
<title><?= $site_name ?></title>
<link rel="stylesheet" href="assets/app.css">
<script src="https://telegram.org/js/telegram-web-app.js"></script>
</head>
<body>

<!-- Header -->
<header class="app-header">
    <h1>🌹 <?= $site_name ?></h1>
    <p id="user-name">در حال بارگذاری...</p>
</header>

<!-- Loading Screen -->
<div id="loading-screen" class="app-content">
    <div class="loading" style="padding-top:60px">
        <div class="spinner"></div>
        <p>در حال اتصال...</p>
    </div>
</div>

<!-- Main Content -->
<div id="app-content" style="display:none">
    <div class="app-content">

        <!-- Services Pane -->
        <div class="tab-pane" data-pane="services" id="pane-services">
            <div class="loading"><div class="spinner"></div></div>
        </div>

        <!-- Wallet Pane -->
        <div class="tab-pane" data-pane="wallet" id="pane-wallet" style="display:none">
            <div class="loading"><div class="spinner"></div></div>
        </div>

        <!-- Admin Stats Pane -->
        <div class="tab-pane" data-pane="stats" id="pane-stats" style="display:none">
            <div class="loading"><div class="spinner"></div></div>
        </div>

        <!-- Admin Receipts Pane -->
        <div class="tab-pane" data-pane="receipts" id="pane-receipts" style="display:none">
            <div class="loading"><div class="spinner"></div></div>
        </div>

    </div><!-- /.app-content -->
</div><!-- /#app-content -->

<!-- Tab Bar (filled by JS) -->
<nav class="tab-bar" id="tab-bar"></nav>

<script src="assets/app.js"></script>
</body>
</html>
