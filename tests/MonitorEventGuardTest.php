<?php

use app\service\MonitorEventGuard;

if (!function_exists('test')) {
    require __DIR__ . '/bootstrap.php';
}

test('normalizes monitor timestamps in seconds and milliseconds', function (): void {
    assertSameValue(1700000000, MonitorEventGuard::normalizeTimestamp('1700000000'));
    assertSameValue(1700000000, MonitorEventGuard::normalizeTimestamp('1700000000000'));
    assertSameValue(null, MonitorEventGuard::normalizeTimestamp('17000000000'));
    assertSameValue(null, MonitorEventGuard::normalizeTimestamp('1700000000.0'));
    assertSameValue(null, MonitorEventGuard::normalizeTimestamp(' 1700000000'));
});

test('accepts only timestamps inside the five minute window', function (): void {
    $now = 1700000000;
    assertSameValue(true, MonitorEventGuard::isFresh((string) ($now - 300), $now));
    assertSameValue(true, MonitorEventGuard::isFresh((string) ($now + 300), $now));
    assertSameValue(false, MonitorEventGuard::isFresh((string) ($now - 301), $now));
    assertSameValue(false, MonitorEventGuard::isFresh((string) ($now + 301), $now));
    assertSameValue(true, MonitorEventGuard::isFresh((string) (($now + 300) * 1000), $now));
});

test('monitor handlers use atomic replay claims and conditional order updates', function (): void {
    $root = dirname(__DIR__);
    $guard = file_get_contents($root . '/app/service/MonitorEventGuard.php');
    $apiMonitor = file_get_contents($root . '/app/controller/api/Monitor.php');
    $legacyMonitor = file_get_contents($root . '/app/controller/index/Index.php');

    assertSameValue(true, str_contains($guard, "monitor_event:"));
    assertSameValue(true, str_contains($guard, 'INSERT IGNORE INTO setting(vkey, vvalue) VALUES (?, ?)'));
    assertSameValue(true, str_contains($guard, 'RETENTION_SECONDS = 86400'));

    foreach ([$apiMonitor, $legacyMonitor] as $controller) {
        assertSameValue(true, str_contains($controller, "MonitorEventGuard::claim('heart'"));
        assertSameValue(true, str_contains($controller, "MonitorEventGuard::claim('push'"));
        assertSameValue(true, str_contains($controller, 'hash_equals('));
        assertSameValue(true, str_contains($controller, '->where("state", 0)'));
    }
});

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit($failures === 0 ? 0 : 1);
}
