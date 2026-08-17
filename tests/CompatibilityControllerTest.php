<?php

use app\controller\index\Index;

test('legacy order and monitor compatibility actions exist', function (): void {
    assertSameValue(true, method_exists(Index::class, 'closeOrder'));
    assertSameValue(true, method_exists(Index::class, 'getState'));
});

test('return URL signatures require a paid order', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/controller/api/Order.php');
    $methodStart = strpos($source, 'public function generateReturnUrl');
    $stateGate = strpos($source, "in_array((int) \$order['state'], [1, 2], true)", $methodStart);
    $signature = strpos($source, '$sign = md5', $methodStart);

    assertSameValue(true, $methodStart !== false);
    assertSameValue(true, $stateGate !== false);
    assertSameValue(true, $signature !== false && $stateGate < $signature);
});

test('legacy checkOrder signatures require a paid order', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/controller/index/Index.php');
    $methodStart = strpos($source, 'public function checkOrder');
    $methodEnd = strpos($source, 'private function orderNotify', $methodStart);
    $method = substr($source, $methodStart, $methodEnd - $methodStart);
    $stateGate = strpos($method, "in_array((int) \$res['state'], [1, 2], true)");
    $signature = strpos($method, '$sign =');

    assertSameValue(true, $methodStart !== false && $methodEnd !== false);
    assertSameValue(true, $stateGate !== false);
    assertSameValue(true, $signature !== false && $stateGate < $signature);
});

test('order creation releases reserved amounts on every failure path', function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/app/controller/api/Order.php');
    $methodStart = strpos($source, 'public function create');
    $methodEnd = strpos($source, 'public function list', $methodStart);
    $method = substr($source, $methodStart, $methodEnd - $methodStart);

    $reservation = strpos($method, 'INSERT IGNORE INTO tmp_price');
    $paymentUrlFailure = strpos($method, "if (empty(\$payUrl))", $reservation);
    $insert = strpos($method, "Db::name('pay_order')->insert", $reservation);
    $catch = strpos($method, 'catch (\\Throwable $e)', $insert);
    $insertFailure = strpos($method, 'if (!$result)', $catch);

    assertSameValue(true, $reservation !== false);
    assertSameValue(true, $paymentUrlFailure !== false);
    assertSameValue(true, $insert !== false && $catch !== false && $insert < $catch);
    assertSameValue(true, $insertFailure !== false);
    assertSameValue(true, substr_count($method, "Db::name('tmp_price')->where('oid', \$orderId)->delete();") >= 3);
});
