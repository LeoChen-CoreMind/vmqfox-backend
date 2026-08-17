<?php

use app\service\QrcodeInput;

test('normalizes pagination without allowing large pages', function (): void {
    assertSameValue(['page' => 1, 'limit' => 12], QrcodeInput::pagination(0, 9999));
    assertSameValue(['page' => 2, 'limit' => 48], QrcodeInput::pagination(2, 48));
    assertSameValue(['page' => 1, 'limit' => 12], QrcodeInput::pagination('bad', 24.5));
});

test('normalizes valid money and rejects invalid money', function (): void {
    assertSameValue('1.00', QrcodeInput::normalizePrice('1'));
    assertSameValue('1.20', QrcodeInput::normalizePrice('1.2'));
    assertSameValue(null, QrcodeInput::normalizePrice('1.001'));
    assertSameValue(null, QrcodeInput::normalizePrice('0'));
    assertSameValue(null, QrcodeInput::normalizePrice('-1'));
});

test('normalizes positive QR code IDs', function (): void {
    assertSameValue(17, QrcodeInput::normalizeId('17'));
    assertSameValue(null, QrcodeInput::normalizeId('0'));
    assertSameValue(null, QrcodeInput::normalizeId('invalid'));
});

test('normalizes QR code enabled states', function (): void {
    assertSameValue(0, QrcodeInput::normalizeState('0'));
    assertSameValue(1, QrcodeInput::normalizeState(1));
    assertSameValue(null, QrcodeInput::normalizeState('2'));
    assertSameValue(null, QrcodeInput::normalizeState('invalid'));
});
