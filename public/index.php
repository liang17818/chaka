<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Zhuzhichao\BankCardInfo\BankCard;

const ALIPAY_CARD_API = 'https://ccdcapi.alipay.com/validateAndCacheCardInfo.json';

function envValue(string $name): string
{
    $value = getenv($name);
    return $value === false ? '' : trim((string)$value);
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function telegramRequest(string $botToken, string $method, array $payload): array
{
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        return ['ok' => false];
    }

    $decoded = json_decode((string)$body, true);
    return is_array($decoded) ? $decoded : ['ok' => false];
}

function sendMessage(string $botToken, string|int $chatId, string $text): void
{
    telegramRequest($botToken, 'sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ]);
}

function parseAllowedUsers(string $raw): array
{
    if ($raw === '') {
        return [];
    }

    $items = array_filter(
        array_map('trim', explode(',', $raw)),
        static fn(string $item): bool => $item !== ''
    );

    return array_values(array_unique($items));
}

function normalizeBin(string $input): ?string
{
    $candidate = preg_replace('/\s+/u', '', trim($input));
    if (!is_string($candidate) || !preg_match('/^\d{6,8}$/', $candidate)) {
        return null;
    }

    return $candidate;
}

function lookupBin(string $bin): array
{
    // 仅使用用户提供的 BIN 构造一个虚拟卡号用于 BIN 识别。
    // 不接收、保存或向第三方发送用户的完整银行卡号。
    $syntheticCardNumber = str_pad($bin, 16, '0');

    $url = ALIPAY_CARD_API . '?' . http_build_query([
        '_input_charset' => 'utf-8',
        'cardNo' => $syntheticCardNumber,
        'cardBinCheck' => 'true',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'chaka-telegram-bot/1.0',
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch);
    curl_close($ch);

    if ($body === false || $curlError !== 0 || $status < 200 || $status >= 300) {
        return ['ok' => false, 'reason' => 'upstream_error'];
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        return ['ok' => false, 'reason' => 'invalid_response'];
    }

    if (empty($data['validated'])) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    return [
        'ok' => true,
        'bank' => (string)($data['bank'] ?? ''),
        'cardType' => (string)($data['cardType'] ?? ''),
    ];
}

$botToken = envValue('BOT_TOKEN');
$webhookSecret = envValue('WEBHOOK_SECRET');
$allowedUsers = parseAllowedUsers(envValue('ALLOWED_USER_IDS'));
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($requestMethod === 'GET' && ($requestPath === '/' || $requestPath === '/healthz')) {
    jsonResponse([
        'ok' => true,
        'service' => 'chaka',
        'message' => 'chaka bot is running',
    ]);
}

if ($requestMethod !== 'POST' || $requestPath !== '/webhook') {
    jsonResponse(['ok' => false, 'error' => 'not_found'], 404);
}

if ($botToken === '' || $webhookSecret === '') {
    jsonResponse(['ok' => false, 'error' => 'server_not_configured'], 500);
}

$receivedSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($receivedSecret === '' || !hash_equals($webhookSecret, $receivedSecret)) {
    jsonResponse(['ok' => false, 'error' => 'forbidden'], 403);
}

$rawBody = file_get_contents('php://input');
$update = json_decode(is_string($rawBody) ? $rawBody : '', true);

if (!is_array($update)) {
    jsonResponse(['ok' => true]);
}

$message = $update['message'] ?? null;
if (!is_array($message)) {
    jsonResponse(['ok' => true]);
}

$chat = $message['chat'] ?? null;
$from = $message['from'] ?? null;
$text = trim((string)($message['text'] ?? ''));

if (!is_array($chat) || !isset($chat['id']) || !is_array($from) || !isset($from['id'])) {
    jsonResponse(['ok' => true]);
}

$chatId = (string)$chat['id'];
$userId = (string)$from['id'];
$chatType = (string)($chat['type'] ?? '');

if ($chatType !== 'private') {
    sendMessage($botToken, $chatId, '为保护支付信息，本机器人仅允许私聊使用。');
    jsonResponse(['ok' => true]);
}

if ($text === '/id' || str_starts_with($text, '/id@')) {
    sendMessage($botToken, $chatId, "你的 Telegram User ID：{$userId}");
    jsonResponse(['ok' => true]);
}

if ($text === '/start' || str_starts_with($text, '/start@')) {
    $authorization = in_array($userId, $allowedUsers, true) ? '已授权' : '未授权';
    sendMessage(
        $botToken,
        $chatId,
        "银行卡 BIN 查询机器人\n\n状态：{$authorization}\n你的 Telegram User ID：{$userId}\n\n请只发送银行卡前 6～8 位 BIN，例如：621483\n不要发送完整银行卡号。"
    );
    jsonResponse(['ok' => true]);
}

if ($text === '/help' || str_starts_with($text, '/help@')) {
    sendMessage($botToken, $chatId, "使用方法：\n1. 私聊机器人\n2. 只发送银行卡前 6～8 位数字\n3. 返回银行、银行代码和卡类型\n\n命令：/start /id /help\n\n为保护支付信息，机器人不会处理完整银行卡号。 ");
    jsonResponse(['ok' => true]);
}

if (!in_array($userId, $allowedUsers, true)) {
    sendMessage($botToken, $chatId, "⛔ 当前账号未授权。\n\n你的 Telegram User ID：{$userId}\n请把这个 ID 加入 Railway 的 ALLOWED_USER_IDS。 ");
    jsonResponse(['ok' => true]);
}

if (preg_match('/^\d{12,19}$/', preg_replace('/\s+/u', '', $text) ?? '')) {
    sendMessage($botToken, $chatId, '⚠️ 请不要发送完整银行卡号。只发送前 6～8 位 BIN，例如：621483。');
    jsonResponse(['ok' => true]);
}

$bin = normalizeBin($text);
if ($bin === null) {
    sendMessage($botToken, $chatId, '格式错误。请只发送银行卡前 6～8 位数字，例如：621483。');
    jsonResponse(['ok' => true]);
}

$result = lookupBin($bin);
if (empty($result['ok'])) {
    $reason = (string)($result['reason'] ?? 'unknown');
    $messageText = match ($reason) {
        'not_found' => '❌ 暂未识别到这个 BIN 的银行信息。',
        'upstream_error' => '⚠️ 查询接口暂时不可用，请稍后重试。',
        default => '⚠️ 查询返回异常，请稍后重试。',
    };

    sendMessage($botToken, $chatId, $messageText);
    jsonResponse(['ok' => true]);
}

$bankCode = (string)($result['bank'] ?? '');
$cardType = (string)($result['cardType'] ?? '');
$bankList = BankCard::getBankList();
$bankName = $bankList[$bankCode] ?? ($bankCode !== '' ? $bankCode : '未知银行');
$typeName = match ($cardType) {
    'DC' => '储蓄卡',
    'CC' => '信用卡',
    default => '未知',
};

$responseText = "✅ BIN 查询成功\n\n"
    . "🔢 BIN：{$bin}\n"
    . "🏦 银行：{$bankName}\n"
    . "🏷 银行代码：" . ($bankCode !== '' ? $bankCode : '未知') . "\n"
    . "💳 卡类型：{$typeName}\n"
    . "🔖 类型代码：" . ($cardType !== '' ? $cardType : '未知') . "\n\n"
    . '提示：BIN 查询结果仅用于识别发卡机构和卡类型，不代表具体账户真实存在或有效。';

sendMessage($botToken, $chatId, $responseText);
jsonResponse(['ok' => true]);
