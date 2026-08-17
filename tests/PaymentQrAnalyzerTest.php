<?php

use app\service\PaymentQrAnalyzer;
use app\service\ProcessRunner;

class FakeProcessRunner extends ProcessRunner
{
    public array $commands = [];
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function run(array $command, int $timeoutSeconds): array
    {
        $this->commands[] = $command;
        return array_shift($this->responses);
    }
}

function qrAnalyzerTestImage(): string
{
    $path = tempnam(sys_get_temp_dir(), 'qr-test-');
    file_put_contents(
        $path,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
    );
    return $path;
}

test('combines qr content and detected amount', function (): void {
    $runner = new FakeProcessRunner([
        ['exit_code' => 0, 'stdout' => "wxp://pay/example\n", 'stderr' => '', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "1.00\n", 'stderr' => '', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "Y1.00\n", 'stderr' => '', 'timed_out' => false],
    ]);
    $path = qrAnalyzerTestImage();

    try {
        $result = (new PaymentQrAnalyzer($runner, null, [
            'zbar_binary' => 'zbarimg',
            'tesseract_binary' => 'tesseract',
            'command_timeout' => 2,
            'max_file_size' => 1024 * 1024,
            'allowed_mime' => ['image/png'],
        ]))->analyze($path);

        assertSameValue('wxp://pay/example', $result['url']);
        assertSameValue('1.00', $result['amount']);
        assertSameValue('detected', $result['amount_status']);
        assertSameValue(['zbarimg', '--quiet', '--raw', $path], $runner->commands[0]);
        assertSameValue('6', $runner->commands[1][6]);
        assertSameValue('11', $runner->commands[2][6]);
    } finally {
        @unlink($path);
    }
});

test('keeps qr result when amount requires manual input', function (): void {
    $runner = new FakeProcessRunner([
        ['exit_code' => 0, 'stdout' => "https://qr.example\n", 'stderr' => '', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "1.00\n", 'stderr' => '', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "7.00\n", 'stderr' => '', 'timed_out' => false],
    ]);
    $path = qrAnalyzerTestImage();

    try {
        $result = (new PaymentQrAnalyzer($runner, null, [
            'command_timeout' => 2,
            'max_file_size' => 1024 * 1024,
            'allowed_mime' => ['image/png'],
        ]))->analyze($path);

        assertSameValue('https://qr.example', $result['url']);
        assertSameValue('', $result['amount']);
        assertSameValue('manual', $result['amount_status']);
    } finally {
        @unlink($path);
    }
});

test('falls back to OpenCV when zbar cannot decode the QR code', function (): void {
    $runner = new FakeProcessRunner([
        ['exit_code' => 1, 'stdout' => '', 'stderr' => 'not found', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "wxp://opencv/example\n", 'stderr' => '', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "1.00\n", 'stderr' => '', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "1.00\n", 'stderr' => '', 'timed_out' => false],
    ]);
    $path = qrAnalyzerTestImage();

    try {
        $result = (new PaymentQrAnalyzer($runner, null, [
            'zbar_binary' => 'zbarimg',
            'python_binary' => 'python3',
            'opencv_decoder_script' => 'qr_decode.py',
            'tesseract_binary' => 'tesseract',
            'command_timeout' => 2,
            'max_file_size' => 1024 * 1024,
            'allowed_mime' => ['image/png'],
        ]))->analyze($path);

        assertSameValue('wxp://opencv/example', $result['url']);
        assertSameValue('opencv', $result['decoder']);
        assertSameValue(['zbarimg', '--quiet', '--raw', $path], $runner->commands[0]);
        assertSameValue(['python3', 'qr_decode.py', $path], $runner->commands[1]);
    } finally {
        @unlink($path);
    }
});

test('reports ZXing-C++ when the Python decoder emits its marker', function (): void {
    $runner = new FakeProcessRunner([
        ['exit_code' => 1, 'stdout' => '', 'stderr' => 'not found', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "wxp://zxing/example\n", 'stderr' => "decoder=zxingcpp\n", 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "1.00\n", 'stderr' => '', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "1.00\n", 'stderr' => '', 'timed_out' => false],
    ]);
    $path = qrAnalyzerTestImage();

    try {
        $result = (new PaymentQrAnalyzer($runner, null, [
            'zbar_binary' => 'zbarimg',
            'python_binary' => 'python3',
            'opencv_decoder_script' => 'qr_decode.py',
            'tesseract_binary' => 'tesseract',
            'command_timeout' => 2,
            'max_file_size' => 1024 * 1024,
            'allowed_mime' => ['image/png'],
        ]))->analyze($path);

        assertSameValue('wxp://zxing/example', $result['url']);
        assertSameValue('zxingcpp', $result['decoder']);
    } finally {
        @unlink($path);
    }
});

test('ignores Python decoder markers on successful zbar output', function (): void {
    $runner = new FakeProcessRunner([
        ['exit_code' => 0, 'stdout' => "wxp://zbar/example\n", 'stderr' => "decoder=zxingcpp\n", 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "1.00\n", 'stderr' => '', 'timed_out' => false],
        ['exit_code' => 0, 'stdout' => "1.00\n", 'stderr' => '', 'timed_out' => false],
    ]);
    $path = qrAnalyzerTestImage();

    try {
        $result = (new PaymentQrAnalyzer($runner, null, [
            'zbar_binary' => 'zbarimg',
            'tesseract_binary' => 'tesseract',
            'command_timeout' => 2,
            'max_file_size' => 1024 * 1024,
            'allowed_mime' => ['image/png'],
        ]))->analyze($path);

        assertSameValue('wxp://zbar/example', $result['url']);
        assertSameValue('zbar', $result['decoder']);
    } finally {
        @unlink($path);
    }
});
