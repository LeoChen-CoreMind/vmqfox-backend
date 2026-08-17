<?php

use app\service\SystemUptime;

$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vmqfox-system-uptime-' . uniqid('', true);
if (!mkdir($testDirectory, 0777, true) && !is_dir($testDirectory)) {
    throw new RuntimeException('unable to create test directory');
}

$uptimeFile = $testDirectory . DIRECTORY_SEPARATOR . 'uptime';
$statFile = $testDirectory . DIRECTORY_SEPARATOR . 'stat';
$missingFile = $testDirectory . DIRECTORY_SEPARATOR . 'missing-uptime';

file_put_contents($uptimeFile, "93780.00 123.45\n");
file_put_contents($statFile, "cpu  1 2 3\nbtime 196400\n");

test('formats proc uptime', function () use ($uptimeFile, $statFile): void {
    $service = new SystemUptime($uptimeFile, $statFile, 200000);
    assertSameValue('1天2小时3分钟', $service->getFormatted());
});

test('falls back to proc stat btime', function () use ($missingFile, $statFile): void {
    $service = new SystemUptime($missingFile, $statFile, 200000);
    assertSameValue('1小时', $service->getFormatted());
});

test('shows less than one minute', function (): void {
    assertSameValue('少于1分钟', SystemUptime::formatSeconds(20));
});

@unlink($uptimeFile);
@unlink($statFile);
@rmdir($testDirectory);
