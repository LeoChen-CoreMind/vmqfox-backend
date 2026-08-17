<?php

namespace app\service;

class SystemUptime
{
    private string $uptimePath;
    private string $statPath;
    private int $now;

    public function __construct(
        string $uptimePath = '/proc/uptime',
        string $statPath = '/proc/stat',
        ?int $now = null
    ) {
        $this->uptimePath = $uptimePath;
        $this->statPath = $statPath;
        $this->now = $now ?? time();
    }

    public function getFormatted(): string
    {
        $seconds = $this->readUptimeSeconds();
        if ($seconds === null) {
            $bootTime = $this->readBootTime();
            if ($bootTime !== null && $this->now >= $bootTime) {
                $seconds = (float) ($this->now - $bootTime);
            }
        }

        if ($seconds === null) {
            return '无法读取服务器启动时间';
        }

        return self::formatSeconds($seconds);
    }

    public static function formatSeconds(float $seconds): string
    {
        $minutes = (int) floor(max(0, $seconds) / 60);
        if ($minutes < 1) {
            return '少于1分钟';
        }

        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $remainingMinutes = $minutes % 60;
        $formatted = '';

        if ($days > 0) {
            $formatted .= $days . '天';
        }
        if ($hours > 0) {
            $formatted .= $hours . '小时';
        }
        if ($remainingMinutes > 0) {
            $formatted .= $remainingMinutes . '分钟';
        }

        return $formatted;
    }

    private function readUptimeSeconds(): ?float
    {
        $contents = @file_get_contents($this->uptimePath);
        if ($contents === false || !preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)/', $contents, $matches)) {
            return null;
        }

        $seconds = (float) $matches[1];
        return is_finite($seconds) ? $seconds : null;
    }

    private function readBootTime(): ?int
    {
        $contents = @file_get_contents($this->statPath);
        if ($contents === false || !preg_match('/^btime\s+(\d+)/m', $contents, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
