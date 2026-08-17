<?php
namespace app\controller\api;

use think\facade\Db;
use think\facade\Request;
use app\service\MonitorEventGuard;

class Monitor extends BaseController
{
    /**
     * 监控端心跳
     * @return \think\Response
     */
    public function heart()
    {
        // 获取请求参数
        $t = Request::param('t');
        $sign = Request::param('sign');

        if ($t === null || $sign === null || $t === '' || $sign === '') {
            return $this->error('缺少必要参数');
        }

        if (!MonitorEventGuard::isFresh($t)) {
            return $this->error('监控事件已过期');
        }

        // 获取数据库中的密钥
        $dbKey = Db::name("setting")->where("vkey", "key")->find();
        $key = $dbKey['vvalue'];

        // 验证签名
        $_sign = $t . $key;
        if (!hash_equals(md5($_sign), (string) $sign)) {
            return $this->error('密钥错误---请检查配置数据！');
        }

        if (!MonitorEventGuard::claim('heart', '', '', (string) $t, (string) $sign)) {
            return $this->error('监控事件重复');
        }

        try {
            Db::name("setting")->where("vkey", "lastheart")->update(["vvalue" => time()]);
            Db::name("setting")->where("vkey", "jkstate")->update(["vvalue" => "1"]);
        } catch (\Throwable $e) {
            MonitorEventGuard::release('heart', '', '', (string) $t, (string) $sign);
            throw $e;
        }

        return $this->success(null, '心跳更新成功');
    }

    /**
     * 兼容旧版心跳接口 appHeart
     * @return \think\response\Json
     */
    public function appHeart()
    {
        // 获取请求参数
        $t = Request::param('t');
        $sign = Request::param('sign');

        if ($t === null || $sign === null || $t === '' || $sign === '') {
            return json(["code" => -1, "msg" => "缺少必要参数"]);
        }

        if (!MonitorEventGuard::isFresh($t)) {
            return json(["code" => -1, "msg" => "监控事件已过期"]);
        }

        // 获取数据库中的密钥
        $dbKey = Db::name("setting")->where("vkey", "key")->find();
        $key = $dbKey['vvalue'];

        if (!hash_equals(md5($t . $key), (string) $sign)) {
            return json(["code" => -1, "msg" => "密钥错误---请检查配置数据！"]);
        }

        if (!MonitorEventGuard::claim('heart', '', '', (string) $t, (string) $sign)) {
            return json(["code" => -1, "msg" => "监控事件重复"]);
        }

        try {
            Db::name("setting")->where("vkey", "lastheart")->update(["vvalue" => time()]);
            Db::name("setting")->where("vkey", "jkstate")->update(["vvalue" => "1"]);
        } catch (\Throwable $e) {
            MonitorEventGuard::release('heart', '', '', (string) $t, (string) $sign);
            throw $e;
        }

        // 使用框架的json助手函数返回
        return json(["code" => 1, "msg" => "成功"]);
    }

    /**
     * 监控端推送通知
     * @return \think\Response
     */
    public function push()
    {
        // 获取请求参数
        $t = Request::param('t');
        $type = Request::param('type');
        $price = Request::param('price');
        $sign = Request::param('sign');

        if ($t === null || $type === null || $price === null || $sign === null
            || $t === '' || $type === '' || $price === '' || $sign === '') {
            return $this->error('缺少必要参数');
        }

        if (!MonitorEventGuard::isFresh($t)) {
            return $this->error('监控事件已过期');
        }

        // 获取系统密钥并验证签名
        $systemKey = Db::name("setting")->where("vkey", "key")->value('vvalue');
        if (empty($systemKey)) {
            return $this->error('系统密钥未设置');
        }

        $_sign = $type . $price . $t . $systemKey;
        if (!hash_equals(md5($_sign), (string) $sign)) {
            return $this->error('签名校验不通过');
        }

        $eventPrice = (string) $price;
        if (!MonitorEventGuard::claim('push', (string) $type, $eventPrice, (string) $t, (string) $sign)) {
            return $this->error('监控事件重复');
        }

        $orderUpdated = false;
        try {
            // 精确化金额
            $price = sprintf("%.2f", $price);

        // 关闭超时订单 only after the event has been authenticated and claimed.
        $this->closeEndOrder();

        // 更新最后支付时间
        Db::name("setting")->where("vkey", "lastpay")->update(["vvalue" => time()]);

        // 查找订单
        $order = Db::name("pay_order")
            ->where("really_price", $price)
            ->where("state", 0)
            ->where("type", $type)
            ->find();

        // 如果未找到，则记录为无订单转账
        if (!$order) {
            $data = [
                "close_date" => 0, "create_date" => time(), "is_auto" => 0,
                "notify_url" => "", "order_id" => "无订单转账-" . time(), "param" => "无订单转账",
                "pay_date" => 0, "pay_id" => "无订单转账-" . time(), "pay_url" => "",
                "price" => $price, "really_price" => $price, "return_url" => "",
                "state" => 1, "type" => $type
            ];
            Db::name("pay_order")->insert($data);
            return $this->success(null, '成功'); // 按旧版逻辑，记录后即返回成功
        }

        // 找到订单，同步处理
            $updated = Db::name("pay_order")
                ->where("id", $order['id'])
                ->where("state", 0)
                ->update([
                "state" => 1, "pay_date" => time(), "close_date" => time()
            ]);

            if ($updated !== 1) {
                return $this->success(null, '订单已处理');
            }

            $orderUpdated = true;

            Db::name("tmp_price")->where("oid", $order['order_id'])->delete();

            // 准备并发送异步通知 (逻辑来自旧版 appPush)
            $notifyUrl = $order['notify_url'];
            if (!empty($notifyUrl)) {
                $p = "payId=".$order['pay_id']."&param=".$order['param']."&type=".$order['type']."&price=".$order['price']."&reallyPrice=".$order['really_price'];
                $signStr = $order['pay_id'].$order['param'].$order['type'].$order['price'].$order['really_price'].$systemKey;
                $p = $p . "&sign=".md5($signStr);

                if (strpos($notifyUrl, "?") === false) {
                    $notifyUrl = $notifyUrl."?".$p;
                } else {
                    $notifyUrl = $notifyUrl."&".$p;
                }

                $re = $this->getCurl($notifyUrl); // 发送GET请求

                // 如果通知失败，则更新订单状态为2
                if ($re != "success") {
                    Db::name("pay_order")->where("id", $order['id'])->update(["state" => 2]);
                }
            }
            return $this->success(null, '订单支付成功');
        } catch (\Throwable $e) {
            if (!$orderUpdated) {
                MonitorEventGuard::release('push', (string) $type, $eventPrice, (string) $t, (string) $sign);
            }
            throw $e;
        }
    }

    /**
     * 兼容旧版推送接口 appPush
     * @return \think\response\Json
     */
    public function appPush()
    {
        $t = Request::param('t');
        $type = Request::param('type');
        $price = Request::param('price');
        $sign = Request::param('sign');

        if ($t === null || $type === null || $price === null || $sign === null
            || $t === '' || $type === '' || $price === '' || $sign === '') {
            return json(["code" => -1, "msg" => "缺少必要参数"]);
        }

        if (!MonitorEventGuard::isFresh($t)) {
            return json(["code" => -1, "msg" => "监控事件已过期"]);
        }

        $systemKey = Db::name("setting")->where("vkey", "key")->value('vvalue');
        if (empty($systemKey)) {
            return json(["code" => -1, "msg" => "系统密钥未设置"]);
        }

        if (!hash_equals(md5($type . $price . $t . $systemKey), (string) $sign)) {
            return json(["code" => -1, "msg" => "签名校验不通过"]);
        }

        $eventPrice = (string) $price;
        if (!MonitorEventGuard::claim('push', (string) $type, $eventPrice, (string) $t, (string) $sign)) {
            return json(["code" => -1, "msg" => "监控事件重复"]);
        }

        $orderUpdated = false;
        try {
            $price = sprintf("%.2f", $price);
            $this->closeEndOrder();
            Db::name("setting")->where("vkey", "lastpay")->update(["vvalue" => time()]);

            $order = Db::name("pay_order")
                ->where("really_price", $price)
                ->where("state", 0)
                ->where("type", $type)
                ->find();

            if (!$order) {
                $eventTime = time();
                Db::name("pay_order")->insert([
                    "close_date" => 0, "create_date" => $eventTime, "is_auto" => 0,
                    "notify_url" => "", "order_id" => "无订单转账-" . $eventTime, "param" => "无订单转账",
                    "pay_date" => 0, "pay_id" => "无订单转账-" . $eventTime, "pay_url" => "",
                    "price" => $price, "really_price" => $price, "return_url" => "",
                    "state" => 1, "type" => $type,
                ]);
                return json(["code" => 1, "msg" => "成功"]);
            }

            $updated = Db::name("pay_order")
                ->where("id", $order['id'])
                ->where("state", 0)
                ->update(["state" => 1, "pay_date" => time(), "close_date" => time()]);

            if ($updated !== 1) {
                return json(["code" => 1, "msg" => "订单已处理"]);
            }

            $orderUpdated = true;
            Db::name("tmp_price")->where("oid", $order['order_id'])->delete();

            $notifyUrl = $order['notify_url'];
            if (!empty($notifyUrl)) {
                $p = "payId=".$order['pay_id']."&param=".$order['param']."&type=".$order['type']."&price=".$order['price']."&reallyPrice=".$order['really_price'];
                $signStr = $order['pay_id'].$order['param'].$order['type'].$order['price'].$order['really_price'].$systemKey;
                $p = $p . "&sign=".md5($signStr);

                if (strpos($notifyUrl, "?") === false) {
                    $notifyUrl = $notifyUrl."?".$p;
                } else {
                    $notifyUrl = $notifyUrl."&".$p;
                }

                $re = $this->postCurl($notifyUrl, []);
                if ($re != "success") {
                    Db::name("pay_order")->where("id", $order['id'])->update(["state" => 2]);
                }
            }

            return json(["code" => 1, "msg" => "成功"]);
        } catch (\Throwable $e) {
            if (!$orderUpdated) {
                MonitorEventGuard::release('push', (string) $type, $eventPrice, (string) $t, (string) $sign);
            }
            throw $e;
        }
    }

    /**
     * 完成订单
     * @param array $order 订单信息
     * @param string $payId 支付ID
     * @return bool 是否完成成功
     */
    private function completeOrder($order, $payId)
    {
        $updated = Db::name("pay_order")
            ->where("id", $order['id'])
            ->where("state", 0)
            ->update([
                "state" => 1,
                "pay_date" => time(),
                "pay_id" => $payId,
            ]);

        if ($updated !== 1) {
            return false;
        }

        // 删除临时价格
        Db::name("tmp_price")->where("oid", $order['order_id'])->delete();

        // 异步通知
        $this->orderNotify($order);

        return true;
    }

    /**
     * 订单通知
     * @param array $order 订单信息
     * @return bool 是否通知成功
     */
    private function orderNotify($order)
    {
        if (empty($order['notify_url'])) {
            return false;
        }

        // 获取密钥
        $setting = Db::name("setting")->where("vkey", "key")->find();
        $key = $setting ? $setting['vvalue'] : '';

        // 构建通知参数
        $params = [
            'payId' => $order['order_id'],
            'param' => $order['param'],
            'type' => $order['type'],
            'price' => $order['price'],
            'reallyPrice' => $order['really_price']
        ];

        // 计算签名
        $sign = md5("payId=" . $params['payId'] . "&param=" . $params['param'] . "&type=" . $params['type'] . "&price=" . $params['price'] . "&reallyPrice=" . $params['reallyPrice'] . "&key=" . $key);
        $params['sign'] = $sign;

        // 发送异步通知
        $result = $this->postCurl($order['notify_url'], $params);

        // 记录通知结果
        $log = [
            'url' => $order['notify_url'],
            'params' => json_encode($params),
            'response' => $result,
            'time' => date('Y-m-d H:i:s')
        ];

        // 可以将通知日志记录到文件或数据库

        return true;
    }

    /**
     * 发送GET请求
     * @param string $url 请求URL
     * @return string 响应结果
     */
    private function getCurl($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * 发送POST请求
     * @param string $url 请求URL
     * @param array $params 请求参数
     * @return string 响应结果
     */
    private function postCurl($url, $params)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * 关闭超时订单
     * @return bool
     */
    private function closeEndOrder()
    {
        // 从设置中获取订单关闭时间（分钟）
        $closeTimeSetting = Db::name('setting')->where('vkey', 'close')->value('vvalue');
        $minutes = intval($closeTimeSetting) > 0 ? intval($closeTimeSetting) : 5; // 默认为5分钟

        $time = time() - ($minutes * 60);

        $orders = Db::name("pay_order")
            ->where("state", 0)
            ->where("create_date", "<", $time)
            ->select();

        foreach($orders as $order) {
            // 更新订单状态
            Db::name("pay_order")
                ->where("order_id", $order['order_id'])
                ->update(["state" => -1, "close_date" => time()]);

            // 删除对应的tmp_price记录
            Db::name("tmp_price")
                ->where("oid", $order['order_id'])
                ->delete();
        }

        // 清理孤立的tmp_price记录
        $tmpPrices = Db::name("tmp_price")->select();
        foreach($tmpPrices as $tmp) {
            $exists = Db::name("pay_order")->where("order_id", $tmp['oid'])->find();
            if (!$exists) {
                Db::name("tmp_price")->where("oid", $tmp['oid'])->delete();
            }
        }

        return true;
    }
}
