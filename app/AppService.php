<?php
declare (strict_types = 1);

namespace app;

use think\Service;
use think\facade\Event;
use think\facade\Db;
use app\service\AdminCredentials;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // 服务注册
    }

    public function boot()
    {
        (new AdminCredentials())->initializeFromEnvironment();

        // 注册定时任务，每分钟自动关闭过期订单
        Event::listen('HttpRun', function () {
            // 随机执行，避免每次请求都执行
            if (mt_rand(1, 10) === 1) {
                $this->closeExpiredOrders();
            }
        });
    }

    /**
     * 关闭过期订单
     */
    private function closeExpiredOrders()
    {
        \app\service\OrderCheckout::expire();
    }
}
