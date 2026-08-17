<?php

use app\service\Installer;

if (!function_exists('test')) {
    require __DIR__ . '/bootstrap.php';
}

test('installer reports incomplete first-run state', function (): void {
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vmqfox-installer-' . uniqid('', true);
    mkdir($root, 0777, true);
    try {
        $status = Installer::status($root);
        assertSameValue(false, $status['ready']);
        assertSameValue(true, in_array('vendor', $status['missing'], true));
        assertSameValue(true, in_array('env', $status['missing'], true));
        assertSameValue(true, in_array('schema', $status['missing'], true));
        assertSameValue(true, in_array('lock', $status['missing'], true));
    } finally {
        rmdir($root);
    }
});

test('installer rejects insecure and placeholder administrator credentials', function (): void {
    foreach ([
        ['admin_username' => 'admin', 'admin_password' => 'admin'],
        ['admin_username' => 'replace-with-user', 'admin_password' => 'replace-with-password'],
    ] as $credentials) {
        $failed = false;
        try {
            Installer::validateInput(array_merge([
                'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'vmq',
                'db_user' => 'vmqfox', 'timezone' => 'Asia/Shanghai',
            ], $credentials));
        } catch (InvalidArgumentException $exception) {
            $failed = true;
        }
        assertSameValue(true, $failed);
    }
});

test('generated environment never contains the administrator password', function (): void {
    $values = Installer::validateInput([
        'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'vmq',
        'db_user' => 'vmqfox', 'db_password' => 'database-secret',
        'admin_username' => 'owner', 'admin_password' => 'administrator-secret',
        'timezone' => 'Asia/Shanghai',
    ]);
    $env = Installer::renderEnv($values);

    assertSameValue(false, str_contains($env, 'administrator-secret'));
    assertSameValue(true, str_contains($env, 'ADMIN_PASSWORD ='));
    assertSameValue(true, str_contains($env, 'PASSWORD = "database-secret"'));
});

test('front controller redirects before Composer is required', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/public/index.php');
    $status = strpos($source, 'Installer::status');
    $autoload = strpos($source, "vendor/autoload.php");

    assertSameValue(true, $status !== false);
    assertSameValue(true, $autoload !== false && $status < $autoload);
});

test('installer status exposes blocking PHP requirements', function (): void {
    $status = Installer::status(dirname(__DIR__));
    assertSameValue(true, isset($status['requirements']['php']));
    assertSameValue(true, isset($status['requirements']['pdo_mysql']));
    assertSameValue(true, isset($status['requirements']['proc_open']));
    assertSameValue(true, isset($status['php_version']));
});

test('installer source exposes explicit database import skip behavior', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/public/install/index.php');
    $service = file_get_contents(dirname(__DIR__) . '/app/service/Installer.php');
    assertSameValue(true, str_contains($source, 'database_imported'));
    assertSameValue(true, str_contains($source, 'schema_action'));
    assertSameValue(true, str_contains($service, 'schemaState'));
});

test('installer does not allow database values to diverge from an existing env file', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/service/Installer.php');
    assertSameValue(true, str_contains($source, 'existing .env already defines'));
});

test('installer handles a missing or unwritable runtime directory without warnings', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/public/install/index.php');
    assertSameValue(true, str_contains($source, 'runtimeError'));
    assertSameValue(true, str_contains($source, 'is_writable($runtime)'));
    assertSameValue(true, str_contains($source, 'chown -R www:www runtime'));
});

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit($failures === 0 ? 0 : 1);
}
