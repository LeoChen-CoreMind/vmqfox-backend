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
