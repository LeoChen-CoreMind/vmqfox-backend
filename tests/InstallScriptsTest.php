<?php

if (!function_exists('test')) {
    require __DIR__ . '/bootstrap.php';
}

test('host installer scripts use strict mode and explicit deployment paths', function (): void {
    $root = dirname(__DIR__);
    foreach (['install.sh', 'install-docker.sh', 'install-baota.sh'] as $name) {
        $source = file_get_contents($root . '/scripts/' . $name);
        assertSameValue(true, str_contains($source, 'set -Eeuo pipefail'));
        assertSameValue(false, str_contains($source, 'php -r'));
        assertSameValue(false, str_contains($source, 'rm -rf'));
    }

    $docker = file_get_contents($root . '/scripts/install-docker.sh');
    $baota = file_get_contents($root . '/scripts/install-baota.sh');
    assertSameValue(true, str_contains($docker, 'docker compose --env-file'));
    assertSameValue(true, str_contains($docker, 'replace-with-*'));
    assertSameValue(true, str_contains($baota, 'VMQ_PHP_BIN'));
    assertSameValue(true, str_contains($baota, '/www/server/php/*/bin/php'));
    assertSameValue(true, str_contains($baota, 'Select the PHP binary'));
    assertSameValue(true, str_contains($baota, 'VMQ_PHP_VERSION'));
    assertSameValue(true, str_contains($baota, 'explicit_version'));
    assertSameValue(false, str_contains($baota, 'php8.2-'));
    assertSameValue(true, str_contains($baota, 'zbar-tools'));
    assertSameValue(true, str_contains($baota, 'tesseract-ocr'));
});

test('PHP installer never invokes host package managers', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/service/Installer.php')
        . file_get_contents(dirname(__DIR__) . '/public/install/index.php');
    assertSameValue(0, preg_match('/(?<!->)(?<!::)\\b(?:shell_exec|exec|system|passthru|proc_open)\\s*\\(/', $source));
});

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit($failures === 0 ? 0 : 1);
}
