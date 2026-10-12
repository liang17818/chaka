<?php

declare(strict_types=1);

$botToken = trim((string)(getenv('BOT_TOKEN') ?: ''));
$webhookSecret = trim((string)(getenv('WEBHOOK_SECRET') ?: ''));
$publicBaseUrl = rtrim(trim((string)(getenv('PUBLIC_BASE_URL') ?: '')), '/');

if ($botToken === '' || $webhookSecret === '' || $publicBaseUrl === '') {
    fwrite(STDOUT, "Webhook setup skipped: BOT_TOKEN, WEBHOOK_SECRET or PUBLIC_BASE_URL missing.\n");
    exit(0);
}

$payload = [
    'url' => $publicBaseUrl . '/webhook',
    'secret_token' => $webhookSecret,
    'allowed_updates' => ['message'],
    'drop_pending_updates' => false,
];

$ch = curl_init("https://api.telegram.org/bot{$botToken}/setWebhook");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($body === false || $status < 200 || $status >= 300) {
    fwrite(STDERR, "Webhook setup failed. HTTP {$status}. {$error}\n");
    exit(1);
}

$result = json_decode((string)$body, true);
if (!is_array($result) || empty($result['ok'])) {
    fwrite(STDERR, "Telegram rejected webhook setup.\n");
    exit(1);
}

fwrite(STDOUT, "Telegram webhook configured successfully.\n");
