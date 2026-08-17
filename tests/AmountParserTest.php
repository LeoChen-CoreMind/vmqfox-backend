<?php

use app\service\AmountParser;

test('extracts one unique amount', function (): void {
    $result = (new AmountParser())->parse(["Y 1.00\n", "1.00\n"]);
    assertSameValue('1.00', $result['amount']);
    assertSameValue('detected', $result['status']);
});

test('does not guess conflicting amounts', function (): void {
    $result = (new AmountParser())->parse(["1.00\n", "7.00\n"]);
    assertSameValue('', $result['amount']);
    assertSameValue('manual', $result['status']);
});

test('ignores qr-like integers', function (): void {
    $result = (new AmountParser())->parse(['20260816 333 60']);
    assertSameValue('', $result['amount']);
});
