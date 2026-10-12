<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Zhuzhichao\BankCardInfo\BankCard;

const CARDBIN_API = 'https://cardbin.cn/api/v1/bin/detail/';
const ALIPAY_CARD_API = 'https://ccdcapi.alipay.com/validateAndCacheCardInfo.json';
const PUBLIC_BIN_TABLE = 'https://raw.githubusercontent.com/wingflare-cloud/framework/main/BankCard.txt';
const VALIDATOR_URL = 'https://card-validator-production-45b3.up.railway.app/';

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

function httpGet(string $url, array $headers = [], int $timeout = 8): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'chaka-telegram-bot/2.1',
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch);
    curl_close($ch);

    return [
        'ok' => $body !== false && $error === 0 && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_string($body) ? $body : '',
    ];
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

function sendMessage(string $botToken, string|int $chatId, string $text, ?int $replyTo = null, ?array $replyMarkup = null): void
{
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true,
    ];

    if ($replyTo !== null) {
        $payload['reply_parameters'] = ['message_id' => $replyTo];
    }

    if ($replyMarkup !== null) {
        $payload['reply_markup'] = $replyMarkup;
    }

    telegramRequest($botToken, 'sendMessage', $payload);
}

function sendValidatorEntry(string $botToken, string|int $chatId, ?int $replyTo = null): void
{
    sendMessage(
        $botToken,
        $chatId,
        "银行卡验证入口\n\n点击按钮打开验证页。完整卡号在页面本地做校验，后端只查询 BIN。\n\n也可以直接发送：查卡+622848 查询 BIN 信息。",
        $replyTo,
        [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🔎 打开银行卡验证',
                        'url' => VALIDATOR_URL,
                    ],
                ],
            ],
        ]
    );
}

function normalizeBinFromCommand(string $text): ?string
{
    if (!preg_match('/^\s*查卡\s*[+＋:：]?\s*(\d{6,8})\s*$/u', $text, $matches)) {
        return null;
    }

    return $matches[1];
}

function lookupCardBinCn(string $bin, string $token): array
{
    if ($token === '') {
        return ['ok' => false];
    }

    $response = httpGet(
        CARDBIN_API . rawurlencode($bin),
        ['Accept: application/json', 'X-API-Token: ' . $token]
    );

    if (!$response['ok']) {
        return ['ok' => false];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data) || (int)($data['code'] ?? -1) !== 0 || !is_array($data['data'] ?? null)) {
        return ['ok' => false];
    }

    $item = $data['data'];
    $issuer = is_array($item['issuer'] ?? null) ? $item['issuer'] : [];
    $country = is_array($item['country'] ?? null) ? $item['country'] : [];

    return [
        'ok' => true,
        'bin' => (string)($item['bin'] ?? $bin),
        'bankName' => (string)($issuer['name_zh'] ?? $issuer['name'] ?? ''),
        'typeName' => (string)($item['type_zh'] ?? $item['type'] ?? ''),
        'scheme' => (string)($item['brand_zh'] ?? $item['scheme'] ?? ''),
        'category' => (string)($item['category_zh'] ?? $item['category'] ?? ''),
        'country' => (string)($country['name_zh'] ?? $country['name'] ?? ''),
    ];
}

function lookupPublicBinTable(string $bin): array
{
    $response = httpGet(PUBLIC_BIN_TABLE, ['Accept: text/plain'], 7);
    if (!$response['ok'] || $response['body'] === '') {
        return ['ok' => false];
    }

    $body = $response['body'];
    $max = min(8, strlen($bin));

    for ($length = $max; $length >= 5; $length--) {
        $prefix = substr($bin, 0, $length);
        $quoted = preg_quote($prefix, '/');
        $patterns = [
            '/[\'\"]' . $quoted . '[\'\"]\s*:\s*[\'\"]([^\'\"]+)[\'\"]/u',
            '/[\'\"]?' . $quoted . '[\'\"]?\s*=>\s*[\'\"]([^\'\"]+)[\'\"]/u',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $body, $match)) {
                continue;
            }

            $parts = explode('-', trim($match[1]));
            return [
                'ok' => true,
                'matchedBin' => $prefix,
                'bankName' => trim($parts[0] ?? ''),
                'cardName' => trim($parts[1] ?? ''),
                'typeName' => trim($parts[2] ?? ''),
            ];
        }
    }

    return ['ok' => false];
}

function lookupAlipayFallback(string $bin): array
{
    $synthetic = str_pad($bin, 16, '0');
    $url = ALIPAY_CARD_API . '?' . http_build_query([
        '_input_charset' => 'utf-8',
        'cardNo' => $synthetic,
        'cardBinCheck' => 'true',
    ]);

    $response = httpGet($url, ['Accept: application/json']);
    if (!$response['ok']) {
        return ['ok' => false];
    }

    $data = json_decode($response['body'], true);
    if (!is_array($data) || empty($data['validated'])) {
        return ['ok' => false];
    }

    return [
        'ok' => true,
        'bankCode' => (string)($data['bank'] ?? ''),
        'cardType' => (string)($data['cardType'] ?? ''),
    ];
}

function inferBankCode(string $bankName, array $alipay): string
{
    if (!empty($alipay['bankCode'])) {
        return (string)$alipay['bankCode'];
    }

    if ($bankName === '') {
        return '';
    }

    foreach (BankCard::getBankList() as $code => $name) {
        if ($name === $bankName || str_contains($name, $bankName) || str_contains($bankName, $name)) {
            return (string)$code;
        }
    }

    return '';
}

function normalizeTypeName(string $value, array $alipay): string
{
    if ($value !== '') {
        return match (strtoupper($value)) {
            'DEBIT', 'DC' => '借记卡',
            'CREDIT', 'CC' => '信用卡',
            default => $value,
        };
    }

    return match ((string)($alipay['cardType'] ?? '')) {
        'DC' => '借记卡',
        'CC' => '信用卡',
        default => '未知',
    };
}

$botToken = envValue('BOT_TOKEN');
$webhookSecret = envValue('WEBHOOK_SECRET');
$cardbinToken = envValue('CARDBIN_TOKEN');
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($requestMethod === 'GET' && ($requestPath === '/' || $requestPath === '/healthz')) {
    jsonResponse([
        'ok' => true,
        'service' => 'chaka',
        'message' => 'chaka telegram bot is running',
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
$message = is_array($update) ? ($update['message'] ?? null) : null;

if (!is_array($message) || !is_array($message['chat'] ?? null)) {
    jsonResponse(['ok' => true]);
}

$chatId = (string)($message['chat']['id'] ?? '');
$text = trim((string)($message['text'] ?? ''));
$messageId = isset($message['message_id']) ? (int)$message['message_id'] : null;

if ($chatId === '' || $text === '') {
    jsonResponse(['ok' => true]);
}

if ($text === '/start' || str_starts_with($text, '/start@') || $text === '/help' || str_starts_with($text, '/help@')) {
    sendMessage(
        $botToken,
        $chatId,
        "银行卡查询机器人\n\n群组和私聊都可使用，所有成员无需授权。\n\n完整卡号验证：发送 查卡，点击按钮打开验证页。\nBIN 查询：发送 查卡+银行卡前6～8位，例如：查卡+622848。"
    );
    jsonResponse(['ok' => true]);
}

if (preg_match('/^\s*查卡\s*$/u', $text) || $text === '/查卡' || str_starts_with($text, '/查卡@')) {
    sendValidatorEntry($botToken, $chatId, $messageId);
    jsonResponse(['ok' => true]);
}

$bin = normalizeBinFromCommand($text);
if ($bin === null) {
    jsonResponse(['ok' => true]);
}

$cardbin = lookupCardBinCn($bin, $cardbinToken);
$product = lookupPublicBinTable($bin);
$alipay = lookupAlipayFallback($bin);

$bankName = (string)($product['bankName'] ?? $cardbin['bankName'] ?? '');
$cardName = (string)($product['cardName'] ?? '');
$typeName = normalizeTypeName((string)($product['typeName'] ?? $cardbin['typeName'] ?? ''), $alipay);
$bankCode = inferBankCode($bankName, $alipay);
$scheme = (string)($cardbin['scheme'] ?? '');
$category = (string)($cardbin['category'] ?? '');
$country = (string)($cardbin['country'] ?? '');
$matchedBin = (string)($product['matchedBin'] ?? $cardbin['bin'] ?? $bin);

if ($bankName === '' && empty($cardbin['ok']) && empty($product['ok']) && empty($alipay['ok'])) {
    sendMessage($botToken, $chatId, "❌ 暂未查到 BIN：{$bin} 的银行卡信息。", $messageId);
    jsonResponse(['ok' => true]);
}

$lines = [
    '✅ BIN 查询成功',
    '',
    'BIN：' . $matchedBin,
    '银行：' . ($bankName !== '' ? $bankName : '未知'),
    '银行简称：' . ($bankCode !== '' ? $bankCode : '未知'),
    '银行卡名：' . ($cardName !== '' ? $cardName : '未知'),
    '银行卡种：' . $typeName,
];

if ($scheme !== '') {
    $lines[] = '卡组织：' . $scheme;
}
if ($category !== '') {
    $lines[] = '卡等级：' . $category;
}
if ($country !== '') {
    $lines[] = '国家/地区：' . $country;
}

$lines[] = '省份：暂无可靠免注册数据';
$lines[] = '城市：暂无可靠免注册数据';
$lines[] = '';
$lines[] = '说明：结果用于 BIN / 发卡机构识别，不代表具体账户真实存在或可交易。';

sendMessage($botToken, $chatId, implode("\n", $lines), $messageId);
jsonResponse(['ok' => true]);
