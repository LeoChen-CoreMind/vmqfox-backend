<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$data = vmqfoxCallbackData();
if (!vmqfoxCallbackIsValid($data, vmqfoxExampleKey())) {
    vmqfoxExampleError('error_sign', 400);
}

header('Content-Type: text/plain; charset=UTF-8');
echo 'success';
