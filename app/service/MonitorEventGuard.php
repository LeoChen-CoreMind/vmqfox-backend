<?php

namespace app\service;

use think\facade\Db;

final class MonitorEventGuard
{
    private const WINDOW_SECONDS = 300;
    private const RETENTION_SECONDS = 86400;
    private const KEY_PREFIX = 'monitor_event:';

    public static function normalizeTimestamp(mixed $raw, ?int $now = null): ?int
    {
        if (is_int($raw)) {
            $value = (string) $raw;
        } elseif (is_string($raw)) {
            $value = $raw;
        } else {
            return null;
        }

        if (!preg_match('/^(?:\d{10}|\d{13})$/', $value)) {
            return null;
        }

        $timestamp = (int) $value;
        return strlen($value) === 13 ? intdiv($timestamp, 1000) : $timestamp;
    }

    public static function isFresh(mixed $raw, ?int $now = null): bool
    {
        $timestamp = self::normalizeTimestamp($raw, $now);
        if ($timestamp === null) {
            return false;
        }

        $current = $now ?? time();
        return abs($timestamp - $current) <= self::WINDOW_SECONDS;
    }

    public static function claim(
        string $kind,
        string $type,
        string $price,
        string $timestamp,
        string $signature
    ): bool {
        if (!self::isFresh($timestamp)) {
            return false;
        }

        $fingerprint = self::fingerprint($kind, $type, $price, $timestamp, $signature);
        $now = time();

        // Both cleanup and INSERT IGNORE deliberately propagate database errors.
        Db::execute(
            'DELETE FROM setting WHERE vkey LIKE ? AND CAST(vvalue AS UNSIGNED) < ?',
            [self::KEY_PREFIX . '%', (string) ($now - self::RETENTION_SECONDS)]
        );

        return Db::execute(
            'INSERT IGNORE INTO setting(vkey, vvalue) VALUES (?, ?)',
            [self::KEY_PREFIX . $fingerprint, (string) $now]
        ) === 1;
    }

    public static function release(
        string $kind,
        string $type,
        string $price,
        string $timestamp,
        string $signature
    ): void {
        $fingerprint = self::fingerprint($kind, $type, $price, $timestamp, $signature);
        Db::execute(
            'DELETE FROM setting WHERE vkey = ?',
            [self::KEY_PREFIX . $fingerprint]
        );
    }

    private static function fingerprint(
        string $kind,
        string $type,
        string $price,
        string $timestamp,
        string $signature
    ): string {
        return hash('sha256', implode("\0", [$kind, $type, $price, $timestamp, $signature]));
    }
}
