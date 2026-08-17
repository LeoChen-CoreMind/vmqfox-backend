<?php

use app\service\ProcessRunner;

test('captures stdout and exit code', function (): void {
    $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'fwrite(STDOUT, "ok");'], 3);
    assertSameValue(0, $result['exit_code']);
    assertSameValue('ok', $result['stdout']);
    assertSameValue(false, $result['timed_out']);
});

test('terminates a timed out command', function (): void {
    $result = (new ProcessRunner())->run([PHP_BINARY, '-r', 'sleep(3);'], 1);
    assertSameValue(true, $result['timed_out']);
});

test('captures output larger than the process pipe buffer', function (): void {
    $result = (new ProcessRunner())->run([
        PHP_BINARY,
        '-r',
        'fwrite(STDOUT, str_repeat("x", 2 * 1024 * 1024));',
    ], 3);

    assertSameValue(2 * 1024 * 1024, strlen($result['stdout']));
    assertSameValue(false, $result['timed_out']);
});
