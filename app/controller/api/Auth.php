<?php
namespace app\controller\api;

use think\facade\Request;
use think\facade\Session;
use think\facade\Db;
use think\facade\Cookie;
use app\service\AdminCredentials;

class Auth extends BaseController
{
    /**
     * 登录
     * @return \think\Response
     */
    public function login()
    {
        $username = Request::param('username');
        $password = Request::param('password');

        if (empty($username) || empty($password)) {
            return $this->error('用户名或密码不能为空');
        }

        if (!(new AdminCredentials())->verify((string) $username, (string) $password)) {
            return $this->error('用户名或密码错误');
        }

        $key = (string) Db::name('setting')->where('vkey', 'key')->value('vvalue');

        // 生成Token
        $token = md5('vmqphp_' . $key);

        // 设置Session
        Session::regenerate(true);
        Session::set('admin', $username);
        Session::save();
        Cookie::set('PHPSESSID', Session::getId(), 86400);

        return $this->success([
            'accessToken' => $token,
            'username' => $username
        ]);
    }

    /**
     * 退出登录
     * @return \think\Response
     */
    public function logout()
    {
        Session::destroy();
        Cookie::delete('PHPSESSID');
        return $this->success(null, '退出成功');
    }
}
