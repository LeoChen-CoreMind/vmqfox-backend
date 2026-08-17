<?php
namespace app\controller\api;

use think\facade\Db;
use think\facade\Request;
use think\facade\Config as ThinkConfig;
use app\service\SystemUptime;
use app\service\AdminCredentials;

class Config extends BaseController
{
    /**
     * 获取系统设置
     * @return \think\Response
     */
    public function get()
    {
        $config = (new AdminCredentials())->publicSettings();

        // 获取系统信息
        $sysInfo = $this->getSystemInfo();

        // 合并配置和系统信息
        $result = array_merge($config, $sysInfo);

        return $this->success($result);
    }

    /**
     * 保存系统设置
     * @return \think\Response
     */
    public function save()
    {
        $settings = Request::param();

        // 允许修改的配置项
        $credentials = new AdminCredentials();
        $publicSettings = $credentials->publicSettings();
        if (isset($settings['user']) || (isset($settings['pass']) && $settings['pass'] !== '')) {
            $credentials->update(
                (string) ($settings['user'] ?? $publicSettings['user'] ?? ''),
                isset($settings['pass']) ? (string) $settings['pass'] : null
            );
        }

        $allowKeys = [
            'notifyUrl', 'returnUrl', 'key',
            'close', 'payQf', 'wxpay', 'zfbpay'
        ];

        // 过滤不允许的键
        $filteredSettings = array_filter($settings, function($key) use ($allowKeys) {
            return in_array($key, $allowKeys);
        }, ARRAY_FILTER_USE_KEY);

        // 更新配置
        foreach ($filteredSettings as $key => $value) {
            Db::name("setting")->where("vkey", $key)->update([
                "vvalue" => $value
            ]);
        }

        return $this->success(null, '保存成功');
    }

    /**
     * 获取系统状态
     * @return \think\Response
     */
    public function status()
    {
        // 获取心跳和支付状态
        $lastheart = Db::name("setting")->where("vkey", "lastheart")->find();
        $lastpay = Db::name("setting")->where("vkey", "lastpay")->find();
        $jkstate = Db::name("setting")->where("vkey", "jkstate")->find();

        // 今日订单统计
        $today = strtotime(date("Y-m-d"), time());

        $todayOrder = Db::name("pay_order")
            ->where("create_date", ">=", $today)
            ->where("create_date", "<=", $today + 86400)
            ->count();

        $todaySuccessOrder = Db::name("pay_order")
            ->where("state", ">=", 1)
            ->where("create_date", ">=", $today)
            ->where("create_date", "<=", $today + 86400)
            ->count();

        $todayCloseOrder = Db::name("pay_order")
            ->where("state", -1)
            ->where("create_date", ">=", $today)
            ->where("create_date", "<=", $today + 86400)
            ->count();

        $todayMoney = Db::name("pay_order")
            ->where("state", ">=", 1)
            ->where("create_date", ">=", $today)
            ->where("create_date", "<=", $today + 86400)
            ->sum("price");

        // 总订单统计
        $countOrder = Db::name("pay_order")->count();
        $countMoney = Db::name("pay_order")
            ->where("state", ">=", 1)
            ->sum("price");

        // 监控状态 - 实时计算并更新数据库
        $monitorStatus = 0; // 0-未知 1-正常 2-异常
        $heartTime = intval($lastheart['vvalue']);

        if ($heartTime > 0) {
            if (time() - $heartTime < 180) {
                $monitorStatus = 1;
                // 如果监控端正常，确保数据库状态为1
                if ($jkstate['vvalue'] != '1') {
                    Db::name("setting")->where("vkey", "jkstate")->update(["vvalue" => "1"]);
                }
            } else {
                $monitorStatus = 2;
                // 如果监控端异常，更新数据库状态为0
                if ($jkstate['vvalue'] != '0') {
                    Db::name("setting")->where("vkey", "jkstate")->update(["vvalue" => "0"]);
                }
            }
        } else {
            // 如果没有心跳记录，设置为未绑定状态
            if ($jkstate['vvalue'] != '-1') {
                Db::name("setting")->where("vkey", "jkstate")->update(["vvalue" => "-1"]);
            }
        }

        $result = [
            'monitorStatus' => $monitorStatus,
            'lastHeartTime' => $heartTime > 0 ? date('Y-m-d H:i:s', $heartTime) : '',
            'lastPayTime' => intval($lastpay['vvalue']) > 0 ? date('Y-m-d H:i:s', intval($lastpay['vvalue'])) : '',
            'jkState' => intval($jkstate['vvalue']),
            'todayOrder' => $todayOrder,
            'todaySuccessOrder' => $todaySuccessOrder,
            'todayCloseOrder' => $todayCloseOrder,
            'todayMoney' => round($todayMoney, 2),
            'countOrder' => $countOrder,
            'countMoney' => round($countMoney, 2)
        ];

        return $this->success($result);
    }

    /**
     * 获取系统设置
     * @return \think\Response
     */
    public function settings()
    {
        $result = (new AdminCredentials())->publicSettings();
        if (($result['key'] ?? '') === '') {
            $result['key'] = bin2hex(random_bytes(16));
            Db::name("setting")->where("vkey","key")->update([
                "vvalue" => $result['key']
            ]);
        }

        return $this->success($result);
    }

    /**
     * 保存系统设置
     * @return \think\Response
     */
    public function updateSettings()
    {
        $params = Request::param();

        $credentials = new AdminCredentials();
        $publicSettings = $credentials->publicSettings();
        if (isset($params['user']) || (isset($params['pass']) && $params['pass'] !== '')) {
            $credentials->update(
                (string) ($params['user'] ?? $publicSettings['user'] ?? ''),
                isset($params['pass']) ? (string) $params['pass'] : null
            );
        }
        if (isset($params['notifyUrl'])) {
            Db::name("setting")->where("vkey", "notifyUrl")->update(["vvalue" => $params['notifyUrl']]);
        }
        if (isset($params['returnUrl'])) {
            Db::name("setting")->where("vkey", "returnUrl")->update(["vvalue" => $params['returnUrl']]);
        }
        if (isset($params['key'])) {
            Db::name("setting")->where("vkey", "key")->update(["vvalue" => $params['key']]);
        }
        if (isset($params['close'])) {
            Db::name("setting")->where("vkey", "close")->update(["vvalue" => $params['close']]);
        }
        if (isset($params['payQf'])) {
            Db::name("setting")->where("vkey", "payQf")->update(["vvalue" => $params['payQf']]);
        }
        if (isset($params['wxpay'])) {
            Db::name("setting")->where("vkey", "wxpay")->update(["vvalue" => $params['wxpay']]);
        }
        if (isset($params['zfbpay'])) {
            Db::name("setting")->where("vkey", "zfbpay")->update(["vvalue" => $params['zfbpay']]);
        }

        return $this->success(null, '保存成功');
    }

    /**
     * 获取监控状态
     * @return \think\Response
     */
    public function monitor()
    {
        $jkstate = Db::name("setting")->where("vkey", "jkstate")->find();
        $lastheart = Db::name("setting")->where("vkey", "lastheart")->find();
        $lastpay = Db::name("setting")->where("vkey", "lastpay")->find();

        $result = [
            'jkstate' => $jkstate['vvalue'],
            'lastheart' => $lastheart['vvalue'],
            'lastpay' => $lastpay['vvalue']
        ];

        return $this->success($result);
    }

    /**
     * 更新监控参数
     * @return \think\Response
     */
    public function updateMonitor()
    {
        $jk = Request::param('jk');

        if ($jk !== null) {
            Db::name("setting")->where("vkey", "jkstate")->update([
                "vvalue" => $jk
            ]);
        }

        return $this->success(null, '设置成功');
    }

    /**
     * 获取系统信息
     * @return array 系统信息
     */
    private function getSystemInfo()
    {
        // 获取MySQL版本
        $v = Db::query("SELECT VERSION();");
        $mysqlVersion = $v[0]['VERSION()'];

        // 检查GD库
        $gdInfo = '';
        if (function_exists("gd_info")) {
            $gd = gd_info();
            $gdInfo = $gd["GD Version"];
        } else {
            $gdInfo = 'GD库未开启';
        }

        return [
            'phpVersion' => PHP_VERSION,
            'phpOs' => PHP_OS,
            'server' => $_SERVER['SERVER_SOFTWARE'],
            'mysqlVersion' => $mysqlVersion,
            'thinkphpVersion' => ThinkConfig::get('app.version'),
            'runTime' => (new SystemUptime())->getFormatted(),
            'appVersion' => ThinkConfig::get('app.ver'),
            'gdInfo' => $gdInfo
        ];
    }

    /**
     * 获取系统运行时间
     * @return string 运行时间
     */
}
