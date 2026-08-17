<?php

$root = dirname(__DIR__);
$routeSource = file_get_contents($root . '/route/app.php');
$routeConfigPath = $root . '/config/route.php';
$adminControllerSource = file_get_contents($root . '/app/controller/admin/Index.php');

test('automatic controller routing is disabled', function () use ($routeConfigPath): void {
    assertSameValue(true, is_file($routeConfigPath));

    $routeConfig = require $routeConfigPath;
    assertSameValue(true, $routeConfig['url_route_must'] ?? false);
});

test('admin actions are explicitly routed and curl helper is private', function () use ($routeSource, $adminControllerSource): void {
    assertSameValue(false, str_contains($routeSource, "admin/index/:action"));
    assertSameValue(true, str_contains($adminControllerSource, 'private function getCurl'));
});

test('administrative REST routes are protected by Auth middleware', function () use ($routeSource): void {
    $matched = preg_match(
        '/\/\/ Public REST API routes(?<public>.*?)\/\/ Authenticated REST API routes(?<private>.*?)\/\/ Legacy API routes/s',
        $routeSource,
        $blocks
    );

    assertSameValue(1, $matched);
    assertSameValue(true, str_contains($blocks['private'], '\\app\\middleware\\Auth::class'));

    foreach (['user/', "Route::get('menu'", 'config/', 'qrcode/'] as $privatePrefix) {
        assertSameValue(false, str_contains($blocks['public'], $privatePrefix));
        assertSameValue(true, str_contains($blocks['private'], $privatePrefix));
    }

    $privateOrderRoutes = [
        "Route::get('order/list'",
        "Route::get('order/detail/:id'",
        "Route::post('order/close/:id'",
        "Route::delete('order/:id'",
        "Route::post('order/expired'",
        "Route::delete('order/last'",
    ];
    foreach ($privateOrderRoutes as $route) {
        assertSameValue(false, str_contains($blocks['public'], $route));
        assertSameValue(true, str_contains($blocks['private'], $route));
    }

    foreach (['admin/index/getSettings', 'admin/index/saveSetting'] as $route) {
        assertSameValue(false, str_contains($blocks['public'], $route));
        assertSameValue(true, str_contains($blocks['private'], $route));
    }
});

test('signed payment and monitor REST routes remain public', function () use ($routeSource): void {
    $matched = preg_match(
        '/\/\/ Public REST API routes(?<public>.*?)\/\/ Authenticated REST API routes/s',
        $routeSource,
        $blocks
    );

    assertSameValue(1, $matched);
    foreach ([
        'auth/login',
        'auth/logout',
        'order/create',
        'order/check/:id',
        'order/get/:id',
        'order/return-url/:id',
        'monitor/heart',
        'monitor/push',
    ] as $route) {
        assertSameValue(true, str_contains($blocks['public'], $route));
    }
});
