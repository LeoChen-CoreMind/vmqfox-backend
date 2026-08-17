<?php

namespace app\service;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;

final class AdminCredentials
{
    private Closure $readSetting;
    private Closure $writeSetting;
    private Closure $readAllSettings;

    public function __construct(
        ?callable $readSetting = null,
        ?callable $writeSetting = null,
        ?callable $readAllSettings = null
    ) {
        $this->readSetting = Closure::fromCallable($readSetting ?? static function (string $key): ?string {
            $value = Db::name('setting')->where('vkey', $key)->value('vvalue');
            return $value === null ? null : (string) $value;
        });
        $this->writeSetting = Closure::fromCallable($writeSetting ?? static function (string $key, string $value): void {
            $exists = Db::name('setting')->where('vkey', $key)->find();
            if ($exists) {
                Db::name('setting')->where('vkey', $key)->update(['vvalue' => $value]);
                return;
            }
            Db::name('setting')->insert(['vkey' => $key, 'vvalue' => $value]);
        });
        $this->readAllSettings = Closure::fromCallable($readAllSettings ?? static function (): array {
            return Db::name('setting')->select()->toArray();
        });
    }

    public function initializeFromEnvironment(): void
    {
        $usernameValue = getenv('ADMIN_USERNAME') ?: '';
        $passwordValue = getenv('ADMIN_PASSWORD') ?: '';
        if (function_exists('env')) {
            $usernameValue = \env('admin.username', $usernameValue);
            $passwordValue = \env('admin.password', $passwordValue);
        }
        $username = trim((string) $usernameValue);
        $password = (string) $passwordValue;
        if ($username === '' || $password === '') {
            return;
        }
        if ($username === 'admin' && $password === 'admin') {
            throw new RuntimeException('Refusing to initialize the insecure admin/admin credential pair.');
        }

        $storedUser = trim((string) $this->read('user'));
        $storedPassword = (string) $this->read('pass');
        if ($storedUser !== '' || $storedPassword !== '') {
            return;
        }

        $this->update($username, $password);
    }

    public function verify(string $username, string $password): bool
    {
        $storedUser = (string) $this->read('user');
        $storedPassword = (string) $this->read('pass');
        if ($storedUser === '' || $storedPassword === '' || !hash_equals($storedUser, $username)) {
            return false;
        }

        $passwordInfo = password_get_info($storedPassword);
        if (($passwordInfo['algoName'] ?? 'unknown') !== 'unknown') {
            return password_verify($password, $storedPassword);
        }

        if (!hash_equals($storedPassword, $password)) {
            return false;
        }

        $this->write('pass', password_hash($password, PASSWORD_DEFAULT));
        return true;
    }

    public function update(string $username, ?string $password): void
    {
        $username = trim($username);
        if ($username === '') {
            throw new InvalidArgumentException('Administrator username cannot be empty.');
        }

        $this->write('user', $username);
        if ($password !== null && $password !== '') {
            $this->write('pass', password_hash($password, PASSWORD_DEFAULT));
        }
    }

    public function publicSettings(): array
    {
        $settings = [];
        foreach ($this->readAll() as $item) {
            $key = (string) ($item['vkey'] ?? '');
            if ($key === '' || $key === 'pass' || str_starts_with($key, 'monitor_event:')) {
                continue;
            }
            $settings[$key] = (string) ($item['vvalue'] ?? '');
        }
        return $settings;
    }

    private function read(string $key): ?string
    {
        return ($this->readSetting)($key);
    }

    private function write(string $key, string $value): void
    {
        ($this->writeSetting)($key, $value);
    }

    private function readAll(): array
    {
        return ($this->readAllSettings)();
    }
}
