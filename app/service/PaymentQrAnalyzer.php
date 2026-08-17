<?php

namespace app\service;

use InvalidArgumentException;
use RuntimeException;

class PaymentQrAnalyzer
{
    private ProcessRunner $runner;
    private AmountParser $amountParser;
    private array $config;

    public function __construct(
        ?ProcessRunner $runner = null,
        ?AmountParser $amountParser = null,
        ?array $config = null
    ) {
        $this->runner = $runner ?? new ProcessRunner();
        $this->amountParser = $amountParser ?? new AmountParser();
        $this->config = $config ?? (array) config('qrcode');
    }

    /**
     * @return array{url:string,amount:string,amount_status:string,candidates:array<int,string>,decoder:?string}
     */
    public function analyze(string $path): array
    {
        $this->validateImage($path);

        if (!$this->runner->isAvailable()) {
            throw new RuntimeException('服务器未启用 proc_open，无法运行二维码识别组件');
        }

        $timeout = max(1, (int) ($this->config['command_timeout'] ?? 12));
        [$url, $decoder] = $this->decodeQrCode($path, $timeout);
        $ocrOutputs = [];
        foreach (['6', '11'] as $pageSegmentationMode) {
            try {
                $ocrResult = $this->runner->run([
                    (string) ($this->config['tesseract_binary'] ?? 'tesseract'),
                    $path,
                    'stdout',
                    '-l',
                    'eng',
                    '--psm',
                    $pageSegmentationMode,
                    '-c',
                    'tessedit_char_whitelist=0123456789.,',
                ], $timeout);
            } catch (\Throwable $e) {
                continue;
            }

            if (!$ocrResult['timed_out'] && $ocrResult['exit_code'] === 0) {
                $ocrOutputs[] = $ocrResult['stdout'];
            }
        }

        $amount = $this->amountParser->parse($ocrOutputs);
        if ($url === '' && $amount['amount'] === '') {
            throw new RuntimeException('未识别到有效二维码或金额，请检查识别组件后重试');
        }

        return [
            'url' => $url,
            'amount' => $amount['amount'],
            'amount_status' => $amount['status'],
            'candidates' => $amount['candidates'],
            'decoder' => $decoder,
        ];
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function decodeQrCode(string $path, int $timeout): array
    {
        $script = (string) ($this->config['opencv_decoder_script']
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'qr_decode.py');
        $decoders = [
            'zbar' => [
                (string) ($this->config['zbar_binary'] ?? 'zbarimg'),
                '--quiet',
                '--raw',
                $path,
            ],
            'opencv' => [
                (string) ($this->config['python_binary'] ?? 'python3'),
                $script,
                $path,
            ],
        ];

        foreach ($decoders as $name => $command) {
            try {
                $result = $this->runner->run($command, $timeout);
            } catch (\Throwable $e) {
                continue;
            }

            $value = trim($result['stdout']);
            if (!$result['timed_out'] && $result['exit_code'] === 0 && $value !== '') {
                $decoder = $name === 'opencv'
                    ? $this->resolveDecoderName($name, (string) $result['stderr'])
                    : $name;
                return [$value, $decoder];
            }
        }

        return ['', null];
    }

    private function resolveDecoderName(string $fallback, string $stderr): string
    {
        if (preg_match('/(?:^|\R)decoder=(zxingcpp|opencv)(?:\R|$)/', trim($stderr), $matches)) {
            return $matches[1];
        }

        return $fallback;
    }

    private function validateImage(string $path): void
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('上传图片不可读取');
        }

        $maxFileSize = (int) ($this->config['max_file_size'] ?? 10 * 1024 * 1024);
        $fileSize = filesize($path);
        if ($fileSize === false || $fileSize <= 0 || $fileSize > $maxFileSize) {
            throw new InvalidArgumentException('图片大小无效或超过 10 MB 限制');
        }

        $imageInfo = @getimagesize($path);
        if ($imageInfo === false) {
            throw new InvalidArgumentException('上传文件不是有效图片');
        }

        $mime = $imageInfo['mime'] ?? null;
        $allowedMime = $this->config['allowed_mime'] ?? ['image/jpeg', 'image/png', 'image/webp'];
        if (!is_string($mime) || !in_array($mime, $allowedMime, true)) {
            throw new InvalidArgumentException('仅支持 JPEG、PNG 或 WebP 图片');
        }

        $pixelCount = (int) ($imageInfo[0] ?? 0) * (int) ($imageInfo[1] ?? 0);
        $maxPixels = (int) ($this->config['max_image_pixels'] ?? 40_000_000);
        if ($pixelCount <= 0 || $pixelCount > $maxPixels) {
            throw new InvalidArgumentException('图片分辨率无效或过大');
        }
    }
}
