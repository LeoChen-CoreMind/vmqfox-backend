<?php

test('batch routes are authenticated and registered before dynamic QR routes', function (): void {
    $routes = file_get_contents(dirname(__DIR__) . '/route/app.php');
    $preview = strpos($routes, "Route::post('qrcode/batch/preview'");
    $commit = strpos($routes, "Route::post('qrcode/batch/commit'");
    $dynamic = strpos($routes, "Route::post('qrcode/:id/amount'");

    assertSameValue(true, $preview !== false && $commit !== false);
    assertSameValue(true, $preview < $dynamic && $commit < $dynamic);
});

test('all QR amount writes share one row lock and batch commit is transactional', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/controller/api/Qrcode.php');

    assertSameValue(true, substr_count($source, 'withQrWriteLock(') >= 4);
    assertSameValue(true, str_contains($source, "where('vkey', 'user')->lock(true)->find()"));
    assertSameValue(true, str_contains($source, 'Db::transaction(function'));
    assertSameValue(true, str_contains($source, "->update(['pay_url' => \$item['pay_url']])"));
});

test('batch replacement preserves record identity amount and state', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/controller/api/Qrcode.php');
    $start = strpos($source, 'private function applyBatchPlan');
    $end = strpos($source, 'private function withQrWriteLock', $start);
    $method = substr($source, $start, $end - $start);
    $replaceStart = strpos($method, "if (\$item['action'] === 'replace')");
    $insertStart = strpos($method, "\$id = Db::name('pay_qrcode')->insertGetId", $replaceStart);
    $replaceBranch = substr($method, $replaceStart, $insertStart - $replaceStart);

    assertSameValue(true, $start !== false && $end !== false && $replaceStart !== false && $insertStart !== false);
    assertSameValue(true, str_contains($replaceBranch, "->update(['pay_url' => \$item['pay_url']])"));
    assertSameValue(false, str_contains($replaceBranch, "'state' => \$item"));
    assertSameValue(false, str_contains($replaceBranch, "'price' => \$item"));
});

test('QR list applies fixed ordering before database pagination', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/controller/api/Qrcode.php');
    $start = strpos($source, 'private function paginatedList');
    $end = strpos($source, 'private function createForType', $start);
    $method = substr($source, $start, $end - $start);
    $order = strpos($method, '->order(QrcodeInput::sortOrder($sort))');
    $page = strpos($method, "->page(\$pagination['page'], \$pagination['limit'])");

    assertSameValue(true, $order !== false && $page !== false && $order < $page);
    assertSameValue(true, str_contains($method, "Request::param('sort', 'newest')"));
    assertSameValue(true, str_contains($method, "'sort' => \$sort"));
});
