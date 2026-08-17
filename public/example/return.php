<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$data = vmqfoxCallbackData();
if (!vmqfoxCallbackIsValid($data, vmqfoxExampleKey())) {
    vmqfoxExampleError('error_sign', 400);
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>支付结果</title>
</head>
<body>
<h1>支付成功</h1>
<dl>
    <dt>商户订单号</dt><dd><?= vmqfoxEscape($data['payId']) ?></dd>
    <dt>自定义参数</dt><dd><?= vmqfoxEscape($data['param']) ?></dd>
    <dt>支付方式</dt><dd><?= vmqfoxEscape($data['type']) ?></dd>
    <dt>订单金额</dt><dd><?= vmqfoxEscape($data['price']) ?></dd>
    <dt>实际支付金额</dt><dd><?= vmqfoxEscape($data['reallyPrice']) ?></dd>
</dl>
</body>
</html>
