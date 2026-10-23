<?php

namespace app\service;

final class RewardImageCropper
{
    public function crop(string $imagePath): array
    {
        if (!preg_match('~^uploads/payment/[a-f0-9]{64}\.(jpg|png|webp)$~D', $imagePath)) {
            throw new \InvalidArgumentException('图片路径无效，请重新上传');
        }
        $source = public_path() . $imagePath;
        $base = realpath(public_path() . 'uploads/payment');
        $resolved = realpath($source);
        if (!$base || !$resolved || dirname($resolved) !== $base || !is_file($resolved)) {
            throw new \InvalidArgumentException('原图不存在，请重新上传');
        }
        $fallback = ['status' => 'original', 'original_image_path' => $imagePath,
            'image_path' => $imagePath, 'warning' => '未能可靠定位完整码区，已保留原图'];
        $temporary = null;
        try {
            $size = filesize($resolved);
            $info = @getimagesize($resolved);
            if (!$size || $size > 10485760 || !$info || $info[0] * $info[1] > 40000000) {
                return $fallback;
            }
            $temporary = tempnam($base, 'crop-');
            if (!$temporary) return $fallback;
            $result = (new ProcessRunner())->run([
                (string) config('qrcode.python_binary', 'python3'),
                dirname(__DIR__, 2) . '/scripts/reward_crop.py', $resolved, $temporary,
            ], 12);
            $data = json_decode($result['stdout'], true);
            if ($result['timed_out'] || $result['exit_code'] !== 0 || !is_array($data)
                || ($data['status'] ?? '') !== 'cropped') return $fallback;
            $image = PaymentImage::save($temporary);
            return ['status' => 'cropped', 'original_image_path' => $imagePath,
                'image_path' => $image, 'bounds' => $data['bounds'],
                'width' => $data['width'], 'height' => $data['height'],
                'warning' => '已生成裁剪候选，请确认完整码点和留白；这不是链接解析或支付验证'];
        } catch (\Throwable $e) {
            error_log('Reward image crop failed: ' . $e->getMessage());
            return $fallback;
        } finally {
            if ($temporary && is_file($temporary)) unlink($temporary);
        }
    }
}
