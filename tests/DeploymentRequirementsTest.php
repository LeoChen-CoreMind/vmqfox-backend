<?php

test('Composer platform requirements match the PHP runtime', function (): void {
    $root = dirname(__DIR__);
    $composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);

    assertSameValue('>=8.2.0', $composer['require']['php'] ?? null);
    assertSameValue('*', $composer['require']['ext-curl'] ?? null);
    assertSameValue('*', $composer['require']['ext-bcmath'] ?? null);
    assertSameValue('>=8.2.0', $lock['platform']['php'] ?? null);
    assertSameValue('*', $lock['platform']['ext-curl'] ?? null);
    assertSameValue('*', $lock['platform']['ext-bcmath'] ?? null);
});

test('Docker and Linux installation include BCMath', function (): void {
    $root = dirname(__DIR__);
    $dockerfile = file_get_contents($root . '/Dockerfile');
    $readme = file_get_contents($root . '/README.md');
    $extensionStart = strpos($dockerfile, 'docker-php-ext-install');
    $extensionEnd = strpos($dockerfile, ';', $extensionStart);
    $extensionBlock = substr($dockerfile, $extensionStart, $extensionEnd - $extensionStart);

    assertSameValue(true, str_contains($extensionBlock, 'bcmath'));
    assertSameValue(true, str_contains($dockerfile, 'function_exists("bcmul")'));
    assertSameValue(true, str_contains($readme, '`bcmath`'));
    assertSameValue(true, str_contains($readme, 'php-bcmath'));
});

test('public release removes bundled clients and unsafe payment demos', function (): void {
    $root = dirname(__DIR__);
    $monitorPage = file_get_contents($root . '/public/admin/jk.html');
    $adminShell = file_get_contents($root . '/public/aaa.html');
    $loginPage = file_get_contents($root . '/public/index.html');
    $apiPage = file_get_contents($root . '/public/api.html');
    $readme = file_get_contents($root . '/README.md');
    $sql = file_get_contents($root . '/vmq.sql');

    assertSameValue(false, is_file($root . '/public/v.apk'));
    assertSameValue(false, is_file($root . '/public/example/index.html'));
    assertSameValue(false, is_file($root . '/public/example/main.php'));
    assertSameValue(false, is_file($root . '/app/controller/index/index.5.1.php'));
    assertSameValue(true, is_file($root . '/public/example/return.php'));
    assertSameValue(true, is_file($root . '/public/example/notify.php'));

    assertSameValue(true, str_contains($monitorPage, 'https://github.com/LeoChen-CoreMind/vmq-ksu-listener'));
    assertSameValue(true, str_contains($monitorPage, '不能与监控 APK 同时使用'));
    assertSameValue(false, str_contains($monitorPage, 'v.apk'));
    assertSameValue(false, str_contains($monitorPage, 'szvone/vmqApk'));
    assertSameValue(true, str_contains($adminShell, 'index.php/api/auth/logout'));
    assertSameValue(false, str_contains($adminShell, 'admin/index/checkUpdate'));
    assertSameValue(false, str_contains($loginPage, "title:'运行环境检测'"));
    assertSameValue(false, str_contains($apiPage, '测试支付页面'));
    assertSameValue(false, str_contains(strtolower($apiPage), 'qr.alipay.com'));
    assertSameValue(false, str_contains($loginPage, '请勿二次出售'));
    assertSameValue(false, str_contains($loginPage, '请勿用于商业用途'));
    assertSameValue(true, str_contains($loginPage, 'https://github.com/LeoChen-CoreMind/vmqfox-backend'));
    assertSameValue(true, str_contains($sql, "('user', '')"));
    assertSameValue(true, str_contains($sql, "('pass', '')"));
    assertSameValue(true, str_contains($sql, "('key', '')"));
    assertSameValue(false, str_contains($sql, "('user', 'admin')"));
    assertSameValue(false, str_contains($sql, "('pass', 'admin')"));
    assertSameValue(true, str_contains($readme, 'KSU 模块与监控 APK 不能同时使用'));
});

test('release artifacts contain no floating image tags or production-shaped secrets', function (): void {
    $root = dirname(__DIR__);
    $compose = file_get_contents($root . '/docker-compose.yml');
    $dockerfile = file_get_contents($root . '/Dockerfile');
    $dockerEnv = file_get_contents($root . '/.env.docker.example');
    $linuxEnv = file_get_contents($root . '/env.example');

    assertSameValue(false, str_contains($compose, ':latest'));
    assertSameValue(false, str_contains($dockerfile, ':latest'));
    assertSameValue(true, str_contains($dockerEnv, 'replace-with-a-long-random-password'));
    assertSameValue(true, str_contains($dockerEnv, 'replace-with-a-random-password'));
    assertSameValue(true, str_contains($linuxEnv, 'replace-with-a-long-random-password'));
    assertSameValue(true, str_contains($linuxEnv, 'replace-with-a-random-password'));

    $textExtensions = ['css', 'env', 'html', 'ini', 'js', 'json', 'md', 'php', 'py', 'sh', 'sql', 'txt', 'xml', 'yml'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (preg_match('#/(?:\.git|node_modules|runtime|tests|vendor)/#', $path)) {
            continue;
        }
        if (!in_array(strtolower($file->getExtension()), $textExtensions, true)) {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        assertSameValue(false, preg_match('#qr\.alipay\.com/[A-Z0-9]{10,}#i', $contents) === 1);
        assertSameValue(false, str_contains($contents, '-----BEGIN PRIVATE KEY-----'));
        assertSameValue(false, str_contains($contents, '-----BEGIN RSA PRIVATE KEY-----'));
        assertSameValue(false, str_contains($contents, '-----BEGIN OPENSSH PRIVATE KEY-----'));
    }
});

test('deployment pins images and enforces TLS CORS and utf8mb4 defaults', function (): void {
    $root = dirname(__DIR__);
    $compose = file_get_contents($root . '/docker-compose.yml');
    $sql = file_get_contents($root . '/vmq.sql');
    $cors = file_get_contents($root . '/app/middleware/CORS.php');
    $apiMonitor = file_get_contents($root . '/app/controller/api/Monitor.php');
    $legacyMonitor = file_get_contents($root . '/app/controller/index/Index.php');
    $appConfig = file_get_contents($root . '/config/app.php');

    assertSameValue(true, str_contains(
        $compose,
        'hulisang/vmqfox-frontend@sha256:4ae8fdea55298c45bff5ffca70943ce03224b702a29e1b8bc051939df6f8a841'
    ));
    assertSameValue(false, str_contains($compose, 'vmqfox-frontend:latest'));
    assertSameValue(false, str_contains($sql, 'ENGINE=MyISAM'));
    assertSameValue(false, str_contains($sql, 'DEFAULT CHARSET=utf8;'));
    assertSameValue(true, substr_count($sql, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') >= 4);

    $optionsCheck = strpos($cors, 'strtoupper($request->method()) === "OPTIONS"');
    $controllerCall = strpos($cors, '$next($request)');
    assertSameValue(true, $optionsCheck !== false && $controllerCall !== false && $optionsCheck < $controllerCall);

    foreach ([$apiMonitor, $legacyMonitor] as $controller) {
        assertSameValue(false, str_contains($controller, 'CURLOPT_SSL_VERIFYPEER, false'));
        assertSameValue(false, str_contains($controller, 'CURLOPT_SSL_VERIFYHOST, false'));
        assertSameValue(true, str_contains($controller, 'CURLOPT_SSL_VERIFYPEER, true'));
        assertSameValue(true, str_contains($controller, 'CURLOPT_SSL_VERIFYHOST, 2'));
        assertSameValue(true, str_contains($controller, 'CURLOPT_FOLLOWLOCATION, false'));
    }

    assertSameValue(true, str_contains($appConfig, "getenv('APP_TIMEZONE')"));
    assertSameValue(true, str_contains($appConfig, "getenv('TZ')"));
});
