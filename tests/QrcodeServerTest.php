<?php

use app\service\QrcodeServer;

test('generates qr images without requiring gd', function (): void {
    $result = (new QrcodeServer())->createQrcode('https://example.test/no-gd');
    $expectedPrefix = extension_loaded('gd')
        ? 'data:image/png;base64,'
        : 'data:image/svg+xml;base64,';

    assertSameValue(true, str_starts_with($result, $expectedPrefix));
    assertSameValue(1, preg_match('/^data:(image\/[A-Za-z0-9.+-]+);base64,(.+)$/', $result));
});
