<?php

namespace app\service;

use InvalidArgumentException;
use think\facade\Db;

final class QrcodeCatalog
{
    public static function normalize(array $item, int $type): array
    {
        $kind = (string) ($item['qr_kind'] ?? 'normal');
        $mode = (string) ($item['amount_mode'] ?? 'fixed');
        if (!in_array($type, [1, 2], true) || !in_array($kind, ['normal', 'reward'], true)
            || !in_array($mode, ['fixed', 'input'], true) || ($kind === 'reward' && $type !== 1)) {
            throw new InvalidArgumentException('二维码类型或金额模式无效');
        }
        $price = $mode === 'input' ? '0.00' : QrcodeInput::normalizePrice($item['price'] ?? null);
        if ($price === null || bccomp($price, '999999.99', 2) > 0) {
            throw new InvalidArgumentException('固定金额必须介于 0.01 和 999999.99 元');
        }
        $url = trim((string) ($item['pay_url'] ?? ''));
        $image = trim((string) ($item['image_path'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));
        $sort = filter_var($item['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        if (strlen($url) > 255 || strlen($name) > 100 || $sort === false || $sort < 0 || $sort > 999999) {
            throw new InvalidArgumentException('名称、链接或排序无效');
        }
        $hash = null;
        if ($image !== '') {
            if (!preg_match('~^uploads/payment/([a-f0-9]{64})\.(png|jpg|webp)$~D', $image, $match)
                || !is_file(public_path() . $image)) {
                throw new InvalidArgumentException('二维码图片不存在，请重新上传');
            }
            $hash = $match[1];
        }
        if ($url === '' && $image === '') throw new InvalidArgumentException('请上传二维码图片或填写收款链接');
        return ['type' => $type, 'qr_kind' => $kind, 'amount_mode' => $mode, 'price' => $price,
            'pay_url' => $url, 'image_path' => $image ?: null, 'image_hash' => $hash,
            'name' => $name, 'sort_order' => $sort];
    }

    public static function group(array $row): string
    {
        return $row['type'] . ':' . $row['amount_mode'] . ':'
            . ($row['amount_mode'] === 'fixed' ? number_format((float) $row['price'], 2, '.', '') : $row['qr_kind']);
    }

    public static function duplicates(array $a, array $b): bool
    {
        return (int) $a['type'] === (int) $b['type'] && (
            (!empty($a['pay_url']) && $a['pay_url'] === ($b['pay_url'] ?? null))
            || (!empty($a['image_hash']) && $a['image_hash'] === ($b['image_hash'] ?? null)));
    }

    public static function validateRows(array $rows): void
    {
        $counts = [];
        $changedGroups = [];
        $seen = [];
        foreach ($rows as $row) {
            $group = self::group($row);
            $counts[$group] = ($counts[$group] ?? 0) + 1;
            if (!isset($row['id'])) $changedGroups[$group] = true;
            foreach ($seen as $other) {
                if ((!isset($row['id']) || !isset($other['id'])) && self::duplicates($row, $other)) {
                    throw new InvalidArgumentException('已存在相同链接或图片的二维码');
                }
            }
            $seen[] = $row;
        }
        foreach ($changedGroups as $group => $_) {
            if ($counts[$group] > 5) throw new InvalidArgumentException('每个固定金额或通用码分组最多保存 5 张二维码');
        }
    }

    public static function lock(): void
    {
        if (!Db::name('setting')->where('vkey', 'user')->lock(true)->find()) {
            throw new \RuntimeException('系统设置锁记录不存在');
        }
    }

    public static function limitKey(string $group): string
    {
        return 'qr_limit:' . hash('sha256', $group);
    }

    public static function groupLimit(string $group): int
    {
        $value = (int) Db::name('setting')->where('vkey', self::limitKey($group))->value('vvalue');
        return $value >= 1 && $value <= 5 ? $value : 5;
    }

    public static function option(array $row): array
    {
        return ['id' => (string) $row['id'], 'name' => $row['name'] ?: ($row['qr_kind'] === 'reward' ? '微信赞赏码' : '收款码'),
            'qrKind' => $row['qr_kind'], 'amountMode' => $row['amount_mode'],
            'fixedAmount' => $row['amount_mode'] === 'fixed' ? number_format((float) $row['price'], 2, '.', '') : null,
            'payUrl' => (string) $row['pay_url'], 'imageUrl' => (string) ($row['image_path'] ?? '')];
    }

    public static function select(int $type, string $amount): array
    {
        $rows = Db::name('pay_qrcode')->where('type', $type)->where('state', 0)
            ->where(function ($query) use ($amount) {
                $query->where('amount_mode', 'input')->whereOr('price', $amount);
            })->order('sort_order asc,id asc')->select()->toArray();
        $buckets = [[], [], []];
        $counts = $limits = [];
        foreach ($rows as $row) {
            $group = self::group($row);
            $limits[$group] ??= self::groupLimit($group);
            if (($counts[$group] ?? 0) >= $limits[$group]) continue;
            if (empty($row['pay_url']) && empty($row['image_path'])) continue;
            $rank = $row['amount_mode'] === 'fixed' ? 0 : ($row['qr_kind'] === 'reward' ? 2 : 1);
            $buckets[$rank][] = self::option($row);
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }
        return array_merge(...$buckets);
    }

    public static function forOrder(array $order): array
    {
        if ((int) $order['state'] !== 0) return [];
        if (!empty($order['qr_options'])) {
            $options = json_decode($order['qr_options'], true, 512, JSON_THROW_ON_ERROR);
            $enabled = array_map('strval', Db::name('pay_qrcode')->where('state', 0)
                ->whereIn('id', array_column($options, 'id'))->column('id'));
            return array_values(array_filter($options, static fn ($option) => in_array((string) $option['id'], $enabled, true)));
        }
        if (empty($order['pay_url'])) return [];
        return [['id' => 'legacy', 'name' => '收款码', 'qrKind' => 'normal',
            'amountMode' => (int) $order['is_auto'] === 0 ? 'fixed' : 'input',
            'fixedAmount' => (int) $order['is_auto'] === 0 ? $order['really_price'] : null,
            'payUrl' => $order['pay_url'], 'imageUrl' => '']];
    }

    public static function saveDefault(string $key, string $url): void
    {
        if (!in_array($key, ['wxpay', 'zfbpay'], true)) throw new InvalidArgumentException('默认收款码类型无效');
        $url = trim($url);
        if (strlen($url) > 255) throw new InvalidArgumentException('收款链接不能超过 255 字节');
        Db::transaction(function () use ($key, $url): void {
            self::lock();
            if (QrcodeMigration::ready()) {
                $type = $key === 'wxpay' ? 1 : 2;
                $old = (string) Db::name('setting')->where('vkey', $key)->value('vvalue');
                $row = $old === '' ? null : Db::name('pay_qrcode')->where('type', $type)
                    ->where('amount_mode', 'input')->where('qr_kind', 'normal')->where('pay_url', $old)->find();
                if ($url !== '' && $url !== $old) {
                    $data = self::normalize(['pay_url' => $url, 'amount_mode' => 'input',
                        'qr_kind' => 'normal', 'name' => $row['name'] ?? '默认收款码',
                        'sort_order' => $row['sort_order'] ?? 0], $type);
                    $others = Db::name('pay_qrcode')->where('type', $type);
                    if ($row) $others->where('id', '<>', $row['id']);
                    self::validateRows(array_merge($others->select()->toArray(), [$data]));
                    if ($row) Db::name('pay_qrcode')->where('id', $row['id'])->update($data);
                    else Db::name('pay_qrcode')->insert($data + ['state' => 0]);
                } elseif ($url === '' && $row) {
                    Db::name('pay_qrcode')->where('id', $row['id'])->update(['state' => 1]);
                }
            }
            Db::name('setting')->where('vkey', $key)->update(['vvalue' => $url]);
        });
    }
}
