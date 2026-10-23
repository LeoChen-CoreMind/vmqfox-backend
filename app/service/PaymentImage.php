<?php

namespace app\service;

final class PaymentImage
{
    public static function save(string $path): string
    {
        $size = is_file($path) ? filesize($path) : false;
        $info = @getimagesize($path);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$size || $size > 10485760 || !$info || !isset($extensions[$info['mime'] ?? ''])
            || $info[0] * $info[1] > 40000000) {
            throw new \InvalidArgumentException('请上传不超过 10 MB、4000 万像素的 JPEG、PNG 或 WebP 图片');
        }
        $relative = 'uploads/payment/' . hash_file('sha256', $path) . '.' . $extensions[$info['mime']];
        $directory = public_path() . 'uploads/payment';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('无法创建收款码图片目录');
        }
        $target = public_path() . $relative;
        if (!is_file($target)) {
            $temporary = tempnam($directory, 'upload-');
            if (!$temporary) throw new \RuntimeException('无法保存图片');
            try {
                if (!copy($path, $temporary) || !rename($temporary, $target)) throw new \RuntimeException('无法保存图片');
                chmod($target, 0644);
            } finally {
                if (is_file($temporary)) unlink($temporary);
            }
        }
        return $relative;
    }
}
