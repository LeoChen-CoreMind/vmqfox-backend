<?php

namespace app\service;

use think\facade\Db;

final class OrderCheckout
{
    public static function expire(): int
    {
        $minutes = (int) Db::name('setting')->where('vkey', 'close')->value('vvalue');
        $before = time() - ($minutes > 0 ? $minutes : 5) * 60;
        return Db::transaction(function () use ($before): int {
            QrcodeCatalog::lock();
            $rows = Db::name('pay_order')->where('state', 0)->where('create_date', '<=', $before)
                ->lock(true)->select()->toArray();
            $count = 0;
            foreach ($rows as $row) {
                $changed = Db::name('pay_order')->where('id', $row['id'])->where('state', 0)
                    ->update(['state' => -1, 'close_date' => time()]);
                if ($changed === 1) {
                    Db::name('tmp_price')->where('oid', $row['order_id'])->delete();
                    $count++;
                }
            }
            return $count;
        });
    }

    public static function create(array $values): array
    {
        $price = QrcodeInput::normalizePrice($values['price']);
        if ($price === null || bccomp($price, '999999.99', 2) > 0) {
            throw new \InvalidArgumentException('订单金额必须介于 0.01 和 999999.99 元');
        }
        if (!QrcodeMigration::ready()) throw new \RuntimeException('请管理员先在二维码管理中升级数据库');
        return Db::transaction(function () use ($values, $price): array {
            QrcodeCatalog::lock();
            if (Db::name('pay_order')->where('pay_id', $values['pay_id'])->find()) {
                throw new \InvalidArgumentException('商户订单号已存在');
            }
            $id = date('YmdHis') . bin2hex(random_bytes(8));
            $cents = (int) bcmul($price, '100', 0);
            $mode = QrcodeInput::normalizeDistinctionMode(
                Db::name('setting')->where('vkey', 'payQf')->value('vvalue')
            ) ?? 1;
            $step = $mode === 2 ? -1 : 1;
            $allocated = false;
            for ($attempt = 0; $attempt < 10 && $cents >= 1 && $cents <= 99999999; $attempt++, $cents += $step) {
                $key = $cents . '-' . $values['type'];
                $allocated = Db::execute('INSERT IGNORE INTO ' . QrcodeMigration::table('tmp_price')
                    . ' (price,oid) VALUES (?,?)', [$key, $id]) === 1;
                if ($allocated) break;
            }
            if (!$allocated) throw new \RuntimeException('订单超出负荷，请稍后重试');
            $actual = bcdiv((string) $cents, '100', 2);
            $options = QrcodeCatalog::select((int) $values['type'], $actual);
            if ($options === []) throw new \RuntimeException('暂无可用支付二维码，请联系商家');
            $first = $options[0];
            $order = array_merge($values, [
                'order_id' => $id, 'price' => $price, 'really_price' => $actual,
                'pay_url' => $first['payUrl'], 'is_auto' => $first['amountMode'] === 'input' ? 1 : 0,
                'qr_options' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'state' => 0, 'create_date' => time(), 'close_date' => 0, 'pay_date' => 0,
            ]);
            if (!Db::name('pay_order')->insert($order)) throw new \RuntimeException('创建订单失败');
            return $order;
        });
    }
}
