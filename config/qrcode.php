<?php

return [
    'zbar_binary' => env('QRCODE_ZBAR_BINARY', 'zbarimg'),
    'python_binary' => env('QRCODE_PYTHON_BINARY', 'python3'),
    'opencv_decoder_script' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'qr_decode.py',
    'tesseract_binary' => env('QRCODE_TESSERACT_BINARY', 'tesseract'),
    'command_timeout' => 12,
    'max_file_size' => 10 * 1024 * 1024,
    'max_image_pixels' => 24_000_000,
    'upload_concurrency' => 2,
    'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
];
