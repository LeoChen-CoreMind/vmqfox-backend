<?php

declare(strict_types=1);

function vmqfoxExampleKey(): string
{
    $key = trim((string) getenv('VMQFOX_COMMUNICATION_KEY'));
    if ($key === '') {
        vmqfoxExampleError('请先为 PHP-FPM 配置 VMQFOX_COMMUNICATION_KEY 环境变量', 500);
    }

    return $key;
}

function vmqfoxQueryValue(string $name): string
{
    $value = $_GET[$name] ?? '';
    if (is_array($value)) {
        vmqfoxExampleError('请求参数格式无效', 400);
    }

    return trim((string) $value);
}

function vmqfoxCallbackData(): array
{
    return [
        'payId' => vmqfoxQueryValue('payId'),
        'param' => vmqfoxQueryValue('param'),
        'type' => vmqfoxQueryValue('type'),
        'price' => vmqfoxQueryValue('price'),
        'reallyPrice' => vmqfoxQueryValue('reallyPrice'),
        'sign' => vmqfoxQueryValue('sign'),
    ];
}

function vmqfoxCallbackIsValid(array $data, string $key): bool
{
    $expected = md5(
        $data['payId']
        . $data['param']
        . $data['type']
        . $data['price']
        . $data['reallyPrice']
        . $key
    );

    return $data['sign'] !== '' && hash_equals($expected, $data['sign']);
}

function vmqfoxEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vmqfoxExampleError(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit($message);
}
