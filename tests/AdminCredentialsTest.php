<?php

use app\service\AdminCredentials;

if (!function_exists('test')) {
    require __DIR__ . '/bootstrap.php';
}

function makeAdminCredentials(array &$settings): AdminCredentials
{
    return new AdminCredentials(
        static function (string $key) use (&$settings): ?string {
            return array_key_exists($key, $settings) ? (string) $settings[$key] : null;
        },
        static function (string $key, string $value) use (&$settings): void {
            $settings[$key] = $value;
        },
        static function () use (&$settings): array {
            $rows = [];
            foreach ($settings as $key => $value) {
                $rows[] = ['vkey' => $key, 'vvalue' => $value];
            }
            return $rows;
        }
    );
}

test('stores and verifies administrator passwords with PASSWORD_DEFAULT', function (): void {
    $settings = ['user' => '', 'pass' => ''];
    $credentials = makeAdminCredentials($settings);

    $credentials->update('owner', 'correct horse battery staple');

    assertSameValue(true, password_verify('correct horse battery staple', $settings['pass']));
    assertSameValue(true, $credentials->verify('owner', 'correct horse battery staple'));
    assertSameValue(false, $credentials->verify('owner', 'wrong'));
});

test('migrates a matching legacy plaintext password once', function (): void {
    $settings = ['user' => 'legacy', 'pass' => 'legacy-password'];
    $credentials = makeAdminCredentials($settings);

    assertSameValue(true, $credentials->verify('legacy', 'legacy-password'));
    $migratedHash = $settings['pass'];
    assertSameValue(true, password_verify('legacy-password', $migratedHash));

    assertSameValue(true, $credentials->verify('legacy', 'legacy-password'));
    assertSameValue($migratedHash, $settings['pass']);
});

test('does not initialize credentials when environment values are missing', function (): void {
    $oldUsername = getenv('ADMIN_USERNAME');
    $oldPassword = getenv('ADMIN_PASSWORD');
    putenv('ADMIN_USERNAME');
    putenv('ADMIN_PASSWORD');

    try {
        $settings = ['user' => '', 'pass' => ''];
        makeAdminCredentials($settings)->initializeFromEnvironment();
        assertSameValue('', $settings['user']);
        assertSameValue('', $settings['pass']);
    } finally {
        $oldUsername === false ? putenv('ADMIN_USERNAME') : putenv('ADMIN_USERNAME=' . $oldUsername);
        $oldPassword === false ? putenv('ADMIN_PASSWORD') : putenv('ADMIN_PASSWORD=' . $oldPassword);
    }
});

test('initializes only empty credentials and rejects admin admin defaults', function (): void {
    $oldUsername = getenv('ADMIN_USERNAME');
    $oldPassword = getenv('ADMIN_PASSWORD');

    try {
        putenv('ADMIN_USERNAME=owner');
        putenv('ADMIN_PASSWORD=initial-secret');
        $settings = ['user' => '', 'pass' => ''];
        $credentials = makeAdminCredentials($settings);
        $credentials->initializeFromEnvironment();
        assertSameValue('owner', $settings['user']);
        assertSameValue(true, password_verify('initial-secret', $settings['pass']));

        putenv('ADMIN_USERNAME=admin');
        putenv('ADMIN_PASSWORD=admin');
        $thrown = false;
        try {
            makeAdminCredentials($settings)->initializeFromEnvironment();
        } catch (RuntimeException) {
            $thrown = true;
        }
        assertSameValue(true, $thrown);
    } finally {
        $oldUsername === false ? putenv('ADMIN_USERNAME') : putenv('ADMIN_USERNAME=' . $oldUsername);
        $oldPassword === false ? putenv('ADMIN_PASSWORD') : putenv('ADMIN_PASSWORD=' . $oldPassword);
    }
});

test('public settings never expose password hashes or replay records', function (): void {
    $settings = [
        'user' => 'owner',
        'pass' => password_hash('secret', PASSWORD_DEFAULT),
        'key' => 'communication-key',
        'monitor_event:abc' => (string) time(),
    ];

    $public = makeAdminCredentials($settings)->publicSettings();
    assertSameValue(false, array_key_exists('pass', $public));
    assertSameValue(false, array_key_exists('monitor_event:abc', $public));
    assertSameValue('owner', $public['user']);
    assertSameValue('communication-key', $public['key']);
});

test('login and settings controllers use the credential service safely', function (): void {
    $root = dirname(__DIR__);
    $auth = file_get_contents($root . '/app/controller/api/Auth.php');
    $legacy = file_get_contents($root . '/app/controller/index/Index.php');
    $config = file_get_contents($root . '/app/controller/api/Config.php');
    $admin = file_get_contents($root . '/app/controller/admin/Index.php');
    $sql = file_get_contents($root . '/vmq.sql');

    assertSameValue(true, str_contains($auth, 'AdminCredentials'));
    assertSameValue(true, str_contains($auth, 'Session::regenerate(true)'));
    assertSameValue(true, str_contains($legacy, 'Session::regenerate(true)'));
    assertSameValue(true, str_contains($config, 'publicSettings()'));
    assertSameValue(true, str_contains($admin, 'publicSettings()'));
    assertSameValue(false, str_contains($sql, "('user', 'admin')"));
    assertSameValue(false, str_contains($sql, "('pass', 'admin')"));
});

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit($failures === 0 ? 0 : 1);
}
